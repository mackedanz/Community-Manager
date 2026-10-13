// /betreiber: Funktionen für den Betreiber der Installation (SERVER_ADMIN_DISCORD_ID), wie /admin der Web-App:
// alle Orgas ansehen, Discord-Server sperren und entsperren, eine Orga löschen (mit Rückfrage).
import { MessageFlags } from 'discord.js';
import { one } from '../db.js';
import { userByDiscordId } from '../lib/access.js';
import { OrgError } from '../lib/errors.js';
import { banGuild, deleteOrg, isOperator, listAllOrgs, listBans, unbanGuild } from '../lib/orgadmin.js';
import { cut } from '../lib/text.js';

/** Für /hilfe: nur für den Betreiber */
export const audience = 'operator';

export const data = {
  name: 'betreiber',
  description: 'Betreiber der Installation: Orgas ansehen, Server sperren oder entsperren, Orga löschen',
  type: 1,
  options: [
    { type: 1, name: 'orgas', description: 'Alle Orgas und gesperrten Server anzeigen' },
    { type: 1, name: 'sperren', description: 'Discord-Server sperren (löscht eine vorhandene Orga dazu)', options: [
      { type: 3, name: 'server_id', description: 'ID des Discord-Servers', required: true, max_length: 25 },
      { type: 3, name: 'grund', description: 'Grund (nur für dich sichtbar)', required: false, max_length: 500 },
    ] },
    { type: 1, name: 'entsperren', description: 'Sperre eines Discord-Servers aufheben', options: [
      { type: 3, name: 'server_id', description: 'ID des Discord-Servers', required: true, max_length: 25 },
    ] },
    { type: 1, name: 'loeschen', description: 'Orga löschen (mit Rückfrage); Konten und Hangars bleiben', options: [
      { type: 3, name: 'orga', description: 'Orga (Vorschläge beim Tippen)', required: true, autocomplete: true },
    ] },
  ],
  // Nur für Admins sichtbar; ausführen darf ohnehin nur, wer in SERVER_ADMIN_DISCORD_ID steht
  default_member_permissions: '8',
  contexts: [0],
};

const EPHEMERAL = MessageFlags.Ephemeral;
const HEX_ID = /^[a-f0-9]{24}$/;
const unix = (dbTime) => Math.floor(Date.parse(`${dbTime.replace(' ', 'T')}Z`) / 1000);

/** Übersicht aller Orgas und Sperren. */
export function orgsEmbed(orgs, bans, guildNames = new Map()) {
  const lines = orgs.map((o) => `**${o.name}** (${o.slug}) · Server ${guildNames.get(String(o.discord_guild_id)) ?? o.discord_guild_id} · ${o.member_count} Mitglieder · ${o.event_count} Termine`);
  const banLines = bans.map((b) => `${b.name} (${b.discord_guild_id}) seit <t:${unix(b.banned_at)}:d>${b.reason ? ` – ${b.reason}` : ''}`);
  return {
    title: `Orgas der Installation (${orgs.length})`,
    description: cut(lines.length > 0 ? lines.join('\n') : 'Keine Orgas.', 3500),
    fields: banLines.length > 0 ? [{ name: `Gesperrte Server (${bans.length})`, value: cut(banLines.join('\n'), 1024) }] : [],
    color: 0x0ea5e9,
  };
}

const notOperator = 'Das darf nur der Betreiber der Installation (SERVER_ADMIN_DISCORD_ID).';

export async function autocomplete(interaction, { pool, config }) {
  if (!isOperator(config, interaction.user.id)) {
    await interaction.respond([]);
    return;
  }
  const term = String(interaction.options.getFocused() ?? '').trim().toLowerCase();
  const orgs = (await listAllOrgs(pool)).filter((o) => o.name.toLowerCase().includes(term) || o.slug.includes(term)).slice(0, 25);
  await interaction.respond(orgs.map((o) => ({ name: cut(`${o.name} (${o.slug})`, 100), value: o.id })));
}

export async function execute(interaction, { pool, config }) {
  const reply = (payload) => interaction.reply({ ...payload, flags: EPHEMERAL, allowedMentions: { parse: [] } });
  if (!isOperator(config, interaction.user.id)) {
    await reply({ content: notOperator });
    return;
  }
  const sub = interaction.options.getSubcommand();
  try {
    if (sub === 'orgas') {
      const orgs = await listAllOrgs(pool);
      const names = new Map([...interaction.client.guilds.cache.values()].map((g) => [g.id, g.name]));
      await reply({ embeds: [orgsEmbed(orgs, await listBans(pool), names)] });
    } else if (sub === 'sperren') {
      const id = interaction.options.getString('server_id', true);
      const account = await userByDiscordId(pool, interaction.user.id);
      const label = await banGuild(pool, id, interaction.client.guilds.cache.get(id.trim())?.name ?? null, interaction.options.getString('grund'), account?.id ?? interaction.user.id);
      await reply({ content: `Server „${label}“ (${id.trim()}) ist gesperrt. Eine Orga dazu wurde gelöscht; neu einrichten lässt er sich erst nach dem Entsperren.` });
    } else if (sub === 'entsperren') {
      const id = interaction.options.getString('server_id', true).trim();
      const n = await unbanGuild(pool, id);
      await reply({ content: n > 0 ? `Server ${id} ist entsperrt. Dort kann jetzt wieder eine Orga mit /einrichten angelegt werden.` : `Server ${id} war nicht gesperrt.` });
    } else if (sub === 'loeschen') {
      const orgId = interaction.options.getString('orga', true);
      const org = HEX_ID.test(orgId) ? await one(pool, 'SELECT id, name, slug FROM organizations WHERE id = ?', [orgId]) : null;
      if (org === null) {
        await reply({ content: 'Diese Orga gibt es nicht. Bitte aus den Vorschlägen wählen.' });
        return;
      }
      await reply({
        content: `**Orga „${org.name}“ (${org.slug}) wirklich löschen?** Mitgliedschaften, Termine, Ausbildungen und Abteilungen der Orga gehen verloren. Konten und Hangars der Mitglieder bleiben.`,
        components: [{ type: 1, components: [
          { type: 2, style: 4, label: 'Ja, Orga löschen', custom_id: `op:del:${org.id}` },
          { type: 2, style: 2, label: 'Abbrechen', custom_id: 'op:cancel' },
        ] }],
      });
    }
  } catch (err) {
    if (!(err instanceof OrgError)) throw err;
    await reply({ content: err.message });
  }
}

export function handles(customId) {
  return customId.startsWith('op:');
}

export async function handleComponent(interaction, { pool, config }) {
  if (!isOperator(config, interaction.user.id)) {
    await interaction.reply({ content: notOperator, flags: EPHEMERAL });
    return;
  }
  if (interaction.customId === 'op:cancel') {
    await interaction.update({ content: 'Abgebrochen, nichts gelöscht.', components: [] });
    return;
  }
  const [, kind, orgId] = interaction.customId.split(':');
  if (kind !== 'del' || !HEX_ID.test(orgId ?? '')) return;
  const org = await one(pool, 'SELECT name FROM organizations WHERE id = ?', [orgId]);
  const n = await deleteOrg(pool, orgId);
  await interaction.update({ content: n > 0 ? `Orga „${org?.name}“ ist gelöscht.` : 'Die Orga gab es schon nicht mehr.', components: [], allowedMentions: { parse: [] } });
}
