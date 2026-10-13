// Moderation: Fälle (Tabelle bot_mod_cases, Migration 023), Moderatoren-Rollen, Protokoll-Kanal und die Prüfungen,
// wer wen moderieren darf. Die Befehle liegen in commands/mod.js, die Einstellungen in commands/mod-einstellungen.js.
import { DiscordAPIError } from 'discord.js';
import { all, nowDb, one, run } from '../db.js';
import { OrgError } from './errors.js';
import { parseRoleIds } from './roles.js';
import { getSetting } from './settings.js';
import { cut } from './text.js';

export const MOD_ROLES = 'mod_role_ids';
export const MOD_LOG_CHANNEL = 'mod_log_channel_id';
export const MAX_MOD_ROLES = 10;
/** Discord erlaubt Timeouts bis 28 Tage. */
export const MAX_TIMEOUT_MS = 28 * 24 * 3600_000;

export const ACTIONS = {
  WARN: 'Verwarnung',
  TIMEOUT: 'Timeout',
  UNTIMEOUT: 'Timeout aufgehoben',
  KICK: 'Kick',
  BAN: 'Bann',
  UNBAN: 'Bann aufgehoben',
  PURGE: 'Nachrichten gelöscht',
  AUTOMOD: 'Automod',
};
const COLORS = { WARN: 0xf59e0b, TIMEOUT: 0xf97316, UNTIMEOUT: 0x22c55e, KICK: 0xef4444, BAN: 0xb91c1c, UNBAN: 0x22c55e, PURGE: 0x71717a, AUTOMOD: 0x8b5cf6 };

const UNITS = { m: 60_000, min: 60_000, h: 3600_000, std: 3600_000, d: 86_400_000, t: 86_400_000, w: 7 * 86_400_000 };

/**
 * "30m", "2h", "1d", "1w" (auch "10 min", "3 t") als Millisekunden; ohne Einheit Minuten.
 * @throws {OrgError} bei ungültiger Angabe oder mehr als 28 Tagen
 */
export function parseDuration(text) {
  const m = /^(\d{1,6})\s*([a-zäöü]*)$/i.exec(String(text ?? '').trim());
  const unit = m ? (m[2] === '' ? 'm' : m[2].toLowerCase()) : null;
  if (!m || !(unit in UNITS) || Number(m[1]) < 1) {
    throw new OrgError('Die Dauer kenne ich nicht. Beispiele: 30m, 2h, 1d, 1w (höchstens 28 Tage).');
  }
  const ms = Number(m[1]) * UNITS[unit];
  if (ms > MAX_TIMEOUT_MS) throw new OrgError('Ein Timeout dauert höchstens 28 Tage.');
  return ms;
}

/** 5400000 => "1 Stunde 30 Minuten" */
export function durationText(ms) {
  const parts = [];
  let rest = Math.round(ms / 60_000);
  for (const [name, plural, size] of [['Tag', 'Tage', 1440], ['Stunde', 'Stunden', 60], ['Minute', 'Minuten', 1]]) {
    const n = Math.floor(rest / size);
    rest -= n * size;
    if (n > 0) parts.push(`${n} ${n === 1 ? name : plural}`);
  }
  return parts.join(' ') || 'weniger als 1 Minute';
}

export async function modRoleIds(db, orgId) {
  return parseRoleIds(await getSetting(db, orgId, MOD_ROLES));
}

/** Darf die Person moderieren? Server-Admins immer, sonst mit einer Moderatoren-Rolle. */
export const canModerate = ({ admin, roles }, modRoles) => admin || modRoles.some((r) => roles.includes(r));

/**
 * Darf der Moderator diese Person moderieren? Liefert den Grund als Text, sonst null.
 * Admins dürfen alle außer dem Eigentümer und sich selbst; Moderatoren nur Mitglieder mit niedrigerer Rolle,
 * keine Admins und keine anderen Moderatoren.
 */
export function targetProblem(c) {
  if (c.actorId === c.targetId) return 'Du kannst dich nicht selbst moderieren.';
  if (c.targetIsSelf) return 'Den Bot selbst kann man nicht moderieren.';
  if (c.targetIsOwner) return 'Der Server-Eigentümer lässt sich nicht moderieren.';
  if (c.actorAdmin) return null;
  if (c.targetIsAdmin) return 'Server-Admins lassen sich nur von Admins moderieren.';
  if (c.targetIsMod) return 'Moderatoren lassen sich nur von Admins moderieren.';
  if (c.actorTop <= c.targetTop) return 'Die Person hat eine gleich hohe oder höhere Rolle als du.';
  return null;
}

/** Fehlendes Bot-Recht als verständlicher Text; andere Fehler gehen weiter. */
export function explainDiscordError(err) {
  if (err instanceof DiscordAPIError && (err.code === 50013 || err.status === 403)) {
    return 'Dem Bot fehlt dafür das Recht oder seine Rolle steht nicht hoch genug. Bitte die Rechte des Bots und die Reihenfolge der Rollen prüfen.';
  }
  return null;
}

