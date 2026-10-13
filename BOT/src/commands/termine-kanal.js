// /termine-kanal: Kanal für die Terminankündigungen festlegen, anzeigen oder ausschalten (nur Server-Admins).
import { ChannelType, MessageFlags, PermissionFlagsBits } from 'discord.js';
import { orgForGuild } from '../db.js';
import { ANNOUNCE_DAYS } from '../lib/announce.js';
import { isGuildAdmin } from '../lib/guild.js';
import { ANNOUNCE_CHANNEL, getSetting, isMissingTable, MISSING_TABLE_TEXT, setSetting } from '../lib/settings.js';

export const data = {
  name: 'termine-kanal',
  description: 'Kanal für Terminankündigungen mit Anmelde-Knöpfen festlegen oder ausschalten (nur Server-Admins)',
  type: 1,
  options: [
    { type: 7, name: 'kanal', description: 'Kanal für die Ankündigungen', required: false, channel_types: [ChannelType.GuildText, ChannelType.GuildAnnouncement] },
    { type: 5, name: 'aus', description: 'Ankündigungen ausschalten (vorhandene Nachrichten bleiben stehen)', required: false },
  ],
  default_member_permissions: '32',
  contexts: [0],
};

const NEEDED = [PermissionFlagsBits.ViewChannel, PermissionFlagsBits.SendMessages, PermissionFlagsBits.EmbedLinks, PermissionFlagsBits.ReadMessageHistory];

export async function execute(interaction, { pool }) {
  const reply = (content) => interaction.reply({ content, flags: MessageFlags.Ephemeral, allowedMentions: { parse: [] } });
  if (!isGuildAdmin(interaction)) {
    await reply('Das dürfen nur Server-Admins (Administrator oder „Server verwalten“).');
    return;
  }
  const org = await orgForGuild(pool, interaction.guildId);
  if (org === null) {
    await reply('Für diesen Server ist noch keine Orga eingerichtet. Das erledigt ein Server-Admin mit /einrichten.');
    return;
  }
  const channel = interaction.options.getChannel('kanal');
  const off = interaction.options.getBoolean('aus') === true;
  try {
    if (off) {
      await setSetting(pool, org.id, ANNOUNCE_CHANNEL, null);
      await reply('Terminankündigungen sind aus. Schon gepostete Nachrichten bleiben stehen und werden weiter aktuell gehalten.');
      return;
    }
    if (channel === null) {
      const current = await getSetting(pool, org.id, ANNOUNCE_CHANNEL);
      await reply(current
        ? `Termine werden in <#${current}> angekündigt. Ändern mit /termine-kanal kanal:#…, ausschalten mit /termine-kanal aus:True.`
        : 'Terminankündigungen sind aus. Einschalten mit /termine-kanal kanal:#…');
      return;
    }
    const guild = interaction.guild;
    const me = guild.members.me ?? (await guild.members.fetchMe());
    const full = guild.channels.cache.get(channel.id) ?? (await guild.channels.fetch(channel.id));
    if (!full?.permissionsFor(me)?.has(NEEDED)) {
      await reply(`Der Bot darf in <#${channel.id}> nicht schreiben (nötig: Kanal ansehen, Nachrichten senden, Links einbetten, Nachrichtenverlauf lesen).`);
      return;
    }
    await setSetting(pool, org.id, ANNOUNCE_CHANNEL, channel.id);
    await reply(`Termine werden ab jetzt in <#${channel.id}> angekündigt: alle geplanten Termine der nächsten ${ANNOUNCE_DAYS} Tage, `
      + 'jeweils mit Knöpfen für „Dabei“, „Vielleicht“ und „Kann nicht“. Die ersten Nachrichten erscheinen innerhalb einer Minute.');
  } catch (err) {
    if (!isMissingTable(err)) throw err;
    await reply(MISSING_TABLE_TEXT);
  }
}
