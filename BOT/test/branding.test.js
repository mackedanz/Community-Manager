import { test } from 'node:test';
import assert from 'node:assert/strict';
import sharp from 'sharp';
import { fetchImage, processImage } from '../src/lib/branding.js';

const image = (width, height, format = 'png') =>
  sharp({ create: { width, height, channels: 4, background: { r: 200, g: 50, b: 50, alpha: 0.5 } } })[format]().toBuffer();

test('Logo wird auf höchstens 256 Pixel verkleinert und bleibt PNG mit Transparenz', async () => {
  const r = await processImage('logo', await image(1000, 500));
  assert.equal(r.ext, 'png');
  const m = await sharp(r.body).metadata();
  assert.deepEqual([m.format, m.width, m.height, m.hasAlpha], ['png', 256, 128, true]);
});

test('Hintergrund wird JPEG mit höchstens 2000 Pixel Breite', async () => {
  const r = await processImage('background', await image(3000, 1000, 'webp'));
  const m = await sharp(r.body).metadata();
  assert.deepEqual([r.ext, m.format, m.width, m.height], ['jpg', 'jpeg', 2000, 667]);
  const small = await sharp((await processImage('background', await image(800, 600))).body).metadata();
  assert.equal(small.width, 800);
});

test('ungeeignete Bilder werden abgelehnt', async () => {
  await assert.rejects(processImage('logo', await image(32, 32)), /zu klein/);
  await assert.rejects(processImage('logo', Buffer.from('kein Bild')), /Erlaubt sind PNG, JPG und WebP/);
  await assert.rejects(processImage('logo', await sharp({ create: { width: 100, height: 100, channels: 3, background: '#000' } }).gif().toBuffer()), /Erlaubt sind/);
});

const response = (status, { body = Buffer.alloc(0), location = null } = {}) => ({
  status,
  headers: new Headers(location ? { location } : {}),
  arrayBuffer: async () => body,
});

test('Bilder nur von Discords Bildservern laden', async () => {
  const body = Buffer.from('bild');
  const calls = [];
  const fetchFn = async (url) => {
    calls.push(url);
    return url.includes('/umleitung') ? response(302, { location: '/ziel.png' }) : response(200, { body });
  };
  assert.deepEqual(await fetchImage('https://cdn.discordapp.com/umleitung', fetchFn), body);
  assert.deepEqual(calls, ['https://cdn.discordapp.com/umleitung', 'https://cdn.discordapp.com/ziel.png']);

  await assert.rejects(fetchImage('https://example.com/a.png', fetchFn), /nicht von Discord geladen/);
  await assert.rejects(fetchImage('http://cdn.discordapp.com/a.png', fetchFn), /nicht von Discord geladen/);
  const away = async () => response(302, { location: 'https://example.com/x.png' });
  await assert.rejects(fetchImage('https://cdn.discordapp.com/a.png', away), /nicht von Discord geladen/);
  await assert.rejects(fetchImage('https://cdn.discordapp.com/a.png', async () => response(404)), /nicht von Discord geladen/);
});
