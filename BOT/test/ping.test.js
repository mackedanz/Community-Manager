import { test } from 'node:test';
import assert from 'node:assert/strict';
import { report } from '../src/commands/ping.js';

// Ersatz für den mysql2-Pool: beantwortet nur die Abfragen, die /ping stellt
function fakePool({ org = null, fail = false } = {}) {
  return {
    async query(sql) {
      if (fail) throw Object.assign(new Error('weg'), { code: 'ECONNREFUSED' });
      if (sql.includes('schema_migrations')) return [[{ name: '019_completion_snapshot.sql' }]];
      if (sql.includes('organizations')) return [org ? [org] : []];
      return [[{ 1: 1 }]];
    },
  };
}

test('meldet Datenbankstand und Orga des Servers', async () => {
  const text = await report({ pool: fakePool({ org: { name: 'Test-Orga' } }), guildId: '1', wsPing: 42 });
  assert.match(text, /Discord: verbunden \(42 ms\)/);
  assert.match(text, /Stand 019_completion_snapshot\.sql/);
  assert.match(text, /Orga dieses Servers: Test-Orga/);
});

test('meldet Server ohne Orga', async () => {
  const text = await report({ pool: fakePool(), guildId: '1', wsPing: -1 });
  assert.match(text, /Wert folgt/);
  assert.match(text, /noch keiner Orga zugeordnet/);
});

test('meldet nicht erreichbare Datenbank statt abzustürzen', async () => {
  const text = await report({ pool: fakePool({ fail: true }), guildId: '1', wsPing: 10 });
  assert.match(text, /Datenbank: nicht erreichbar \(ECONNREFUSED\)/);
});