/** Fall speichern; liefert die Fallnummer. */
export async function addCase(db, { orgId, targetId, targetName, moderatorId = null, action, reason = null, detail = null }) {
  const [res] = await db.query(
    'INSERT INTO bot_mod_cases (org_id, target_id, target_name, moderator_id, action, reason, detail, created_at) VALUES (?,?,?,?,?,?,?,?)',
    [orgId, targetId, cut(targetName, 100), moderatorId, action, reason ? cut(reason, 500) : null, detail ? cut(detail, 200) : null, nowDb()],
  );
  return Number(res.insertId);
}

/** Fälle einer Person, neueste zuerst. */
export async function listCases(db, orgId, targetId, take = 15) {
  return all(db, `SELECT * FROM bot_mod_cases WHERE org_id = ? AND target_id = ? ORDER BY id DESC LIMIT ${Number(take)}`, [orgId, targetId]);
}

export async function activeWarnings(db, orgId, targetId) {
  const row = await one(db, "SELECT COUNT(*) AS n FROM bot_mod_cases WHERE org_id = ? AND target_id = ? AND action = 'WARN' AND removed_at IS NULL", [orgId, targetId]);
  return Number(row?.n ?? 0);
}

/** Verwarnung zurücknehmen. @throws {OrgError} */
export async function removeWarning(db, orgId, caseId) {
  const n = await run(db, "UPDATE bot_mod_cases SET removed_at = ? WHERE id = ? AND org_id = ? AND action = 'WARN' AND removed_at IS NULL", [nowDb(), caseId, orgId]);
  if (n === 0) throw new OrgError(`Eine offene Verwarnung mit der Nummer ${caseId} gibt es nicht.`);
}

const unix = (dbTime) => Math.floor(Date.parse(`${dbTime.replace(' ', 'T')}Z`) / 1000);

/** Kasten für das Protokoll und die Antwort an den Moderator. */
export function caseEmbed(c) {
  const fields = [{ name: 'Person', value: `<@${c.target_id}> (${cut(c.target_name, 100)})`, inline: true }];
  if (c.moderator_id) fields.push({ name: 'Von', value: `<@${c.moderator_id}>`, inline: true });
  if (c.detail) fields.push({ name: 'Details', value: c.detail, inline: true });
  if (c.reason) fields.push({ name: 'Grund', value: c.reason });
  return {
    title: `Fall #${c.id} · ${ACTIONS[c.action] ?? c.action}`,
    color: COLORS[c.action] ?? 0x71717a,
    fields,
    timestamp: new Date(unix(c.created_at) * 1000).toISOString(),
  };
}

/** Übersicht der Fälle einer Person. */
export function casesEmbed(userName, cases, activeWarns) {
  const lines = cases.map((c) => {
    const line = `**#${c.id}** <t:${unix(c.created_at)}:d> ${ACTIONS[c.action] ?? c.action}${c.detail ? ` (${c.detail})` : ''}${c.reason ? ` – ${cut(c.reason, 120)}` : ''}`;
    return c.removed_at ? `~~${line}~~ (zurückgenommen)` : line;
  });
  return {
    title: cut(`Moderation: ${userName}`, 256),
    description: cases.length === 0 ? 'Keine Einträge.' : cut(lines.join('\n'), 4000),
    footer: { text: `${activeWarns} aktive Verwarnung${activeWarns === 1 ? '' : 'en'}` },
    color: 0x0ea5e9,
  };
}

/** Text der Direktnachricht an die betroffene Person. */
export function dmText(guildName, action, reason, detail = null) {
  const what = { WARN: 'verwarnt', TIMEOUT: 'für eine Zeit stummgeschaltet', KICK: 'vom Server entfernt', BAN: 'vom Server gebannt' }[action] ?? 'moderiert';
  return `Du wurdest auf **${cut(guildName, 100)}** ${what}${detail ? ` (${detail})` : ''}.${reason ? `\nGrund: ${cut(reason, 500)}` : ''}`;
}

/** Schreibt einen Kasten in den Protokoll-Kanal der Orga, falls einer eingestellt ist. Fehler stören die Moderation nicht. */
export async function logToChannel(client, db, orgId, payload, log) {
  try {
    const channelId = await getSetting(db, orgId, MOD_LOG_CHANNEL);
    if (channelId === null) return;
    const channel = client.channels.cache.get(channelId) ?? (await client.channels.fetch(channelId));
    await channel?.send({ ...payload, allowedMentions: { parse: [] } });
  } catch (err) {
    log?.warn(`Moderations-Protokoll nicht geschrieben: ${err.message}`);
  }
}
