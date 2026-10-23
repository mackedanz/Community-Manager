// /orga: Einstellungen der Orga ansehen und ändern (nur Server-Admins, wie "Orga verwalten" in der Web-App).
// Rollen, RSI-Orga und Handle-Stil ändert /einrichten, das Aussehen /design, den Ankündigungskanal /termine-kanal.
import { MessageFlags } from 'discord.js';
import { orgForGuild } from '../db.js';
import { isGuildAdmin } from '../lib/guild.js';
import { OrgError } from '../lib/errors.js';
import { DISCORD_EVENT_MODES, orgCounts, renameOrg, setDiscordEventsMode } from '../lib/orgadmin.js';
import { parseRoleIds, parseRoleLabels, ROLE_KINDS } from '../lib/roles.js';
import { cleanHandleStyle, HANDLE_STYLES } from '../lib/rsi.js';
import { ANNOUNCE_CHANNEL, getSetting, isMissingTable } from '../lib/settings.js';
import { cut } from '../lib/text.js';

export const data = {
  name: 'orga',
  description: 'Einstellungen der Orga anzeigen und ändern: Name, Übernahme der Discord-Events (nur Server-Admins)',
  type: 1,
  options: [
    { type: 3, name: 'name', description: 'Neuer Name der Orga (2 bis 60 Zeichen)', required: false, min_length: 2, max_length: 60 },
    { type: 3, name: 'discord_events', description: 'Discord-Server-Events als Termine übernehmen', required: false, choices: [
      { name: 'Aus', value: 'OFF' }, { name: 'Als Entwurf übernehmen', value: 'DRAFT' }, { name: 'Direkt sichtbar übernehmen', value: 'PUBLISHED' },
    ] },
  ],
  default_member_permissions: '32',
  contexts: [0],
};

const ROLE_TITLES = { use: 'Nutzung', plan: 'Planer', recruit: 'Recruiter', instruct: 'Ausbilder', lead: 'Abteilungsleiter', admin: 'Orga-Leitung' };
const unix = (dbTime) => Math.floor(Date.parse(`${dbTime.replace(' ', 'T')}Z`) / 1000);

/** Übersicht der Einstellungen als Kasten. */
export function orgEmbed(org, { counts, announceChannel, gate, appUrl, note = null }) {
  const labels = parseRoleLabels(org.role_labels);
  const roles = Object.entries(ROLE_KINDS).map(([kind, col]) => {
    const ids = parseRoleIds(org[col]);
    return `${ROLE_TITLES[kind]}: ${ids.length === 0 ? '–' : ids.map((id) => labels[id] ?? `<@&${id}>`).join(', ')}`;
  });
  const fields = [
    { name: 'Rollen (ändern mit /einrichten)', value: cut(roles.join('\n'), 1024) },
    { name: 'RSI-Orga', value: org.rsi_sid ? `${org.rsi_org_name ?? org.rsi_sid} (${org.rsi_sid}), ${counts.rsiMembers} sichtbare Mitglieder` : 'nicht verbunden', inline: true },
    { name: 'Handle im Nickname', value: HANDLE_STYLES[cleanHandleStyle(org.handle_style)].split(' (')[0], inline: true },
    { name: 'Discord-Events übernehmen', value: DISCORD_EVENT_MODES[org.discord_events_mode] ?? 'aus', inline: true },
    { name: 'Terminankündigungen', value: announceChannel ? `<#${announceChannel}>` : 'aus (einschalten mit /termine-kanal)', inline: true },
    {
      name: 'Zugang',
      value: `${counts.allowed} auf der Zugangsliste, ${counts.members} mit Konto${gate ? ', Anmeldung nur mit Eintrag' : ''}`
        + (org.allowlist_synced_at ? `\nzuletzt abgeglichen <t:${unix(org.allowlist_synced_at)}:R>` : ''),
      inline: true,
    },
    { name: 'Kommende Termine', value: String(counts.events), inline: true },
  ];
  return {
    title: cut(`Orga: ${org.name}`, 256),
    url: `${appUrl}/o/${encodeURIComponent(org.slug)}/settings`,
    description: note ?? undefined,
    fields,
    color: 0x0ea5e9,
    footer: { text: 'Löschen der Orga und die Liste der Zugänge: auf der Webseite unter „Orga verwalten“.' },
  };
}

export async function execute(interaction, { pool, config }) {
  const reply = (payload) => interaction.reply({ ...payload, flags: MessageFlags.Ephemeral, allowedMentions: { parse: [] } });
  if (!isGuildAdmin(interaction)) {
    await reply({ content: 'Das dürfen nur Server-Admins (Administrator oder „Server verwalten“).' });
    return;
  }
  let org = await orgForGuild(pool, interaction.guildId);
  if (org === null) {
    await reply({ content: 'Für diesen Server ist noch keine Orga eingerichtet. Das erledigt ein Server-Admin mit /einrichten.' });
    return;
  }
  const name = interaction.options.getString('name');
  const mode = interaction.options.getString('discord_events');
  const done = [];
  try {
    if (name !== null) {
      await renameOrg(pool, org.id, name);
      done.push(`Name: ${name.trim()}`);
    }
    if (mode !== null) {
      await setDiscordEventsMode(pool, org.id, mode);
      done.push(`Discord-Events: ${DISCORD_EVENT_MODES[mode]}${mode === 'OFF' ? '' : ' (der Abgleich läuft in den nächsten Minuten)'}`);
    }
  } catch (err) {
    if (!(err instanceof OrgError)) throw err;
    await reply({ content: `Nicht gespeichert: ${err.message}` });
    return;
  }
  org = (await orgForGuild(pool, interaction.guildId)) ?? org;
  let announceChannel = null;
  try {
    announceChannel = await getSetting(pool, org.id, ANNOUNCE_CHANNEL);
  } catch (err) {
    if (!isMissingTable(err)) throw err;
  }
  await reply({ embeds: [orgEmbed(org, {
    counts: await orgCounts(pool, org.id),
    announceChannel,
    gate: config.loginRequiresAllowlist,
    appUrl: config.appUrl,
    note: done.length > 0 ? `Gespeichert: ${done.join('; ')}.` : null,
  })] });
}
