// /mod-einstellungen: Moderatoren-Rollen und Protokoll-Kanal festlegen (nur Server-Admins).
import { ChannelType, MessageFlags, PermissionFlagsBits } from 'discord.js';
import { orgForGuild } from '../db.js';
import { isGuildAdmin } from '../lib/guild.js';
import { MAX_MOD_ROLES, MOD_LOG_CHANNEL, MOD_ROLES, modRoleIds } from '../lib/moderation.js';
import { getSetting, isMissingTable, MISSING_TABLE_TEXT, setSetting } from '../lib/settings.js';

export const audience = 'admins';

export const data = {
  name: 'mod-einstellungen',
  description: 'Moderation einrichten: Moderatoren-Rollen und Protokoll-Kanal (nur Server-Admins)',
  type: 1,
  options: [
    { type: 1, name: 'anzeigen', description: 'Aktuelle Einstellungen anzeigen' },
    { type: 1, name: 'rolle-hinzufuegen', description: 'Eine Rolle zu den Moderatoren hinzufügen', options: [{ type: 8, name: 'rolle', description: 'Rolle der Moderatoren', required: true }] },
    { type: 1, name: 'rolle-entfernen', description: 'Eine Rolle aus den Moderatoren entfernen', options: [{ type: 8, name: 'rolle', description: 'Rolle, die keine Moderation mehr darf', required: true }] },
    { type: 1, name: 'protokoll-kanal', description: 'Kanal für das Moderations-Protokoll festlegen oder ausschalten', options: [
      { type: 7, name: 'kanal', description: 'Kanal für das Protokoll', required: false, channel_types: [ChannelType.GuildText, ChannelType.GuildAnnouncement] },
      { type: 5, name: 'aus', description: 'Protokoll ausschalten', required: false },
    ] },
  ],
  default_member_permissions: '32',
  contexts: [0],
};

const NEEDED = [PermissionFlagsBits.ViewChannel, PermissionFlagsBits.SendMessages, PermissionFlagsBits.EmbedLinks];

/** Übersicht der Einstellungen als Text. */
export function settingsText(roles, channelId) {
  return `**Moderation**\nModeratoren-Rollen: ${roles.length ? roles.map((r) => `<@&${r}>`).join(', ') : 'keine (nur Server-Admins moderieren)'}\n`
    + `Protokoll-Kanal: ${channelId ? `<#${channelId}>` : 'aus'}`;
}

export async function execute(interaction, { pool }) {
  const reply = (content) => interaction.reply({ content, flags: MessageFlags.Ephemeral, allowedMentions: { parse: [] } });
  if (!isGuildAdmin(interaction)) return void (await reply('Das dürfen nur Server-Admins (Administrator oder „Server verwalten“).'));
  const org = await orgForGuild(pool, interaction.guildId);
  if (org === null) return void (await reply('Für diesen Server ist noch keine Orga eingerichtet. Das erledigt ein Server-Admin mit /einrichten.'));
  try {
    const sub = interaction.options.getSubcommand();
    const roles = await modRoleIds(pool, org.id);
    if (sub === 'anzeigen') {
      return void (await reply(settingsText(roles, await getSetting(pool, org.id, MOD_LOG_CHANNEL))));
    }
    if (sub === 'rolle-hinzufuegen' || sub === 'rolle-entfernen') {
      const role = interaction.options.getRole('rolle', true);
      if (sub === 'rolle-hinzufuegen') {
        if (role.id === interaction.guildId) return void (await reply('@everyone kann nicht Moderator sein.'));
        if (roles.includes(role.id)) return void (await reply(`<@&${role.id}> ist schon Moderatoren-Rolle.`));
        if (roles.length >= MAX_MOD_ROLES) return void (await reply(`Es sind höchstens ${MAX_MOD_ROLES} Moderatoren-Rollen möglich.`));
        roles.push(role.id);
      } else {
        if (!roles.includes(role.id)) return void (await reply(`<@&${role.id}> ist keine Moderatoren-Rolle.`));
        roles.splice(roles.indexOf(role.id), 1);
      }
      await setSetting(pool, org.id, MOD_ROLES, roles.length ? roles.join(',') : null);
      return void (await reply(settingsText(roles, await getSetting(pool, org.id, MOD_LOG_CHANNEL))));
    }
    // protokoll-kanal
    const channel = interaction.options.getChannel('kanal');
    if (interaction.options.getBoolean('aus') === true) {
      await setSetting(pool, org.id, MOD_LOG_CHANNEL, null);
      return void (await reply('Das Moderations-Protokoll ist aus.'));
    }
    if (channel === null) {
      const current = await getSetting(pool, org.id, MOD_LOG_CHANNEL);
      return void (await reply(current ? `Das Protokoll steht in <#${current}>. Ändern mit /mod-einstellungen protokoll-kanal kanal:#…` : 'Das Protokoll ist aus. Einschalten mit /mod-einstellungen protokoll-kanal kanal:#…'));
    }
    const guild = interaction.guild;
    const me = guild.members.me ?? (await guild.members.fetchMe());
    const full = guild.channels.cache.get(channel.id) ?? (await guild.channels.fetch(channel.id));
    if (!full?.permissionsFor(me)?.has(NEEDED)) {
      return void (await reply(`Der Bot darf in <#${channel.id}> nicht schreiben (nötig: Kanal ansehen, Nachrichten senden, Links einbetten).`));
    }
    await setSetting(pool, org.id, MOD_LOG_CHANNEL, channel.id);
    await reply(`Das Protokoll steht ab jetzt in <#${channel.id}>: alle Moderationsaktionen sowie Beitritte und Austritte.`);
  } catch (err) {
    if (!isMissingTable(err)) throw err;
    await reply(MISSING_TABLE_TEXT);
  }
}
