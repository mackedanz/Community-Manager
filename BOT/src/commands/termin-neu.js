// /termin-neu: Planer und Admins legen einen Termin an. Ausbildung, Abteilung, Entwurf und "auch in Discord" werden
// als Optionen gewählt, Titel, Zeiten, Ort und Beschreibung danach in einem Fenster eingetragen.
import { MessageFlags } from 'discord.js';
import { memberContext, userByDiscordId } from '../lib/access.js';
import { eventDetail } from '../lib/events.js';
import { createEvent, searchCourses, searchDepartments } from '../lib/eventadmin.js';
import { OrgError } from '../lib/errors.js';
import { cut } from '../lib/text.js';
import { detailView } from './termine.js';

/** Für /hilfe: nutzbar für Planer und Admins */
export const audience = 'planners';

export const data = {
  name: 'termin-neu',
  description: 'Neuen Termin anlegen (Planer und Admins); Schiffe und Plätze danach auf der Webseite',
  type: 1,
  options: [
    { type: 3, name: 'ausbildung', description: 'Termin für diese Ausbildungsstufe (Vorschläge beim Tippen)', required: false, autocomplete: true },
    { type: 3, name: 'abteilung', description: 'Training oder Übung dieser Abteilung (Vorschläge beim Tippen)', required: false, autocomplete: true },
    { type: 3, name: 'art', description: 'Bei einer Abteilung: Training oder Übung (Standard: Training)', required: false, choices: [
      { name: 'Training', value: 'TRAINING' }, { name: 'Übung', value: 'EXERCISE' },
    ] },
    { type: 5, name: 'entwurf', description: 'Erst als Entwurf anlegen (nur Planer sehen ihn)', required: false },
    { type: 5, name: 'discord_event', description: 'Auch als Discord-Event anlegen', required: false },
  ],
  contexts: [0],
};

const HEX_ID = /^[a-f0-9]{24}$/;

export async function autocomplete(interaction, { pool }) {
  const { org, access } = await memberContext(interaction, pool);
  if (!org || !(access.admin || access.canPlan)) {
    await interaction.respond([]);
    return;
  }
  const focused = interaction.options.getFocused(true);
  const term = String(focused.value ?? '').trim();
  if (focused.name === 'ausbildung') {
    const rows = await searchCourses(pool, org.id, term);
    await interaction.respond(rows.map((c) => ({ name: cut(`${c.area_name} · ${c.topic} · Stufe ${c.level} („${c.title}“)`, 100), value: c.id })));
  } else {
    const rows = await searchDepartments(pool, org.id, term);
    await interaction.respond(rows.map((d) => ({ name: cut(d.name, 100), value: d.id })));
  }
}

/** Eingabefenster; die gewählten Optionen stecken in der custom_id (höchstens 100 Zeichen). */
export function eventModal({ courseId, departmentId, kind, draft, discord }) {
  const flags = `${draft ? 'd' : ''}${discord ? 'p' : ''}` || '-';
  return {
    title: 'Neuer Termin',
    custom_id: `evtnew:${courseId ?? '-'}:${departmentId ?? '-'}:${kind === 'EXERCISE' ? 'E' : 'T'}:${flags}`,
    components: [
      { type: 1, components: [{ type: 4, custom_id: 'title', label: 'Titel', style: 1, required: true, min_length: 2, max_length: 100 }] },
      { type: 1, components: [{ type: 4, custom_id: 'start', label: 'Beginn (deutsche Zeit)', style: 1, required: true, max_length: 20, placeholder: '14.10.2026 20:00' }] },
      { type: 1, components: [{ type: 4, custom_id: 'end', label: 'Ende (freiwillig)', style: 1, required: false, max_length: 20, placeholder: '14.10.2026 22:00' }] },
      { type: 1, components: [{ type: 4, custom_id: 'location', label: 'Ort (freiwillig)', style: 1, required: false, max_length: 100 }] },
      { type: 1, components: [{ type: 4, custom_id: 'description', label: 'Beschreibung (freiwillig)', style: 2, required: false, max_length: 3000 }] },
    ],
  };
}

