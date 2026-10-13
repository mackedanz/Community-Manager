import { test } from 'node:test';
import assert from 'node:assert/strict';
import { berlinText, detailView, handles, listView, unix } from '../src/commands/termine.js';
import { rulesMessage, setupProblem, handles as rulesHandles } from '../src/commands/serverregeln.js';
import { accessIn } from '../src/lib/access.js';
import { missingText } from '../src/lib/events.js';

const R_USE = '111111111111111111';
const R_PLAN = '222222222222222222';
const R_INSTR = '333333333333333333';
const org = { name: 'SpaceTrotter', slug: 'spacetrotter', member_role_ids: R_USE, planner_role_ids: R_PLAN, instructor_role_ids: R_INSTR };
const id = 'a'.repeat(24);

test('Zeiten aus der Datenbank', () => {
  assert.equal(unix('2026-10-14 18:00:00'), Date.parse('2026-10-14T18:00:00Z') / 1000);
  // 18:00 UTC = 20:00 deutsche Sommerzeit
  assert.match(berlinText('2026-10-14 18:00:00'), /14\.10\.2026, 20:00 Uhr/);
});

test('Rechte aus den Discord-Rollen', () => {
  assert.deepEqual(accessIn(org, { admin: true, roles: [] }), { member: true, admin: true, canPlan: true, canInstruct: true });
  assert.deepEqual(accessIn(org, { admin: false, roles: [R_USE, R_INSTR] }), { member: true, admin: false, canPlan: false, canInstruct: true });
  // Wie in der Web-App: ohne Nutzungs-Rolle kein Mitglied, auch mit Planer-Rolle
  assert.equal(accessIn(org, { admin: false, roles: [R_PLAN] }).member, false);
  assert.equal(accessIn({ ...org, member_role_ids: null }, { admin: false, roles: [] }).member, true);
});

test('Terminliste mit Auswahl', () => {
  const v = listView(org, [{ id, title: 'Mining-Abend', startsAt: '2026-10-14 18:00:00', endsAt: null, training: 'Laser · Stufe 2', department: null, yes: 3 }]);
  assert.match(v.content, /SpaceTrotter/);
  assert.match(v.content, /<t:\d+:f> \*\*Mining-Abend\*\* · Ausbildung Laser · Stufe 2 – 3 dabei/);
  assert.equal(v.components[0].components[0].custom_id, 'evt:show');
  assert.equal(v.components[0].components[0].options[0].value, id);
  assert.match(listView(org, []).content, /nichts geplant/);
});

test('Termin mit Antworten und Knöpfen', () => {
  const e = {
    id, title: 'Mining-Abend', description: 'Treffpunkt Port Olisar', location: 'Yela', startsAt: '2026-10-14 18:00:00', endsAt: '2026-10-14 20:00:00',
    status: 'PLANNED', course: { areaName: 'Mining und Salvage', topic: 'Laser', level: 2, title: 'Fortgeschritten' }, department: null,
    rsvps: [{ userId: 'u1', status: 'YES', name: 'Anna' }, { userId: 'u2', status: 'MAYBE', name: 'Bert' }],
  };
  const v = detailView(org, e, { appUrl: 'https://cm.example', myStatus: 'YES', note: 'Gespeichert: Dabei.' });
  const embed = v.embeds[0];
  assert.equal(embed.url, `https://cm.example/o/spacetrotter/events/${id}`);
  assert.deepEqual(embed.fields.map((f) => f.name), ['Wann', 'Wo', 'Ausbildung', 'Dabei (1)', 'Vielleicht (1)', 'Kann nicht (0)', 'Deine Antwort']);
  assert.equal(embed.fields.at(-1).value, 'Dabei');
  assert.equal(v.content, 'Gespeichert: Dabei.');
  assert.deepEqual(v.components[0].components.map((b) => b.custom_id), ['YES', 'MAYBE', 'NO', 'NONE'].map((s) => `evt:rsvp:${id}:${s}`));
  assert.ok(v.components[0].components.every((b) => !b.disabled));

  const cancelled = detailView(org, { ...e, status: 'CANCELLED' }, { appUrl: 'https://cm.example' });
  assert.match(cancelled.embeds[0].title, /^Abgesagt: /);
  assert.deepEqual(cancelled.components[0].components.map((b) => b.disabled), [true, true, false, false]);
  assert.ok(handles('evt:show') && handles('evt:list') && handles(`evt:rsvp:${id}:YES`) && !handles('onb:use'));
});

test('Hinweis auf fehlende Stufen', () => {
  assert.equal(missingText([{ level: 1, title: 'Grundlagen' }]), 'Für diese Ausbildung fehlt dir noch: Stufe 1 („Grundlagen“). Erst die früheren Stufen bestehen, dann kannst du dich anmelden.');
});

test('Serverregeln: Nachricht und Prüfung der Voraussetzungen', () => {
  const m = rulesMessage('', 'Sei nett.', '444444444444444444');
  assert.equal(m.embeds[0].title, 'Serverregeln');
  assert.equal(m.embeds[0].description, 'Sei nett.');
  assert.deepEqual(m.components[0].components[0], { type: 2, style: 3, label: 'Bestätigen', custom_id: 'rules:ok:444444444444444444' });

  const ok = { everyone: false, managed: false, botCanManageRoles: true, botAboveRole: true, botCanPost: true };
  assert.equal(setupProblem(ok), null);
  assert.match(setupProblem({ ...ok, everyone: true }), /@everyone/);
  assert.match(setupProblem({ ...ok, managed: true }), /Integration/);
  assert.match(setupProblem({ ...ok, botCanManageRoles: false }), /Rollen verwalten/);
  assert.match(setupProblem({ ...ok, botAboveRole: false }), /darüber ziehen/);
  assert.match(setupProblem({ ...ok, botCanPost: false }), /nicht schreiben/);
  assert.ok(rulesHandles('rules:ok:1') && rulesHandles('rules:modal:1:2') && !rulesHandles('evt:list'));
});

test('Platzwahl: freie Plätze, eigener Platz mit Freigeben, nichts bei Absage', async () => {
  const { seatMenu } = await import('../src/commands/termine.js');
  const ev = { id: 'a'.repeat(24), status: 'PLANNED', ships: [{ name: 'Phoenix', task: null, slots: [
    { id: 's1', label: 'Pilot', userId: 'me', holder: 'Ich' }, { id: 's2', label: 'Copilot', userId: 'u2', holder: 'Bert' }, { id: 's3', label: 'Crew 3', userId: null, holder: null },
  ] }] };
  const menu = seatMenu(ev, 'me').components[0];
  assert.equal(menu.custom_id, `evt:slot:${ev.id}`);
  assert.deepEqual(menu.options.map((o) => o.value), ['s1', 's3', 'NONE']);
  assert.deepEqual(seatMenu(ev, 'neu').components[0].options.map((o) => o.value), ['s3']);
  assert.equal(seatMenu({ ...ev, status: 'CANCELLED' }, 'me'), null);
  assert.equal(seatMenu({ ...ev, ships: [] }, 'me'), null);
  const many = { ...ev, ships: [{ name: 'X', slots: Array.from({ length: 40 }, (_, i) => ({ id: `p${i}`, label: `P${i}`, userId: null, holder: null })) }] };
  assert.equal(seatMenu(many, 'me').components[0].options.length, 25);
});
