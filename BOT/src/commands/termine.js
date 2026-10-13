// /termine: kommende Termine der Orga anzeigen und per Knopf zu- oder absagen (nur für die aufrufende Person sichtbar).
// Es gelten dieselben Regeln wie auf der Web-App: Ausbildungstermine erst nach bestandener Vorstufe.
// Hier liegen auch die Knöpfe unter den öffentlichen Ankündigungen (evt:pub:…) und das Bestätigen der Teilnehmer
// eines Ausbildungstermins durch Ausbilder (evt:conf:…, evt:passed:…).
import { MessageFlags } from 'discord.js';
import { orgForGuild } from '../db.js';
import { accessFor, userByDiscordId } from '../lib/access.js';
import { eventEmbed, eventUrl, syncPost } from '../lib/announce.js';
import { OrgError } from '../lib/errors.js';
import { eventDetail, RSVP_LABELS, setRsvp, upcomingEvents } from '../lib/events.js';
import { ANNOUNCE_CHANNEL, getSetting, isMissingTable } from '../lib/settings.js';
import { cut } from '../lib/text.js';
import { confirm, participants } from '../lib/training.js';

/** Für /hilfe: nutzbar für Mitglieder der Orga */
export const audience = 'members';

export const data = {
  name: 'termine',
  description: 'Kommende Termine der Orga anzeigen und zu- oder absagen',
  type: 1,
  contexts: [0],
};

const EPHEMERAL = MessageFlags.Ephemeral;
const HEX_ID = /^[a-f0-9]{24}$/;
/** Discord erlaubt höchstens 25 Einträge in einer Auswahl. */
const MAX_OPTIONS = 25;

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

/** Ein Termin mit Antworten und Knöpfen (persönliche Ansicht). */
export function detailView(org, e, { appUrl, myStatus = null, note = null, canConfirm = false }) {
  const cancelled = e.status === 'CANCELLED';
  const button = (status, label, style) => ({ type: 2, style, label, custom_id: `evt:rsvp:${e.id}:${status}`, disabled: cancelled && status !== 'NO' && status !== 'NONE' });
  const second = [{ type: 2, style: 2, label: 'Zurück zur Liste', custom_id: 'evt:list' }];
  if (canConfirm && e.course) second.push({ type: 2, style: 1, label: 'Teilnahme bestätigen', custom_id: `evt:conf:${e.id}` });
  second.push({ type: 2, style: 5, label: 'Im Community-Manager öffnen', url: eventUrl(org, e, appUrl) });
  return {
    content: note ?? '',
    allowedMentions: { parse: [] },
    embeds: [eventEmbed(org, e, appUrl, { myStatus })],
    components: [
      { type: 1, components: [button('YES', RSVP_LABELS.YES, 3), button('MAYBE', RSVP_LABELS.MAYBE, 1), button('NO', RSVP_LABELS.NO, 4), button('NONE', 'Antwort entfernen', 2)] },
      { type: 1, components: second },
    ],
  };
}

