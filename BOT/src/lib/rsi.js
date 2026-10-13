// Abgleich mit der öffentlichen Mitgliederliste der Orga auf robertsspaceindustries.com (wie RsiOrg.php der PHP-App).
// RSI hat dafür keine offizielle API: es wird dieselbe Abfrage genutzt wie von der Orga-Seite.
// Schlägt sie fehl, bleibt der alte Stand erhalten.
import { nowDb, run, transaction, val } from '../db.js';
import { OrgError } from './errors.js';
import { cut } from './text.js';

const ENDPOINT = 'https://robertsspaceindustries.com/api/orgs/getOrgMembers';
/** RSI liefert höchstens 32 Mitglieder pro Seite, größere Werte führen zu leeren Seiten. */
const PAGE_SIZE = 32;
/** Obergrenze, damit eine riesige Orga den Abgleich nicht ewig laufen lässt (3200 Mitglieder). */
const MAX_PAGES = 100;

/** Wo im Nickname der Handle steht (Auswahl je Orga, Schlüssel in organizations.handle_style). */
export const HANDLE_STYLES = {
  plain: 'Außerhalb aller Klammern (z. B. „(Vorname) Handle [Discordname]“)',
  round: 'In runden Klammern (z. B. „Discordname (Handle)“)',
  square: 'In eckigen Klammern (z. B. „Discordname [Handle]“)',
  curly: 'In geschweiften Klammern (z. B. „Discordname {Handle}“)',
  angle: 'In spitzen Klammern (z. B. „Discordname <Handle>“)',
};

export function cleanHandleStyle(style) {
  return style != null && style in HANDLE_STYLES ? style : 'plain';
}

/** Kürzel bereinigen: nur Buchstaben, Ziffern, - und _, in Großbuchstaben. */
export function cleanSid(sid) {
  const s = sid.trim().toUpperCase();
  return /^[A-Z0-9_-]{2,20}$/.test(s) ? s : null;
}

const ENTITIES = { amp: '&', lt: '<', gt: '>', quot: '"', apos: "'", nbsp: ' ' };

function decodeEntities(s) {
  return s.replace(/&(#x[0-9a-f]+|#\d+|[a-z]+);/gi, (m, e) => {
    if (e[0] === '#') {
      const code = e[1] === 'x' || e[1] === 'X' ? parseInt(e.slice(2), 16) : parseInt(e.slice(1), 10);
      return Number.isFinite(code) ? String.fromCodePoint(code) : m;
    }
    return ENTITIES[e.toLowerCase()] ?? m;
  });
}

/** Liest Orga-Name und Mitglieder aus dem HTML einer Seite. */
export function parseMembersHtml(html) {
  let name = null;
  const members = [];
  for (const card of html.split(/(?=<li class="member-item)/)) {
    if (!card.startsWith('<li class="member-item')) continue;
    if (name === null) {
      const n = card.match(/data-org-name="([^"]*)"/);
      if (n) name = decodeEntities(n[1]).trim();
    }
    const cls = card.match(/^<li class="([^"]*)"/);
    const h = card.match(/href="\/citizens\/([^"/?#]+)"/);
    let handle = null;
    if (h) {
      try {
        handle = decodeURIComponent(h[1].replaceAll('+', ' '));
      } catch {
        handle = h[1];
      }
    }
    members.push({ handle, main: (cls?.[1] ?? '').includes('org-main') });
  }
  return { name, members };
}

/** Eine Seite der Mitgliederliste. @throws {OrgError} */
async function fetchPage(sid, page, fetchFn) {
  let json;
  try {
    const res = await fetchFn(ENDPOINT, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ symbol: sid, search: '', pagesize: PAGE_SIZE, page }),
      signal: AbortSignal.timeout(30_000),
    });
    json = res.status === 200 ? await res.json().catch(() => null) : null;
  } catch {
    throw new OrgError('RSI ist gerade nicht erreichbar.');
  }
  if (json === null || typeof json !== 'object' || json.success !== 1 || typeof json.data?.html !== 'string') {
    throw new OrgError(`Die Mitgliederliste von „${sid}“ lässt sich auf RSI nicht abrufen (Kürzel falsch oder Liste nicht öffentlich).`);
  }
  return { total: Number(json.data.totalrows ?? 0), ...parseMembersHtml(json.data.html) };
}

