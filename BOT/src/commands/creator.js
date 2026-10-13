// /creator: YouTube-Kanäle verwalten, deren neue Videos der Bot in einen Discord-Kanal postet (nur Server-Admins).
import { ChannelType, MessageFlags, PermissionFlagsBits } from 'discord.js';
import { orgForGuild } from '../db.js';
import { addCreator, listCreators, MAX_CREATORS, removeCreator } from '../lib/creators.js';
import { OrgError } from '../lib/errors.js';
import { isGuildAdmin } from '../lib/guild.js';
import { isMissingTable, MISSING_TABLE_TEXT } from '../lib/settings.js';
import { cut } from '../lib/text.js';
import { resolveChannel } from '../lib/youtube.js';

export const data = {
  name: 'creator',
  description: 'YouTube-Creator verwalten: neue Videos postet der Bot automatisch (nur Server-Admins)',
  type: 1,
  options: [
    { type: 1, name: 'hinzufuegen', description: 'YouTube-Kanal hinzufügen oder seinen Discord-Kanal ändern', options: [
      { type: 3, name: 'youtube', description: 'Link zum Kanal (youtube.com/@Name), @Name oder Kanal-ID', required: true, max_length: 200 },
      { type: 7, name: 'kanal', description: 'Discord-Kanal für die neuen Videos', required: true, channel_types: [ChannelType.GuildText, ChannelType.GuildAnnouncement] },
      { type: 8, name: 'rolle', description: 'Diese Rolle bei jedem neuen Video erwähnen (freiwillig)', required: false },
      { type: 5, name: 'ohne_shorts', description: 'Shorts nicht posten', required: false },
    ] },
    { type: 1, name: 'entfernen', description: 'Creator entfernen', options: [
      { type: 3, name: 'creator', description: 'Creator (Vorschläge beim Tippen)', required: true, autocomplete: true },
    ] },
    { type: 1, name: 'liste', description: 'Alle Creator dieser Orga anzeigen' },
  ],
  default_member_permissions: '32',
  contexts: [0],
};

const EPHEMERAL = MessageFlags.Ephemeral;
const HEX_ID = /^[a-f0-9]{24}$/;
const NEEDED = [PermissionFlagsBits.ViewChannel, PermissionFlagsBits.SendMessages, PermissionFlagsBits.EmbedLinks];
const unix = (dbTime) => Math.floor(Date.parse(`${dbTime.replace(' ', 'T')}Z`) / 1000);

/** Übersicht aller Creator. */
export function creatorsEmbed(org, creators) {
  const lines = creators.map((c) => {
    const extras = [c.mention_role_id ? `erwähnt <@&${c.mention_role_id}>` : null, Number(c.skip_shorts) ? 'ohne Shorts' : null].filter(Boolean).join(', ');
    const state = c.last_error ? ` – ⚠ ${c.last_error}` : c.checked_at ? ` – geprüft <t:${unix(c.checked_at)}:R>` : '';
    return `**[${c.name}](https://www.youtube.com/channel/${c.channel_id})** → <#${c.discord_channel_id}>${extras ? ` (${extras})` : ''}${state}`;
  });
  return {
    title: cut(`Creator – ${org.name} (${creators.length}/${MAX_CREATORS})`, 256),
    description: cut(lines.length > 0 ? lines.join('\n') : 'Noch keine Creator. Hinzufügen mit /creator hinzufuegen.', 4000),
    color: 0x0ea5e9,
    footer: { text: 'Der Bot schaut alle 10 Minuten nach neuen Videos.' },
  };
}

export async function autocomplete(interaction, { pool }) {
  if (!isGuildAdmin(interaction)) {
    await interaction.respond([]);
    return;
  }
  const org = await orgForGuild(pool, interaction.guildId);
  if (org === null) {
    await interaction.respond([]);
    return;
  }
  const term = String(interaction.options.getFocused() ?? '').trim().toLowerCase();
  const list = (await listCreators(pool, org.id).catch(() => [])).filter((c) => c.name.toLowerCase().includes(term)).slice(0, 25);
  await interaction.respond(list.map((c) => ({ name: cut(c.name, 100), value: c.id })));
}

export async function execute(interaction, { pool }) {
  const reply = (payload) => interaction.reply({ ...payload, flags: EPHEMERAL, allowedMentions: { parse: [] } });
  if (!isGuildAdmin(interaction)) {
    await reply({ content: 'Das dürfen nur Server-Admins (Administrator oder „Server verwalten“).' });
    return;
  }
  const org = await orgForGuild(pool, interaction.guildId);
  if (org === null) {
    await reply({ content: 'Für diesen Server ist noch keine Orga eingerichtet. Das erledigt ein Server-Admin mit /einrichten.' });
    return;
  }
  const sub = interaction.options.getSubcommand();
  try {
    if (sub === 'liste') {
      await reply({ embeds: [creatorsEmbed(org, await listCreators(pool, org.id))] });
      return;
    }
    if (sub === 'entfernen') {
      const id = interaction.options.getString('creator', true);
      const n = HEX_ID.test(id) ? await removeCreator(pool, org.id, id) : 0;
      await reply({ content: n > 0 ? 'Creator entfernt. Neue Videos werden nicht mehr gepostet.' : 'Diesen Creator gibt es nicht. Bitte aus den Vorschlägen wählen.' });
      return;
    }
    // hinzufuegen
    const channel = interaction.options.getChannel('kanal', true);
    const guild = interaction.guild;
    const me = guild.members.me ?? (await guild.members.fetchMe());
    const full = guild.channels.cache.get(channel.id) ?? (await guild.channels.fetch(channel.id));
    if (!full?.permissionsFor(me)?.has(NEEDED)) {
      await reply({ content: `Der Bot darf in <#${channel.id}> nicht schreiben (nötig: Kanal ansehen, Nachrichten senden, Links einbetten).` });
      return;
    }
    const role = interaction.options.getRole('rolle');
    await interaction.deferReply({ flags: EPHEMERAL });
    let note;
    try {
      const yt = await resolveChannel(interaction.options.getString('youtube', true));
      const r = await addCreator(pool, org.id, {
        channelId: yt.channelId,
        name: yt.name,
        discordChannelId: channel.id,
        mentionRoleId: role && role.id !== guild.id ? role.id : null,
        skipShorts: interaction.options.getBoolean('ohne_shorts') === true,
      });
      note = `${r.updated ? 'Geändert' : 'Hinzugefügt'}: **${yt.name}** → <#${channel.id}>${role ? `, erwähnt ${role}` : ''}. `
        + 'Ab jetzt postet der Bot jedes neue Video (geprüft wird alle 10 Minuten). Bisherige Videos werden nicht nachträglich gepostet.';
    } catch (err) {
      if (!(err instanceof OrgError)) throw err;
      note = `Nicht hinzugefügt: ${err.message}`;
    }
    await interaction.editReply({ content: note, allowedMentions: { parse: [] } });
  } catch (err) {
    if (!isMissingTable(err)) throw err;
    if (interaction.deferred) await interaction.editReply(MISSING_TABLE_TEXT);
    else await reply({ content: MISSING_TABLE_TEXT });
  }
}
