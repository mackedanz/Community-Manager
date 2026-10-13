// /ping: zeigt, ob der Bot Discord und die Datenbank des Community-Managers erreicht.
import { MessageFlags } from 'discord.js';
import { checkDatabase, orgForGuild } from '../db.js';

export const data = {
  name: 'ping',
  description: 'Prüft, ob der Bot Discord und die Datenbank des Community-Managers erreicht',
  type: 1,
  contexts: [0], // nur auf Servern
};

/** Baut den Antworttext; getrennt von execute(), damit er sich ohne Discord testen lässt. */
export async function report({ pool, guildId, wsPing }) {
  const lines = [`Discord: verbunden (${wsPing >= 0 ? `${wsPing} ms` : 'Wert folgt'})`];
  try {
    const db = await checkDatabase(pool);
    lines.push(`Datenbank: verbunden (${db.ms} ms), Stand ${db.migration ?? 'ohne Migrationen'}`);
    const org = guildId ? await orgForGuild(pool, guildId) : null;
    lines.push(org ? `Orga dieses Servers: ${org.name}` : 'Dieser Server ist noch keiner Orga zugeordnet.');
  } catch (err) {
    lines.push(`Datenbank: nicht erreichbar (${err.code ?? err.message})`);
  }
  return lines.join('\n');
}

export async function execute(interaction, { pool }) {
  await interaction.deferReply({ flags: MessageFlags.Ephemeral });
  const text = await report({ pool, guildId: interaction.guildId, wsPing: interaction.client.ws.ping });
  await interaction.editReply(text);
}