/** Prüft, ob es die Orga gibt, und holt ihren Namen (nur die erste Seite). @throws {OrgError} */
export async function lookup(sid, fetchFn = fetch) {
  const first = await fetchPage(sid, 1, fetchFn);
  if (first.total < 1 || first.name === null) {
    throw new OrgError(`Zu „${sid}“ hat RSI keine Orga mit sichtbaren Mitgliedern gefunden.`);
  }
  return { name: first.name, total: first.total };
}

/**
 * Speichert das Kürzel der Orga nach erfolgreicher Prüfung. Ein geändertes Kürzel verwirft den alten Stand;
 * der Abgleich selbst läuft separat (sync()).
 * @returns {Promise<string>} Name der RSI-Orga
 */
export async function connect(pool, orgId, sid, fetchFn = fetch) {
  const clean = cleanSid(sid);
  if (clean === null) throw new OrgError('Das RSI-Kürzel darf nur Buchstaben, Ziffern, - und _ enthalten (2 bis 20 Zeichen).');
  const info = await lookup(clean, fetchFn);
  const old = await val(pool, 'SELECT rsi_sid FROM organizations WHERE id = ?', [orgId]);
  if (old !== clean) {
    await run(pool, 'DELETE FROM org_rsi_members WHERE org_id = ?', [orgId]);
    await run(pool, 'UPDATE organizations SET rsi_redacted = 0, rsi_synced_at = NULL WHERE id = ?', [orgId]);
  }
  await run(pool, 'UPDATE organizations SET rsi_sid = ?, rsi_org_name = ? WHERE id = ?', [clean, cut(info.name, 190), orgId]);
  return info.name;
}

/**
 * Holt die ganze Mitgliederliste und ersetzt den gespeicherten Stand. Bei einem Fehler bleibt der alte erhalten.
 * @returns {Promise<{members: number, redacted: number}>}
 */
export async function sync(pool, orgId, { fetchFn = fetch, pauseMs = 300 } = {}) {
  const sid = await val(pool, 'SELECT rsi_sid FROM organizations WHERE id = ?', [orgId]);
  if (typeof sid !== 'string' || sid === '') throw new OrgError('Für diese Orga ist kein RSI-Kürzel hinterlegt.');
  const members = new Map();
  let redacted = 0;
  let total = 0;
  for (let page = 1; page <= MAX_PAGES; page++) {
    // Pause zwischen zwei Seiten, aus Rücksicht auf RSI
    if (page > 1 && pauseMs > 0) await new Promise((r) => setTimeout(r, pauseMs));
    const p = await fetchPage(sid, page, fetchFn);
    total = p.total;
    for (const m of p.members) {
      if (m.handle === null) redacted++;
      else members.set(m.handle.toLowerCase(), m);
    }
    if (p.members.length === 0 || page * PAGE_SIZE >= total) break;
  }
  if (total > 0 && members.size === 0 && redacted === 0) {
    throw new OrgError('RSI hat keine Mitglieder geliefert; der alte Stand bleibt erhalten.');
  }
  await transaction(pool, async (conn) => {
    await run(conn, 'DELETE FROM org_rsi_members WHERE org_id = ?', [orgId]);
    for (const m of members.values()) {
      await run(conn, 'INSERT INTO org_rsi_members (org_id, handle, main) VALUES (?,?,?)', [orgId, cut(m.handle, 100), m.main ? 1 : 0]);
    }
    await run(conn, 'UPDATE organizations SET rsi_redacted = ?, rsi_synced_at = ? WHERE id = ?', [redacted, nowDb(), orgId]);
  });
  return { members: members.size, redacted };
}