/** Liest die Optionen aus der custom_id des Fensters. null, wenn sie nicht passt. */
export function parseModalId(customId) {
  const [prefix, course, dept, kind, flags] = customId.split(':');
  if (prefix !== 'evtnew') return null;
  const id = (v) => (v === '-' ? null : HEX_ID.test(v ?? '') ? v : undefined);
  const courseId = id(course);
  const departmentId = id(dept);
  if (courseId === undefined || departmentId === undefined) return null;
  return { courseId, departmentId, kind: kind === 'E' ? 'EXERCISE' : 'TRAINING', draft: flags.includes('d'), discord: flags.includes('p') };
}

async function plannerContext(interaction, pool) {
  const ctx = await memberContext(interaction, pool);
  if (ctx.refusal) return ctx;
  if (!ctx.access.admin && !ctx.access.canPlan) return { refusal: 'Termine anlegen dürfen nur Planer und Admins.' };
  const user = await userByDiscordId(pool, interaction.user.id);
  if (user === null) return { refusal: 'Zum Anlegen von Terminen brauchst du ein Konto im Community-Manager. Melde dich einmal auf der Webseite an.' };
  return { ...ctx, user };
}

export async function execute(interaction, { pool }) {
  const { refusal } = await plannerContext(interaction, pool);
  if (refusal) {
    await interaction.reply({ content: refusal, flags: MessageFlags.Ephemeral });
    return;
  }
  const o = interaction.options;
  const pick = (name) => {
    const v = o.getString(name);
    return v === null ? null : v;
  };
  const courseId = pick('ausbildung');
  const departmentId = pick('abteilung');
  for (const v of [courseId, departmentId]) {
    if (v !== null && !HEX_ID.test(v)) {
      await interaction.reply({ content: 'Bitte die Ausbildung bzw. Abteilung aus den Vorschlägen auswählen.', flags: MessageFlags.Ephemeral });
      return;
    }
  }
  await interaction.showModal(eventModal({
    courseId, departmentId, kind: o.getString('art'), draft: o.getBoolean('entwurf') === true, discord: o.getBoolean('discord_event') === true,
  }));
}

export function handles(customId) {
  return customId.startsWith('evtnew:');
}

/** Fenster abgeschickt: Termin anlegen und wie in /termine anzeigen. */
export async function handleComponent(interaction, { pool, config }) {
  const opts = parseModalId(interaction.customId);
  if (opts === null) return;
  const { org, access, user, refusal } = await plannerContext(interaction, pool);
  if (refusal) {
    await interaction.reply({ content: refusal, flags: MessageFlags.Ephemeral });
    return;
  }
  const f = (id) => interaction.fields.getTextInputValue(id);
  await interaction.deferReply({ flags: MessageFlags.Ephemeral });
  let eventId;
  try {
    eventId = await createEvent(pool, org.id, user.id, {
      title: f('title'), startsAt: f('start'), endsAt: f('end'), location: f('location'), description: f('description'),
    }, { courseId: opts.courseId, departmentId: opts.departmentId, departmentKind: opts.kind, draft: opts.draft, discord: opts.discord });
  } catch (err) {
    if (!(err instanceof OrgError)) throw err;
    await interaction.editReply(`Nicht angelegt: ${err.message}\nDeine Eingaben musst du leider neu eintragen.`);
    return;
  }
  const e = await eventDetail(pool, org.id, eventId, access);
  const note = [
    opts.draft ? 'Als Entwurf angelegt: Nur Planer sehen ihn, bis er auf der Webseite veröffentlicht wird.' : 'Termin angelegt.',
    opts.discord ? 'Das Discord-Event legt die Webseite in den nächsten Minuten an.' : null,
    'Schiffe und Plätze kannst du auf der Webseite ergänzen.',
  ].filter(Boolean).join(' ');
  await interaction.editReply(detailView(org, e, { appUrl: config.appUrl, note, canConfirm: access.admin || access.canInstruct, canPlan: true }));
}
