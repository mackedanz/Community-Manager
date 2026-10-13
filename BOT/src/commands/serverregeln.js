// /serverregeln: Ein Admin veröffentlicht einen Text nach Wahl in einem Kanal nach Wahl, darunter die Schaltfläche
// "Bestätigen". Wer sie drückt, bekommt die gewählte Rolle.
//
// Ablauf: /serverregeln kanal rolle -> Eingabefenster für Überschrift und Text -> Nachricht im Kanal.
// Die Rollen-ID steckt in der Schaltfläche selbst (custom_id), dafür braucht es keine Tabelle; die Schaltfläche
// funktioniert auch nach einem Neustart des Bots.
import { ChannelType, DiscordAPIError, MessageFlags, PermissionFlagsBits } from 'discord.js';
import { orgForGuild } from '../db.js';
import { isGuildAdmin, memberRoleIds } from '../lib/guild.js';
import { getSetting } from '../lib/settings.js';
import { VISITOR_ROLE } from '../lib/welcome.js';

export const data = {
  name: 'serverregeln',
  description: 'Regeln mit „Bestätigen“-Schaltfläche in einem Kanal veröffentlichen; Bestätigen vergibt eine Rolle',
  type: 1,
  options: [
    { type: 7, name: 'kanal', description: 'Kanal, in dem die Regeln erscheinen', required: true, channel_types: [ChannelType.GuildText, ChannelType.GuildAnnouncement] },
    { type: 8, name: 'rolle', description: 'Rolle, die man mit „Bestätigen“ bekommt (Standard: Besucher)', required: false },
  ],
  default_member_permissions: '32', // "Server verwalten"; der Bot prüft die Rechte zusätzlich selbst
  contexts: [0],
};

const EPHEMERAL = MessageFlags.Ephemeral;
const SNOWFLAKE = /^\d{17,20}$/;
const NO_MENTIONS = { parse: [] };

/** Nachricht mit den Regeln und der Schaltfläche "Bestätigen". */
export function rulesMessage(title, text, roleId) {
  return {
    embeds: [{ title: title || 'Serverregeln', description: text, color: 0x0ea5e9 }],
    components: [{ type: 1, components: [{ type: 2, style: 3, label: 'Bestätigen', custom_id: `rules:ok:${roleId}` }] }],
    allowedMentions: NO_MENTIONS,
  };
}

/**
 * Darf der Bot diese Rolle vergeben und in diesem Kanal schreiben? Liefert den Grund als Text, oder null.
 * @param {{everyone: boolean, managed: boolean, botCanManageRoles: boolean, botAboveRole: boolean, botCanPost: boolean}} c
 */
export function setupProblem(c) {
  if (c.everyone) return 'Die Rolle @everyone hat ohnehin jeder. Bitte eine andere Rolle wählen.';
  if (c.managed) return 'Diese Rolle gehört zu einer Integration oder einem Bot und lässt sich nicht vergeben.';
  if (!c.botCanManageRoles) return 'Dem Bot fehlt das Recht „Rollen verwalten“. Bitte in den Servereinstellungen bei der Rolle des Bots einschalten.';
  if (!c.botAboveRole) return 'Die Rolle des Bots steht unter der gewählten Rolle. Bitte in den Servereinstellungen unter „Rollen“ die Rolle des Bots darüber ziehen.';
  if (!c.botCanPost) return 'Der Bot darf in diesem Kanal nicht schreiben (nötig: Kanal ansehen, Nachrichten senden, Links einbetten).';
  return null;
}

async function botMember(guild) {
  return guild.members.me ?? guild.members.fetchMe();
}

