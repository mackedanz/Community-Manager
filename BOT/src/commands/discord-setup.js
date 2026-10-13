// /discord-setup: Rollen, Kanäle, Regeln und Willkommensnachricht anlegen, die noch fehlen (nur Server-Admins).
// Erst eine Vorschau, gemacht wird erst nach „Jetzt einrichten“. Planung und Ausführung stehen in lib/serversetup.js.
import { MessageFlags, PermissionFlagsBits } from 'discord.js';
import { guildIconUrl, guildInfo, invokerName, isGuildAdmin } from '../lib/guild.js';
import { modRoleIds } from '../lib/moderation.js';
import { OrgError } from '../lib/errors.js';
import { ensureOrg } from '../lib/onboarding.js';
import { CHANNEL_DEFS, planSetup, planText, runSetup } from '../lib/serversetup.js';
import { getSetting, isMissingTable, MISSING_TABLE_TEXT } from '../lib/settings.js';
import { VISITOR_ROLE } from '../lib/welcome.js';

export const audience = 'admins';

export const data = {
  name: 'discord-setup',
  description: 'Fehlende Rollen, Kategorien, Kanäle und Regeln anlegen (nur Server-Admins, mit Vorschau)',
  type: 1,
  default_member_permissions: '32',
  contexts: [0],
};

/** Rechte des Bots für die Einladung: die bisherigen plus „Kanäle verwalten“. */
export const INVITE_PERMISSIONS = '19009928424534';
const NEEDED = [PermissionFlagsBits.ManageRoles, PermissionFlagsBits.ManageChannels];

const EPHEMERAL = MessageFlags.Ephemeral;
const NO_MENTIONS = { parse: [] };
const running = new Set();

export function handles(customId) {
  return customId === 'setup:go' || customId === 'setup:no';
}

/** Zustand des Servers und der Einstellungen für planSetup(). */
async function gather(pool, guild, org) {
  const [roles, channels] = await Promise.all([guild.roles.fetch(), guild.channels.fetch()]);
  const settings = {};
  for (const def of CHANNEL_DEFS.filter((d) => d.setting)) settings[def.key] = await getSetting(pool, org.id, def.setting);
  return {
    org,
    modRoles: await modRoleIds(pool, org.id),
    visitorRoles: [await getSetting(pool, org.id, VISITOR_ROLE)].filter(Boolean),
    settings,
    roles: [...roles.values()].filter((r) => r.id !== guild.id).map((r) => ({ id: r.id, name: r.name, managed: r.managed })),
    channels: [...channels.values()].filter(Boolean).map((c) => ({ id: c.id, name: c.name, type: c.type })),
  };
}

async function botProblem(guild, config) {
  const me = guild.members.me ?? (await guild.members.fetchMe());
  if (me.permissions.has(NEEDED)) return null;
  return 'Dem Bot fehlen die Rechte „Rollen verwalten“ und „Kanäle verwalten“. Bitte den Bot mit diesem Link neu einladen (er bleibt auf dem Server, nur die Rechte ändern sich) '
    + `und /discord-setup noch einmal ausführen:\nhttps://discord.com/oauth2/authorize?client_id=${config.applicationId}&scope=bot%20applications.commands&permissions=${INVITE_PERMISSIONS}`;
}

