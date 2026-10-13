import { test } from 'node:test';
import assert from 'node:assert/strict';
import { parseBerlin, validateEvent } from '../src/lib/eventadmin.js';
import { eventModal, parseModalId } from '../src/commands/termin-neu.js';
import { deleteConfirmView, detailView } from '../src/commands/termine.js';
import { orgEmbed } from '../src/commands/orga.js';
import { orgsEmbed } from '../src/commands/betreiber.js';
import { helpText, usage } from '../src/commands/help.js';
import { commands } from '../src/commands/index.js';
import { isOperator } from '../src/lib/orgadmin.js';

const id = 'c'.repeat(24);

test('deutsche Zeit in UTC umrechnen (Sommer, Winter, Umstellung)', () => {
  assert.equal(parseBerlin('14.10.2026 20:00'), '2026-10-14 18:00:00'); // Sommerzeit
  assert.equal(parseBerlin('14.12.2026 20:00 Uhr'), '2026-12-14 19:00:00'); // Winterzeit
  assert.equal(parseBerlin('1.1.27 8.30'), '2027-01-01 07:30:00');
  assert.equal(parseBerlin('2026-10-14 20:00'), '2026-10-14 18:00:00');
  assert.equal(parseBerlin('29.03.2026 02:30'), null); // diese Stunde fällt bei der Umstellung aus
  assert.equal(parseBerlin('31.02.2026 20:00'), null);
  assert.equal(parseBerlin('morgen abend'), null);
});

test('Eingaben für einen Termin prüfen', () => {
  const ok = validateEvent({ title: ' Mining ', startsAt: '14.10.2026 20:00', endsAt: '14.10.2026 22:00', location: '', description: 'Treffpunkt' });
  assert.deepEqual(ok, { title: 'Mining', startsAt: '2026-10-14 18:00:00', endsAt: '2026-10-14 20:00:00', location: null, description: 'Treffpunkt' });
  assert.throws(() => validateEvent({ title: 'x', startsAt: '14.10.2026 20:00' }), /Titel zu kurz/);
  assert.throws(() => validateEvent({ title: 'Mining', startsAt: 'bald' }), /gültigen Beginn/);
  assert.throws(() => validateEvent({ title: 'Mining', startsAt: '14.10.2026 20:00', endsAt: '14.10.2026 19:00' }), /nach dem Beginn/);
});

test('Fenster für einen neuen Termin: Optionen in der custom_id', () => {
  const m = eventModal({ courseId: id, departmentId: null, kind: 'EXERCISE', draft: true, discord: true });
  assert.ok(m.custom_id.length <= 100);
  assert.equal(m.components.length, 5);
  assert.deepEqual(parseModalId(m.custom_id), { courseId: id, departmentId: null, kind: 'EXERCISE', draft: true, discord: true });
  assert.deepEqual(parseModalId(eventModal({ courseId: null, departmentId: null, kind: null, draft: false, discord: false }).custom_id),
    { courseId: null, departmentId: null, kind: 'TRAINING', draft: false, discord: false });
  assert.equal(parseModalId('evtnew:kaputt:-:T:-'), null);
});

test('Planer sehen Absagen und Löschen, mit Rückfrage', () => {
  const org = { slug: 's', name: 'S' };
  const e = { id, title: 'Mining', description: null, location: null, startsAt: '2026-10-14 18:00:00', endsAt: null, status: 'PLANNED', course: null, department: null, rsvps: [] };
  assert.equal(detailView(org, e, { appUrl: 'x' }).components.length, 2);
  const planner = detailView(org, e, { appUrl: 'x', canPlan: true });
  assert.deepEqual(planner.components[2].components.map((b) => [b.label, b.custom_id]), [['Absagen', `evt:cancel:${id}`], ['Löschen', `evt:del:${id}`]]);
  assert.equal(detailView(org, { ...e, status: 'CANCELLED' }, { appUrl: 'x', canPlan: true }).components[2].components[0].label, 'Wieder aktivieren');
  assert.match(detailView(org, { ...e, status: 'DRAFT' }, { appUrl: 'x', canPlan: true }).content, /^Entwurf/);
  assert.deepEqual(deleteConfirmView(e).components[0].components.map((b) => b.custom_id), [`evt:delok:${id}`, `evt:open:${id}`]);
});

test('/orga: Übersicht der Einstellungen', () => {
  const org = { name: 'SpaceTrotter', slug: 'spacetrotter', member_role_ids: '111111111111111111', role_labels: '{"111111111111111111":"Mitglied"}', rsi_sid: 'EXPG', rsi_org_name: 'Explorer', handle_style: 'round', discord_events_mode: 'DRAFT', allowlist_synced_at: null };
  const e = orgEmbed(org, { counts: { allowed: 12, members: 8, rsiMembers: 30, events: 3 }, announceChannel: '42', gate: true, appUrl: 'https://cm.example', note: 'Gespeichert: x.' });
  const f = Object.fromEntries(e.fields.map((x) => [x.name, x.value]));
  assert.match(f['Rollen (ändern mit /einrichten)'], /^Nutzung: Mitglied\nPlaner: –/);
  assert.equal(f['RSI-Orga'], 'Explorer (EXPG), 30 sichtbare Mitglieder');
  assert.equal(f['Handle im Nickname'], 'In runden Klammern');
  assert.equal(f['Discord-Events übernehmen'], 'als Entwurf übernehmen');
  assert.equal(f.Terminankündigungen, '<#42>');
  assert.equal(f.Zugang, '12 auf der Zugangsliste, 8 mit Konto, Anmeldung nur mit Eintrag');
  assert.equal(e.url, 'https://cm.example/o/spacetrotter/settings');
});

test('/betreiber: Übersicht, Unterbefehle in der Hilfe nur für den Betreiber', () => {
  const e = orgsEmbed([{ name: 'A', slug: 'a', discord_guild_id: '5', member_count: 3, event_count: 1 }], [{ name: 'Böse', discord_guild_id: '9', banned_at: '2026-10-01 10:00:00', reason: 'Spam' }], new Map([['5', 'Server A']]));
  assert.equal(e.description, '**A** (a) · Server Server A · 3 Mitglieder · 1 Termine');
  assert.match(e.fields[0].value, /^Böse \(9\) seit <t:\d+:d> – Spam$/);
  const op = commands.find((c) => c.data.name === 'betreiber');
  assert.equal(usage(op.data), '/betreiber orgas|sperren|entsperren|loeschen');
  assert.doesNotMatch(helpText(commands, { admin: true, appUrl: 'x' }), /betreiber/);
  assert.match(helpText(commands, { admin: true, operator: true, appUrl: 'x' }), /\*\*Für den Betreiber der Installation\*\*\n`\/betreiber`/);
  assert.match(helpText(commands, { admin: false, appUrl: 'x' }), /\*\*Für Planer und Admins\*\*\n`\/termin-neu`/);
  assert.equal(isOperator({ serverAdminIds: ['1', '2'] }, '2'), true);
  assert.equal(isOperator({ serverAdminIds: [] }, '2'), false);
});
