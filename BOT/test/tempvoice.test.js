import { test } from 'node:test';
import assert from 'node:assert/strict';
import { channelNameFor, createTempVoice, TEMPVOICE_CHANNELS, TEMPVOICE_TRIGGER } from '../src/lib/tempvoice.js';

const log = { info() {}, warn() {}, error() {} };

/** Zeitgeber, der nur auf Zuruf läuft. */
function fakeTimers() {
  const list = [];
  return {
    setTimer: (fn) => { const t = { fn, cleared: false, unref() {} }; list.push(t); return t; },
    clearTimer: (t) => { if (t) t.cleared = true; },
    fire: async () => { for (const t of list.filter((x) => !x.cleared)) { t.cleared = true; await t.fn(); } },
  };
}

/** Server mit Auslöser-Kanal "T" und einem Speicher für Einstellungen. */
function setup() {
  const settings = new Map([[`o1:${TEMPVOICE_TRIGGER}`, 'T']]);
  const store = {
    org: async () => ({ id: 'o1' }),
    get: async (o, n) => settings.get(`${o}:${n}`) ?? null,
    set: async (o, n, v) => (v === null ? settings.delete(`${o}:${n}`) : settings.set(`${o}:${n}`, v)),
  };
  const channels = new Map();
  let next = 1;
  const guild = {
    id: 'g1',
    channels: {
      cache: channels,
      fetch: async (id) => { if (!channels.has(id)) throw Object.assign(new Error('weg'), { code: 10003 }); return channels.get(id); },
      create: async (opts) => {
        const id = `c${next++}`;
        const ch = { id, guild, members: new Map(), created: opts, delete: async () => { channels.delete(id); } };
        channels.set(id, ch);
        return ch;
      },
    },
  };
  const trigger = { id: 'T', guild, parentId: 'K', bitrate: 64000, members: new Map(), permissionOverwrites: { cache: new Map([['r', { id: 'r', type: 0, allow: 1n, deny: 2n }]]) } };
  const overwrites = trigger.permissionOverwrites.cache;
  overwrites.filter = (fn) => ({ map: (m) => [...overwrites.values()].filter(fn).map(m) });
  channels.set('T', trigger);
  const timers = fakeTimers();
  const tv = createTempVoice({ log, store, ...timers });
  const member = (id, name) => {
    const m = { id, displayName: name, user: { username: name, bot: false }, voice: { setChannel: async (ch) => { ch.members.set(id, m); } } };
    return m;
  };
  return { tv, settings, channels, guild, trigger, timers, member };
}

test('Kanalname: Anzeigename, höchstens 100 Zeichen, Ersatz bei leerem Namen', () => {
  assert.equal(channelNameFor({ displayName: 'Mike' }), 'Mike');
  assert.equal(channelNameFor({ displayName: 'x'.repeat(150) }).length, 100);
  assert.equal(channelNameFor({ displayName: '  ' }), 'Sprachkanal');
});

test('Betreten des Auslösers legt einen Kanal mit dem Namen an, verschiebt und merkt ihn sich', async () => {
  const { tv, settings, channels, guild, trigger, member } = setup();
  const mike = member('u1', 'Mike');
  await tv.handleVoiceState({ channelId: null }, { channelId: 'T', guild, channel: trigger, member: mike });
  const created = channels.get('c1');
  assert.equal(created.created.name, 'Mike');
  assert.equal(created.created.parent, 'K');
  // Rechte des Auslösers plus Verwalten-Recht für den Ersteller
  assert.equal(created.created.permissionOverwrites.length, 2);
  assert.equal(created.created.permissionOverwrites.at(-1).id, 'u1');
  assert.ok(created.members.has('u1'));
  assert.deepEqual(JSON.parse(settings.get(`o1:${TEMPVOICE_CHANNELS}`)), ['c1']);
});

test('Kanal wird nach der Frist gelöscht, wenn er leer bleibt; Zurückkehren rettet ihn', async () => {
  const { tv, settings, channels, guild, trigger, timers, member } = setup();
  const mike = member('u1', 'Mike');
  await tv.handleVoiceState({ channelId: null }, { channelId: 'T', guild, channel: trigger, member: mike });
  const ch = channels.get('c1');
  // Mike geht: Löschen wird vorgemerkt
  ch.members.clear();
  await tv.handleVoiceState({ channelId: 'c1', channel: ch, member: mike }, { channelId: null, guild, member: mike });
  assert.equal(tv.pending(), 1);
  // Er kommt zurück: Löschen entfällt
  ch.members.set('u1', mike);
  await tv.handleVoiceState({ channelId: null, member: mike }, { channelId: 'c1', channel: ch, guild, member: mike });
  assert.equal(tv.pending(), 0);
  await timers.fire();
  assert.ok(channels.has('c1'));
  // Wieder gehen und die Frist ablaufen lassen
  ch.members.clear();
  await tv.handleVoiceState({ channelId: 'c1', channel: ch, member: mike }, { channelId: null, guild, member: mike });
  await timers.fire();
  assert.equal(channels.has('c1'), false);
  assert.equal(settings.has(`o1:${TEMPVOICE_CHANNELS}`), false);
  assert.deepEqual(tv.trackedIds(), []);
});

test('Kanal mit Leuten wird nicht gelöscht, fremde Kanäle und Bots bleiben unberührt', async () => {
  const { tv, channels, guild, trigger, timers, member } = setup();
  const mike = member('u1', 'Mike');
  await tv.handleVoiceState({ channelId: null }, { channelId: 'T', guild, channel: trigger, member: mike });
  const ch = channels.get('c1');
  const anna = member('u2', 'Anna');
  ch.members.set('u2', anna);
  ch.members.delete('u1');
  // Jemand ist noch drin: kein Löschen vorgemerkt
  await tv.handleVoiceState({ channelId: 'c1', channel: ch, member: mike }, { channelId: null, guild, member: mike });
  assert.equal(tv.pending(), 0);
  // Ein gewöhnlicher Kanal wird nie angefasst
  const other = { id: 'X', members: new Map() };
  await tv.handleVoiceState({ channelId: 'X', channel: other, member: mike }, { channelId: null, guild, member: mike });
  assert.equal(tv.pending(), 0);
  // Bots lösen nichts aus
  const bot = { ...mike, user: { bot: true } };
  await tv.handleVoiceState({ channelId: null }, { channelId: 'T', guild, channel: trigger, member: bot });
  assert.equal(channels.size, 2);
  await timers.fire();
  assert.ok(channels.has('c1'));
});

test('Neustart: leere gemerkte Kanäle werden gelöscht, verschwundene vergessen', async () => {
  const { tv, settings, channels, guild, timers } = setup();
  channels.set('a', { id: 'a', guild, members: new Map(), delete: async () => { channels.delete('a'); } });
  settings.set(`o1:${TEMPVOICE_CHANNELS}`, JSON.stringify(['a', 'gone']));
  await tv.sweep({ guilds: { cache: new Map([['g1', guild]]) } });
  assert.deepEqual(tv.trackedIds(), ['a']);
  await timers.fire();
  assert.equal(channels.has('a'), false);
  assert.equal(settings.has(`o1:${TEMPVOICE_CHANNELS}`), false);
});
