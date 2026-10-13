import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readConfig, ConfigError } from '../src/config.js';

const full = {
  DISCORD_BOT_TOKEN: 'token',
  AUTH_DISCORD_ID: '123',
  DB_HOST: 'db',
  DB_USER: 'hangar',
  DB_PASSWORD: 'geheim',
};

test('liest vollständige Einstellungen mit Standardwerten', () => {
  const c = readConfig(full);
  assert.equal(c.token, 'token');
  assert.equal(c.applicationId, '123');
  assert.equal(c.devGuildId, null);
  assert.deepEqual(c.db, { host: 'db', port: 3306, database: 'hangar', user: 'hangar', password: 'geheim' });
});

test('nennt alle fehlenden Einstellungen', () => {
  assert.throws(
    () => readConfig({ ...full, DISCORD_BOT_TOKEN: ' ', DB_HOST: undefined }),
    (err) => err instanceof ConfigError && err.message.includes('DISCORD_BOT_TOKEN') && err.message.includes('DB_HOST'),
  );
});

test('lehnt ungültigen Port ab', () => {
  assert.throws(() => readConfig({ ...full, DB_PORT: 'abc' }), ConfigError);
});
