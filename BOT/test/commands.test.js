import { test } from 'node:test';
import assert from 'node:assert/strict';
import { panelData, handles } from '../src/commands/panel.js';
import { refusal } from '../src/commands/abgleichen.js';
import { commands } from '../src/commands/index.js';

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

test('Befehle: eindeutige Namen und gültige Beschreibungen', () => {
  const names = commands.map((c) => c.data.name);
  assert.deepEqual(names.sort(), ['abgleichen', 'design', 'einrichten', 'ping']);
  for (const c of commands) {
    assert.ok(c.data.description.length <= 100, c.data.name);
    for (const o of c.data.options ?? []) assert.ok(o.description.length <= 100, `${c.data.name}.${o.name}`);
  }
});