export async function execute(interaction, { pool }) {
  if (!isGuildAdmin(interaction)) {
    await interaction.reply({ content: 'Das dürfen nur Server-Admins (Administrator oder „Server verwalten“).', flags: EPHEMERAL });
    return;
  }
  const channel = interaction.options.getChannel('kanal', true);
  const guild = interaction.guild ?? (await interaction.client.guilds.fetch(interaction.guildId));
  let role = interaction.options.getRole('rolle');
  if (role === null) {
    // Ohne Angabe: die Rolle „Besucher“, die /discord-setup angelegt hat
    const org = await orgForGuild(pool, interaction.guildId).catch(() => null);
    const id = org === null ? null : await getSetting(pool, org.id, VISITOR_ROLE).catch(() => null);
    role = id === null ? null : (guild.roles.cache.get(id) ?? (await guild.roles.fetch(id).catch(() => null)));
    if (role === null) {
      await interaction.reply({ content: 'Bitte eine Rolle angeben (rolle:@…). Eine Rolle „Besucher“ als Standard legt /discord-setup an.', flags: EPHEMERAL });
      return;
    }
  }
  const me = await botMember(guild);
  const fullChannel = guild.channels.cache.get(channel.id) ?? (await guild.channels.fetch(channel.id));
  const post = fullChannel?.permissionsFor(me);
  const problem = setupProblem({
    everyone: role.id === guild.id,
    managed: Boolean(role.managed),
    botCanManageRoles: me.permissions.has(PermissionFlagsBits.ManageRoles),
    botAboveRole: me.roles.highest.position > role.position,
    botCanPost: Boolean(post?.has([PermissionFlagsBits.ViewChannel, PermissionFlagsBits.SendMessages, PermissionFlagsBits.EmbedLinks])),
  });
  if (problem !== null) {
    await interaction.reply({ content: problem, flags: EPHEMERAL, allowedMentions: NO_MENTIONS });
    return;
  }
  await interaction.showModal({
    title: 'Serverregeln',
    custom_id: `rules:modal:${channel.id}:${role.id}`,
    components: [
      { type: 1, components: [{ type: 4, custom_id: 'title', label: 'Überschrift', style: 1, required: false, max_length: 256, value: 'Serverregeln' }] },
      { type: 1, components: [{ type: 4, custom_id: 'text', label: 'Text der Regeln', style: 2, required: true, max_length: 4000, placeholder: 'Die Regeln dieses Servers … (Discord-Formatierung wie **fett** geht)' }] },
    ],
  });
}

export function handles(customId) {
  return customId.startsWith('rules:modal:') || customId.startsWith('rules:ok:');
}

/** Eingabefenster abgeschickt (Regeln veröffentlichen) oder "Bestätigen" gedrückt (Rolle vergeben). */
export async function handleComponent(interaction) {
  const [, kind, a, b] = interaction.customId.split(':');
  if (kind === 'modal') {
    await publish(interaction, a, b);
    return;
  }
  await confirm(interaction, a);
}

async function publish(interaction, channelId, roleId) {
  if (!SNOWFLAKE.test(channelId ?? '') || !SNOWFLAKE.test(roleId ?? '')) return;
  if (!isGuildAdmin(interaction)) {
    await interaction.reply({ content: 'Das dürfen nur Server-Admins.', flags: EPHEMERAL });
    return;
  }
  const title = interaction.fields.getTextInputValue('title').trim();
  const text = interaction.fields.getTextInputValue('text').trim();
  await interaction.deferReply({ flags: EPHEMERAL });
  try {
    const channel = await interaction.client.channels.fetch(channelId);
    const msg = await channel.send(rulesMessage(title, text, roleId));
    await interaction.editReply({ content: `Die Regeln stehen jetzt in <#${channelId}>: ${msg.url}\nWer „Bestätigen“ drückt, bekommt <@&${roleId}>.`, allowedMentions: NO_MENTIONS });
  } catch (err) {
    if (!(err instanceof DiscordAPIError)) throw err;
    await interaction.editReply(`Die Regeln konnten nicht veröffentlicht werden (Discord: ${err.message}).`);
  }
}

async function confirm(interaction, roleId) {
  if (!SNOWFLAKE.test(roleId ?? '')) return;
  if (memberRoleIds(interaction).includes(roleId)) {
    await interaction.reply({ content: 'Du hast die Regeln schon bestätigt.', flags: EPHEMERAL });
    return;
  }
  try {
    await interaction.guild.members.addRole({ user: interaction.user.id, role: roleId, reason: 'Serverregeln bestätigt' });
    await interaction.reply({ content: `Danke! Du hast die Regeln bestätigt und die Rolle <@&${roleId}> bekommen.`, flags: EPHEMERAL, allowedMentions: NO_MENTIONS });
  } catch (err) {
    if (!(err instanceof DiscordAPIError)) throw err;
    // 50013: fehlende Rechte oder Rolle des Bots zu weit unten; 10011: Rolle gelöscht
    const why = err.code === 10011 ? 'Die Rolle gibt es nicht mehr.' : 'Der Bot darf diese Rolle gerade nicht vergeben.';
    await interaction.reply({ content: `${why} Bitte sag einem Admin Bescheid.`, flags: EPHEMERAL });
  }
}