export async function execute(interaction, { pool, config }) {
  if (!isGuildAdmin(interaction)) {
    return void (await interaction.reply({ content: 'Das dürfen nur Server-Admins (Administrator oder „Server verwalten“).', flags: EPHEMERAL }));
  }
  await interaction.deferReply({ flags: EPHEMERAL });
  const say = (content, components = []) => interaction.editReply({ content, embeds: [], components, allowedMentions: NO_MENTIONS });
  try {
    const guild = interaction.guild ?? (await interaction.client.guilds.fetch(interaction.guildId));
    const info = await guildInfo(interaction.client, interaction.guildId).catch(() => null);
    const org = await ensureOrg(pool, config, {
      guildId: interaction.guildId, invokerId: interaction.user.id, invokerName: invokerName(interaction),
      guild: info, iconUrl: info ? guildIconUrl(interaction.guildId, info.icon) : null,
    });
    const problem = await botProblem(guild, config);
    if (problem) return void (await say(problem));
    const plan = planSetup(await gather(pool, guild, org));
    if (plan.empty) return void (await say('Alles ist schon eingerichtet: Rollen und Kanäle sind vorhanden und eingetragen. Es gibt nichts zu tun.'));
    // Als Kasten: dort sind bis zu 4096 Zeichen erlaubt, im normalen Text nur 2000
    await interaction.editReply({
      content: '',
      embeds: [{ title: 'Das würde /discord-setup tun', description: planText(plan), color: 0x0ea5e9 }],
      allowedMentions: NO_MENTIONS,
      components: [{ type: 1, components: [
        { type: 2, style: 3, label: 'Jetzt einrichten', custom_id: 'setup:go' },
        { type: 2, style: 2, label: 'Abbrechen', custom_id: 'setup:no' },
      ] }],
    });
  } catch (err) {
    if (err instanceof OrgError) return void (await say(err.message));
    if (isMissingTable(err)) return void (await say(MISSING_TABLE_TEXT));
    throw err;
  }
}

export async function handleComponent(interaction, { pool, config, log }) {
  if (!isGuildAdmin(interaction)) {
    return void (await interaction.reply({ content: 'Das dürfen nur Server-Admins.', flags: EPHEMERAL }));
  }
  if (interaction.customId === 'setup:no') {
    return void (await interaction.update({ content: 'Abgebrochen. Es wurde nichts geändert.', embeds: [], components: [] }));
  }
  if (running.has(interaction.guildId)) {
    return void (await interaction.reply({ content: 'Die Einrichtung läuft gerade schon.', flags: EPHEMERAL }));
  }
  running.add(interaction.guildId);
  try {
    await interaction.update({ content: 'Wird eingerichtet … das dauert etwa eine halbe Minute.', embeds: [], components: [] });
    const guild = interaction.guild ?? (await interaction.client.guilds.fetch(interaction.guildId));
    const info = await guildInfo(interaction.client, interaction.guildId).catch(() => null);
    const org = await ensureOrg(pool, config, {
      guildId: interaction.guildId, invokerId: interaction.user.id, invokerName: invokerName(interaction),
      guild: info, iconUrl: info ? guildIconUrl(interaction.guildId, info.icon) : null,
    });
    const problem = await botProblem(guild, config);
    if (problem) return void (await interaction.editReply({ content: problem, allowedMentions: NO_MENTIONS }));
    // Neu planen: seit der Vorschau kann sich etwas geändert haben
    const plan = planSetup(await gather(pool, guild, org));
    if (plan.empty) return void (await interaction.editReply('Es gibt nichts mehr zu tun.'));
    const report = await runSetup({ pool, guild, org, plan, botId: interaction.client.user.id });
    const next = '\n\nAls Nächstes: `/mod-einstellungen` und `/automod` für die Moderation, `/willkommen text:…` für einen eigenen Begrüßungstext, '
      + '`/serverregeln` für eigene Regeln, und die Rollen den passenden Leuten geben.';
    await interaction.editReply({
      content: '',
      embeds: [{ title: 'Fertig', description: `${report.join('\n')}${next}`.slice(0, 4000), color: 0x22c55e }],
      allowedMentions: NO_MENTIONS,
    });
  } catch (err) {
    log.error('/discord-setup fehlgeschlagen', err);
    await interaction.editReply({ content: err instanceof OrgError ? err.message : 'Da ist etwas schiefgelaufen. Fertiges bleibt bestehen; bitte /discord-setup später noch einmal ausführen.', allowedMentions: NO_MENTIONS }).catch(() => {});
  } finally {
    running.delete(interaction.guildId);
  }
}
