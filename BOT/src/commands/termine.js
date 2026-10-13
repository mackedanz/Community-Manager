// /termine: kommende Termine der Orga anzeigen und per Knopf zu- oder absagen (nur für die aufrufende Person sichtbar).
// Es gelten dieselben Regeln wie auf der Web-App: Ausbildungstermine erst nach bestandener Vorstufe.
import { MessageFlags } from 'discord.js';
import { orgForGuild } from '../db.js';
import { accessFor, userByDiscordId } from '../lib/access.js';
import { OrgError } from '../lib/errors.js';
import { eventDetail, RSVP_LABELS, setRsvp, upcomingEvents } from '../lib/events.js';
import { cut } from '../lib/text.js';

export const data = {
  name: 'termine',
  description: 'Kommende Termine der Orga anzeigen und zu- oder absagen',
  type: 1,
  contexts: [0],
};

const EPHEMERAL = MessageFlags.Ephemeral;
const HEX_ID = /^[a-f0-9]{24}$/;

/** Zeitpunkt aus der Datenbank (UTC) als Unix-Sekunden für Discord-Zeitstempel. */
export function unix(dbTime) {
  return Math.floor(Date.parse(`${dbTime.replace(' ', 'T')}Z`) / 1000);
}

const berlin = new Intl.DateTimeFormat('de-DE', {
  timeZone: 'Europe/Berlin', weekday: 'short', day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit',
});

/** Zeit als Text in deutscher Zeit (für Auswahllisten, in denen Discord-Zeitstempel nicht wirken). */
export function berlinText(dbTime) {
  return `${berlin.format(new Date(unix(dbTime) * 1000))} Uhr`;
}

/** Liste der kommenden Termine mit Auswahl zum Öffnen. */
export function listView(org, events, note = null) {
  if (events.length === 0) {
    return { content: `**Kommende Termine – ${org.name}**\nGerade ist nichts geplant.${note ? `\n\n${note}` : ''}`, embeds: [], components: [], allowedMentions: { parse: [] } };
  }
  const lines = events.map((e) => {
    const tag = e.training ? ` · Ausbildung ${e.training}` : e.department ? ` · ${e.department}` : '';
    return `• <t:${unix(e.startsAt)}:f> **${e.title}**${tag} – ${e.yes} dabei`;
  });
  return {
    content: `**Kommende Termine – ${org.name}**\n${lines.join('\n')}${note ? `\n\n${note}` : ''}`,
    embeds: [],
    allowedMentions: { parse: [] },
    components: [{
      type: 1,
      components: [{
        type: 3, // Textauswahl
        custom_id: 'evt:show',
        placeholder: 'Termin öffnen, um zu- oder abzusagen',
        options: events.map((e) => ({
          label: cut(e.title, 100),
          value: e.id,
          description: cut(`${berlinText(e.startsAt)} · ${e.yes} dabei`, 100),
        })),
      }],
    }],
  };
}

/** Ein Termin mit Antworten und Knöpfen. */
export function detailView(org, e, { appUrl, myStatus = null, note = null }) {
  const names = (status) => e.rsvps.filter((r) => r.status === status).map((r) => r.name);
  const field = (name, list) => ({ name: `${name} (${list.length})`, value: list.length === 0 ? '–' : cut(list.join(', '), 1024), inline: true });
  const fields = [{
    name: 'Wann',
    value: `<t:${unix(e.startsAt)}:F> (<t:${unix(e.startsAt)}:R>)${e.endsAt ? `\nbis <t:${unix(e.endsAt)}:F>` : ''}`,
  }];
  if (e.location) fields.push({ name: 'Wo', value: cut(e.location, 1024) });
  if (e.course) fields.push({ name: 'Ausbildung', value: `${e.course.areaName} · ${e.course.topic} · Stufe ${e.course.level} („${e.course.title}“)` });
  if (e.department) fields.push({ name: 'Abteilung', value: e.department });
  fields.push(field(RSVP_LABELS.YES, names('YES')), field(RSVP_LABELS.MAYBE, names('MAYBE')), field(RSVP_LABELS.NO, names('NO')));
  fields.push({ name: 'Deine Antwort', value: myStatus ? RSVP_LABELS[myStatus] : 'noch keine' });

  const cancelled = e.status === 'CANCELLED';
  const button = (status, label, style) => ({ type: 2, style, label, custom_id: `evt:rsvp:${e.id}:${status}`, disabled: cancelled && status !== 'NO' && status !== 'NONE' });
  return {
    content: note ?? '',
    allowedMentions: { parse: [] },
    embeds: [{
      title: cut(`${cancelled ? 'Abgesagt: ' : ''}${e.title}`, 256),
      url: `${appUrl}/o/${encodeURIComponent(org.slug)}/events/${e.id}`,
      description: e.description ? cut(e.description, 1500) : undefined,
      color: cancelled ? 0x71717a : 0x0ea5e9,
      fields,
    }],
    components: [
      { type: 1, components: [button('YES', RSVP_LABELS.YES, 3), button('MAYBE', RSVP_LABELS.MAYBE, 1), button('NO', RSVP_LABELS.NO, 4), button('NONE', 'Antwort entfernen', 2)] },
      { type: 1, components: [
        { type: 2, style: 2, label: 'Zurück zur Liste', custom_id: 'evt:list' },
        { type: 2, style: 5, label: 'Im Community-Manager öffnen', url: `${appUrl}/o/${encodeURIComponent(org.slug)}/events/${e.id}` },
      ] },
    ],
  };
}

