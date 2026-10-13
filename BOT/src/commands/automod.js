// /automod: Regeln der automatischen Moderation einstellen (nur Server-Admins). Die Prüfung der Nachrichten steht in events/automod.js.
import { ChannelType, MessageFlags } from 'discord.js';
import { orgForGuild } from '../db.js';
import {
  changeList, configText, forgetGuild, loadConfig, MAX_DOMAINS, MAX_EXEMPT_CHANNELS, MAX_WORDS, normalizeDomain, normalizeWord, saveConfig,
} from '../lib/automod.js';
import { OrgError } from '../lib/errors.js';
import { isGuildAdmin } from '../lib/guild.js';
import { isMissingTable, MISSING_TABLE_TEXT } from '../lib/settings.js';

export const audience = 'admins';

const action = { type: 3, name: 'aktion', description: 'Hinzufügen oder entfernen', required: true, choices: [{ name: 'hinzufügen', value: 'add' }, { name: 'entfernen', value: 'remove' }] };

export const data = {
  name: 'automod',
  description: 'Automatische Moderation einstellen: Spam, Links, Wörter, Erwähnungen (nur Server-Admins)',
  type: 1,
  options: [
    { type: 1, name: 'anzeigen', description: 'Aktuelle Einstellungen anzeigen' },
    { type: 1, name: 'schalter', description: 'Spam-, Einladungs- oder Link-Regel ein- oder ausschalten', options: [
      { type: 3, name: 'regel', description: 'Welche Regel?', required: true, choices: [
        { name: 'Spam', value: 'spam' }, { name: 'Einladungslinks', value: 'invites' }, { name: 'Links', value: 'links' },
      ] },
      { type: 5, name: 'an', description: 'Einschalten (nein = ausschalten)', required: true },
    ] },
    { type: 1, name: 'erwaehnungen', description: 'Höchstzahl erwähnter Personen und Rollen je Nachricht (0 = aus)', options: [
      { type: 4, name: 'anzahl', description: '0 = aus, sonst 3 bis 50', required: true, min_value: 0, max_value: 50 },
    ] },
    { type: 1, name: 'wort', description: 'Verbotenes Wort hinzufügen oder entfernen', options: [
      action, { type: 3, name: 'wort', description: 'Das Wort (ganze Wörter, Groß-/Kleinschreibung egal)', required: true, max_length: 40 },
    ] },
    { type: 1, name: 'domain', description: 'Bei der Link-Regel erlaubte Domain hinzufügen oder entfernen', options: [
      action, { type: 3, name: 'domain', description: 'z. B. youtube.com (Unterseiten und Subdomains eingeschlossen)', required: true, max_length: 100 },
    ] },
    { type: 1, name: 'ausnahme-kanal', description: 'Kanal von der Prüfung ausnehmen oder wieder einbeziehen', options: [
      { type: 7, name: 'kanal', description: 'Kanal (erneut wählen = wieder prüfen)', required: true, channel_types: [ChannelType.GuildText, ChannelType.GuildAnnouncement, ChannelType.GuildVoice, ChannelType.GuildForum] },
    ] },
  ],
  default_member_permissions: '32',
  contexts: [0],
};

export async function execute(interaction, { pool, config }) {
  const reply = (content) => interaction.reply({ content, flags: MessageFlags.Ephemeral, allowedMentions: { parse: [] } });
  if (!isGuildAdmin(interaction)) return void (await reply('Das dürfen nur Server-Admins (Administrator oder „Server verwalten“).'));
  const org = await orgForGuild(pool, interaction.guildId);
  if (org === null) return void (await reply('Für diesen Server ist noch keine Orga eingerichtet. Das erledigt ein Server-Admin mit /einrichten.'));
  try {
    const cfg = await loadConfig(pool, org.id);
    const sub = interaction.options.getSubcommand();
    const add = interaction.options.getString('aktion') === 'add';
    if (sub === 'schalter') cfg[interaction.options.getString('regel', true)] = interaction.options.getBoolean('an', true);
    else if (sub === 'erwaehnungen') {
      const n = interaction.options.getInteger('anzahl', true);
      if (n > 0 && n < 3) throw new OrgError('Die Grenze muss mindestens 3 sein (oder 0 zum Ausschalten).');
      cfg.mentions = n;
    } else if (sub === 'wort') cfg.words = changeList(cfg.words, normalizeWord(interaction.options.getString('wort', true)), add, MAX_WORDS, 'der verbotenen Wörter');
    else if (sub === 'domain') cfg.domains = changeList(cfg.domains, normalizeDomain(interaction.options.getString('domain', true)), add, MAX_DOMAINS, 'der erlaubten Domains');
    else if (sub === 'ausnahme-kanal') {
      const id = interaction.options.getChannel('kanal', true).id;
      cfg.exemptChannels = changeList(cfg.exemptChannels, id, !cfg.exemptChannels.includes(id), MAX_EXEMPT_CHANNELS, 'der ausgenommenen Kanäle');
    }
    if (sub !== 'anzeigen') {
      await saveConfig(pool, org.id, cfg);
      forgetGuild(interaction.guildId);
    }
    await reply(configText(cfg, config.messageContent));
  } catch (err) {
    if (err instanceof OrgError) return void (await reply(err.message));
    if (!isMissingTable(err)) throw err;
    await reply(MISSING_TABLE_TEXT);
  }
}
