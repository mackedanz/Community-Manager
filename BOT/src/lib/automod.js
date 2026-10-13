// Automatische Moderation: Regeln und Prüfung. Die Einstellungen je Orga stehen als JSON in bot_settings (name = automod),
// die Auswertung der Nachrichten macht events/automod.js. Alle Regeln sind zunächst aus; ein Admin schaltet sie mit /automod ein.
// Ausgenommen sind immer Admins, Moderatoren und Bots.
import { orgForGuild } from '../db.js';
import { OrgError } from './errors.js';
import { modRoleIds } from './moderation.js';
import { getSetting, setSetting } from './settings.js';

export const AUTOMOD_SETTING = 'automod';
export const MAX_WORDS = 50;
export const MAX_DOMAINS = 30;
export const MAX_EXEMPT_CHANNELS = 10;

export const RULE_LABELS = {
  spam: 'Spam (viele Nachrichten kurz hintereinander)',
  invites: 'Einladungslinks zu anderen Servern',
  links: 'Links (außer erlaubte Domains)',
};

export const DEFAULTS = Object.freeze({ spam: false, invites: false, links: false, mentions: 0, words: [], domains: [], exemptChannels: [] });

/** Einstellungen der Orga (fehlende oder kaputte Werte fallen auf die Standardwerte zurück). */
export async function loadConfig(db, orgId) {
  let stored = {};
  try {
    stored = JSON.parse((await getSetting(db, orgId, AUTOMOD_SETTING)) ?? '{}') ?? {};
  } catch {
    stored = {};
  }
  const list = (v) => (Array.isArray(v) ? v.filter((s) => typeof s === 'string') : []);
  return {
    spam: stored.spam === true,
    invites: stored.invites === true,
    links: stored.links === true,
    mentions: Number.isInteger(stored.mentions) && stored.mentions >= 0 ? stored.mentions : 0,
    words: list(stored.words),
    domains: list(stored.domains),
    exemptChannels: list(stored.exemptChannels),
  };
}

export async function saveConfig(db, orgId, cfg) {
  await setSetting(db, orgId, AUTOMOD_SETTING, JSON.stringify(cfg));
}

/** Ist irgendeine Regel eingeschaltet? */
export const anyRule = (cfg) => cfg.spam || cfg.invites || cfg.links || cfg.mentions > 0 || cfg.words.length > 0;

