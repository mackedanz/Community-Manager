import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
  changeList, configText, DEFAULTS, evaluate, linkHosts, normalizeDomain, normalizeWord, SpamTracker, anyRule,
} from '../src/lib/automod.js';
import { deletedEmbed, editedEmbed } from '../src/events/messagelog.js';
import { data } from '../src/commands/automod.js';
import { readConfig } from '../src/config.js';

const cfg = (extra = {}) => ({ ...DEFAULTS, words: [], domains: [], exemptChannels: [], ...extra });

test('Einladungslinks werden erkannt, normale Links nicht', () => {
  const c = cfg({ invites: true });
  assert.equal(evaluate('komm auf https://discord.gg/abc123 !', {}, c).rule, 'invites');
  assert.equal(evaluate('discord.com/invite/xyz', {}, c).rule, 'invites');
  assert.equal(evaluate('https://example.com/discord', {}, c), null);
  assert.equal(evaluate('discord.gg/abc123', {}, cfg()), null); // Regel aus
});

test('Links: nur erlaubte Domains samt Subdomains', () => {
  const c = cfg({ links: true, domains: ['youtube.com'] });
  assert.equal(evaluate('kein Link hier', {}, c), null);
  assert.equal(evaluate('https://www.youtube.com/watch?v=1', {}, c), null);
  assert.equal(evaluate('https://m.youtube.com/x', {}, c), null);
  assert.equal(evaluate('https://notyoutube.com/x', {}, c).rule, 'links');
  assert.equal(evaluate('siehe www.boese.de/seite', {}, c).rule, 'links');
  assert.equal(evaluate('https://youtube.com.boese.de/', {}, c).rule, 'links');
  assert.deepEqual(linkHosts('a https://www.A.de/x und http://b.org?q=1'), ['a.de', 'b.org']);
});

test('Verbotene Wörter: ganze Wörter, ohne Rücksicht auf Groß-/Kleinschreibung', () => {
  const c = cfg({ words: ['blödmann', 'spam'] });
  assert.equal(evaluate('Du BLÖDMANN!', {}, c).rule, 'words');
  assert.equal(evaluate('das ist spam.', {}, c).rule, 'words');
  assert.equal(evaluate('Spamschutz ist toll', {}, c), null);
  assert.equal(evaluate('Kein Treffer', {}, c), null);
  // Sonderzeichen im Wort sind kein Regex
  assert.equal(evaluate('hallo', {}, cfg({ words: ['h.llo'] })), null);
});

test('Massen-Erwähnungen: nur über der Grenze', () => {
  const c = cfg({ mentions: 5 });
  assert.equal(evaluate('hi', { mentions: 5 }, c), null);
  assert.equal(evaluate('hi', { mentions: 6 }, c).rule, 'mentions');
  assert.equal(evaluate('hi', { mentions: 60 }, cfg()), null);
});

test('Spam: sechs Nachrichten in acht Sekunden, je Person getrennt', () => {
  const t = new SpamTracker({ limit: 6, windowMs: 8000 });
  for (let i = 0; i < 5; i++) assert.equal(t.record('g:a', `m${i}`, 1000 + i * 500), null);
  assert.deepEqual(t.record('g:a', 'm5', 3500), ['m0', 'm1', 'm2', 'm3', 'm4', 'm5']);
  assert.equal(t.record('g:a', 'm6', 3600), null); // neu angefangen
  // langsam schreiben löst nichts aus
  const slow = new SpamTracker({ limit: 6, windowMs: 8000 });
  for (let i = 0; i < 20; i++) assert.equal(slow.record('g:b', `s${i}`, i * 3000), null);
  // andere Person zählt nicht mit
  const two = new SpamTracker({ limit: 3, windowMs: 8000 });
  assert.equal(two.record('g:a', '1', 0), null);
  assert.equal(two.record('g:b', '2', 100), null);
  assert.equal(two.record('g:a', '3', 200), null);
  assert.equal(two.record('g:b', '4', 300), null);
});

test('Domains, Wörter und Listen pflegen', () => {
  assert.equal(normalizeDomain('https://www.YouTube.com/watch?v=1'), 'youtube.com');
  assert.equal(normalizeDomain('beispiel.de'), 'beispiel.de');
  assert.throws(() => normalizeDomain('kein domain'), /keine Domain/);
  assert.throws(() => normalizeDomain('localhost'), /keine Domain/);
  assert.equal(normalizeWord('  Wort '), 'wort');
  assert.throws(() => normalizeWord('x'), /2 bis 40/);
  assert.deepEqual(changeList(['a'], 'b', true, 3, 'x'), ['a', 'b']);
  assert.deepEqual(changeList(['a', 'b'], 'a', false, 3, 'x'), ['b']);
  assert.throws(() => changeList(['a'], 'a', true, 3, 'x'), /steht schon/);
  assert.throws(() => changeList(['a'], 'z', false, 3, 'x'), /steht nicht/);
  assert.throws(() => changeList(['a', 'b', 'c'], 'd', true, 3, 'der Wörter'), /höchstens 3/);
});

test('Übersicht: Hinweis, wenn der Bot Nachrichten noch nicht lesen darf', () => {
  assert.equal(anyRule(cfg()), false);
  assert.equal(anyRule(cfg({ words: ['x'] })), true);
  assert.match(configText(cfg({ spam: true, words: ['x'], domains: ['a.de'], exemptChannels: ['9'] }), true), /Spam: an[\s\S]*`x`[\s\S]*a\.de[\s\S]*<#9>/);
  assert.doesNotMatch(configText(cfg(), true), /Noch nicht aktiv/);
  assert.match(configText(cfg(), false), /BOT_MESSAGE_CONTENT=1/);
});

test('Nachrichten-Protokoll: gelöscht und geändert', () => {
  const author = { id: '7', globalName: 'Anna', username: 'anna' };
  const del = deletedEmbed({ author, channelId: '5', content: 'Hallo', attachments: new Map() });
  assert.match(del.description, /<@7> \(Anna\) in <#5>/);
  assert.equal(del.fields[0].value, 'Hallo');
  assert.equal(deletedEmbed({ author, channelId: '5', content: '', attachments: new Map([['a', {}]]) }).fields[0].value, '(nur Anhang)');
  assert.equal(deletedEmbed({ author, channelId: '5', content: 'x'.repeat(2000), attachments: new Map() }).fields[0].value.length <= 1000, true);
  const edit = editedEmbed({ content: 'alt' }, { author, channelId: '5', content: 'neu', url: 'https://discord.com/x' });
  assert.deepEqual(edit.fields.map((f) => f.value), ['alt', 'neu']);
});

test('/automod: Unterbefehle, Längen; Config-Schalter für Message Content', () => {
  assert.deepEqual(data.options.map((o) => o.name), ['anzeigen', 'schalter', 'erwaehnungen', 'wort', 'domain', 'ausnahme-kanal']);
  for (const sub of data.options) {
    assert.ok(sub.description.length <= 100, sub.name);
    for (const o of sub.options ?? []) assert.ok(o.description.length <= 100, `${sub.name}.${o.name}`);
  }
  const env = { DISCORD_BOT_TOKEN: 't', AUTH_DISCORD_ID: '1', DB_HOST: 'db', DB_USER: 'u', DB_PASSWORD: 'p' };
  assert.equal(readConfig(env).messageContent, false);
  assert.equal(readConfig({ ...env, BOT_MESSAGE_CONTENT: '1' }).messageContent, true);
  assert.equal(readConfig({ ...env, BOT_MESSAGE_CONTENT: '0' }).messageContent, false);
});
