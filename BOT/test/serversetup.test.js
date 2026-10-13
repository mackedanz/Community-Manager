import { test } from 'node:test';
import assert from 'node:assert/strict';
import { ChannelType, PermissionFlagsBits as P, PermissionsBitField } from 'discord.js';
import { ADMIN_NOTE, baseName, CATEGORY_DEFS, CHANNEL_DEFS, overwritesFor, planSetup, planText } from '../src/lib/serversetup.js';
import { welcomeText } from '../src/lib/welcome.js';
import { INVITE_PERMISSIONS, data as setupData, handles } from '../src/commands/discord-setup.js';
import { data as welcomeData } from '../src/commands/willkommen.js';

const TEXT = ChannelType.GuildText;
const VOICE = ChannelType.GuildVoice;
const CAT = ChannelType.GuildCategory;
const org = (extra = {}) => ({ member_role_ids: null, planner_role_ids: null, instructor_role_ids: null, ...extra });
const state = (extra = {}) => ({
  org: org(), modRoles: [], visitorRoles: [], settings: { rules: null, welcome: null, announce: null, modlog: null, admin: null }, roles: [], channels: [], ...extra,
});
const byName = (list, name) => list.find((x) => x.name === name);
const SENDS = [P.SendMessages, P.SendMessagesInThreads, P.CreatePublicThreads, P.CreatePrivateThreads];

test('Die Standard-Struktur: Kategorien, Kanäle und wer sie sieht', () => {
  assert.deepEqual(CATEGORY_DEFS.map((k) => [k.name, k.visibility]), [
    ['Eingangshalle', 'public'], ['Intern', 'members'], ['Events', 'members'], ['Star Citizen Info', 'public'], ['Öffentlich', 'public'],
    ['Öffentliche Sprachkanäle', 'public'], ['Gaming Area', 'public'], ['Abteilungen', 'members'], ['Administrative', 'admins'],
  ]);
  const inCat = (key) => CHANNEL_DEFS.filter((c) => c.category === key).map((c) => c.name);
  assert.deepEqual(inCat('entrance'), ['! -server-regeln-!', 'willkommen']);
  assert.deepEqual(inCat('intern'), ['team', 'abteilungen', 'recruits']);
  assert.deepEqual(inCat('events'), ['operations-plan', 'op-chat']);
  assert.deepEqual(inCat('info'), ['patchnotes', 'sc-news', 'youtube-news', 'tips und tricks', 'issue-council', 'leaks']);
  assert.deepEqual(inCat('open'), ['screenshots', 'chat', 'wer streamt gerade']);
  assert.deepEqual(inCat('voice'), ['Lobby', 'Gruppe 1', 'Gruppe 2']);
  assert.deepEqual(inCat('gaming'), ['Gaming 1', 'Gaming 2']);
  assert.deepEqual(inCat('departments'), []);
  assert.deepEqual(inCat('admin'), ['mod-protokoll', 'bot-einstellungen']);
  assert.ok(CHANNEL_DEFS.filter((c) => c.voice).every((c) => c.write === undefined));
  // Die Bot-Kanäle sind an die Einstellungen gebunden: Termine = operations-plan
  assert.deepEqual(CHANNEL_DEFS.filter((c) => c.setting).map((c) => [c.key, c.name]), [
    ['rules', '! -server-regeln-!'], ['welcome', 'willkommen'], ['announce', 'operations-plan'], ['modlog', 'mod-protokoll'], ['admin', 'bot-einstellungen'],
  ]);
});