/** "https://www.Beispiel.de/seite" => "beispiel.de"; @throws {OrgError} bei ungültiger Angabe */
export function normalizeDomain(input) {
  const host = String(input ?? '').trim().toLowerCase().replace(/^[a-z]+:\/\//, '').replace(/^www\./, '').split(/[/?#:\s]/)[0];
  if (!/^[a-z0-9-]+(\.[a-z0-9-]+)*\.[a-z]{2,}$/.test(host)) throw new OrgError('Das ist keine Domain. Beispiel: youtube.com');
  return host;
}

/** Wort für die Liste: Kleinbuchstaben, 2 bis 40 Zeichen. @throws {OrgError} */
export function normalizeWord(input) {
  const w = String(input ?? '').trim().toLowerCase();
  if (w.length < 2 || w.length > 40) throw new OrgError('Ein Wort braucht 2 bis 40 Zeichen.');
  return w;
}

/** Trägt ein oder entfernt einen Eintrag einer Liste; liefert die neue Liste. @throws {OrgError} */
export function changeList(list, item, add, max, what) {
  if (add) {
    if (list.includes(item)) throw new OrgError(`„${item}“ steht schon in der Liste.`);
    if (list.length >= max) throw new OrgError(`Die Liste ${what} kann höchstens ${max} Einträge haben.`);
    return [...list, item];
  }
  if (!list.includes(item)) throw new OrgError(`„${item}“ steht nicht in der Liste.`);
  return list.filter((x) => x !== item);
}

const INVITE = /(?:discord\.gg|discord(?:app)?\.com\/invite|discord\.com\/invite)\/[a-z0-9-]{2,}/i;
const URL_RE = /(?:https?:\/\/|www\.)[^\s<>()]+/gi;
const escapeRegex = (s) => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

/** Hosts aller Links einer Nachricht (ohne "www."). */
export function linkHosts(text) {
  const out = [];
  for (const m of String(text).matchAll(URL_RE)) {
    try {
      out.push(new URL(m[0].startsWith('http') ? m[0] : `https://${m[0]}`).hostname.toLowerCase().replace(/^www\./, ''));
    } catch {
      // kein gültiger Link
    }
  }
  return out;
}

/**
 * Prüft den Text einer Nachricht gegen die Regeln (ohne Spam, der braucht den Verlauf).
 * @returns {{rule: string, reason: string} | null}
 */
export function evaluate(text, { mentions = 0 } = {}, cfg) {
  const t = String(text ?? '');
  if (cfg.invites && INVITE.test(t)) return { rule: 'invites', reason: 'Einladungslinks zu anderen Servern sind hier nicht erlaubt.' };
  if (cfg.links) {
    const blocked = linkHosts(t).find((h) => !cfg.domains.some((d) => h === d || h.endsWith(`.${d}`)));
    if (blocked) return { rule: 'links', reason: 'Links sind hier nur von erlaubten Seiten gestattet.' };
  }
  if (cfg.words.length > 0) {
    const lower = t.toLowerCase();
    const hit = cfg.words.find((w) => new RegExp(`(?<![\\p{L}\\p{N}])${escapeRegex(w)}(?![\\p{L}\\p{N}])`, 'u').test(lower));
    if (hit) return { rule: 'words', reason: 'Diese Nachricht enthält ein verbotenes Wort.' };
  }
  if (cfg.mentions > 0 && mentions > cfg.mentions) return { rule: 'mentions', reason: `Bitte nicht mehr als ${cfg.mentions} Personen oder Rollen in einer Nachricht erwähnen.` };
  return null;
}

/** Merkt sich die letzten Nachrichten je Person; meldet Spam, wenn zu viele in kurzer Zeit kommen. */
export class SpamTracker {
  constructor({ limit = 6, windowMs = 8000 } = {}) {
    this.limit = limit;
    this.windowMs = windowMs;
    this.seen = new Map(); // key => [{ id, at }]
  }

  /** @returns {string[] | null} IDs der Nachrichten des Schwalls, sobald das Limit erreicht ist, sonst null */
  record(key, id, now = Date.now()) {
    const recent = (this.seen.get(key) ?? []).filter((m) => now - m.at < this.windowMs);
    recent.push({ id, at: now });
    if (recent.length >= this.limit) {
      this.seen.delete(key);
      return recent.map((m) => m.id);
    }
    this.seen.set(key, recent);
    // alte Einträge anderer Personen gelegentlich wegräumen
    if (this.seen.size > 5000) for (const [k, v] of this.seen) if (now - v[v.length - 1].at > this.windowMs) this.seen.delete(k);
    return null;
  }
}

/** Ab so vielen Verstößen innerhalb einer Stunde gibt es automatisch einen Timeout. */
export const ESCALATE_AFTER = 3;
export const ESCALATE_TIMEOUT_MS = 10 * 60_000;

/** Von der Automod selbst gelöschte Nachrichten, damit das Nachrichten-Protokoll sie nicht noch einmal meldet. */
const selfDeleted = new Set();
export function markSelfDeleted(...ids) {
  for (const id of ids) {
    selfDeleted.add(id);
    setTimeout(() => selfDeleted.delete(id), 60_000).unref();
  }
}
export const wasSelfDeleted = (id) => selfDeleted.has(id);

/** Orga, Automod-Einstellungen und Moderatoren-Rollen eines Servers, 30 Sekunden zwischengespeichert (spart Abfragen je Nachricht). */
const cache = new Map();
const TTL_MS = 30_000;
export async function guildContext(db, guildId, now = Date.now()) {
  const hit = cache.get(guildId);
  if (hit && now - hit.at < TTL_MS) return hit.value;
  const org = await orgForGuild(db, guildId);
  const value = org === null ? null : { org, cfg: await loadConfig(db, org.id), modRoles: await modRoleIds(db, org.id) };
  cache.set(guildId, { at: now, value });
  return value;
}
export const forgetGuild = (guildId) => cache.delete(guildId);

/** Übersicht der Einstellungen als Text. */
export function configText(cfg, contentEnabled) {
  const on = (b) => (b ? 'an' : 'aus');
  const lines = [
    '**Automatische Moderation**',
    `Spam: ${on(cfg.spam)} · Einladungslinks: ${on(cfg.invites)} · Links: ${on(cfg.links)}`,
    `Massen-Erwähnungen: ${cfg.mentions > 0 ? `mehr als ${cfg.mentions} Erwähnungen` : 'aus'}`,
    `Verbotene Wörter: ${cfg.words.length ? cfg.words.map((w) => `\`${w}\``).join(', ') : 'keine'}`,
    `Erlaubte Domains (bei Links): ${cfg.domains.length ? cfg.domains.join(', ') : 'keine'}`,
    `Ausgenommene Kanäle: ${cfg.exemptChannels.length ? cfg.exemptChannels.map((c) => `<#${c}>`).join(', ') : 'keine'}`,
    'Admins und Moderatoren sind immer ausgenommen. Bei einem Verstoß wird die Nachricht gelöscht und als Fall gespeichert; ab '
      + `${ESCALATE_AFTER} Verstößen in einer Stunde gibt es ${ESCALATE_TIMEOUT_MS / 60_000} Minuten Timeout.`,
  ];
  if (!contentEnabled) {
    lines.push('\n⚠ **Noch nicht aktiv:** Der Bot darf Nachrichten noch nicht lesen. Im Developer Portal unter Bot „Message Content Intent“ einschalten, '
      + 'in der .env `BOT_MESSAGE_CONTENT=1` setzen und den Bot neu starten.');
  }
  return lines.join('\n');
}
