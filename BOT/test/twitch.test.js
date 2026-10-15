import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createTwitch, parseLogin } from '../src/lib/twitch.js';
import { liveMessage } from '../src/lib/creators.js';
import { creatorsEmbed } from '../src/commands/creator.js';
import { readConfig } from '../src/config.js';

const reply = (status, body) => ({ status, json: async () => body });

test('parseLogin: Link, @Name und Name, klein geschrieben', () => {
  assert.equal(parseLogin('https://www.twitch.tv/Star_Citizen'), 'star_citizen');
  assert.equal(parseLogin('twitch.tv/Knusper/'), 'knusper');
  assert.equal(parseLogin('@Fhexy'), 'fhexy');
  assert.equal(parseLogin(' DieLoge '), 'dieloge');
  assert.equal(parseLogin('ab'), null);
  assert.equal(parseLogin('mit leerzeichen'), null);
  assert.equal(parseLogin('https://example.com/abc'), null);
});

test('createTwitch: Token holen und wiederverwenden, Kanäle und Streams abfragen', async () => {
  const calls = [];
  const fetchFn = async (url, opts = {}) => {
    calls.push(url);
    if (url.includes('id.twitch.tv')) return reply(200, { access_token: 'tok', expires_in: 3600 });
    assert.equal(opts.headers.Authorization, 'Bearer tok');
    assert.equal(opts.headers['Client-Id'], 'id');
    if (url.includes('/users?')) return reply(200, { data: [{ id: '1', login: 'knusper', display_name: 'Knusper' }] });
    return reply(200, { data: [
      { id: '99', user_login: 'knusper', user_name: 'Knusper', title: 'Abend', game_name: 'Star Citizen', type: 'live' },
      { id: '98', user_login: 'andere', user_name: 'Andere', title: 'x', game_name: '', type: '' },
    ] });
  };
  const t = createTwitch({ clientId: 'id', clientSecret: 'secret' }, fetchFn);
  assert.deepEqual([...(await t.users(['knusper', 'gibtsnicht'])).entries()], [['knusper', { id: '1', login: 'knusper', name: 'Knusper' }]]);
  const live = await t.streams(['knusper', 'andere']);
  assert.deepEqual([...live.keys()], ['knusper']);
  assert.equal(live.get('knusper').game, 'Star Citizen');
  assert.equal(calls.filter((u) => u.includes('id.twitch.tv')).length, 1, 'Token nur einmal holen');
  assert.match(calls.find((u) => u.includes('/streams')), /user_login=knusper&user_login=andere/);
});

test('createTwitch: abgelaufenes Token wird einmal erneuert, falsche Zugangsdaten und Ausfälle werden gemeldet', async () => {
  let tokens = 0;
  let first = true;
  const fetchFn = async (url) => {
    if (url.includes('id.twitch.tv')) return reply(200, { access_token: `tok${++tokens}`, expires_in: 3600 });
    if (first) { first = false; return reply(401, {}); }
    return reply(200, { data: [] });
  };
  const t = createTwitch({ clientId: 'id', clientSecret: 's' }, fetchFn);
  assert.equal((await t.streams(['knusper'])).size, 0);
  assert.equal(tokens, 2);
  await assert.rejects(createTwitch({ clientId: 'id', clientSecret: 'falsch' }, async () => reply(403, {})).users(['a']), /lehnt die Zugangsdaten ab/);
  await assert.rejects(createTwitch({ clientId: 'id', clientSecret: 's' }, async () => { throw new Error('offline'); }).users(['a']), /nicht erreichbar/);
});

test('liveMessage: Rolle erwähnen, Link zum Kanal', () => {
  const m = liveMessage({ name: 'Knusper', channel_id: 'knusper', mention_role_id: '42' }, { title: 'Abend', game: 'Star Citizen' });
  assert.equal(m.content, '<@&42> **Knusper** ist live auf Twitch: **Abend** (Star Citizen)\nhttps://www.twitch.tv/knusper');
  assert.deepEqual(m.allowedMentions, { roles: ['42'] });
  assert.match(liveMessage({ name: 'K', channel_id: 'k', mention_role_id: null }, { title: '', game: '' }).content, /\*\*ohne Titel\*\*\nhttps:/);
});

test('creatorsEmbed: Twitch-Kanäle verlinken auf Twitch', () => {
  const e = creatorsEmbed({ name: 'S' }, [{ platform: 'twitch', name: 'Knusper', channel_id: 'knusper', discord_channel_id: '9', mention_role_id: null, skip_shorts: 0, last_error: null, checked_at: null }]);
  assert.match(e.description, /\(https:\/\/www\.twitch\.tv\/knusper\)/);
  assert.match(e.description, /Twitch, Live-Meldung/);
});

test('Konfiguration: Twitch nur mit Client-ID und Geheimnis', () => {
  const base = { DISCORD_BOT_TOKEN: 't', AUTH_DISCORD_ID: '1', DB_HOST: 'h', DB_USER: 'u', DB_PASSWORD: 'p' };
  assert.equal(readConfig(base).twitch, null);
  assert.equal(readConfig({ ...base, TWITCH_CLIENT_ID: 'a' }).twitch, null);
  assert.deepEqual(readConfig({ ...base, TWITCH_CLIENT_ID: 'a', TWITCH_CLIENT_SECRET: 'b' }).twitch, { clientId: 'a', clientSecret: 'b' });
});
