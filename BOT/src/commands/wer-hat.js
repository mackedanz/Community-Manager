// /wer-hat: Wer in der Orga hat ein bestimmtes Schiff? Mit Vorschlägen aus dem Katalog beim Tippen.
// Gezeigt werden nur Mitglieder, die ihren Hangar für Mitglieder freigegeben haben (und man selbst).
import { MessageFlags } from 'discord.js';
import { one } from '../db.js';
import { memberContext, userByDiscordId } from '../lib/access.js';
import { searchShips, shipOwners } from '../lib/hangar.js';
import { cut } from '../lib/text.js';

/** Für /hilfe: nutzbar für Mitglieder der Orga */
export const audience = 'members';

export const data = {
  name: 'wer-hat',
  description: 'Wer in der Orga hat dieses Schiff? (nur Mitglieder mit freigegebenem Hangar)',
  type: 1,
  options: [{ type: 3, name: 'schiff', description: 'Schiff aus dem Katalog (beim Tippen erscheinen Vorschläge)', required: true, autocomplete: true, max_length: 100 }],
  contexts: [0],
};

/** Vorschläge beim Tippen: Name als Anzeige, Katalog-ID als Wert. */
export async function autocomplete(interaction, { pool }) {
  const term = String(interaction.options.getFocused() ?? '').trim();
  const rows = term.length === 0 ? [] : await searchShips(pool, term);
  await interaction.respond(rows.map((r) => ({ name: cut(r.manufacturer ? `${r.name} (${r.manufacturer})` : r.name, 100), value: r.id })));
}

export function ownersEmbed(shipName, owners, url) {
  const total = owners.reduce((s, o) => s + o.quantity, 0);
  const lines = owners.map((o) => `${o.discordId ? `<@${o.discordId}>` : o.name}${o.quantity > 1 ? ` – ${o.quantity}×` : ''}${o.lti ? ' (LTI)' : ''}`);
  return {
    title: cut(`Wer hat: ${shipName}`, 256),
    url,
    description: owners.length === 0
      ? 'Niemand mit freigegebenem Hangar hat dieses Schiff.'
      : cut(`${total} ${total === 1 ? 'Schiff' : 'Schiffe'} bei ${owners.length} ${owners.length === 1 ? 'Mitglied' : 'Mitgliedern'}\n\n${lines.join('\n')}`, 4000),
    color: 0x0ea5e9,
    footer: { text: 'Nur Mitglieder, die ihren Hangar für Mitglieder freigegeben haben.' },
  };
}

export async function execute(interaction, { pool, config }) {
  const reply = (payload) => interaction.reply({ ...payload, flags: MessageFlags.Ephemeral, allowedMentions: { parse: [] } });
  const { org, refusal } = await memberContext(interaction, pool);
  if (refusal) {
    await reply({ content: refusal });
    return;
  }
  // Gewählter Vorschlag liefert die Katalog-ID; getippter Text ohne Auswahl wird als Name gesucht
  const input = interaction.options.getString('schiff', true).trim();
  const ship = await one(pool, "SELECT id, kind, slug, name FROM catalog_items WHERE kind = 'SHIP' AND (id = ? OR name = ?) LIMIT 1", [input, input]);
  if (ship === null) {
    await reply({ content: `„${cut(input, 100)}“ gibt es nicht im Katalog. Tipp den Namen und wähle einen Vorschlag aus der Liste.` });
    return;
  }
  const viewer = await userByDiscordId(pool, interaction.user.id);
  const owners = await shipOwners(pool, org.id, ship.id, viewer?.id ?? null);
  await reply({ embeds: [ownersEmbed(ship.name, owners, `${config.appUrl}/catalog/ship/${encodeURIComponent(ship.slug)}`)] });
}
