// Daten eines Discord-Servers über die REST-Schnittstelle (wie DiscordBot.php der PHP-App).
import { DiscordAPIError, PermissionFlagsBits, Routes } from 'discord.js';
import { OrgError } from './errors.js';

/** Darf diese Person den Server verwalten? (Administrator oder "Server verwalten"; Eigentümer haben alle Rechte) */
export function isGuildAdmin(interaction) {
  const p = interaction.memberPermissions;
  return p != null && (p.has(PermissionFlagsBits.Administrator) || p.has(PermissionFlagsBits.ManageGuild));
}

/** Rollen-IDs der aufrufenden Person (ohne @everyone). */
export function memberRoleIds(interaction) {
  const m = interaction.member;
  if (m == null) return [];
  if (Array.isArray(m.roles)) return m.roles.map(String);
  // GuildMember: die IDs aus der Interaktion, unabhängig davon, ob die Rollen im Cache sind
  if (Array.isArray(m._roles)) return m._roles.map(String);
  return [...m.roles.cache.keys()].filter((id) => id !== interaction.guildId);
}

/** Anzeigename der aufrufenden Person: Server-Nickname, sonst Anzeigename, sonst Benutzername. */
export function invokerName(interaction) {
  const m = interaction.member;
  return (m?.nickname ?? m?.nick) || interaction.user.globalName || interaction.user.username || 'Discord-Nutzer';
}

/** Name und Icon des Servers, null wenn der Bot nicht darauf ist. */
export async function guildInfo(client, guildId) {
  const cached = client.guilds.cache.get(guildId);
  if (cached) return { name: cached.name, icon: cached.icon };
  try {
    const g = await client.rest.get(Routes.guild(guildId));
    return { name: String(g.name ?? ''), icon: g.icon ?? null };
  } catch (err) {
    if (err instanceof DiscordAPIError && (err.status === 403 || err.status === 404)) return null;
    throw err;
  }
}

export function guildIconUrl(guildId, icon) {
  return icon ? `https://cdn.discordapp.com/icons/${guildId}/${icon}.png` : null;
}

/** Wandelt ein Mitglied aus der REST-Antwort in die Form der PHP-App um (null für Bots). */
export function toMember(guildId, m) {
  const u = m.user;
  if (!u?.id || u.bot) return null;
  const id = String(u.id);
  const avatar = m.avatar
    ? `https://cdn.discordapp.com/guilds/${guildId}/users/${id}/avatars/${m.avatar}.png`
    : u.avatar ? `https://cdn.discordapp.com/avatars/${id}/${u.avatar}.png` : null;
  const accountName = String(u.global_name || u.username || 'Discord-Nutzer');
  return {
    id,
    name: String(m.nick || accountName),
    nick: m.nick ? String(m.nick) : null,
    accountName,
    avatar,
    roles: Array.isArray(m.roles) ? m.roles.map(String) : [],
  };
}

/**
 * Alle Mitglieder des Servers, seitenweise à 1000. Braucht den Intent "Server Members" im Developer Portal.
 * @throws {OrgError} wenn der Bot die Liste nicht lesen darf oder Discord nicht antwortet
 */
export async function guildMembers(client, guildId) {
  const out = [];
  let after = '0';
  for (let page = 0; page < 100; page++) {
    let rows;
    try {
      rows = await client.rest.get(Routes.guildMembers(guildId), { query: new URLSearchParams({ limit: '1000', after }) });
    } catch (err) {
      if (err instanceof DiscordAPIError && (err.status === 401 || err.status === 403)) {
        throw new OrgError('Der Bot darf die Mitgliederliste nicht lesen (Intent "Server Members" im Developer Portal einschalten).');
      }
      if (err instanceof DiscordAPIError) throw new OrgError(`Discord antwortet mit Status ${err.status}.`);
      throw new OrgError('Discord ist nicht erreichbar.');
    }
    if (!Array.isArray(rows) || rows.length === 0) break;
    for (const m of rows) {
      const member = toMember(guildId, m);
      if (member) out.push(member);
    }
    if (rows.length < 1000) break;
    after = String(rows.at(-1).user.id);
  }
  return out;
}
