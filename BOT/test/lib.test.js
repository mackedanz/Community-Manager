import { test } from 'node:test';
import assert from 'node:assert/strict';
import { slugify, newId, cut } from '../src/lib/text.js';
import { buildRoleLabels, cleanRoleIds, parseRoleIds, parseRoleLabels, allAccessRoleIds } from '../src/lib/roles.js';
import { OrgError } from '../src/lib/errors.js';
import { cleanSid, cleanHandleStyle, parseMembersHtml, lookup } from '../src/lib/rsi.js';
import { syncNote } from '../src/lib/onboarding.js';
import { toMember } from '../src/lib/guild.js';

const R1 = '111111111111111111';
const R2 = '222222222222222222';

test('slugify wie Text::slugify der PHP-App', () => {
  assert.equal(slugify('Ärger & Co.'), 'aerger-co');
  assert.equal(slugify('Café Orga'), 'cafe-orga');
  assert.equal(slugify('!!!'), 'orga');
  assert.equal(slugify('a'.repeat(50)), 'a'.repeat(40));
});

test('newId liefert 24 Hex-Zeichen', () => {
  assert.match(newId(), /^[0-9a-f]{24}$/);
  assert.equal(cut('äöü-Test', 3), 'äöü');
});

test('Rollen-IDs prüfen, zusammenfassen und lesen', () => {
  assert.equal(cleanRoleIds([R1, ` ${R2} `, R1, '']), `${R1},${R2}`);
  assert.equal(cleanRoleIds([]), null);
  assert.throws(() => cleanRoleIds(['123']), OrgError);
  assert.throws(() => cleanRoleIds(Array.from({ length: 11 }, (_, i) => `1${String(i).padStart(17, '0')}`)), OrgError);
  assert.deepEqual(parseRoleIds(`${R1},${R2}`), [R1, R2]);
  assert.deepEqual(parseRoleIds(null), []);
});

test('Rollennamen nur für eingetragene Rollen', () => {
  const json = buildRoleLabels([R1], { [R1]: '  Mitglied ', [R2]: 'Weg' });
  assert.deepEqual(parseRoleLabels(json), { [R1]: 'Mitglied' });
  assert.equal(buildRoleLabels([R1], {}), null);
  assert.deepEqual(parseRoleLabels('[]'), {});
  assert.deepEqual(parseRoleLabels('kaputt'), {});
});

test('alle Zugangsrollen einer Orga ohne Doppelte', () => {
  assert.deepEqual(allAccessRoleIds({ member_role_ids: R1, planner_role_ids: `${R1},${R2}`, lead_role_ids: null }), [R1, R2]);
});

test('RSI-Kürzel und Handle-Stil', () => {
  assert.equal(cleanSid(' expg '), 'EXPG');
  assert.equal(cleanSid('a b'), null);
  assert.equal(cleanHandleStyle('round'), 'round');
  assert.equal(cleanHandleStyle('quatsch'), 'plain');
});

const html = '<li class="member-item js-member-item org-main" data-org-name="Explorer &amp; Co"><a href="/citizens/Mimack76">x</a></li>'
  + '<li class="member-item js-member-item org-affiliate"><a href="/citizens/Zweiter">y</a></li>'
  + '<li class="member-item js-member-item org-main"><span>verborgen</span></li>';

test('RSI-Mitgliederliste lesen', () => {
  const r = parseMembersHtml(html);
  assert.equal(r.name, 'Explorer & Co');
  assert.deepEqual(r.members, [
    { handle: 'Mimack76', main: true },
    { handle: 'Zweiter', main: false },
    { handle: null, main: true },
  ]);
});

const fakeFetch = (json, status = 200) => async () => ({ status, json: async () => json });

test('RSI-Orga nachschlagen', async () => {
  assert.deepEqual(await lookup('EXPG', fakeFetch({ success: 1, data: { totalrows: 3, html } })), { name: 'Explorer & Co', total: 3 });
  await assert.rejects(lookup('EXPG', fakeFetch({ success: 0 })), /nicht abrufen/);
  await assert.rejects(lookup('EXPG', fakeFetch({ success: 1, data: { totalrows: 0, html: '' } })), /keine Orga/);
  await assert.rejects(lookup('EXPG', async () => { throw new Error('offline'); }), /nicht erreichbar/);
});

test('Meldung nach dem Abgleich', () => {
  assert.equal(syncNote({ total: 5, added: 2, removed: 1, deleted: 0, skipped: 0 }), 'Fertig: 5 Mitglieder dürfen sich anmelden (+2, −1).');
  assert.match(syncNote({ total: 5, added: 0, removed: 6, deleted: 0, skipped: 6 }), /Sicherheitsbremse/);
  assert.match(syncNote({ total: 5, added: 0, removed: 1, deleted: 1, skipped: 0 }), /1 Konten gelöscht/);
});

test('Mitglied aus der Discord-Antwort', () => {
  assert.equal(toMember('9', { user: { id: '1', bot: true } }), null);
  assert.deepEqual(toMember('9', { nick: 'Micha', avatar: 'g', roles: [R1], user: { id: '1', username: 'mimack', global_name: 'Mi', avatar: 'u' } }), {
    id: '1', name: 'Micha', nick: 'Micha', accountName: 'Mi', avatar: 'https://cdn.discordapp.com/guilds/9/users/1/avatars/g.png', roles: [R1],
  });
  assert.equal(toMember('9', { user: { id: '1', username: 'mimack', avatar: 'u' } }).avatar, 'https://cdn.discordapp.com/avatars/1/u.png');
});
