import { test } from 'node:test';
import assert from 'node:assert/strict';
import { parseChannelPage, parseFeed, parseInput, resolveChannel } from '../src/lib/youtube.js';
import { videoMessage } from '../src/lib/creators.js';
import { creatorsEmbed } from '../src/commands/creator.js';

const UC = 'UCTeLqJq1mXUX5WWoNXLmOIA';

// Nachgebauter Feed im Format von YouTube (zwei Einträge, neuestes zuerst)
const feedXml = `<?xml version="1.0" encoding="UTF-8"?>
<feed xmlns:yt="http://www.youtube.com/xml/schemas/2015" xmlns="http://www.w3.org/2005/Atom">
 <title>Test &amp; Co</title>
 <author>
  <name>Test &amp; Co</name>
  <uri>https://www.youtube.com/channel/${UC}</uri>
 </author>
 <entry>
  <id>yt:video:short000001</id>
  <yt:videoId>short000001</yt:videoId>
  <title>Kurz &amp; knapp</title>
  <link rel="alternate" href="https://www.youtube.com/shorts/short000001"/>
  <published>2026-10-12T10:00:00+00:00</published>
 </entry>
 <entry>
  <id>yt:video:video000001</id>
  <yt:videoId>video000001</yt:videoId>
  <title>Langes Video</title>
  <link rel="alternate" href="https://www.youtube.com/watch?v=video000001"/>
  <published>2026-10-11T10:00:00+00:00</published>
 </entry>
</feed>`;

test('Eingabe erkennen: Kanal-ID, Link, @Name', () => {
  assert.deepEqual(parseInput(UC), { channelId: UC });
  assert.deepEqual(parseInput(`https://www.youtube.com/channel/${UC}/videos`), { channelId: UC });
  assert.deepEqual(parseInput('@RobertsSpaceInd'), { handle: 'RobertsSpaceInd' });
  assert.deepEqual(parseInput('https://www.youtube.com/@RobertsSpaceInd/featured'), { handle: 'RobertsSpaceInd' });
  assert.deepEqual(parseInput('youtube.com/@mein.kanal'), { handle: 'mein.kanal' });
  assert.equal(parseInput('https://twitch.tv/irgendwer'), null);
  assert.equal(parseInput(''), null);
});

test('Kanal-ID und Name aus der Kanalseite', () => {
  const html = `<html><link rel="canonical" href="https://www.youtube.com/channel/${UC}"><meta property="og:title" content="Star &amp; Citizen"></html>`;
  assert.deepEqual(parseChannelPage(html), { channelId: UC, name: 'Star & Citizen' });
  assert.deepEqual(parseChannelPage(`"externalId":"${UC}"`), { channelId: UC, name: null });
  assert.deepEqual(parseChannelPage('<html></html>'), { channelId: null, name: null });
});

test('Feed lesen: Videos, Shorts, Namen', () => {
  const f = parseFeed(feedXml);
  assert.equal(f.name, 'Test & Co');
  assert.deepEqual(f.videos.map((v) => [v.id, v.title, v.short]), [['short000001', 'Kurz & knapp', true], ['video000001', 'Langes Video', false]]);
  assert.equal(f.videos[1].url, 'https://www.youtube.com/watch?v=video000001');
});

const response = (status, body) => ({ status, text: async () => body });

test('Kanal auflösen: @Name über die Kanalseite, dann Feed', async () => {
  const urls = [];
  const fetchFn = async (url) => {
    urls.push(url);
    if (url.includes('/@')) return response(200, `<link rel="canonical" href="https://www.youtube.com/channel/${UC}">`);
    return response(200, feedXml);
  };
  assert.deepEqual(await resolveChannel('@Test', fetchFn), { channelId: UC, name: 'Test & Co' });
  assert.deepEqual(urls, ['https://www.youtube.com/@Test', `https://www.youtube.com/feeds/videos.xml?channel_id=${UC}`]);
  await assert.rejects(resolveChannel('@Fehlt', async () => response(404, '')), /gibt es nicht/);
  await assert.rejects(resolveChannel(UC, async () => response(404, '')), /gibt es nicht/);
  await assert.rejects(resolveChannel('kein link', fetchFn), /nicht nach einem YouTube-Kanal/);
  await assert.rejects(resolveChannel(UC, async () => { throw new Error('offline'); }), /nicht erreichbar/);
});

test('Nachricht zu einem neuen Video, mit und ohne Rolle', () => {
  const v = { title: 'Langes Video', url: 'https://www.youtube.com/watch?v=video000001', short: false };
  const plain = videoMessage({ name: 'Test', mention_role_id: null }, v);
  assert.equal(plain.content, '**Test** hat ein neues Video veröffentlicht: **Langes Video**\nhttps://www.youtube.com/watch?v=video000001');
  assert.deepEqual(plain.allowedMentions, { parse: [] });
  const ping = videoMessage({ name: 'Test', mention_role_id: '42' }, { ...v, short: true });
  assert.match(ping.content, /^<@&42> \*\*Test\*\* hat einen neuen Short/);
  assert.deepEqual(ping.allowedMentions, { roles: ['42'] });
});

test('Übersicht der Creator', () => {
  const e = creatorsEmbed({ name: 'SpaceTrotter' }, [
    { name: 'Test', channel_id: UC, discord_channel_id: '9', mention_role_id: '42', skip_shorts: 1, checked_at: '2026-10-13 10:00:00', last_error: null },
    { name: 'Weg', channel_id: UC, discord_channel_id: '9', mention_role_id: null, skip_shorts: 0, checked_at: null, last_error: 'Diesen YouTube-Kanal gibt es nicht (mehr).' },
  ]);
  assert.match(e.title, /\(2\/50\)/);
  assert.match(e.description, /\*\*\[Test\]\(https:\/\/www\.youtube\.com\/channel\/UC.+\)\*\* → <#9> \(erwähnt <@&42>, ohne Shorts\) – geprüft <t:\d+:R>/);
  assert.match(e.description, /Weg.*⚠ Diesen YouTube-Kanal gibt es nicht/);
  assert.match(creatorsEmbed({ name: 'S' }, []).description, /Noch keine Creator/);
});
