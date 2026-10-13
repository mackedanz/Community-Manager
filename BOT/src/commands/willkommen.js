// /willkommen: Kanal und Text der Willkommensnachricht festlegen oder ausschalten (nur Server-Admins).
import { ChannelType, MessageFlags, PermissionFlagsBits } from 'discord.js';
import { orgForGuild } from '../db.js';
import { isGuildAdmin } from '../lib/guild.js';
import { getSetting, isMissingTable, MISSING_TABLE_TEXT, setSetting } from '../lib/settings.js';
import { DEFAULT_WELCOME, MAX_WELCOME_TEXT, RULES_CHANNEL, WELCOME_CHANNEL, WELCOME_TEXT, welcomeText } from '../lib/welcome.js';

export const audience = 'admins';

export const data = {
  name: 'willkommen',
  description: 'Willkommensnachricht für Neue: Kanal und Text festlegen oder ausschalten (nur Server-Admins)',
  type: 1,
  options: [
    { type: 7, name: 'kanal', description: 'Kanal für die Willkommensnachrichten', required: false, channel_types: [ChannelType.GuildText, ChannelType.GuildAnnouncement] },
    { type: 3, name: 'text', description: 'Eigener Text. {user} = Mitglied, {server} = Servername, {regeln} = Regeln-Kanal', required: false, max_length: MAX_WELCOME_TEXT },
    { type: 5, name: 'aus', description: 'Willkommensnachrichten ausschalten', required: false },
  ],
  default_member_permissions: '32',
  contexts: [0],
};

const NEEDED = [PermissionFlagsBits.ViewChannel, PermissionFlagsBits.SendMessages];

export async function execute(interaction, { pool }) {
  const reply = (content) => interaction.reply({ content, flags: MessageFlags.Ephemeral, allowedMentions: { parse: [] } });
  if (!isGuildAdmin(interaction)) return void (await reply('Das dürfen nur Server-Admins (Administrator oder „Server verwalten“).'));
  const org = await orgForGuild(pool, interaction.guildId);
  if (org === null) return void (await reply('Für diesen Server ist noch keine Orga eingerichtet. Das erledigt ein Server-Admin mit /einrichten.'));
  try {
    const channel = interaction.options.getChannel('kanal');
    const text = interaction.options.getString('text');
    if (interaction.options.getBoolean('aus') === true) {
      await setSetting(pool, org.id, WELCOME_CHANNEL, null);
      return void (await reply('Willkommensnachrichten sind aus.'));
    }
    if (channel !== null) {
      const guild = interaction.guild;
      const me = guild.members.me ?? (await guild.members.fetchMe());
      const full = guild.channels.cache.get(channel.id) ?? (await guild.channels.fetch(channel.id));
      if (!full?.permissionsFor(me)?.has(NEEDED)) return void (await reply(`Der Bot darf in <#${channel.id}> nicht schreiben (nötig: Kanal ansehen, Nachrichten senden).`));
      await setSetting(pool, org.id, WELCOME_CHANNEL, channel.id);
    }
    if (text !== null) await setSetting(pool, org.id, WELCOME_TEXT, text.trim() === '' ? null : text.trim());
    const [channelId, saved, rules] = await Promise.all([
      getSetting(pool, org.id, WELCOME_CHANNEL), getSetting(pool, org.id, WELCOME_TEXT), getSetting(pool, org.id, RULES_CHANNEL),
    ]);
    const preview = welcomeText(saved ?? DEFAULT_WELCOME, { userId: interaction.user.id, serverName: interaction.guild.name, rulesChannelId: rules });
    await reply(`**Willkommensnachricht**\nKanal: ${channelId ? `<#${channelId}>` : 'aus (einschalten mit /willkommen kanal:#…)'}\nText${saved ? '' : ' (Standard)'}:\n>>> ${preview}`);
  } catch (err) {
    if (!isMissingTable(err)) throw err;
    await reply(MISSING_TABLE_TEXT);
  }
}
