// Orga per /einrichten anlegen, Rollen wählen und die Zugangsliste (org_allowed_members) mit Discord abgleichen.
// Gleiche Regeln wie Onboarding.php der PHP-App, die denselben Abgleich stündlich ausführt.
import { all, inList, nowDb, one, run, transaction, val } from '../db.js';
import { OrgError } from './errors.js';
import { allAccessRoleIds, buildRoleLabels, cleanRoleIds, parseRoleIds, parseRoleLabels, ROLE_KINDS } from './roles.js';
import { HANDLE_STYLES } from './rsi.js';
import { cut, len, newId, slugify } from './text.js';

/** Ab so vielen Abgängen auf einmal (und mehr als der Hälfte der Liste) wird beim Abgleich nichts gelöscht. */
export const DELETE_BRAKE_MIN = 5;

export async function findOrg(db, orgId) {
  return one(db, 'SELECT * FROM organizations WHERE id = ?', [orgId]);
}

export async function uniqueSlug(db, name) {
  const base = slugify(name);
  for (let i = 1; ; i++) {
    const slug = i === 1 ? base : `${base}-${i}`;
    if ((await val(db, 'SELECT 1 FROM organizations WHERE slug = ?', [slug])) === null) return slug;
  }
}

/** Prüft, ob der Server Orgas einrichten darf (ONBOARDING_GUILD_IDS). */
export function guildAllowed(config, guildId) {
  return config.onboardingGuildIds.length === 0 || config.onboardingGuildIds.includes(guildId);
}

/**
 * Orga zum Server; wird beim ersten Aufruf angelegt. Die aufrufende Person kommt dauerhaft auf die Liste.
 * @param {{guildId: string, invokerId: string, invokerName: string, guild: {name: string, icon: ?string}|null, iconUrl: ?string}} p
 */
export async function ensureOrg(pool, config, p) {
  if (!guildAllowed(config, p.guildId)) {
    throw new OrgError(`Dieser Server ist nicht freigeschaltet. Bitte den Betreiber, die Server-ID ${p.guildId} freizugeben.`);
  }
  let org = await one(pool, 'SELECT * FROM organizations WHERE discord_guild_id = ?', [p.guildId]);
  if (org === null) {
    if ((await val(pool, 'SELECT 1 FROM banned_guilds WHERE discord_guild_id = ?', [p.guildId])) !== null) {
      throw new OrgError('Dieser Discord-Server wurde vom Betreiber gesperrt.');
    }
    let name = cut((p.guild?.name ?? '').trim(), 60);
    if (len(name) < 2) name = `Orga ${p.guildId.slice(-4)}`;
    const id = newId();
    await run(
      pool,
      'INSERT INTO organizations (id, slug, name, discord_guild_id, icon_url, created_by_id) VALUES (?,?,?,?,?,?)',
      [id, await uniqueSlug(pool, name), name, p.guildId, p.iconUrl ?? null, p.invokerId],
    );
    org = await findOrg(pool, id);
    if (org === null) throw new OrgError('Orga konnte nicht angelegt werden.');
  }
  await run(
    pool,
    `INSERT INTO org_allowed_members (org_id, discord_id, name, fixed, synced_at) VALUES (?,?,?,1,?)
     ON DUPLICATE KEY UPDATE fixed = 1, name = VALUES(name)`,
    [org.id, p.invokerId, cut(p.invokerName, 190), nowDb()],
  );
  return org;
}

/**
 * Speichert die gewählten Rollen einer Art ('use', 'plan', 'recruit', 'instruct', 'lead', 'admin').
 * @param {string[]} ids @param {Record<string,string>} names Rollen-ID => Name
 */
export async function setRoles(pool, orgId, kind, ids, names) {
  const column = ROLE_KINDS[kind];
  if (!column) throw new OrgError('Unbekannte Auswahl.');
  const org = await findOrg(pool, orgId);
  if (org === null) throw new OrgError('Orga nicht gefunden.');
  const next = {};
  for (const col of Object.values(ROLE_KINDS)) next[col] = col === column ? cleanRoleIds(ids) : org[col] ?? null;
  const labels = buildRoleLabels(
    Object.values(next).flatMap(parseRoleIds),
    { ...parseRoleLabels(org.role_labels), ...names },
  );
  await run(
    pool,
    'UPDATE organizations SET member_role_ids = ?, planner_role_ids = ?, recruiter_role_ids = ?, instructor_role_ids = ?, lead_role_ids = ?, admin_role_ids = ?, role_labels = ? WHERE id = ?',
    [next.member_role_ids, next.planner_role_ids, next.recruiter_role_ids, next.instructor_role_ids, next.lead_role_ids, next.admin_role_ids, labels, orgId],
  );
  if ((org[column] ?? null) !== next[column]) {
    // Alle bisherigen Mitglieder beim nächsten Seitenaufruf neu prüfen
    await run(pool, 'UPDATE users SET membership_checked_at = NULL WHERE id IN (SELECT user_id FROM org_memberships WHERE org_id = ?)', [orgId]);
  }
}

/** Speichert, wo im Server-Nickname der RSI-Handle steht. */
export async function setHandleStyle(pool, orgId, style) {
  if (!(style in HANDLE_STYLES)) throw new OrgError('Ungültige Auswahl.');
  await run(pool, 'UPDATE organizations SET handle_style = ? WHERE id = ?', [style, orgId]);
}

