// Reagiert auf Beitritt, Austritt und Änderungen von Server-Mitgliedern: Die Zugangsliste der Orga wird kurz danach
// abgeglichen, statt erst beim stündlichen Abgleich der PHP-App.
//
// Mehrere Ereignisse kurz hintereinander (z. B. eine Rolle an viele vergeben) werden zu einem Abgleich zusammengefasst.
// Der Abgleich läuft immer über die ganze Mitgliederliste (syncAllowlist), damit die Sicherheitsbremse gegen
// versehentliches Löschen vieler Konten auch hier greift.
import { Events } from 'discord.js';
import { orgForGuild, run } from '../db.js';
import { OrgError } from '../lib/errors.js';
import { currentGuildIconUrl, guildMembers } from '../lib/guild.js';
import { syncAllowlist, syncNote } from '../lib/onboarding.js';
import { allAccessRoleIds, parseRoleIds } from '../lib/roles.js';

/** Wartezeit nach dem letzten Ereignis, bevor abgeglichen wird. */
export const QUIET_MS = 15_000;
/** Spätestens nach dieser Zeit wird abgeglichen, auch wenn weiter Ereignisse kommen. */
export const MAX_WAIT_MS = 60_000;

/** Rollen-IDs eines Mitglieds (ohne @everyone), unabhängig davon, ob die Rollen im Cache sind. */
const roleIdsOf = (m) => (Array.isArray(m?._roles) ? m._roles : []);

/**
 * Betrifft die Änderung eines Mitglieds die Zugangsliste? Ja, wenn sich eine der Rollen der Orga oder der
 * angezeigte Name geändert hat. Ein unvollständiger alter Stand (nicht im Cache) zählt als Änderung.
 */
export function memberChangeMatters(org, oldMember, newMember) {
  if (oldMember == null || oldMember.partial) return true;
  const access = new Set(allAccessRoleIds(org));
  const before = new Set(roleIdsOf(oldMember).filter((r) => access.has(r)));
  const after = new Set(roleIdsOf(newMember).filter((r) => access.has(r)));
  if (before.size !== after.size || [...before].some((r) => !after.has(r))) return true;
  return oldMember.nickname !== newMember.nickname
    || oldMember.user?.globalName !== newMember.user?.globalName
    || oldMember.user?.username !== newMember.user?.username
    || oldMember.avatar !== newMember.avatar;
}

/** Hat das Mitglied eine Rolle, mit der es das Tool nutzen darf? */
export function hasAccessRole(org, member) {
  const access = new Set(allAccessRoleIds(org));
  return roleIdsOf(member).some((r) => access.has(r));
}

/**
 * Plant Abgleiche je Server. sync(guildId) wird nach QUIET_MS Ruhe ausgeführt, spätestens nach MAX_WAIT_MS.
 * Läuft gerade ein Abgleich für den Server, folgt nach dessen Ende genau ein weiterer.
 */
export function createScheduler(sync, { quietMs = QUIET_MS, maxWaitMs = MAX_WAIT_MS } = {}) {
  const pending = new Map(); // guildId => { timer, firstAt }
  const running = new Set();
  const again = new Set();

  async function fire(guildId) {
    pending.delete(guildId);
    if (running.has(guildId)) {
      again.add(guildId);
      return;
    }
    running.add(guildId);
    try {
      await sync(guildId);
    } finally {
      running.delete(guildId);
      if (again.delete(guildId)) schedule(guildId);
    }
  }

  function schedule(guildId) {
    const now = Date.now();
    const p = pending.get(guildId);
    if (p) clearTimeout(p.timer);
    const firstAt = p?.firstAt ?? now;
    const delay = Math.max(0, Math.min(quietMs, firstAt + maxWaitMs - now));
    const timer = setTimeout(() => fire(guildId), delay);
    timer.unref?.();
    pending.set(guildId, { timer, firstAt });
  }

  function stop() {
    for (const p of pending.values()) clearTimeout(p.timer);
    pending.clear();
  }

  return { schedule, stop, pendingCount: () => pending.size };
}

/** Verbindet die Mitglieder-Ereignisse des Clients mit dem Abgleich. */
export function registerMemberEvents(client, { pool, config, log }) {
  const scheduler = createScheduler(async (guildId) => {
    // Läuft im Timer: Fehler hier nur ins Log, sonst würden sie den Bot beenden
    let org = null;
    try {
      org = await orgForGuild(pool, guildId);
      if (org === null || parseRoleIds(org.member_role_ids).length === 0) return;
      const r = await syncAllowlist(pool, config, org.id, (id) => guildMembers(client, id), (id) => currentGuildIconUrl(client, id));
      log.info(`Automatischer Abgleich ${org.name}: ${syncNote(r)}`);
    } catch (err) {
      const name = org?.name ?? `Server ${guildId}`;
      if (err instanceof OrgError) log.warn(`Automatischer Abgleich ${name} nicht möglich: ${err.message}`);
      else log.error(`Automatischer Abgleich ${name} fehlgeschlagen`, err);
    }
  });

  /** Die Orga zum Server, wenn sie Rollen gewählt hat (sonst gibt es nichts abzugleichen). */
  async function configuredOrg(guildId) {
    const org = await orgForGuild(pool, guildId);
    return org !== null && parseRoleIds(org.member_role_ids).length > 0 ? org : null;
  }

  /** Das Konto der Person beim nächsten Seitenaufruf neu prüfen (Rechte wie Planen oder Ausbilden sofort aktuell). */
  const recheck = (discordId) => run(pool, 'UPDATE users SET membership_checked_at = NULL WHERE discord_id = ?', [discordId]);

  const guard = (name, fn) => async (...args) => {
    try {
      await fn(...args);
    } catch (err) {
      log.error(`${name} fehlgeschlagen`, err);
    }
  };

  // Nach dem Start alle Server einmal abgleichen: Änderungen, während der Bot aus war, kommen so sofort an
  client.once(Events.ClientReady, (c) => {
    for (const guildId of c.guilds.cache.keys()) scheduler.schedule(guildId);
  });

  client.on(Events.GuildMemberAdd, guard('Beitritt', async (member) => {
    if (member.user.bot) return;
    const org = await configuredOrg(member.guild.id);
    if (org !== null && hasAccessRole(org, member)) scheduler.schedule(member.guild.id);
  }));

  client.on(Events.GuildMemberRemove, guard('Austritt', async (member) => {
    if (member.user?.bot) return;
    const org = await configuredOrg(member.guild.id);
    if (org === null) return;
    await recheck(member.id);
    scheduler.schedule(member.guild.id);
  }));

  client.on(Events.GuildMemberUpdate, guard('Mitglied geändert', async (oldMember, newMember) => {
    if (newMember.user.bot) return;
    const org = await configuredOrg(newMember.guild.id);
    if (org === null || !memberChangeMatters(org, oldMember, newMember)) return;
    await recheck(newMember.id);
    scheduler.schedule(newMember.guild.id);
  }));

  return scheduler;
}
