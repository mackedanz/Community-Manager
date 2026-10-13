// /mod: Moderation durch Admins und Moderatoren-Rollen (festgelegt mit /mod-einstellungen).
// Verwarnen, Timeout, Kick, Bann, Nachrichten löschen. Jede Aktion wird als Fall gespeichert, der Person per
// Direktnachricht mitgeteilt (wenn möglich) und in den Protokoll-Kanal geschrieben.
import { DiscordAPIError, MessageFlags, PermissionFlagsBits } from 'discord.js';
import { nowDb, orgForGuild } from '../db.js';
import { OrgError } from '../lib/errors.js';
import { isGuildAdmin, memberRoleIds } from '../lib/guild.js';
import {
  activeWarnings, addCase, canModerate, caseEmbed, casesEmbed, dmText, durationText, explainDiscordError, listCases,
  logToChannel, modRoleIds, parseDuration, removeWarning, targetProblem,
} from '../lib/moderation.js';
import { isMissingTable, MISSING_TABLE_TEXT } from '../lib/settings.js';

/** Für /hilfe */
export const audience = 'mods';

const user = (name, description, required = true) => ({ type: 6, name, description, required });
const reasonOpt = (required = false) => ({ type: 3, name: 'grund', description: 'Grund (sieht die Person und das Protokoll)', required, max_length: 500 });

export const data = {
  name: 'mod',
  description: 'Moderation: verwarnen, Timeout, kicken, bannen, Nachrichten löschen (Admins und Moderatoren)',
  type: 1,
  options: [
    { type: 1, name: 'verwarnen', description: 'Eine Person verwarnen', options: [user('person', 'Wer wird verwarnt?'), reasonOpt(true)] },
    { type: 1, name: 'timeout', description: 'Eine Person für eine Zeit stummschalten', options: [
      user('person', 'Wer bekommt den Timeout?'),
      { type: 3, name: 'dauer', description: 'z. B. 30m, 2h, 1d, 1w (höchstens 28 Tage)', required: true, max_length: 10 },
      reasonOpt(),
    ] },
    { type: 1, name: 'timeout-aufheben', description: 'Einen Timeout beenden', options: [user('person', 'Wessen Timeout endet?'), reasonOpt()] },
    { type: 1, name: 'kick', description: 'Eine Person vom Server entfernen (sie kann wiederkommen)', options: [user('person', 'Wer wird gekickt?'), reasonOpt()] },
    { type: 1, name: 'bann', description: 'Eine Person bannen', options: [
      user('person', 'Wer wird gebannt?'),
      reasonOpt(),
      { type: 4, name: 'nachrichten_tage', description: 'Nachrichten der letzten Tage mitlöschen (0 bis 7)', required: false, min_value: 0, max_value: 7 },
    ] },
    { type: 1, name: 'entbannen', description: 'Einen Bann aufheben', options: [
      { type: 3, name: 'person_id', description: 'Discord-ID der Person (Nutzer-ID kopieren)', required: true, max_length: 20 },
      reasonOpt(),
    ] },
    { type: 1, name: 'warnungen', description: 'Verwarnungen und Maßnahmen einer Person anzeigen', options: [user('person', 'Wessen Fälle?')] },
    { type: 1, name: 'warnung-entfernen', description: 'Eine Verwarnung zurücknehmen', options: [
      { type: 4, name: 'nummer', description: 'Fallnummer der Verwarnung', required: true, min_value: 1 },
    ] },
    { type: 1, name: 'loeschen', description: 'Letzte Nachrichten in diesem Kanal löschen (nicht älter als 14 Tage)', options: [
      { type: 4, name: 'anzahl', description: 'Wie viele der letzten Nachrichten (1 bis 100)?', required: true, min_value: 1, max_value: 100 },
      user('person', 'Nur Nachrichten dieser Person', false),
    ] },
  ],
  contexts: [0],
};

const EPHEMERAL = MessageFlags.Ephemeral;
const NO_MENTIONS = { parse: [] };
const SNOWFLAKE = /^\d{17,20}$/;

const nameOf = (u) => u.globalName || u.username;

/** Ein Fall: speichern, protokollieren, Kasten zurückgeben. */
async function record(interaction, pool, org, log, c) {
  const id = await addCase(pool, { orgId: org.id, moderatorId: interaction.user.id, ...c });
  const embed = caseEmbed({
    id, moderator_id: interaction.user.id, created_at: nowDb(), target_id: c.targetId, target_name: c.targetName,
    action: c.action, reason: c.reason ?? null, detail: c.detail ?? null,
  });
  await logToChannel(interaction.client, pool, org.id, { embeds: [embed] }, log);
  return embed;
}

/** Direktnachricht an die Person; schlägt sie fehl (DMs aus), ist das kein Fehler. Liefert, ob sie ankam. */
async function tell(targetUser, text) {
  try {
    await targetUser.send({ content: text, allowedMentions: NO_MENTIONS });
    return true;
  } catch {
    return false;
  }
}

