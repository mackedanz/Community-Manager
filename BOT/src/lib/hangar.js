// Hangar, Orga-Flotte, Profil und "Wer hat …?" (wie Hangar.php und Community.php der Web-App).
//
// Sichtbarkeit wie in Visibility.php: Den eigenen Hangar sieht man immer. Den Hangar anderer nur, wenn sie ihn für
// Mitglieder freigegeben haben (hangar_visibility = 'MEMBERS') und sie Mitglied dieser Orga sind. Errungenschaften
// genauso mit achievements_visibility. Die Orga-Flotte ist anonym (nur Schiff und Anzahl) und gilt für alle Mitglieder.
import { all, one, val } from '../db.js';

export const KIND_LABELS = {
  SHIP: 'Schiffe', ARMOR: 'Rüstungen', WEAPON: 'Waffen', ITEM: 'Sonstiges (Loot, Ausrüstung)', UPGRADE: 'Upgrades (CCU)', PAINT: 'Paints & Skins',
};

/** Eigene bzw. sichtbare Einträge eines Kontos, mit Namen aus dem Katalog. */
export async function hangarItems(db, userId, { shipsOnly = false } = {}) {
  const rows = await all(
    db,
    `SELECT o.kind, o.quantity, o.lti, o.custom_name, c.name AS c_name, c.manufacturer
       FROM owned_items o LEFT JOIN catalog_items c ON c.id = o.catalog_item_id
      WHERE o.user_id = ?${shipsOnly ? " AND o.kind = 'SHIP'" : ''} ORDER BY o.kind ASC, o.created_at ASC, o.id ASC`,
    [userId],
  );
  return rows.map((r) => ({
    kind: r.kind, name: r.c_name ?? r.custom_name ?? 'Unbekannt', manufacturer: r.manufacturer,
    quantity: Number(r.quantity), lti: Boolean(Number(r.lti)),
  }));
}

/** Gleiche Schiffe zusammenfassen: Name => {Anzahl, davon LTI}. Sortiert nach Name. */
export function groupShips(items) {
  const map = new Map();
  for (const i of items.filter((x) => x.kind === 'SHIP')) {
    const g = map.get(i.name) ?? { name: i.name, manufacturer: i.manufacturer, count: 0, lti: 0 };
    g.count += i.quantity;
    if (i.lti) g.lti += i.quantity;
    map.set(i.name, g);
  }
  return [...map.values()].sort((a, b) => a.name.localeCompare(b.name, 'de', { sensitivity: 'base' }));
}

/** Anzahl je Art (Schiffe, Rüstungen, …) in der Reihenfolge von KIND_LABELS. */
export function countByKind(items) {
  const out = {};
  for (const i of items) out[i.kind] = (out[i.kind] ?? 0) + i.quantity;
  return Object.keys(KIND_LABELS).filter((k) => out[k]).map((k) => ({ kind: k, label: KIND_LABELS[k], count: out[k] }));
}

/** Konto und Mitgliedschaft in dieser Orga zu einer Discord-ID. */
export async function memberAccount(db, orgId, discordId) {
  return one(
    db,
    `SELECT u.id, u.name, u.rsi_handle, u.hangar_visibility, u.achievements_visibility, m.nick, (m.user_id IS NOT NULL) AS in_org
       FROM users u LEFT JOIN org_memberships m ON m.user_id = u.id AND m.org_id = ?
      WHERE u.discord_id = ?`,
    [orgId, discordId],
  );
}

/** Darf der Betrachter Hangar bzw. Errungenschaften des Kontos sehen? */
export function canView(account, field, self) {
  if (self) return true;
  return Boolean(Number(account.in_org)) && account[field] === 'MEMBERS';
}

/** Anonyme Flotte der Orga: alle Schiffe aller Mitglieder, nur Name und Anzahl (Community::getOrgFleet). */
export async function orgFleet(db, orgId) {
  const inOrg = 'o.user_id IN (SELECT user_id FROM org_memberships WHERE org_id = ?)';
  const rows = await all(
    db,
    `SELECT COALESCE(c.name, o.custom_name) AS name, c.manufacturer, SUM(o.quantity) AS q
       FROM owned_items o LEFT JOIN catalog_items c ON c.id = o.catalog_item_id
      WHERE o.kind = 'SHIP' AND (c.id IS NOT NULL OR o.custom_name IS NOT NULL) AND ${inOrg}
      GROUP BY COALESCE(c.name, o.custom_name), c.manufacturer`,
    [orgId],
  );
  const entries = rows
    .map((r) => ({ name: r.name, manufacturer: r.manufacturer, count: Number(r.q) }))
    .sort((a, b) => b.count - a.count || a.name.localeCompare(b.name, 'de', { sensitivity: 'base' }));
  const owners = Number(await val(db, `SELECT COUNT(DISTINCT o.user_id) FROM owned_items o WHERE o.kind = 'SHIP' AND ${inOrg}`, [orgId]));
  return { entries, totalShips: entries.reduce((s, e) => s + e.count, 0), owners };
}