/** Orga des Servers und Rechte der Person, oder ein Ablehnungstext. */
async function context(interaction, pool) {
  const org = await orgForGuild(pool, interaction.guildId);
  if (org === null) return { refusal: 'Für diesen Server ist noch keine Orga eingerichtet. Das erledigt ein Server-Admin mit /einrichten.' };
  const access = accessFor(org, interaction);
  if (!access.member) return { refusal: 'Die Termine sehen nur Mitglieder der Orga.' };
  return { org, access };
}

async function renderDetail(pool, config, org, access, eventId, discordId, note = null) {
  const e = await eventDetail(pool, org.id, eventId, access);
  if (e === null) return null;
  const user = await userByDiscordId(pool, discordId);
  const myStatus = user === null ? null : e.rsvps.find((r) => r.userId === user.id)?.status ?? null;
  const hint = user === null ? `Zum Zu- oder Absagen melde dich einmal auf ${config.appUrl} an.` : null;
  return detailView(org, e, { appUrl: config.appUrl, myStatus, note: note ?? hint });
}

export async function execute(interaction, { pool }) {
  const { org, refusal } = await context(interaction, pool);
  if (refusal) {
    await interaction.reply({ content: refusal, flags: EPHEMERAL });
    return;
  }
  await interaction.deferReply({ flags: EPHEMERAL });
  await interaction.editReply(listView(org, await upcomingEvents(pool, org.id)));
}

export function handles(customId) {
  return customId === 'evt:show' || customId === 'evt:list' || customId.startsWith('evt:rsvp:');
}

/** Auswahl eines Termins, "Zurück zur Liste" und die Antwort-Knöpfe. */
export async function handleComponent(interaction, { pool, config }) {
  const { org, access, refusal } = await context(interaction, pool);
  if (refusal) {
    await interaction.reply({ content: refusal, flags: EPHEMERAL });
    return;
  }
  if (interaction.customId === 'evt:list') {
    await interaction.update(listView(org, await upcomingEvents(pool, org.id)));
    return;
  }
  if (interaction.customId === 'evt:show') {
    const view = await renderDetail(pool, config, org, access, interaction.values[0], interaction.user.id);
    await interaction.update(view ?? listView(org, await upcomingEvents(pool, org.id), 'Diesen Termin gibt es nicht mehr.'));
    return;
  }

  const [, , eventId, status] = interaction.customId.split(':');
  if (!HEX_ID.test(eventId ?? '')) return;
  await interaction.deferUpdate();
  const user = await userByDiscordId(pool, interaction.user.id);
  let note;
  if (user === null) {
    note = `Zum Zu- oder Absagen melde dich einmal auf ${config.appUrl} an. Danach klappt es auch hier.`;
  } else {
    try {
      await setRsvp(pool, org.id, user.id, eventId, status, access);
      note = status === 'NONE' ? 'Deine Antwort ist entfernt.' : `Gespeichert: ${RSVP_LABELS[status]}.`;
    } catch (err) {
      if (!(err instanceof OrgError)) throw err;
      note = err.message;
    }
  }
  const view = await renderDetail(pool, config, org, access, eventId, interaction.user.id, note);
  await interaction.editReply(view ?? listView(org, await upcomingEvents(pool, org.id), 'Diesen Termin gibt es nicht mehr.'));
}
