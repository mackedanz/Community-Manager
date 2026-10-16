import { test } from 'node:test';
import assert from 'node:assert/strict';
import { MessageFlags } from 'discord.js';
import { createAutoDelete, isEphemeral } from '../src/lib/autodelete.js';

/** Zeitgeber, der nur auf Zuruf läuft. */
function fakeTimers() {
  const list = [];
  return {
    setTimer: (fn) => { const t = { fn, cleared: false, unref() {} }; list.push(t); return t; },
    clearTimer: (t) => { if (t) t.cleared = true; },
    fire: async () => { for (const t of list.filter((x) => !x.cleared)) { t.cleared = true; await t.fn(); } },
  };
}

const fake = (over = {}) => ({
  deferred: false, replied: true, ephemeral: true, deleted: 0, isAutocomplete: () => false,
  fetchReply: async () => ({ id: 'm1' }), deleteReply() { this.deleted++; return Promise.resolve(); }, ...over,
});

test('private Antworten erkennen, öffentliche und Vorschläge nicht', () => {
  assert.equal(isEphemeral(fake()), true);
  assert.equal(isEphemeral(fake({ ephemeral: false })), false);
  assert.equal(isEphemeral(fake({ replied: false })), false);
  assert.equal(isEphemeral(fake({ isAutocomplete: () => true })), false);
  const flags = { has: (f) => f === MessageFlags.Ephemeral };
  assert.equal(isEphemeral(fake({ ephemeral: null, message: { id: 'x', flags } })), true);
  assert.equal(isEphemeral(fake({ ephemeral: null, message: { id: 'x', flags: { has: () => false } } })), false);
});

test('private Antwort wird nach der Frist gelöscht, öffentliche nicht', async () => {
  const t = fakeTimers();
  const ad = createAutoDelete({ setTimer: t.setTimer, clearTimer: t.clearTimer });
  const priv = fake();
  const pub = fake({ ephemeral: false });
  await ad.track(priv);
  await ad.track(pub);
  assert.equal(ad.size(), 1);
  await t.fire();
  assert.equal(priv.deleted, 1);
  assert.equal(pub.deleted, 0);
  assert.equal(ad.size(), 0);
});

test('weitere Aktion auf derselben Nachricht verlängert die Frist', async () => {
  const t = fakeTimers();
  const ad = createAutoDelete({ setTimer: t.setTimer, clearTimer: t.clearTimer });
  const first = fake();
  const click = fake({ message: { id: 'm1', flags: { has: () => true } }, ephemeral: null });
  await ad.track(first);
  await ad.track(click);
  assert.equal(ad.size(), 1);
  await t.fire();
  assert.equal(first.deleted, 0);
  assert.equal(click.deleted, 1);
});

test('Fehler beim Löschen oder Abfragen stören nicht', async () => {
  const t = fakeTimers();
  const ad = createAutoDelete({ setTimer: t.setTimer, clearTimer: t.clearTimer });
  await ad.track(fake({ fetchReply: async () => { throw new Error('weg'); } }));
  assert.equal(ad.size(), 0);
  await ad.track(fake({ deleteReply: async () => { throw new Error('unbekannte Nachricht'); } }));
  await t.fire();
});
