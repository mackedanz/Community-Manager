// /hangar: der eigene Hangar (alles), oder die Schiffe eines Mitglieds, das seinen Hangar für Mitglieder freigegeben hat.
import { MessageFlags } from 'discord.js';
import { memberContext } from '../lib/access.js';
import { canView, countByKind, groupShips, hangarItems, memberAccount } from '../lib/hangar.js';
import { cut } from '../lib/text.js';

/** Für /hilfe: nutzbar für Mitglieder der Orga */
export const audience = 'members';

export const data = {
  name: 'hangar',
  description: 'Deinen Hangar anzeigen, oder die Schiffe eines Mitglieds (wenn freigegeben)',
  type: 1,
  options: [{ type: 6, name: 'mitglied', description: 'Schiffe dieses Mitglieds anzeigen', required: false }],
  contexts: [0],
};

/** Liste der Schiffe als Zeilen ("2× Cutlass Black (1× LTI)"). */
export function shipLines(ships) {
  return ships.map((s) => `${s.count > 1 ? `${s.count}× ` : ''}**${s.name}**${s.lti > 0 ? ` (${s.lti === s.count ? 'LTI' : `${s.lti}× LTI`})` : ''}`);
}

/** Kasten mit Schiffen und (beim eigenen Hangar) der Zahl der übrigen Einträge je Art. */
export function hangarEmbed(title, items, { self, url }) {
  const ships = groupShips(items);
  const lines = shipLines(ships);
  let description = lines.length === 0 ? 'Keine Schiffe.' : cut(lines.join('\n'), 3500);
  if (description.length < lines.join('\n').length) description += '\n… (vollständig auf der Webseite)';
  const fields = [];
  if (self) {
    const counts = countByKind(items).filter((k) => k.kind !== 'SHIP');
    if (counts.length > 0) fields.push({ name: 'Außerdem', value: counts.map((k) => `${k.label}: ${k.count}`).join('\n') });
  }
  const total = ships.reduce((s, x) => s + x.count, 0);
  return { title: cut(`${title} – ${total} ${total === 1 ? 'Schiff' : 'Schiffe'}`, 256), url, description, fields, color: 0x0ea5e9 };
}

export async function execute(interaction, { pool, config }) {
  const reply = (payload) => interaction.reply({ ...payload, flags: MessageFlags.Ephemeral, allowedMentions: { parse: [] } });
  const { org, refusal } = await memberContext(interaction, pool);
  if (refusal) {
    await reply({ content: refusal });
    return;
  }
  const target = interaction.options.getUser('mitglied') ?? interaction.user;
  const self = target.id === interaction.user.id;
  const account = await memberAccount(pool, org.id, target.id);
  const name = interaction.options.getMember('mitglied')?.displayName ?? target.globalName ?? target.username;
  if (account === null) {
    await reply({ content: self
      ? `Du hast noch kein Konto im Community-Manager. Melde dich einmal auf ${config.appUrl} an und übernimm deinen Hangar mit dem RSI-Sync.`
      : `${name} hat noch kein Konto im Community-Manager.` });
    return;
  }
  if (!canView(account, 'hangar_visibility', self)) {
    await reply({ content: `${name} hat den Hangar nicht für Mitglieder freigegeben.` });
    return;
  }
  const items = await hangarItems(pool, account.id, { shipsOnly: !self });
  const embed = self
    ? hangarEmbed('Dein Hangar', items, { self: true, url: `${config.appUrl}/hangar` })
    : hangarEmbed(`Hangar von ${name}`, items, { self: false, url: `${config.appUrl}/members/${account.id}` });
  await reply({ embeds: [embed] });
}
