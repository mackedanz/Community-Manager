// Aussehen der Installation: Logo, Hintergrundbild und Deckkraft des Hintergrunds (wie Branding.php der PHP-App).
// Die Einstellungen stehen in app_settings, die Bilder im Bilderordner der Web-App unter brand/ (gemeinsames Volume).
// Bilder werden nur von Discords Bildservern geladen, geprüft, neu kodiert (PNG bzw. JPEG) und unter ihrem
// Inhalts-Hash abgelegt.
import { createHash, randomBytes } from 'node:crypto';
import { mkdir, rename, stat, unlink, writeFile } from 'node:fs/promises';
import path from 'node:path';
import sharp from 'sharp';
import { run, val } from '../db.js';
import { OrgError } from './errors.js';

export const DEFAULT_DARK = 10;
export const DEFAULT_LIGHT = 40;
export const MAX_BYTES = 5 * 1024 * 1024;
const MAX_SIDE = 4000;
const MIN_SIDE = 64;
const LOGO_SIDE = 256;
const BACKGROUND_WIDTH = 2000;
/** Von hier lädt der Bot Anhänge (Discord-Bildserver). */
export const HOSTS = ['cdn.discordapp.com', 'media.discordapp.net'];
const FILE = /^(logo|bg)-[a-f0-9]{16}\.(png|jpg)$/;

const dir = (imageDir) => path.join(imageDir, 'brand');

async function get(db, name) {
  const v = await val(db, 'SELECT value FROM app_settings WHERE name = ?', [name]);
  return typeof v === 'string' ? v : null;
}

async function put(db, name, value) {
  if (value === null) {
    await run(db, 'DELETE FROM app_settings WHERE name = ?', [name]);
    return;
  }
  await run(db, 'INSERT INTO app_settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', [name, value]);
}

/** Pfad einer gespeicherten Branding-Datei (nur gültige Namen, nur vorhandene). */
async function filePath(imageDir, file) {
  if (!FILE.test(file)) return null;
  const p = path.join(dir(imageDir), file);
  try {
    return (await stat(p)).isFile() ? p : null;
  } catch {
    return null;
  }
}

/** Aktueller Stand: eigenes Logo/eigener Hintergrund vorhanden? Deckkraft dunkel und hell in Prozent. */
export async function current(db, imageDir) {
  const r = { logoCustom: false, backgroundCustom: false, opacityDark: DEFAULT_DARK, opacityLight: DEFAULT_LIGHT };
  const logo = await get(db, 'brand_logo');
  r.logoCustom = logo !== null && (await filePath(imageDir, logo)) !== null;
  const bg = await get(db, 'brand_background');
  r.backgroundCustom = bg !== null && (await filePath(imageDir, bg)) !== null;
  for (const [key, name] of [['opacityDark', 'brand_opacity_dark'], ['opacityLight', 'brand_opacity_light']]) {
    const v = await get(db, name);
    if (v !== null && /^\d+$/.test(v) && Number(v) <= 100) r[key] = Number(v);
  }
  return r;
}

function hostAllowed(url) {
  try {
    const u = new URL(url);
    return u.protocol === 'https:' && HOSTS.includes(u.hostname.toLowerCase());
  } catch {
    return false;
  }
}

/** Lädt einen Discord-Anhang (nur von Discords Bildservern, höchstens 5 MB). @throws {OrgError} */
export async function fetchImage(url, fetchFn = fetch) {
  const fail = () => new OrgError('Das Bild konnte nicht von Discord geladen werden (erlaubt: PNG, JPG, WebP bis 5 MB).');
  for (let i = 0; i < 5; i++) {
    if (!hostAllowed(url)) throw fail();
    let res;
    try {
      res = await fetchFn(url, { redirect: 'manual', headers: { Accept: 'image/*' }, signal: AbortSignal.timeout(15_000) });
    } catch {
      throw fail();
    }
    if (res.status >= 300 && res.status < 400 && res.headers.get('location')) {
      url = new URL(res.headers.get('location'), url).href;
      continue;
    }
    if (res.status !== 200 || Number(res.headers.get('content-length') ?? 0) > MAX_BYTES) throw fail();
    const body = Buffer.from(await res.arrayBuffer());
    if (body.length === 0 || body.length > MAX_BYTES) throw fail();
    return body;
  }
  throw fail();
}