/** Schiffe aus dem Katalog für die Autovervollständigung von /wer-hat. */
export async function searchShips(db, term, take = 25) {
  const like = `%${term.replace(/[\\%_]/g, (m) => `\\${m}`)}%`;
  return all(
    db,
    `SELECT id, name, manufacturer FROM catalog_items WHERE kind = 'SHIP' AND (name LIKE ? OR manufacturer LIKE ?)
      ORDER BY (name LIKE ?) DESC, name ASC LIMIT ${Number(take)}`,
    [like, like, `${term.replace(/[\\%_]/g, (m) => `\\${m}`)}%`],
  );
}

/**
 * Wer in dieser Orga hat das Schiff? Nur Mitglieder mit freigegebenem Hangar und der Betrachter selbst
 * (Community::getOwners, auf die Orga des Servers beschränkt).
 */
export async function shipOwners(db, orgId, catalogItemId, viewerUserId) {
  const rows = await all(
    db,
    `SELECT u.id, COALESCE(m.nick, u.name) AS name, u.discord_id, SUM(o.quantity) AS q, MAX(o.lti) AS lti
       FROM owned_items o JOIN users u ON u.id = o.user_id JOIN org_memberships m ON m.user_id = u.id AND m.org_id = ?
      WHERE o.catalog_item_id = ? AND (u.hangar_visibility = 'MEMBERS' OR u.id = ?)
      GROUP BY u.id, COALESCE(m.nick, u.name), u.discord_id`,
    [orgId, catalogItemId, viewerUserId ?? ''],
  );
  return rows
    .map((r) => ({ userId: r.id, name: r.name ?? 'Unbekannt', discordId: r.discord_id, quantity: Number(r.q), lti: Boolean(Number(r.lti)) }))
    .sort((a, b) => a.name.localeCompare(b.name, 'de', { sensitivity: 'base' }));
}

/** Errungenschaften eines Kontos (Titel, in der Reihenfolge des Erwerbs). */
export async function achievements(db, userId) {
  return all(
    db,
    'SELECT a.title, ua.earned_at FROM user_achievements ua JOIN achievements a ON a.id = ua.achievement_id WHERE ua.user_id = ? ORDER BY ua.earned_at ASC',
    [userId],
  );
}

/** Zeitpunkt des letzten RSI-Syncs oder null. */
export async function lastSync(db, userId) {
  return val(db, 'SELECT created_at FROM import_logs WHERE user_id = ? ORDER BY created_at DESC LIMIT 1', [userId]);
}

// ----- RSI-Handle (wie RsiOrg::handleFromName und Community::rsiHandleOf) -----

const GROUP_PATTERNS = { round: /\(([^()]*)\)/gu, square: /\[([^[\]]*)\]/gu, curly: /\{([^{}]*)\}/gu, angle: /<([^<>]*)>/gu };
const validHandle = (h) => (/^[A-Za-z0-9_-]{2,100}$/.test(h) ? h : null);

/** RSI-Handle aus einem Discord-Namen; style sagt, wo er steht (plain = außerhalb aller Klammern). */
export function handleFromName(name, style = 'plain') {
  if (name == null) return null;
  const pattern = GROUP_PATTERNS[style];
  if (pattern) {
    for (const m of name.matchAll(pattern)) {
      const h = validHandle(m[1].trim());
      if (h) return h;
    }
    return null;
  }
  let h = name.trim();
  let before;
  do {
    before = h;
    h = h.replace(/\s*[([{<][^)\]}>]*[)\]}>]\s*$/u, '').replace(/^\s*[([{<][^)\]}>]*[)\]}>]\s*/u, '').trim();
  } while (h !== before);
  return validHandle(h);
}

/**
 * RSI-Handle für den Link zum RSI-Profil: der per RSI-Sync übernommene, sonst einer aus den Discord-Namen, der in der
 * Mitgliederliste einer RSI-Orga vorkommt; sonst der Handle aus dem Nickname, wenn eine der Orgas ein RSI-Kürzel hat.
 */
export async function rsiHandleOf(db, account) {
  if (account.rsi_handle) return account.rsi_handle;
  const rows = await all(db, 'SELECT m.org_id, m.nick, o.handle_style, o.rsi_sid FROM org_memberships m JOIN organizations o ON o.id = m.org_id WHERE m.user_id = ?', [account.id]);
  if (rows.length === 0) return null;
  const nickHandles = rows.map((r) => handleFromName(r.nick, r.handle_style ?? 'plain'));
  const nickHandle = nickHandles.find((h) => h !== null) ?? null;
  const found = nickHandles.filter(Boolean);
  // Der Kontoname zählt nur, wenn der Nickname keinen Handle ergibt
  const cands = [...new Set(found.length > 0 ? found : [handleFromName(account.name)].filter(Boolean))];
  if (cands.length === 0) return null;
  const orgIds = rows.map((r) => r.org_id);
  const h = await val(
    db,
    `SELECT handle FROM org_rsi_members WHERE org_id IN (${orgIds.map(() => '?').join(',')}) AND handle IN (${cands.map(() => '?').join(',')}) LIMIT 1`,
    [...orgIds, ...cands],
  );
  if (typeof h === 'string') return h;
  return rows.some((r) => r.rsi_sid) ? nickHandle : null;
}