test('Leerer Server: alles wird neu angelegt, Mitglieds-Rolle mit Warnung', () => {
  const plan = planSetup(state());
  assert.deepEqual(plan.roles.map((r) => [r.name, r.action]), [['Mitglied', 'create'], ['Planer', 'create'], ['Ausbilder', 'create'], ['Moderator', 'create'], ['Besucher', 'create']]);
  assert.equal(plan.channels.length, CHANNEL_DEFS.length);
  assert.ok(plan.channels.every((c) => c.action === 'create'));
  assert.equal(plan.categories.length, 9); // auch die leere Kategorie „Abteilungen“
  assert.ok(plan.categories.every((k) => k.action === 'create'));
  assert.equal(plan.postRules, true);
  assert.equal(plan.postAdminNote, true);
  assert.equal(plan.memberChange, true);
  assert.equal(plan.empty, false);
  const text = planText(plan);
  assert.ok(text.length < 4000, `Vorschau zu lang: ${text.length}`);
  assert.match(text, /\*\*Rollen:\*\* ➕ „Mitglied“ · ➕ „Planer“ · ➕ „Ausbilder“ · ➕ „Moderator“ · ➕ „Besucher“/);
  assert.match(text, /„Bestätigen“ vergibt die Rolle „Besucher“/);
  assert.match(text, /➕ \*\*Eingangshalle\*\* \(alle\): ➕ #┋! -server-regeln-! · ➕ #┋willkommen/);
  assert.match(text, /➕ \*\*Intern\*\* \(nur Mitglieder\): ➕ #┋team · ➕ #┋abteilungen · ➕ #┋recruits/);
  assert.match(text, /➕ \*\*Events\*\* \(nur Mitglieder\): ➕ #┋operations-plan · ➕ #┋op-chat/);
  assert.match(text, /➕ \*\*Star Citizen Info\*\* \(alle\): ➕ #┋patchnotes · ➕ #┋sc-news · ➕ #┋youtube-news · ➕ #┋tips und tricks · ➕ #┋issue-council · ➕ #┋leaks/);
  assert.match(text, /➕ \*\*Öffentliche Sprachkanäle\*\* \(alle\): ➕ 🔊┋Lobby · ➕ 🔊┋Gruppe 1 · ➕ 🔊┋Gruppe 2/);
  assert.match(text, /➕ \*\*Abteilungen\*\* \(nur Mitglieder\) \(leer\)/);
  assert.match(text, /➕ \*\*Administrative\*\* \(nur Admins\): ➕ #┋mod-protokoll · ➕ #┋bot-einstellungen/);
  assert.match(text, /⚠ \*\*Wichtig:\*\* Die Rolle „Mitglied“/);
});

test('Vorhandenes wird übernommen oder bleibt, nichts doppelt', () => {
  const plan = planSetup(state({
    org: org({ member_role_ids: '100', planner_role_ids: '300' }),
    modRoles: ['500'],
    roles: [{ id: '100', name: 'Member' }, { id: '300', name: 'Planer' }, { id: '400', name: 'ausbilder' }, { id: '500', name: 'Mods' }, { id: '600', name: 'Moderator', managed: true }],
    settings: { rules: null, welcome: null, announce: '20', modlog: '999', admin: null }, // 999 gibt es nicht mehr
    channels: [
      { id: '10', name: '┋!-server-regeln-!', type: TEXT }, { id: '20', name: 'events', type: TEXT }, { id: '30', name: 'willkommen', type: CAT },
      { id: '40', name: 'INTERN', type: CAT }, { id: '50', name: '┋bot-einstellungen', type: TEXT }, { id: '60', name: '┋team', type: TEXT },
      { id: '70', name: '┋wer-streamt-gerade', type: TEXT }, { id: '80', name: '┋Lobby', type: VOICE }, { id: '90', name: 'Gruppe 1', type: TEXT },
    ],
  }));
  const role = (key) => plan.roles.find((r) => r.key === key);
  assert.equal(role('use').action, 'configured'); // „Member“ ist schon eingetragen
  assert.equal(role('plan').action, 'configured');
  assert.deepEqual([role('instruct').action, role('instruct').roleIds], ['use', ['400']]); // Groß-/Kleinschreibung egal
  assert.equal(role('mod').action, 'configured');
  assert.equal(role('visitor').action, 'create');
  assert.equal(plan.memberChange, false);
  const ch = (name) => byName(plan.channels, name);
  assert.deepEqual([ch('! -server-regeln-!').action, ch('! -server-regeln-!').channelId], ['use', '10']);
  assert.equal(ch('operations-plan').action, 'configured'); // egal, wie der Kanal heißt
  assert.equal(ch('willkommen').action, 'create'); // gleichnamige Kategorie zählt nicht als Textkanal
  assert.equal(ch('mod-protokoll').action, 'create'); // Einstellung zeigt auf einen gelöschten Kanal
  assert.deepEqual([ch('bot-einstellungen').action, ch('bot-einstellungen').channelId], ['use', '50']);
  assert.deepEqual([ch('team').action, ch('team').channelId], ['configured', '60']); // ohne Einstellung: vorhanden = fertig
  assert.equal(ch('wer streamt gerade').action, 'configured'); // Leerzeichen und Bindestrich sind gleich
  assert.equal(ch('Lobby').action, 'configured');
  assert.equal(ch('Gruppe 1').action, 'create'); // ein Textkanal ersetzt keinen Sprachkanal
  assert.equal(plan.postAdminNote, false);
  assert.equal(plan.postRules, false); // vorhandener Regeln-Kanal: keine zweite Regelnachricht
  // Intern gibt es (großgeschrieben): die neuen Kanäle abteilungen und recruits kommen dort hinein
  assert.deepEqual([plan.categories.find((k) => k.name === 'Intern').action, plan.categories.find((k) => k.name === 'Intern').channelId], ['use', '40']);
  // Abteilungen fehlt und ist leer vorgesehen
  assert.equal(plan.categories.find((k) => k.name === 'Abteilungen').action, 'create');
  assert.equal(plan.categories.find((k) => k.name === 'Eingangshalle').action, 'create');
  assert.equal(plan.categories.find((k) => k.name === 'Öffentliche Sprachkanäle').action, 'create');
  // Vorhandene Kategorie wird verwendet, auch wenn sie anders geschrieben ist
  const withCat = planSetup(state({ channels: [{ id: '41', name: '┋EINGANGSHALLE', type: CAT }] }));
  assert.deepEqual(withCat.categories.find((k) => k.name === 'Eingangshalle'), { ...CATEGORY_DEFS[0], needed: true, action: 'use', channelId: '41' });
});

test('Alles vorhanden: nichts zu tun; verwaiste Rollen-Einstellung wird ersetzt', () => {
  const channels = [];
  let n = 1000;
  for (const k of CATEGORY_DEFS) channels.push({ id: String(n++), name: k.name, type: CAT });
  const ids = {};
  for (const c of CHANNEL_DEFS) {
    const id = String(n++);
    channels.push({ id, name: `┋${c.name}`, type: c.voice ? VOICE : TEXT });
    if (c.key) ids[c.key] = id;
  }
  const all = state({
    org: org({ member_role_ids: '100', planner_role_ids: '300', instructor_role_ids: '400' }),
    modRoles: ['500'],
    visitorRoles: ['700'],
    roles: ['100', '300', '400', '500', '700'].map((id) => ({ id, name: `R${id}` })),
    settings: ids,
    channels,
  });
  const plan = planSetup(all);
  assert.equal(plan.empty, true);
  assert.deepEqual(plan.categories, []);
  assert.ok(planText(plan).includes('✅ **Abteilungen** (nur Mitglieder) (leer)'));
  // Die eingetragene Planer-Rolle gibt es nicht mehr: wird neu angelegt
  const broken = planSetup({ ...all, roles: all.roles.filter((r) => r.id !== '300') });
  assert.equal(broken.roles.find((r) => r.key === 'plan').action, 'create');
  assert.equal(broken.empty, false);
  // Eine vorhandene Rolle „Besucher“ wird übernommen und eingetragen
  const found = planSetup(state({ roles: [{ id: '55', name: 'besucher' }] }));
  assert.deepEqual([found.roles.find((r) => r.key === 'visitor').action, found.roles.find((r) => r.key === 'visitor').roleIds], ['use', ['55']]);
});

test('Rechte: öffentlich lesbar, nur Mitglieder, nur Moderatoren, nur Admins', () => {
  const ids = { everyone: 'G', bot: 'B', members: ['R1', 'R2'], mods: ['M1'] };
  const botEntry = { id: 'B', type: 1, allow: [P.ViewChannel, P.SendMessages, P.EmbedLinks, P.ReadMessageHistory] };

  // öffentlich, alle schreiben: nur der Bot braucht einen Eintrag
  assert.deepEqual(overwritesFor('public', 'all', ids), [botEntry]);
  // öffentlich, nur der Bot schreibt (Regeln, Willkommen)
  assert.deepEqual(overwritesFor('public', 'bot', ids), [{ id: 'G', type: 0, allow: [P.ViewChannel, P.ReadMessageHistory], deny: SENDS }, botEntry]);
  // nur Mitglieder: @everyone gesperrt, die Rollen dürfen
  assert.deepEqual(overwritesFor('members', 'all', ids), [
    { id: 'G', type: 0, deny: [P.ViewChannel] },
    { id: 'R1', type: 0, allow: [P.ViewChannel, P.ReadMessageHistory] },
    { id: 'R2', type: 0, allow: [P.ViewChannel, P.ReadMessageHistory] },
    botEntry,
  ]);
  // nur Mitglieder, nur der Bot schreibt (operations-plan): lesen ja, schreiben nein
  assert.deepEqual(overwritesFor('members', 'bot', ids)[0], { id: 'G', type: 0, deny: [P.ViewChannel, ...SENDS] });
  assert.deepEqual(overwritesFor('members', 'bot', ids)[1], { id: 'R1', type: 0, allow: [P.ViewChannel, P.ReadMessageHistory], deny: SENDS });
  assert.deepEqual(overwritesFor('members', 'bot', ids).at(-1), botEntry); // der Bot darf trotzdem schreiben
  // nur Moderatoren (Protokoll)
  assert.deepEqual(overwritesFor('mods', 'bot', ids), [
    { id: 'G', type: 0, deny: [P.ViewChannel, ...SENDS] },
    { id: 'M1', type: 0, allow: [P.ViewChannel, P.ReadMessageHistory], deny: SENDS },
    botEntry,
  ]);
  // nur Admins
  assert.deepEqual(overwritesFor('admins', 'all', ids), [{ id: 'G', type: 0, deny: [P.ViewChannel] }, botEntry]);
  // Sprachkanäle: nur Sichtbarkeit, kein Eintrag für den Bot
  assert.deepEqual(overwritesFor('public', undefined, ids, true), []);
  assert.deepEqual(overwritesFor('members', undefined, ids, true).map((o) => o.id), ['G', 'R1', 'R2']);
  // ohne Moderatoren-Rollen bleibt nur @everyone gesperrt plus der Bot
  assert.equal(overwritesFor('mods', 'bot', { ...ids, mods: [] }).length, 2);
});

test('Willkommenstext: Platzhalter und Standard', () => {
  assert.equal(welcomeText('Hi {user} bei {server}, lies {regeln}', { userId: '7', serverName: 'Orga', rulesChannelId: '9' }), 'Hi <@7> bei Orga, lies <#9>');
  assert.equal(welcomeText('{regeln}', { userId: '7', serverName: 'O' }), 'die Regeln');
  assert.match(welcomeText(null, { userId: '7', serverName: 'Orga', rulesChannelId: '9' }), /^Willkommen <@7> auf \*\*Orga\*\*! Lies bitte <#9>/);
  assert.equal(welcomeText('{user} {user}', { userId: '7', serverName: 'O' }), '<@7> <@7>');
});

test('Namen vergleichen: Symbole am Rand, Groß-/Kleinschreibung, Leerzeichen', () => {
  assert.equal(baseName('┋Regeln'), 'regeln');
  assert.equal(baseName('┋!-server-regeln-!'), 'server-regeln');
  assert.equal(baseName('! -server-regeln-!'), 'server-regeln');
  assert.equal(baseName('・Bot-Einstellungen'), 'bot-einstellungen');
  assert.equal(baseName('┋wer streamt gerade'), 'wer-streamt-gerade');
  assert.equal(baseName('regeln'), 'regeln');
  // Der Regeln-Kanal wird unter allen üblichen Namen erkannt
  for (const name of ['┋!-server-regeln-!', '┋regeln', 'Regeln']) {
    const p = planSetup(state({ channels: [{ id: '9', name, type: TEXT }] }));
    const rules = p.channels.find((c) => c.key === 'rules');
    assert.deepEqual([rules.action, rules.channelId], ['use', '9'], name);
  }
  // Termine: ein vorhandener Kanal „termine“ wird zum Ankündigungs-Kanal
  assert.equal(planSetup(state({ channels: [{ id: '8', name: '┋termine', type: TEXT }] })).channels.find((c) => c.key === 'announce').channelId, '8');
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
  assert.match(ADMIN_NOTE, /`\/discord-setup`/);
});
