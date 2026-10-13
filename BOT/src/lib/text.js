// Gleiche Ergebnisse wie die Hilfsfunktionen der PHP-App (helpers.php, Text.php), damit IDs und Adressen zusammenpassen.
import { randomBytes } from 'node:crypto';

/** Neue ID im Format der App: 24 Hex-Zeichen (new_id() in helpers.php). */
export function newId() {
  return randomBytes(12).toString('hex');
}

/** Adressteil aus einem Namen (Text::slugify): "Ärger & Co." => "aerger-co". */
export function slugify(name, max = 40, fallback = 'orga') {
  let s = name.toLowerCase().replaceAll('ä', 'ae').replaceAll('ö', 'oe').replaceAll('ü', 'ue').replaceAll('ß', 'ss');
  s = s.normalize('NFKD').replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
  s = s.slice(0, max).replace(/^-+|-+$/g, '');
  return s === '' ? fallback : s;
}

/** Kürzt auf höchstens n Zeichen (wie mb_substr). */
export function cut(s, n) {
  return Array.from(s).slice(0, n).join('');
}

/** Zeichenlänge (wie mb_strlen). */
export function len(s) {
  return Array.from(s).length;
}
