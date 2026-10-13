import { test } from 'node:test';
import assert from 'node:assert/strict';
import { ChannelType, PermissionFlagsBits as P, PermissionsBitField } from 'discord.js';
import { CATEGORY_NAME, overwritesFor, planSetup, planText } from '../src/lib/serversetup.js';
import { welcomeText } from '../src/lib/welcome.js';
import { INVITE_PERMISSIONS, data as setupData, handles } from '../src/commands/discord-setup.js';
import { data as welcomeData } from '../src/commands/willkommen.js';

const TEXT = ChannelType.GuildText;
const CAT = ChannelType.GuildCategory;
const org = (extra = {}) => ({ member_role_ids: null, planner_role_ids: null, instructor_role_ids: null, ...extra });
const state = (extra = {}) => ({ org: org(), modRoles: [], settings: { rules: null, welcome: null, announce: null, modlog: null }, roles: [], channels: [], ...extra });
const byKey = (list, key) => list.find((x) => x.key === key);

test('Leerer Server: alles wird neu angelegt, Mitglieds-Rolle mit Warnung', () => {
  const plan = planSetup(state());
  assert.deepEqual(plan.roles.map((r) => [r.name, r.action]), [['Mitglied', 'create'], ['Planer', 'create'], ['Ausbilder', 'create'], ['Moderator', 'create']]);
  assert.deepEqual(plan.channels.map((c) => [c.name, c.action]), [['regeln', 'create'], ['willkommen', 'create'], ['termine', 'create'], ['mod-protokoll', 'create']]);
  assert.deepEqual(plan.category, { action: 'create', channelId: null });
  assert.equal(plan.postRules, true);
  assert.equal(plan.memberChange, true);
  assert.equal(plan.empty, false);
  const text = planText(plan);
  assert.match(text, /➕ wird angelegt: „Mitglied“/);
  assert.match(text, /➕ wird angelegt: #mod-protokoll/);
  assert.match(text, /Kategorie „Community“/);
  assert.match(text, /⚠ \*\*Wichtig:\*\* Die Rolle für „Mitglied“/);
});

test('Vorhandenes wird übernommen oder bleibt, nichts doppelt', () => {
  const plan = planSetup(state({
    org: org({ member_role_ids: '100', planner_role_ids: '300' }),
    modRoles: ['500'],
    roles: [{ id: '100', name: 'Member' }, { id: '300', name: 'Planer' }, { id: '400', name: 'ausbilder' }, { id: '500', name: 'Mods' }, { id: '600', name: 'Moderator', managed: true }],
    settings: { rules: null, welcome: null, announce: '20', modlog: '999' }, // 999 gibt es nicht mehr
    channels: [{ id: '10', name: 'Regeln', type: TEXT }, { id: '20', name: 'events', type: TEXT }, { id: '30', name: 'willkommen', type: CAT }, { id: '40', name: 'Community', type: CAT }],
  }));
  assert.equal(byKey(plan.roles, 'use').action, 'configured'); // "Member" ist schon eingetragen
  assert.equal(byKey(plan.roles, 'plan').action, 'configured');
  assert.deepEqual([byKey(plan.roles, 'instruct').action, byKey(plan.roles, 'instruct').roleIds], ['use', ['400']]); // Groß-/Kleinschreibung egal
  assert.equal(byKey(plan.roles, 'mod').action, 'configured');
  assert.equal(plan.memberChange, false);
  assert.deepEqual([byKey(plan.channels, 'rules').action, byKey(plan.channels, 'rules').channelId], ['use', '10']);
  assert.equal(byKey(plan.channels, 'announce').action, 'configured'); // egal, wie der Kanal heißt
  assert.equal(byKey(plan.channels, 'welcome').action, 'create'); // gleichnamige Kategorie zählt nicht als Textkanal
  assert.equal(byKey(plan.channels, 'modlog').action, 'create'); // Einstellung zeigt auf einen gelöschten Kanal
  assert.deepEqual(plan.category, { action: 'use', channelId: '40' });
  assert.equal(plan.postRules, false); // vorhandener #regeln: keine zweite Regelnachricht
});

test('Alles vorhanden: nichts zu tun; verwaiste Rollen-Einstellung wird ersetzt', () => {
  const all = state({
    org: org({ member_role_ids: '100', planner_role_ids: '300', instructor_role_ids: '400' }),
    modRoles: ['500'],
    roles: ['100', '300', '400', '500'].map((id) => ({ id, name: `R${id}` })),
    settings: { rules: '1', welcome: '2', announce: '3', modlog: '4' },
    channels: ['1', '2', '3', '4'].map((id) => ({ id, name: `k${id}`, type: TEXT })),
  });
  const plan = planSetup(all);
  assert.equal(plan.empty, true);
  assert.equal(plan.category, null);
  // Die eingetragene Planer-Rolle gibt es nicht mehr: wird neu angelegt
  const broken = planSetup({ ...all, roles: all.roles.filter((r) => r.id !== '300') });
  assert.equal(byKey(broken.roles, 'plan').action, 'create');
  assert.equal(broken.empty, false);
});

test('Rechte neuer Kanäle: öffentlich nur lesbar, Protokoll nur für Moderatoren', () => {
  const ids = { everyone: 'G', bot: 'B', mods: ['M1', 'M2'] };
  const read = overwritesFor('read', ids);
  assert.deepEqual(read[0], { id: 'G', type: 0, allow: [P.ViewChannel, P.ReadMessageHistory], deny: [P.SendMessages, P.SendMessagesInThreads, P.CreatePublicThreads, P.CreatePrivateThreads] });
  assert.deepEqual(read[1], { id: 'B', type: 1, allow: [P.ViewChannel, P.SendMessages, P.EmbedLinks, P.ReadMessageHistory] });
  const mods = overwritesFor('mods', ids);
  assert.deepEqual(mods[0], { id: 'G', type: 0, deny: [P.ViewChannel] });
  assert.deepEqual(mods.slice(1, 3).map((o) => [o.id, o.type]), [['M1', 0], ['M2', 0]]);
  assert.equal(mods.at(-1).id, 'B'); // der Bot sieht den Kanal selbst auch
  assert.equal(overwritesFor('mods', { ...ids, mods: [] }).length, 2);
});

test('Willkommenstext: Platzhalter und Standard', () => {
  assert.equal(welcomeText('Hi {user} bei {server}, lies {regeln}', { userId: '7', serverName: 'Orga', rulesChannelId: '9' }), 'Hi <@7> bei Orga, lies <#9>');
  assert.equal(welcomeText('{regeln}', { userId: '7', serverName: 'O' }), 'die Regeln');
  assert.match(welcomeText(null, { userId: '7', serverName: 'Orga', rulesChannelId: '9' }), /^Willkommen <@7> auf \*\*Orga\*\*! Lies bitte <#9>/);
  assert.equal(welcomeText('{user} {user}', { userId: '7', serverName: 'O' }), '<@7> <@7>');
});

test('Befehle: Einladungsrechte, Knöpfe, Längen', () => {
  const needed = new PermissionsBitField(BigInt(INVITE_PERMISSIONS));
  for (const p of ['ManageRoles', 'ManageChannels', 'KickMembers', 'BanMembers', 'ModerateMembers', 'ManageMessages', 'ManageEvents', 'SendMessages', 'EmbedLinks']) {
    assert.ok(needed.has(p), p);
  }
  assert.ok(!needed.has('Administrator'));
  assert.ok(handles('setup:go') && handles('setup:no') && !handles('rules:ok:1'));
  assert.equal(setupData.default_member_permissions, '32');
  for (const d of [setupData, welcomeData]) {
    assert.ok(d.description.length <= 100, d.name);
    for (const o of d.options ?? []) assert.ok(o.description.length <= 100, `${d.name}.${o.name}`);
  }
  assert.equal(CATEGORY_NAME, 'Community');
});