export async function execute(interaction, { pool, log }) {
  const reply = (content, extra = {}) => interaction.editReply({ content, allowedMentions: NO_MENTIONS, ...extra });
  await interaction.deferReply({ flags: EPHEMERAL });
  try {
    const org = await orgForGuild(pool, interaction.guildId);
    if (org === null) return void (await reply('Für diesen Server ist noch keine Orga eingerichtet. Das erledigt ein Server-Admin mit /einrichten.'));
    const admin = isGuildAdmin(interaction);
    const modRoles = await modRoleIds(pool, org.id);
    if (!canModerate({ admin, roles: memberRoleIds(interaction) }, modRoles)) {
      return void (await reply(modRoles.length === 0
        ? 'Das dürfen nur Server-Admins. Moderatoren-Rollen legt ein Admin mit /mod-einstellungen fest.'
        : 'Das dürfen nur Moderatoren und Server-Admins.'));
    }
    const sub = interaction.options.getSubcommand();
    const ctx = { interaction, pool, org, log, admin, modRoles, reply };
    const handler = { verwarnen, timeout, 'timeout-aufheben': untimeout, kick, bann, entbannen, warnungen, 'warnung-entfernen': warnungEntfernen, loeschen }[sub];
    await handler(ctx);
  } catch (err) {
    if (isMissingTable(err)) return void (await reply(MISSING_TABLE_TEXT));
    if (err instanceof OrgError) return void (await reply(err.message));
    const known = explainDiscordError(err);
    if (known) return void (await reply(known));
    throw err;
  }
}

/** Das Mitglied zur Option "person", geprüft auf Rechte und Rollen. Liefert { user, member } oder wirft OrgError. */
async function target(ctx, { needMember = true, check = 'moderatable' } = {}) {
  const { interaction, admin, modRoles } = ctx;
  const u = interaction.options.getUser('person', true);
  const guild = interaction.guild ?? (await interaction.client.guilds.fetch(interaction.guildId));
  const member = await guild.members.fetch(u.id).catch(() => null);
  if (member === null && needMember) throw new OrgError('Diese Person ist nicht (mehr) auf dem Server.');
  const actor = interaction.member;
  const problem = targetProblem({
    actorId: interaction.user.id,
    targetId: u.id,
    targetIsSelf: u.id === interaction.client.user.id,
    targetIsOwner: guild.ownerId === u.id,
    actorAdmin: admin,
    targetIsAdmin: member?.permissions.has(PermissionFlagsBits.Administrator) ?? false,
    targetIsMod: member ? modRoles.some((r) => member.roles.cache.has(r)) : false,
    actorTop: actor?.roles?.highest?.position ?? 0,
    targetTop: member?.roles.highest.position ?? -1,
  });
  if (problem) throw new OrgError(problem);
  if (member !== null && member[check] === false) {
    throw new OrgError('Der Bot kann diese Person nicht moderieren: Seine Rolle muss im Server höher stehen, und ihm fehlt eventuell das Recht (Mitglieder kicken, bannen oder Mitglieder moderieren).');
  }
  return { user: u, member, guild };
}

const auditReason = (interaction, reason) => `${nameOf(interaction.user)}: ${reason ?? 'ohne Angabe'}`.slice(0, 480);
const sent = (ok) => (ok ? 'Die Person wurde per Direktnachricht informiert.' : 'Die Person war per Direktnachricht nicht erreichbar.');

async function verwarnen(ctx) {
  const { interaction, pool, org, log, reply } = ctx;
  const t = await target(ctx, { needMember: false, check: null });
  const reason = interaction.options.getString('grund', true);
  const embed = await record(interaction, pool, org, log, { targetId: t.user.id, targetName: nameOf(t.user), action: 'WARN', reason });
  const ok = await tell(t.user, dmText(t.guild.name, 'WARN', reason));
  const n = await activeWarnings(pool, org.id, t.user.id);
  await reply(`Verwarnt. ${t.user.username} hat jetzt ${n} aktive Verwarnung${n === 1 ? '' : 'en'}. ${sent(ok)}`, { embeds: [embed] });
}

async function timeout(ctx) {
  const { interaction, pool, org, log, reply } = ctx;
  const ms = parseDuration(interaction.options.getString('dauer', true));
  const t = await target(ctx);
  const reason = interaction.options.getString('grund');
  await t.member.timeout(ms, auditReason(interaction, reason));
  const detail = durationText(ms);
  const embed = await record(interaction, pool, org, log, { targetId: t.user.id, targetName: nameOf(t.user), action: 'TIMEOUT', reason, detail });
  const ok = await tell(t.user, dmText(t.guild.name, 'TIMEOUT', reason, detail));
  await reply(`Timeout für ${detail} gesetzt. ${sent(ok)}`, { embeds: [embed] });
}