/**
 * Prüft ein Bild und kodiert es neu: Logo höchstens 256 Pixel (PNG, mit Transparenz),
 * Hintergrund höchstens 2000 Pixel breit (JPEG, Transparenz auf Schwarz).
 * @param {'logo'|'background'} kind @returns {Promise<{body: Buffer, ext: 'png'|'jpg'}>} @throws {OrgError}
 */
export async function processImage(kind, bytes) {
  if (bytes.length === 0 || bytes.length > MAX_BYTES) throw new OrgError('Das Bild ist größer als 5 MB.');
  let meta;
  try {
    meta = await sharp(bytes).metadata();
  } catch {
    throw new OrgError('Erlaubt sind PNG, JPG und WebP.');
  }
  if (!['jpeg', 'png', 'webp'].includes(meta.format)) throw new OrgError('Erlaubt sind PNG, JPG und WebP.');
  const { width: w, height: h } = meta;
  if (w > MAX_SIDE || h > MAX_SIDE) throw new OrgError(`Das Bild ist zu groß (höchstens ${MAX_SIDE} × ${MAX_SIDE} Pixel).`);
  if (w < MIN_SIDE || h < MIN_SIDE) throw new OrgError(`Das Bild ist zu klein (mindestens ${MIN_SIDE} × ${MIN_SIDE} Pixel).`);
  try {
    // Nur das erste Bild (animierte WebP) und ohne Metadaten wie Standort oder Kamera
    const img = sharp(bytes, { pages: 1 }).rotate();
    if (kind === 'logo') {
      const body = await img.resize(LOGO_SIDE, LOGO_SIDE, { fit: 'inside', withoutEnlargement: true }).png({ compressionLevel: 9 }).toBuffer();
      return { body, ext: 'png' };
    }
    const body = await img.resize({ width: BACKGROUND_WIDTH, withoutEnlargement: true }).flatten({ background: '#000000' }).jpeg({ quality: 85 }).toBuffer();
    return { body, ext: 'jpg' };
  } catch {
    throw new OrgError('Das Bild lässt sich nicht lesen.');
  }
}

/** Setzt die Einstellung und löscht die zuvor gespeicherte Datei. */
async function swap(db, imageDir, setting, file) {
  const old = await get(db, setting);
  await put(db, setting, file);
  if (old !== null && old !== file) {
    const p = await filePath(imageDir, old);
    if (p !== null) await unlink(p).catch(() => {});
  }
}

/** Speichert ein neues Logo oder Hintergrundbild. @param {'logo'|'background'} kind @throws {OrgError} */
export async function setImage(db, imageDir, kind, bytes) {
  if (kind !== 'logo' && kind !== 'background') throw new OrgError('Unbekannte Auswahl.');
  const img = await processImage(kind, bytes);
  const file = `${kind === 'logo' ? 'logo' : 'bg'}-${createHash('sha1').update(img.body).digest('hex').slice(0, 16)}.${img.ext}`;
  const target = path.join(dir(imageDir), file);
  const tmp = `${target}.${randomBytes(4).toString('hex')}.tmp`;
  try {
    await mkdir(dir(imageDir), { recursive: true, mode: 0o775 });
  } catch {
    throw new OrgError('Der Bildordner ist nicht beschreibbar.');
  }
  try {
    await writeFile(tmp, img.body, { mode: 0o644 });
    await rename(tmp, target);
  } catch {
    await unlink(tmp).catch(() => {});
    throw new OrgError('Das Bild konnte nicht gespeichert werden.');
  }
  await swap(db, imageDir, `brand_${kind}`, file);
}

/** Setzt Logo, Hintergrund oder alles (samt Deckkraft) auf die Standardwerte zurück. */
export async function reset(db, imageDir, what) {
  if (!['logo', 'background', 'all'].includes(what)) throw new OrgError('Unbekannte Auswahl.');
  if (what !== 'background') await swap(db, imageDir, 'brand_logo', null);
  if (what !== 'logo') await swap(db, imageDir, 'brand_background', null);
  if (what === 'all') {
    await put(db, 'brand_opacity_dark', null);
    await put(db, 'brand_opacity_light', null);
  }
}

/** Deckkraft in Prozent, getrennt für dunkel und hell; null lässt den Wert unverändert. @throws {OrgError} */
export async function setOpacity(db, dark, light) {
  for (const v of [dark, light]) {
    if (v !== null && (v < 0 || v > 100)) throw new OrgError('Die Deckkraft muss zwischen 0 und 100 Prozent liegen.');
  }
  if (dark !== null) await put(db, 'brand_opacity_dark', String(dark));
  if (light !== null) await put(db, 'brand_opacity_light', String(light));
}
