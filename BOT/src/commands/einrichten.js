// /einrichten: legt die Orga für diesen Server an (falls nötig), verbindet die RSI-Orga und zeigt das Panel
// mit den Rollenauswahlen (wie DiscordController::setupCommand() der PHP-App).
import { MessageFlags } from 'discord.js';
import { OrgError } from '../lib/errors.js';
import { guildIconUrl, guildInfo, invokerName, isGuildAdmin } from '../lib/guild.js';
import { ensureOrg, findOrg, setHandleStyle } from '../lib/onboarding.js';
import { connect, sync } from '../lib/rsi.js';
import { leadershipPanelData, panelData } from './panel.js';

export const data = {
  name: 'einrichten',
  description: 'Community-Hangar für diesen Server einrichten: Rollen wählen und Mitglieder übernehmen',
  type: 1,
  options: [{
    type: 3, // STRING
    name: 'rsi_kuerzel',
    description: 'Kürzel der Orga auf RSI (z. B. EXPG). Gibt es keine RSI-Orga: - eingeben',
    required: true,
    max_length: 20,
  }, {
    type: 3,
    name: 'handle_stil',
    description: 'Wo steht der RSI-Handle im Server-Nickname? (Standard: außerhalb aller Klammern)',
    required: false,
    choices: [
      { name: 'Außerhalb aller Klammern', value: 'plain' },
      { name: 'In runden Klammern ( )', value: 'round' },
      { name: 'In eckigen Klammern [ ]', value: 'square' },
      { name: 'In geschweiften Klammern { }', value: 'curly' },
      { name: 'In spitzen Klammern < >', value: 'angle' },
    ],
  }],
  default_member_permissions: '32', // "Server verwalten"; der Bot prüft die Rechte zusätzlich selbst
  contexts: [0],
};

export async function execute(interaction, { pool, config }) {
  if (!isGuildAdmin(interaction)) {
    await interaction.reply({ content: 'Das dürfen nur Server-Admins (Administrator oder „Server verwalten“).', flags: MessageFlags.Ephemeral });
    return;
  }
  await interaction.deferReply({ flags: MessageFlags.Ephemeral });
  const say = (content) => interaction.editReply({ content, components: [], allowedMentions: { parse: [] } });

  let org;
  try {
    const guild = await guildInfo(interaction.client, interaction.guildId).catch(() => null);
    org = await ensureOrg(pool, config, {
      guildId: interaction.guildId,
      invokerId: interaction.user.id,
      invokerName: invokerName(interaction),
      guild,
      iconUrl: guild ? guildIconUrl(interaction.guildId, guild.icon) : null,
    });
    const style = interaction.options.getString('handle_stil');
    if (style !== null) {
      await setHandleStyle(pool, org.id, style);
      org = (await findOrg(pool, org.id)) ?? org;
    }
  } catch (err) {
    if (!(err instanceof OrgError)) throw err;
    await say(err.message);
    return;
  }

  // Die Orga-Leitung wählt man in einer zweiten Nachricht (das erste Panel hat schon fünf Zeilen)
  const showLeadership = async (current) => {
    try {
      await interaction.followUp({ ...leadershipPanelData(current), flags: MessageFlags.Ephemeral });
    } catch {
      // Antwort nicht mehr möglich; die Rollen lassen sich auch im Manager unter „Orga verwalten“ setzen
    }
  };

  // "-" heißt: keine RSI-Orga (das Feld ist Pflicht, damit es beim Einrichten nicht übersehen wird)
  const sid = (interaction.options.getString('rsi_kuerzel') ?? '').trim();
  if (sid === '' || sid === '-') {
    await interaction.editReply(panelData(org, null, config.appUrl));
    await showLeadership(org);
    return;
  }
  let name;
  try {
    name = await connect(pool, org.id, sid);
  } catch (err) {
    if (!(err instanceof OrgError)) throw err;
    await interaction.editReply(panelData(org, `RSI-Orga nicht übernommen: ${err.message}`, config.appUrl));
    await showLeadership(org);
    return;
  }
  org = (await findOrg(pool, org.id)) ?? org;
  await interaction.editReply(panelData(org, `RSI-Orga „${name}“ verbunden, die Mitgliederliste wird abgeglichen …`, config.appUrl));
  await showLeadership(org);

  // Der Abgleich dauert einige Sekunden (eine Abfrage je 32 Mitglieder)
  let note;
  try {
    const r = await sync(pool, org.id);
    note = `RSI-Orga „${name}“ verbunden: ${r.members} sichtbare Mitglieder, ${r.redacted} verborgen.`;
  } catch (err) {
    if (!(err instanceof OrgError)) throw err;
    note = `RSI-Abgleich nicht möglich: ${err.message}`;
  }
  const fresh = await findOrg(pool, org.id);
  if (fresh !== null) await interaction.editReply(panelData(fresh, note, config.appUrl));
}