/**
 * Gleicht die Zugangsliste mit Discord ab: wer eine der Rollen der Orga hat, steht drin.
 * Wer dadurch auf keiner Zugangsliste mehr steht, verliert sein Konto samt Hangar (nur mit LOGIN_REQUIRES_ALLOWLIST).
 * @param {(guildId: string) => Promise<Array<{id: string, name: string, nick: ?string, accountName: string, avatar: ?string, roles: string[]}>>} fetchMembers
 * @returns {Promise<{total: number, added: number, removed: number, deleted: number, skipped: number}>}
 */
export async function syncAllowlist(pool, config, orgId, fetchMembers) {
  const org = await findOrg(pool, orgId);
  if (org === null) throw new OrgError('Orga nicht gefunden.');
  if (parseRoleIds(org.member_role_ids).length === 0) {
    throw new OrgError('Wähle zuerst mindestens eine Rolle, die das Tool nutzen darf.');
  }
  const wanted = new Set(allAccessRoleIds(org));
  const guildMembers = await fetchMembers(String(org.discord_guild_id));
  await refreshNames(pool, orgId, guildMembers);
  const members = guildMembers.filter((m) => m.roles.some((r) => wanted.has(r)));
  const now = nowDb();

  const result = await transaction(pool, async (conn) => {
    const before = (await all(conn, 'SELECT discord_id FROM org_allowed_members WHERE org_id = ?', [orgId])).map((r) => r.discord_id);
    const fixed = new Set((await all(conn, 'SELECT discord_id FROM org_allowed_members WHERE org_id = ? AND fixed = 1', [orgId])).map((r) => r.discord_id));
    for (const m of members) {
      await run(
        conn,
        `INSERT INTO org_allowed_members (org_id, discord_id, name, avatar_url, synced_at) VALUES (?,?,?,?,?)
         ON DUPLICATE KEY UPDATE name = VALUES(name), avatar_url = VALUES(avatar_url), synced_at = VALUES(synced_at)`,
        [orgId, m.id, cut(m.name, 190), m.avatar, now],
      );
    }
    const ids = members.map((m) => m.id);
    const removed = await run(
      conn,
      'DELETE FROM org_allowed_members WHERE org_id = ? AND fixed = 0' + (ids.length === 0 ? '' : ` AND discord_id NOT IN (${inList(ids)})`),
      [orgId, ...ids],
    );
    await run(conn, 'UPDATE organizations SET allowlist_synced_at = ? WHERE id = ?', [now, orgId]);
    const total = Number(await val(conn, 'SELECT COUNT(*) FROM org_allowed_members WHERE org_id = ?', [orgId]));
    const idSet = new Set(ids);
    const beforeSet = new Set(before);
    return {
      total,
      added: ids.filter((id) => !beforeSet.has(id)).length,
      removed,
      before: before.length,
      gone: before.filter((id) => !idSet.has(id) && !fixed.has(id)),
    };
  });

  let deleted = 0;
  let skipped = 0;
  // Eine leere Mitgliederliste ist verdächtig (Discord-Panne?): dann wird nichts gelöscht
  if (members.length > 0 && config.loginRequiresAllowlist) {
    if (result.gone.length >= DELETE_BRAKE_MIN && result.gone.length * 2 > result.before) {
      // Sicherheitsbremse: so viele auf einmal sehen nach einem Rollenumbau oder Fehler aus, nicht nach Abgängen
      skipped = (await formerMemberUserIds(pool, config, result.gone)).length;
    } else {
      for (const userId of await formerMemberUserIds(pool, config, result.gone)) {
        deleted += await run(pool, 'DELETE FROM users WHERE id = ?', [userId]);
      }
    }
  }
  return { total: result.total, added: result.added, removed: result.removed, deleted, skipped };
}

/** Übernimmt Server-Nickname und Kontoname aller Mitglieder, die schon ein Konto im Tool haben. */
async function refreshNames(pool, orgId, guildMembers) {
  for (const m of guildMembers) {
    await run(
      pool,
      'UPDATE org_memberships m JOIN users u ON u.id = m.user_id SET m.nick = ?, u.name = ? WHERE m.org_id = ? AND u.discord_id = ?',
      [m.nick !== null ? cut(m.nick, 190) : null, cut(m.accountName, 190), orgId, m.id],
    );
  }
}

/** Konten (Benutzer-IDs) von Personen, die auf keiner Zugangsliste mehr stehen. Server-Admins sind ausgenommen. */
async function formerMemberUserIds(pool, config, discordIds) {
  const out = [];
  for (const discordId of discordIds) {
    if (config.serverAdminIds.includes(discordId)
      || (await val(pool, 'SELECT 1 FROM org_allowed_members WHERE discord_id = ? LIMIT 1', [discordId])) !== null) {
      continue;
    }
    const userId = await val(pool, "SELECT user_id FROM accounts WHERE provider = 'discord' AND provider_account_id = ?", [discordId]);
    if (userId !== null) out.push(String(userId));
  }
  return out;
}

/** Ergebnis eines Abgleichs als Satz für die Antwort in Discord. */
export function syncNote(r) {
  let out = `Fertig: ${r.total} Mitglieder dürfen sich anmelden (+${r.added}, −${r.removed})`;
  if (r.deleted > 0) out += `, ${r.deleted} Konten gelöscht`;
  if (r.skipped > 0) {
    out += `, ${r.skipped} Konten NICHT gelöscht (Sicherheitsbremse: zu viele auf einmal; prüfen und dann auf dem Server bin/purge-former-members.php ausführen)`;
  }
  return `${out}.`;
}
