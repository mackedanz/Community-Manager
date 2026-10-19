// /sprachkanal: Auslöser-Kanal für temporäre Sprachkanäle festlegen, anzeigen oder ausschalten (nur Server-Admins).
import { ChannelType, MessageFlags, PermissionFlagsBits } from 'discord.js';
import { orgForGuild } from '../db.js';
import { isGuildAdmin } from '../lib/guild.js';
import { getSetting, isMissingTable, MISSING_TABLE_TEXT, setSetting } from '../lib/settings.js';
import { TEMPVOICE_TRIGGER } from '../lib/tempvoice.js';

export const data = {
  name: 'sprachkanal',
  description: 'Temporäre Sprachkanäle: Kanal „Sprachkanal erstellen“ festlegen oder ausschalten (nur Server-Admins)',
  type: 1,
  options: [
    { type: 7, name: 'kanal', description: 'Sprachkanal, den man betritt, um einen eigenen Kanal zu bekommen', required: false, channel_types: [ChannelType.GuildVoice] },
    { type: 5, name: 'aus', description: 'Ausschalten (schon angelegte Kanäle verschwinden trotzdem, wenn sie leer sind)', required: false },
  ],
  default_member_permissions: '32',
  contexts: [0],
};

const NEEDED = [PermissionFlagsBits.ViewChannel, PermissionFlagsBits.ManageChannels, PermissionFlagsBits.MoveMembers];

export async function execute(interaction, { pool }) {
  const reply = (content) => interaction.reply({ content, flags: MessageFlags.Ephemeral, allowedMentions: { parse: [] } });
  if (!isGuildAdmin(interaction)) return void (await reply('Das dürfen nur Server-Admins (Administrator oder „Server verwalten“).'));
  const org = await orgForGuild(pool, interaction.guildId);
  if (org === null) return void (await reply('Für diesen Server ist noch keine Orga eingerichtet. Das erledigt ein Server-Admin mit /einrichten.'));
  const channel = interaction.options.getChannel('kanal');
  try {
    if (interaction.options.getBoolean('aus') === true) {
      await setSetting(pool, org.id, TEMPVOICE_TRIGGER, null);
      return void (await reply('Temporäre Sprachkanäle sind aus. Schon angelegte Kanäle werden gelöscht, sobald sie leer sind.'));
    }
    if (channel === null) {
      const current = await getSetting(pool, org.id, TEMPVOICE_TRIGGER);
      return void (await reply(current
        ? `Wer <#${current}> betritt, bekommt einen eigenen Sprachkanal. Ändern mit /sprachkanal kanal:#…, ausschalten mit /sprachkanal aus:True.`
        : 'Temporäre Sprachkanäle sind aus. Einschalten mit /sprachkanal kanal:#… (den Sprachkanal „Sprachkanal erstellen“ vorher anlegen).'));
    }
    const guild = interaction.guild;
    const me = guild.members.me ?? (await guild.members.fetchMe());
    const full = guild.channels.cache.get(channel.id) ?? (await guild.channels.fetch(channel.id));
    if (!full?.permissionsFor(me)?.has(NEEDED)) {
      return void (await reply(`Dem Bot fehlen Rechte bei <#${channel.id}> (nötig: Kanal ansehen, Kanäle verwalten, Mitglieder verschieben). Bitte beim Kanal oder seiner Kategorie vergeben.`));
    }
    await setSetting(pool, org.id, TEMPVOICE_TRIGGER, channel.id);
    await reply(`Wer <#${channel.id}> betritt, bekommt einen eigenen Sprachkanal mit seinem Namen in derselben Kategorie und wird hineinverschoben. `
      + 'Drei Minuten nach dem Verlassen des letzten Mitglieds wird er gelöscht.');
  } catch (err) {
    if (!isMissingTable(err)) throw err;
    await reply(MISSING_TABLE_TEXT);
  }
}