/** Auswahl der Teilnehmer, die eine Ausbildung bestanden haben (für Ausbilder und Admins). */
export function confirmView(e, people, note = null) {
  const shown = people.slice(0, MAX_OPTIONS);
  const more = people.length > MAX_OPTIONS ? `\nEs sind ${people.length} Teilnehmer; hier stehen die ersten ${MAX_OPTIONS}, die übrigen bitte auf der Webseite bestätigen.` : '';
  const head = `**Teilnahme bestätigen: ${e.title}**\nAusbildung: ${e.course.topic} · Stufe ${e.course.level} („${e.course.title}“)\n`;
  if (shown.length === 0) {
    return {
      content: `${head}Noch niemand hat mit „Dabei“ zugesagt (und ein Konto in der Web-App).${note ? `\n\n${note}` : ''}`,
      embeds: [],
      allowedMentions: { parse: [] },
      components: [{ type: 1, components: [{ type: 2, style: 2, label: 'Zurück zum Termin', custom_id: `evt:open:${e.id}` }] }],
    };
  }
  return {
    content: `${head}Markiere alle, die bestanden haben. Wer nicht markiert ist, gilt als nicht bestanden.${more}${note ? `\n\n${note}` : ''}`,
    embeds: [],
    allowedMentions: { parse: [] },
    components: [
      { type: 1, components: [{
        type: 3,
        // Die gezeigten Personen stehen im Termin; welche gezeigt wurden, ergibt sich aus den Optionen der Auswahl
        custom_id: `evt:passed:${e.id}`,
        placeholder: 'Wer hat bestanden?',
        min_values: 0,
        max_values: shown.length,
        options: shown.map((p) => ({ label: cut(p.name, 100), value: p.userId, default: p.passed })),
      }] },
      { type: 1, components: [{ type: 2, style: 2, label: 'Zurück zum Termin', custom_id: `evt:open:${e.id}` }] },
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
  return detailView(org, e, { appUrl: config.appUrl, myStatus, note: note ?? hint, canConfirm: access.admin || access.canInstruct });
}

const goneView = async (pool, org) => listView(org, await upcomingEvents(pool, org.id), 'Diesen Termin gibt es nicht mehr.');

/** Antwort speichern; liefert den Hinweistext für die Person. */
async function answer(pool, config, org, access, discordId, eventId, status) {
  const user = await userByDiscordId(pool, discordId);
  if (user === null) return `Zum Zu- oder Absagen melde dich einmal auf ${config.appUrl} an. Danach klappt es auch hier.`;
  try {
    await setRsvp(pool, org.id, user.id, eventId, status, access);
    return status === 'NONE' ? 'Deine Antwort ist entfernt.' : `Gespeichert: ${RSVP_LABELS[status]}.`;
  } catch (err) {
    if (!(err instanceof OrgError)) throw err;
    return err.message;
  }
}

/** Ankündigung im Kanal sofort an die neue Antwort anpassen (sonst spätestens beim nächsten Lauf nach einer Minute). */
async function refreshAnnouncement(pool, client, config, org, eventId, log) {
  try {
    const channelId = await getSetting(pool, org.id, ANNOUNCE_CHANNEL);
    await syncPost(pool, client, org, eventId, { channelId, appUrl: config.appUrl, log });
  } catch (err) {
    if (!isMissingTable(err)) log?.warn(`Ankündigung nicht aktualisiert: ${err.message}`);
  }
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
  return customId === 'evt:show' || customId === 'evt:list' || /^evt:(rsvp|pub|conf|passed|open):/.test(customId);
}

export async function handleComponent(interaction, { pool, config, log }) {
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
    await interaction.update(view ?? (await goneView(pool, org)));
    return;
  }

  const [, kind, eventId, status] = interaction.customId.split(':');
  if (!HEX_ID.test(eventId ?? '')) return;

  if (kind === 'open') {
    const view = await renderDetail(pool, config, org, access, eventId, interaction.user.id);
    await interaction.update(view ?? (await goneView(pool, org)));
    return;
  }

  if (kind === 'pub') {
    // Knopf unter der öffentlichen Ankündigung: Antwort nur für die Person sichtbar, die Ankündigung zählt neu
    await interaction.deferReply({ flags: EPHEMERAL });
    const note = await answer(pool, config, org, access, interaction.user.id, eventId, status);
    await interaction.editReply({ content: `${note}\nAlle deine Termine: /termine`, allowedMentions: { parse: [] } });
    await refreshAnnouncement(pool, interaction.client, config, org, eventId, log);
    return;
  }

  if (kind === 'rsvp') {
    await interaction.deferUpdate();
    const note = await answer(pool, config, org, access, interaction.user.id, eventId, status);
    const view = await renderDetail(pool, config, org, access, eventId, interaction.user.id, note);
    await interaction.editReply(view ?? (await goneView(pool, org)));
    await refreshAnnouncement(pool, interaction.client, config, org, eventId, log);
    return;
  }

  // Bestätigen der Teilnehmer: nur Admins und Ausbilder
  if (!access.admin && !access.canInstruct) {
    await interaction.reply({ content: 'Das dürfen nur Admins und Ausbilder.', flags: EPHEMERAL });
    return;
  }
  const e = await eventDetail(pool, org.id, eventId, access);
  if (e === null || e.course === null) {
    await interaction.update(await goneView(pool, org));
    return;
  }
  if (kind === 'conf') {
    await interaction.update(confirmView(e, await participants(pool, org.id, eventId, e.course.id)));
    return;
  }
  if (kind === 'passed') {
    await interaction.deferUpdate();
    const shownIds = interaction.component.options.map((o) => o.value);
    const confirmer = await userByDiscordId(pool, interaction.user.id);
    let note;
    try {
      await confirm(pool, org.id, eventId, confirmer?.id ?? null, interaction.values, shownIds);
      note = `Gespeichert: ${interaction.values.length} von ${shownIds.length} bestanden.`;
    } catch (err) {
      if (!(err instanceof OrgError)) throw err;
      note = err.message;
    }
    await interaction.editReply(confirmView(e, await participants(pool, org.id, eventId, e.course.id), note));
  }
}
