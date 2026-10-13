import { test } from 'node:test';
import assert from 'node:assert/strict';
import { canModerate, caseEmbed, casesEmbed, dmText, durationText, parseDuration, targetProblem } from '../src/lib/moderation.js';
import { joinEmbed, leaveEmbed } from '../src/events/modlog.js';
import { settingsText } from '../src/commands/mod-einstellungen.js';
import { data } from '../src/commands/mod.js';
import { commands } from '../src/commands/index.js';
import { helpText } from '../src/commands/help.js';

test('Dauer: Einheiten, Standard Minuten, Grenzen', () => {
  assert.equal(parseDuration('30m'), 30 * 60_000);
  assert.equal(parseDuration('30'), 30 * 60_000);
  assert.equal(parseDuration('2 h'), 2 * 3600_000);
  assert.equal(parseDuration('1d'), 86_400_000);
  assert.equal(parseDuration('3 T'), 3 * 86_400_000);
  assert.equal(parseDuration('4w'), 28 * 86_400_000);
  assert.throws(() => parseDuration('5w'), /höchstens 28 Tage/);
  assert.throws(() => parseDuration('0m'), /Dauer/);
  assert.throws(() => parseDuration('abc'), /Dauer/);
  assert.throws(() => parseDuration(''), /Dauer/);
  assert.equal(durationText(5_400_000), '1 Stunde 30 Minuten');
  assert.equal(durationText(2 * 86_400_000), '2 Tage');
});

test('Wer darf moderieren: Admins und Moderatoren-Rollen', () => {
  assert.equal(canModerate({ admin: true, roles: [] }, []), true);
  assert.equal(canModerate({ admin: false, roles: ['1'] }, ['1', '2']), true);
  assert.equal(canModerate({ admin: false, roles: ['3'] }, ['1', '2']), false);
  assert.equal(canModerate({ admin: false, roles: ['3'] }, []), false);
});

test('Wen darf man moderieren: Selbst, Eigentümer, Admins, Moderatoren, Rollenhöhe', () => {
  const base = { actorId: 'a', targetId: 't', targetIsSelf: false, targetIsOwner: false, actorAdmin: false, targetIsAdmin: false, targetIsMod: false, actorTop: 5, targetTop: 2 };
  assert.equal(targetProblem(base), null);
  assert.match(targetProblem({ ...base, targetId: 'a' }), /selbst/);
  assert.match(targetProblem({ ...base, targetIsSelf: true }), /Bot selbst/);
  assert.match(targetProblem({ ...base, targetIsOwner: true, actorAdmin: true }), /Eigentümer/);
  assert.match(targetProblem({ ...base, targetIsAdmin: true }), /nur von Admins/);
  assert.match(targetProblem({ ...base, targetIsMod: true }), /Moderatoren lassen sich nur von Admins/);
  assert.match(targetProblem({ ...base, targetTop: 5 }), /gleich hohe oder höhere/);
  assert.match(targetProblem({ ...base, targetTop: 9 }), /gleich hohe oder höhere/);
  // Admins dürfen auch höhere Rollen und Moderatoren, aber keine Eigentümer
  assert.equal(targetProblem({ ...base, actorAdmin: true, targetTop: 9, targetIsMod: true, targetIsAdmin: true }), null);
});

test('Fall-Kasten, Übersicht und Direktnachricht', () => {
  const c = { id: 7, target_id: '123', target_name: 'Anna', moderator_id: '456', action: 'TIMEOUT', reason: 'Spam', detail: '1 Stunde', created_at: '2026-10-13 12:00:00' };
  const e = caseEmbed(c);
  assert.equal(e.title, 'Fall #7 · Timeout');
  assert.deepEqual(e.fields.map((f) => f.name), ['Person', 'Von', 'Details', 'Grund']);
  assert.match(e.fields[0].value, /<@123> \(Anna\)/);
  assert.equal(e.timestamp, '2026-10-13T12:00:00.000Z');
  const list = casesEmbed('Anna', [c, { ...c, id: 6, action: 'WARN', detail: null, removed_at: '2026-10-13 13:00:00' }], 0);
  assert.match(list.description, /\*\*#7\*\*.*Timeout \(1 Stunde\) – Spam/);
  assert.match(list.description, /~~\*\*#6\*\*.*Verwarnung.*~~ \(zurückgenommen\)/);
  assert.equal(list.footer.text, '0 aktive Verwarnungen');
  assert.equal(casesEmbed('A', [], 1).footer.text, '1 aktive Verwarnung');
  assert.match(casesEmbed('A', [], 0).description, /Keine Einträge/);
  assert.equal(dmText('Orga', 'WARN', 'Ton', null), 'Du wurdest auf **Orga** verwarnt.\nGrund: Ton');
  assert.match(dmText('Orga', 'TIMEOUT', null, '1 Tag'), /stummgeschaltet \(1 Tag\)\.$/);
});

test('Protokoll: Beitritt und Austritt, Einstellungstext', () => {
  const user = { globalName: 'Anna', username: 'anna', createdAt: new Date('2025-01-01T00:00:00Z'), bot: false };
  assert.match(joinEmbed({ id: '1', user }).description, /<@1> \(Anna\)/);
  const left = leaveEmbed({ id: '1', user, guild: { id: 'g' }, roles: { cache: new Map([['g', {}], ['r1', {}]]) } });
  assert.match(left.fields[0].value, /<@&r1>/);
  assert.doesNotMatch(left.fields[0].value, /<@&g>/);
  assert.deepEqual(leaveEmbed({ id: '1', user, guild: { id: 'g' }, roles: { cache: new Map() } }).fields, []);
  assert.match(settingsText([], null), /keine \(nur Server-Admins moderieren\)[\s\S]*aus/);
  assert.match(settingsText(['5'], '9'), /<@&5>[\s\S]*<#9>/);
});

test('/mod: Unterbefehle, Pflichtangaben vor freiwilligen, Längen', () => {
  assert.deepEqual(data.options.map((o) => o.name), ['verwarnen', 'timeout', 'timeout-aufheben', 'kick', 'bann', 'entbannen', 'warnungen', 'warnung-entfernen', 'loeschen']);
  for (const sub of data.options) {
    assert.ok(sub.description.length <= 100, sub.name);
    const flags = (sub.options ?? []).map((o) => o.required === true);
    assert.deepEqual(flags, [...flags].sort((a, b) => b - a), `Pflichtangaben zuerst bei ${sub.name}`);
    for (const o of sub.options ?? []) assert.ok(o.description.length <= 100, `${sub.name}.${o.name}`);
  }
});

test('/hilfe: Moderationsbefehle für Moderatoren und Admins, nicht für andere', () => {
  const plain = helpText(commands, { admin: false, appUrl: 'https://cm.example' });
  const asMod = helpText(commands, { admin: false, mod: true, appUrl: 'https://cm.example' });
  const asAdmin = helpText(commands, { admin: true, appUrl: 'https://cm.example' });
  assert.doesNotMatch(plain, /`\/mod`/);
  assert.match(asMod, /\*\*Für Moderatoren und Admins\*\*\n`\/mod` – /);
  assert.doesNotMatch(asMod, /mod-einstellungen/);
  assert.match(asAdmin, /`\/mod-einstellungen` – /);
  assert.ok(asAdmin.length < 4096, `Hilfe zu lang: ${asAdmin.length}`);
});
