// Orga verwalten (OrgController der Web-App) und Betreiber-Funktionen (ServerAdmin.php).
import { all, run, transaction, val } from '../db.js';
import { OrgError } from './errors.js';
import { cut, len } from './text.js';

export const DISCORD_EVENT_MODES = {
  OFF: 'aus',
  DRAFT: 'als Entwurf übernehmen',
  PUBLISHED: 'direkt sichtbar übernehmen',
};

/** Orga umbenennen (2 bis 60 Zeichen). Die Adresse (slug) bleibt, damit Links gültig bleiben. @throws {OrgError} */
export async function renameOrg(pool, orgId, name) {
  const n = String(name ?? '').trim();
  if (len(n) < 2) throw new OrgError('Name zu kurz');
  if (len(n) > 60) throw new OrgError('Name zu lang');
  await run(pool, 'UPDATE organizations SET name = ? WHERE id = ?', [n, orgId]);
}

/** Übernahme der Discord-Server-Events als Termine (OFF, DRAFT, PUBLISHED). @throws {OrgError} */
export async function setDiscordEventsMode(pool, orgId, mode) {
  if (!(mode in DISCORD_EVENT_MODES)) throw new OrgError('Ungültige Auswahl.');
  await run(pool, 'UPDATE organizations SET discord_events_mode = ? WHERE id = ?', [mode, orgId]);
}

/** Zahlen für die Übersicht in /orga. */
export async function orgCounts(db, orgId) {
  const n = async (sql) => Number(await val(db, sql, [orgId]));
  return {
    allowed: await n('SELECT COUNT(*) FROM org_allowed_members WHERE org_id = ?'),
    members: await n('SELECT COUNT(*) FROM org_memberships WHERE org_id = ?'),
    rsiMembers: await n('SELECT COUNT(*) FROM org_rsi_members WHERE org_id = ?'),
    events: await n("SELECT COUNT(*) FROM events WHERE org_id = ? AND status = 'PLANNED' AND starts_at >= UTC_TIMESTAMP()"),
  };
}

// ----- Betreiber (SERVER_ADMIN_DISCORD_ID) -----

export function isOperator(config, discordId) {
  return config.serverAdminIds.includes(discordId);
}

export async function listAllOrgs(db) {
  return all(
    db,
    `SELECT o.id, o.name, o.slug, o.discord_guild_id, o.created_at,
            (SELECT COUNT(*) FROM org_memberships m WHERE m.org_id = o.id) AS member_count,
            (SELECT COUNT(*) FROM events e WHERE e.org_id = o.id) AS event_count
       FROM organizations o ORDER BY o.name ASC`,
  );
}

export async function listBans(db) {
  return all(db, 'SELECT discord_guild_id, name, reason, banned_at FROM banned_guilds ORDER BY banned_at DESC');
}

/** Sperrt den Discord-Server und löscht eine vorhandene Orga dazu (ServerAdmin::banGuild). @throws {OrgError} */
export async function banGuild(pool, guildId, name, reason, bannedBy) {
  const id = String(guildId ?? '').trim();
  if (!/^\d{5,25}$/.test(id)) throw new OrgError('Ungültige Discord-Server-ID.');
  const orgName = await val(pool, 'SELECT name FROM organizations WHERE discord_guild_id = ?', [id]);
  const label = cut(String(orgName ?? name ?? '').trim(), 100) || id;
  const note = reason ? cut(String(reason).trim(), 500) : '';
  await transaction(pool, async (conn) => {
    await run(
      conn,
      'INSERT INTO banned_guilds (discord_guild_id, name, reason, banned_by_id) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE name = VALUES(name), reason = VALUES(reason)',
      [id, label, note !== '' ? note : null, cut(bannedBy, 32)],
    );
    await run(conn, 'DELETE FROM organizations WHERE discord_guild_id = ?', [id]);
  });
  return label;
}

export async function unbanGuild(pool, guildId) {
  return run(pool, 'DELETE FROM banned_guilds WHERE discord_guild_id = ?', [String(guildId ?? '').trim()]);
}

/** Löscht die Orga samt Mitgliedschaften und Terminen. Konten und Hangars bleiben. */
export async function deleteOrg(pool, orgId) {
  return run(pool, 'DELETE FROM organizations WHERE id = ?', [orgId]);
}
