// Begrüßt neue Mitglieder im Willkommens-Kanal der Orga (/willkommen oder /discord-setup).
import { Events } from 'discord.js';
import { orgForGuild } from '../db.js';
import { isMissingTable } from '../lib/settings.js';
import { welcomeMessage } from '../lib/welcome.js';

export function registerWelcome(client, { pool, log }) {
  client.on(Events.GuildMemberAdd, async (member) => {
    try {
      if (member.user.bot) return;
      const org = await orgForGuild(pool, member.guild.id);
      if (org === null) return;
      const msg = await welcomeMessage(pool, org.id, member);
      if (msg === null) return;
      const channel = client.channels.cache.get(msg.channelId) ?? (await client.channels.fetch(msg.channelId));
      await channel?.send(msg.payload);
    } catch (err) {
      if (!isMissingTable(err)) log.warn(`Willkommensnachricht fehlgeschlagen: ${err.message}`);
    }
  });
}