async function untimeout(ctx) {
  const { interaction, pool, org, log, reply } = ctx;
  const t = await target(ctx);
  if (!t.member.isCommunicationDisabled()) throw new OrgError('Diese Person hat gerade keinen Timeout.');
  const reason = interaction.options.getString('grund');
  await t.member.timeout(null, auditReason(interaction, reason));
  const embed = await record(interaction, pool, org, log, { targetId: t.user.id, targetName: nameOf(t.user), action: 'UNTIMEOUT', reason });
  await reply('Timeout aufgehoben.', { embeds: [embed] });
}

async function kick(ctx) {
  const { interaction, pool, org, log, reply } = ctx;
  const t = await target(ctx, { check: 'kickable' });
  const reason = interaction.options.getString('grund');
  // Erst benachrichtigen: nach dem Kick teilen Bot und Person keinen Server mehr
  const ok = await tell(t.user, dmText(t.guild.name, 'KICK', reason));
  await t.member.kick(auditReason(interaction, reason));
  const embed = await record(interaction, pool, org, log, { targetId: t.user.id, targetName: nameOf(t.user), action: 'KICK', reason });
  await reply(`Gekickt. ${sent(ok)}`, { embeds: [embed] });
}

async function bann(ctx) {
  const { interaction, pool, org, log, reply } = ctx;
  const t = await target(ctx, { needMember: false, check: 'bannable' });
  const reason = interaction.options.getString('grund');
  const days = interaction.options.getInteger('nachrichten_tage') ?? 0;
  const ok = t.member ? await tell(t.user, dmText(t.guild.name, 'BAN', reason)) : false;
  await t.guild.members.ban(t.user.id, { reason: auditReason(interaction, reason), deleteMessageSeconds: days * 86_400 });
  const embed = await record(interaction, pool, org, log, {
    targetId: t.user.id, targetName: nameOf(t.user), action: 'BAN', reason, detail: days > 0 ? `Nachrichten der letzten ${days} Tage gelöscht` : null,
  });
  await reply(`Gebannt. ${t.member ? sent(ok) : ''}`.trim(), { embeds: [embed] });
}

async function entbannen(ctx) {
  const { interaction, pool, org, log, reply } = ctx;
  const id = interaction.options.getString('person_id', true).trim();
  if (!SNOWFLAKE.test(id)) throw new OrgError('Das ist keine Discord-ID (17 bis 20 Ziffern). In Discord mit Entwicklermodus: Rechtsklick auf die Person, „Nutzer-ID kopieren“.');
  const reason = interaction.options.getString('grund');
  const guild = interaction.guild ?? (await interaction.client.guilds.fetch(interaction.guildId));
  let ban;
  try {
    ban = await guild.bans.fetch(id);
  } catch (err) {
    if (err instanceof DiscordAPIError && err.code === 10026) throw new OrgError('Diese Person ist nicht gebannt.');
    throw err;
  }
  await guild.members.unban(id, auditReason(interaction, reason));
  const embed = await record(interaction, pool, org, log, { targetId: id, targetName: nameOf(ban.user), action: 'UNBAN', reason });
  await reply('Bann aufgehoben.', { embeds: [embed] });
}

async function warnungen(ctx) {
  const { interaction, pool, org, reply } = ctx;
  const u = interaction.options.getUser('person', true);
  const [cases, active] = await Promise.all([listCases(pool, org.id, u.id), activeWarnings(pool, org.id, u.id)]);
  await reply('', { embeds: [casesEmbed(nameOf(u), cases, active)] });
}

async function warnungEntfernen(ctx) {
  const { interaction, pool, org, reply } = ctx;
  const id = interaction.options.getInteger('nummer', true);
  await removeWarning(pool, org.id, id);
  await reply(`Verwarnung #${id} ist zurückgenommen.`);
}

async function loeschen(ctx) {
  const { interaction, pool, org, log, reply } = ctx;
  const count = interaction.options.getInteger('anzahl', true);
  const only = interaction.options.getUser('person');
  const channel = interaction.channel;
  if (!channel?.isTextBased() || channel.isDMBased()) throw new OrgError('Hier lassen sich keine Nachrichten löschen.');
  // Bei einer Person werden die letzten 100 Nachrichten durchsucht, sonst genau die letzten
  const fetched = await channel.messages.fetch({ limit: 100 });
  const picked = [...fetched.values()].filter((m) => (only === null || m.author.id === only.id) && !m.pinned).slice(0, count);
  if (picked.length === 0) throw new OrgError('Keine passenden Nachrichten gefunden.');
  const deleted = await channel.bulkDelete(picked, true); // ältere als 14 Tage lässt Discord nicht zu
  const n = deleted.size;
  if (n === 0) throw new OrgError('Die Nachrichten sind älter als 14 Tage und lassen sich nicht auf einmal löschen.');
  const embed = await record(interaction, pool, org, log, {
    targetId: only?.id ?? interaction.user.id,
    targetName: only ? nameOf(only) : 'Kanal',
    action: 'PURGE',
    detail: `${n} Nachricht${n === 1 ? '' : 'en'} in #${channel.name}`,
  });
  await reply(`${n} Nachricht${n === 1 ? '' : 'en'} gelöscht.`, { embeds: [embed] });
}
