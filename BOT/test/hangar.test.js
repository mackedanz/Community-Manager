import { test } from 'node:test';
import assert from 'node:assert/strict';
import { canView, countByKind, groupShips, handleFromName } from '../src/lib/hangar.js';
import { hangarEmbed, shipLines } from '../src/commands/hangar.js';
import { fleetEmbed } from '../src/commands/flotte.js';
import { ownersEmbed } from '../src/commands/wer-hat.js';
import { profileEmbed } from '../src/commands/profil.js';

test('RSI-Handle aus dem Namen wie RsiOrg::handleFromName (Fälle aus RsiOrgTest.php)', () => {
  const cases = [
    ['(Vorname) mimack76 [Discordname]', 'plain', 'mimack76'],
    ['(Vorname) [Tag] mimack76', 'plain', 'mimack76'],
    ['<x> mimack76 <Micha>', 'plain', 'mimack76'],
    ['Micha (mimack76)', 'plain', 'Micha'],
    ['Micha (mimack76)', 'round', 'mimack76'],
    ['Micha der Große [mimack76]', 'square', 'mimack76'],
    ['Micha {mimack76}', 'curly', 'mimack76'],
    ['Micha <mimack76>', 'angle', 'mimack76'],
    ['Micha (Vorname) (mimack76)', 'round', 'Vorname'],
    ['Micha [mimack76]', 'round', null],
    ['mimack76', 'round', null],
    ['Micha (der Große)', 'round', null],
    ['  mimack76 ', 'unbekannt', 'mimack76'],
    ['eXpG_McDance (Micha)', 'plain', 'eXpG_McDance'],
    ['Pilot-2 [Anna]', 'plain', 'Pilot-2'],
    ['[SpaceTrotter] Pilot-2 (Anna)', 'plain', 'Pilot-2'],
    ['Micha der Große', 'plain', null],
    ['(nur Klammer)', 'plain', null],
    [null, 'plain', null],
  ];
  for (const [name, style, want] of cases) assert.equal(handleFromName(name, style), want, `${name} / ${style}`);
});

const items = [
  { kind: 'SHIP', name: 'Cutlass Black', manufacturer: 'Drake', quantity: 1, lti: true },
  { kind: 'SHIP', name: 'Cutlass Black', manufacturer: 'Drake', quantity: 1, lti: false },
  { kind: 'SHIP', name: 'Arrow', manufacturer: 'Anvil', quantity: 1, lti: true },
  { kind: 'ARMOR', name: 'Helm', manufacturer: null, quantity: 3, lti: false },
];

test('Schiffe zusammenfassen und Arten zählen', () => {
  assert.deepEqual(groupShips(items).map((s) => [s.name, s.count, s.lti]), [['Arrow', 1, 1], ['Cutlass Black', 2, 1]]);
  assert.deepEqual(countByKind(items), [{ kind: 'SHIP', label: 'Schiffe', count: 3 }, { kind: 'ARMOR', label: 'Rüstungen', count: 3 }]);
  assert.deepEqual(shipLines(groupShips(items)), ['**Arrow** (LTI)', '2× **Cutlass Black** (1× LTI)']);
});

test('Sichtbarkeit: eigenes immer, fremdes nur freigegeben und in der Orga', () => {
  assert.equal(canView({ in_org: 0, hangar_visibility: 'PRIVATE' }, 'hangar_visibility', true), true);
  assert.equal(canView({ in_org: 1, hangar_visibility: 'MEMBERS' }, 'hangar_visibility', false), true);
  assert.equal(canView({ in_org: 1, hangar_visibility: 'PRIVATE' }, 'hangar_visibility', false), false);
  assert.equal(canView({ in_org: 0, hangar_visibility: 'MEMBERS' }, 'hangar_visibility', false), false);
});

test('Hangar-Kasten: eigener mit übrigen Arten, fremder nur Schiffe', () => {
  const own = hangarEmbed('Dein Hangar', items, { self: true, url: 'https://cm.example/hangar' });
  assert.equal(own.title, 'Dein Hangar – 3 Schiffe');
  assert.deepEqual(own.fields, [{ name: 'Außerdem', value: 'Rüstungen: 3' }]);
  assert.deepEqual(hangarEmbed('Hangar von Anna', items.slice(0, 3), { self: false, url: 'x' }).fields, []);
  assert.equal(hangarEmbed('Leer', [], { self: true, url: 'x' }).description, 'Keine Schiffe.');
});

test('Flotte: anonym, mit Suche', () => {
  const fleet = { entries: [{ name: 'Cutlass Black', manufacturer: 'Drake', count: 5 }, { name: 'Arrow', manufacturer: 'Anvil', count: 2 }], totalShips: 7, owners: 3 };
  const all = fleetEmbed({ name: 'SpaceTrotter' }, fleet, { url: 'x' });
  assert.match(all.description, /^7 Schiffe bei 3 Mitgliedern, 2 verschiedene\n\n5× \*\*Cutlass Black\*\* · Drake\n2× \*\*Arrow\*\* · Anvil$/);
  assert.match(fleetEmbed({ name: 'S' }, fleet, { search: 'drake', url: 'x' }).description, /^5 Schiffe passend zu „drake“\n\n5× \*\*Cutlass Black\*\*/);
  assert.match(fleetEmbed({ name: 'S' }, fleet, { search: 'xyz', url: 'x' }).description, /Nichts gefunden/);
});

test('Wer hat: Besitzer mit Anzahl und LTI', () => {
  const e = ownersEmbed('Arrow', [{ name: 'Anna', discordId: '42', quantity: 2, lti: true }, { name: 'Bert', discordId: null, quantity: 1, lti: false }], 'x');
  assert.match(e.description, /^3 Schiffe bei 2 Mitgliedern\n\n<@42> – 2× \(LTI\)\nBert$/);
  assert.match(ownersEmbed('Arrow', [], 'x').description, /Niemand/);
});

test('Profil: freigegebene und gesperrte Teile', () => {
  const open = profileEmbed({
    title: 'Profil von Anna', url: 'x', avatar: null, rsiHandle: 'anna_sc', lastSync: '2026-10-01 10:00:00',
    ships: groupShips(items), achievements: [{ title: 'Erstes Schiff' }], qualifications: [{ topic: 'Laser', level: 1 }],
  });
  const f = Object.fromEntries(open.fields.map((x) => [x.name, x.value]));
  assert.equal(f['RSI-Handle'], '[anna_sc](https://robertsspaceindustries.com/en/citizens/anna_sc)');
  assert.equal(f.Schiffe, '3');
  assert.equal(f['Errungenschaften (1)'], 'Erstes Schiff');
  assert.equal(f['Ausbildungen (1)'], 'Laser 1');
  const closed = profileEmbed({ title: 't', url: 'x', avatar: null, rsiHandle: null, lastSync: null, ships: null, achievements: null, qualifications: [] });
  const g = Object.fromEntries(closed.fields.map((x) => [x.name, x.value]));
  assert.deepEqual(g, { 'RSI-Handle': 'unbekannt', Schiffe: 'nicht freigegeben', Errungenschaften: 'nicht freigegeben' });
});
