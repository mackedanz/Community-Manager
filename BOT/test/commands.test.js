import { test } from 'node:test';
import assert from 'node:assert/strict';
import { panelData, handles } from '../src/commands/panel.js';
import { refusal } from '../src/commands/abgleichen.js';
import { commands } from '../src/commands/index.js';
import { audienceOf, helpText, usage } from '../src/commands/help.js';

const R1 = '111111111111111111';
const R2 = '222222222222222222';
const config = { onboardingGuildIds: [] };

test('Panel zeigt Rollen, RSI-Orga und fünf Auswahlen', () => {
  const org = {
    name: 'Test-Orga', member_role_ids: R1, planner_role_ids: R2, role_labels: JSON.stringify({ [R1]: 'Mitglied' }),
    rsi_sid: 'EXPG', rsi_org_name: 'Explorer', handle_style: 'round',
  };
  const p = panelData(org, 'Hinweis', 'https://hangar.example');
  assert.match(p.content, /Dürfen das Tool nutzen: Mitglied/);
  assert.match(p.content, new RegExp(`Dürfen Events planen: <@&${R2}>`));
  assert.match(p.content, /Recruiter .*: –/);
  assert.match(p.content, /RSI-Orga: Explorer \(EXPG\)/);
  assert.match(p.content, /In runden Klammern/);
  assert.match(p.content, /auf https:\/\/hangar\.example anmelden/);
  assert.match(p.content, /\n\nHinweis$/);
  assert.equal(p.components.length, 5);
  assert.deepEqual(p.components[0].components[0].default_values, [{ id: R1, type: 'role' }]);
  assert.equal(p.components[2].components[0].default_values, undefined);
  assert.ok(handles('onb:use') && handles('onb:sync') && !handles('andere'));
});

test('/abgleichen: Gründe für eine Ablehnung', () => {
  const org = { member_role_ids: R1, allowlist_synced_at: null };
  const base = { guildId: '5', roles: [], admin: false, config, now: Date.parse('2026-10-13T12:00:00Z') };
  assert.match(refusal(null, base), /noch keine Orga/);
  assert.match(refusal(org, { ...base, config: { onboardingGuildIds: ['7'] } }), /nicht freigeschaltet/);
  assert.match(refusal(org, base), /nur Mitglieder mit einer Nutzungs-Rolle/);
  assert.equal(refusal(org, { ...base, roles: [R1] }), null);
  assert.equal(refusal(org, { ...base, admin: true }), null);
  assert.match(refusal({ ...org, allowlist_synced_at: '2026-10-13 11:59:50' }, { ...base, admin: true }), /gerade erst/);
  assert.equal(refusal({ ...org, allowlist_synced_at: '2026-10-13 11:59:00' }, { ...base, admin: true }), null);
  assert.match(refusal({ member_role_ids: null }, { ...base, admin: true }), /noch keine Rolle gewählt/);
});

test('/hilfe: Übersicht nach Zielgruppe, Admin-Befehle nur für Admins', () => {
  const forMember = helpText(commands, { admin: false, appUrl: 'https://cm.example' });
  const forAdmin = helpText(commands, { admin: true, ids: new Map([['termine', '123']]), appUrl: 'https://cm.example' });
  assert.match(forMember, /\*\*Für alle\*\*\n.*`\/ping`/);
  assert.match(forMember, /\*\*Für Mitglieder der Orga\*\*[\s\S]*`\/termine`/);
  assert.doesNotMatch(forMember, /serverregeln|Für Server-Admins/);
  assert.match(forMember, /zeigt \/hilfe, wenn ein Admin/);
  assert.match(forAdmin, /\*\*Für Server-Admins\*\*[\s\S]*`\/serverregeln` – .* · `\/serverregeln <kanal> <rolle>`/);
  assert.match(forAdmin, /<\/termine:123> – /);
  assert.match(forAdmin, /`\/termine-kanal \[kanal\] \[aus\]`/);
  // /help und /hilfe erscheinen als eine Zeile
  assert.equal((forAdmin.match(/hilfe/g) ?? []).length >= 1 && !/`\/help`/.test(forAdmin), true);
  assert.ok(forAdmin.length < 4096);
  assert.equal(audienceOf(commands.find((c) => c.data.name === 'design')), 'admins');
  assert.equal(usage({ name: 'x', options: [{ name: 'a', required: true }, { name: 'b' }] }), '/x <a> [b]');
});

test('Befehle: eindeutige Namen und gültige Beschreibungen', () => {
  const names = commands.map((c) => c.data.name);
  assert.deepEqual(names.sort(), ['abgleichen', 'ausbildung', 'automod', 'betreiber', 'creator', 'design', 'discord-setup', 'einrichten', 'flotte', 'hangar', 'help', 'hilfe', 'mod', 'mod-einstellungen', 'orga', 'ping', 'profil', 'serverregeln', 'termin-neu', 'termine', 'termine-kanal', 'wer-hat', 'willkommen']);
  for (const c of commands) {
    assert.ok(c.data.description.length <= 100, c.data.name);
    for (const o of c.data.options ?? []) assert.ok(o.description.length <= 100, `${c.data.name}.${o.name}`);
  }
});
