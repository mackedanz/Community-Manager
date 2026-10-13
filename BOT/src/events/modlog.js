// Schreibt Beitritte und Austritte in den Protokoll-Kanal der Orga (wenn /mod-einstellungen protokoll-kanal gesetzt ist).
import { Events } from 'discord.js';
import { orgForGuild } from '../db.js';
import { logToChannel } from '../lib/moderation.js';
import { isMissingTable } from '../lib/settings.js';
import { cut } from '../lib/text.js';

const unix = (date) => Math.floor(date.getTime() / 1000);

export function joinEmbed(member) {
  return {
    title: 'Beigetreten',
    description: `<@${member.id}> (${cut(member.user.globalName || member.user.username, 100)})`,
    fields: [{ name: 'Konto erstellt', value: `<t:${unix(member.user.createdAt)}:R>` }],
    color: 0x22c55e,
    timestamp: new Date().toISOString(),
  };
}

export function leaveEmbed(member) {
  const roles = [...(member.roles?.cache?.keys() ?? [])].filter((r) => r !== member.guild.id);
  return {
    title: 'Verlassen',
    description: `<@${member.id}> (${cut(member.user.globalName || member.user.username, 100)})`,
    fields: roles.length ? [{ name: 'Rollen', value: cut(roles.map((r) => `<@&${r}>`).join(', '), 1024) }] : [],
    color: 0x71717a,
    timestamp: new Date().toISOString(),
  };
}

export function registerModLog(client, { pool, log }) {
  const handler = (build) => async (member) => {
    try {
      if (member.user?.bot) return;
      const org = await orgForGuild(pool, member.guild.id);
      if (org !== null) await logToChannel(client, pool, org.id, { embeds: [build(member)] }, log);
    } catch (err) {
      if (!isMissingTable(err)) log.warn(`Beitritts-Protokoll fehlgeschlagen: ${err.message}`);
    }
  };
  client.on(Events.GuildMemberAdd, handler(joinEmbed));
  client.on(Events.GuildMemberRemove, handler(leaveEmbed));
}
