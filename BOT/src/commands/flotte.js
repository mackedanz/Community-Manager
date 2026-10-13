// /flotte: alle Schiffe aller Mitglieder der Orga, anonym (nur Schiff und Anzahl), optional gefiltert.
import { MessageFlags } from 'discord.js';
import { memberContext } from '../lib/access.js';
import { orgFleet } from '../lib/hangar.js';
import { cut } from '../lib/text.js';

/** Für /hilfe: nutzbar für Mitglieder der Orga */
export const audience = 'members';

export const data = {
  name: 'flotte',
  description: 'Flotte der Orga: alle Schiffe der Mitglieder mit Anzahl (ohne Namen)',
  type: 1,
  options: [{ type: 3, name: 'suche', description: 'Nur Schiffe, deren Name oder Hersteller diesen Text enthält', required: false, max_length: 60 }],
  contexts: [0],
};

/** Höchstzahl der Zeilen; der Rest steht auf der Webseite. */
const MAX_LINES = 60;

export function fleetEmbed(org, fleet, { search = null, url }) {
  const q = search?.trim().toLowerCase() ?? '';
  const entries = q === '' ? fleet.entries
    : fleet.entries.filter((e) => e.name.toLowerCase().includes(q) || (e.manufacturer ?? '').toLowerCase().includes(q));
  const lines = entries.slice(0, MAX_LINES).map((e) => `${e.count}× **${e.name}**${e.manufacturer ? ` · ${e.manufacturer}` : ''}`);
  if (entries.length > MAX_LINES) lines.push(`… und ${entries.length - MAX_LINES} weitere auf der Webseite`);
  const head = q === ''
    ? `${fleet.totalShips} Schiffe bei ${fleet.owners} ${fleet.owners === 1 ? 'Mitglied' : 'Mitgliedern'}, ${fleet.entries.length} verschiedene`
    : `${entries.reduce((s, e) => s + e.count, 0)} Schiffe passend zu „${search.trim()}“`;
  return {
    title: cut(`Flotte – ${org.name}`, 256),
    url,
    description: cut(`${head}\n\n${lines.length > 0 ? lines.join('\n') : 'Nichts gefunden.'}`, 4000),
    color: 0x0ea5e9,
    footer: { text: 'Anonyme Übersicht: zeigt nur Schiffe und Anzahl, nicht wem sie gehören.' },
  };
}

export async function execute(interaction, { pool, config }) {
  const { org, refusal } = await memberContext(interaction, pool);
  if (refusal) {
    await interaction.reply({ content: refusal, flags: MessageFlags.Ephemeral });
    return;
  }
  await interaction.deferReply({ flags: MessageFlags.Ephemeral });
  const fleet = await orgFleet(pool, org.id);
  await interaction.editReply({
    embeds: [fleetEmbed(org, fleet, { search: interaction.options.getString('suche'), url: `${config.appUrl}/o/${encodeURIComponent(org.slug)}/hangar` })],
  });
}
