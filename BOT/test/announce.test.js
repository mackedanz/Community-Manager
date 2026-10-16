import { test } from 'node:test';
import assert from 'node:assert/strict';
import { announceView, dueReminder, fingerprint, isPast, reminderText } from '../src/lib/announce.js';
import { confirmView } from '../src/commands/termine.js';
import { qualificationsText } from '../src/commands/ausbildung.js';

const org = { name: 'SpaceTrotter', slug: 'spacetrotter' };
const id = 'b'.repeat(24);
const e = {
  id, title: 'Mining-Abend', description: null, location: null, startsAt: '2026-10-14 18:00:00', endsAt: null, status: 'PLANNED',
  course: null, department: null, rsvps: [{ userId: 'u1', status: 'YES', name: 'Anna' }],
};
const at = (iso) => Date.parse(iso);

test('Ankündigung: Schiffe mit Plätzen und Hinweis auf den Community-Manager', () => {
  const withShips = { ...e, ships: [{ name: 'Constellation Phoenix', task: 'Lazarett', slots: [{ id: 's1', label: 'Pilot', userId: 'u1', holder: 'Anna' }, { id: 's2', label: 'Copilot', userId: null, holder: null }] }] };
  const f = announceView(org, { ...withShips, voiceChannelId: '123456789012345678' }, 'https://cm.example', at('2026-10-10T12:00:00Z')).embeds[0].fields;
  assert.equal(f.find((x) => x.name === 'Treffpunkt im Voice').value, '<#123456789012345678>');
  assert.ok(!announceView(org, e, 'https://cm.example', at('2026-10-10T12:00:00Z')).embeds[0].fields.some((x) => x.name === 'Treffpunkt im Voice'));
  const ship = f.find((x) => x.name === '🚀 Constellation Phoenix · 1/2 · Lazarett');
  assert.match(ship.value, /✅ Pilot: Anna\n⬜ Copilot: offen/);
  assert.match(ship.value, /Platz wählen“ oder im Community-Manager/);
  assert.ok(f.every((x) => x.value.length <= 1024) && f.length <= 25);
  // Knopf zur Platzwahl nur mit Schiffen, nicht bei abgesagten Terminen
  const seat = (view) => view.components.flatMap((r) => r.components).find((b) => b.custom_id === `evt:seat:${id}`);
  assert.ok(seat(announceView(org, withShips, 'https://cm.example', at('2026-10-10T12:00:00Z'))));
  assert.ok(!seat(announceView(org, e, 'https://cm.example', at('2026-10-10T12:00:00Z'))));
  assert.ok(!seat(announceView(org, { ...withShips, status: 'CANCELLED' }, 'https://cm.example', at('2026-10-10T12:00:00Z'))));
  // ohne Schiffe kein Feld
  assert.ok(!announceView(org, e, 'https://cm.example', at('2026-10-10T12:00:00Z')).embeds[0].fields.some((x) => x.name.startsWith('🚀')));
  assert.notEqual(fingerprint(announceView(org, e, 'https://cm.example')), fingerprint(announceView(org, withShips, 'https://cm.example')));
});

test('vorbei: Ende, sonst sechs Stunden nach Beginn', () => {
  assert.equal(isPast(e, at('2026-10-14T23:59:00Z')), false);
  assert.equal(isPast(e, at('2026-10-15T00:01:00Z')), true);
  assert.equal(isPast({ ...e, endsAt: '2026-10-14 19:00:00' }, at('2026-10-14T19:01:00Z')), true);
});

test('Ankündigung: öffentliche Knöpfe, ohne eigene Antwort, Stand ändert den Fingerabdruck', () => {
  const v = announceView(org, e, 'https://cm.example', at('2026-10-10T12:00:00Z'));
  assert.deepEqual(v.components[0].components.slice(0, 3).map((b) => b.custom_id), ['YES', 'MAYBE', 'NO'].map((s) => `evt:pub:${id}:${s}`));
  assert.equal(v.components[0].components[3].url, `https://cm.example/o/spacetrotter/events/${id}`);
  assert.ok(!v.embeds[0].fields.some((f) => f.name === 'Deine Antwort'));
  assert.equal(fingerprint(v), fingerprint(announceView(org, e, 'https://cm.example', at('2026-10-11T12:00:00Z'))));
  assert.notEqual(fingerprint(v), fingerprint(announceView(org, { ...e, rsvps: [] }, 'https://cm.example', at('2026-10-10T12:00:00Z'))));

  const past = announceView(org, e, 'https://cm.example', at('2026-10-16T00:00:00Z'));
  assert.match(past.embeds[0].title, /^Vorbei: /);
  assert.ok(past.components[0].components.slice(0, 3).every((b) => b.disabled));
  const cancelled = announceView(org, { ...e, status: 'CANCELLED' }, 'https://cm.example', at('2026-10-10T12:00:00Z'));
  assert.deepEqual(cancelled.components[0].components.slice(0, 3).map((b) => b.disabled), [true, true, false]);
});

test('Erinnerung: 24 Stunden und 1 Stunde vorher', () => {
  assert.equal(dueReminder('2026-10-14 18:00:00', at('2026-10-13T17:00:00Z')), null); // 25 h vorher
  assert.equal(dueReminder('2026-10-14 18:00:00', at('2026-10-13T19:00:00Z')), '24h');
  assert.equal(dueReminder('2026-10-14 18:00:00', at('2026-10-14T17:30:00Z')), '1h');
  assert.equal(dueReminder('2026-10-14 18:00:00', at('2026-10-14T18:00:01Z')), null);
  assert.match(reminderText(org, e, 'MAYBE', 'https://cm.example'), /Mining-Abend\*\* \(SpaceTrotter\).*\nDeine Antwort: Vielleicht/);
});

test('Teilnahme bestätigen: Auswahl mit Vorbelegung, höchstens 25', () => {
  const course = { topic: 'Laser', level: 2, title: 'Fortgeschritten' };
  const people = Array.from({ length: 30 }, (_, i) => ({ userId: `u${i}`, name: `P${i}`, passed: i === 0 }));
  const v = confirmView({ ...e, course }, people);
  const select = v.components[0].components[0];
  assert.equal(select.custom_id, `evt:passed:${id}`);
  assert.equal(select.options.length, 25);
  assert.equal(select.max_values, 25);
  assert.equal(select.options[0].default, true);
  assert.match(v.content, /30 Teilnehmer/);
  assert.match(confirmView({ ...e, course }, []).content, /Noch niemand/);
});

test('Ausbildungen als Text nach Bereich und Thema', () => {
  const text = qualificationsText('Deine Ausbildungen', [
    { areaName: 'Mining und Salvage', topic: 'Laser', level: 1, title: 'Grundlagen', completedAt: '2026-10-01 10:00:00', retired: false },
    { areaName: 'Mining und Salvage', topic: 'Laser', level: 2, title: 'Fortgeschritten', completedAt: '2026-10-08 10:00:00', retired: true },
  ]);
  assert.match(text, /__Mining und Salvage__\n\*\*Laser:\*\* Stufe 1 „Grundlagen“ \(01\.10\.2026\), Stufe 2 „Fortgeschritten“ \(08\.10\.2026\) – Ausbildung inzwischen gelöscht/);
  assert.match(qualificationsText('Deine Ausbildungen', []), /Noch keine/);
});
