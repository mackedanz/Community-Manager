// Protokoll gelöschter und geänderter Nachrichten im Protokoll-Kanal der Orga. Nur Nachrichten, die der Bot noch im Speicher hat
// (seit dem Start, die letzten 200 je Kanal); gespeichert wird nichts in der Datenbank. Läuft nur mit BOT_MESSAGE_CONTENT=1.
import { Events } from 'discord.js';
import { wasSelfDeleted, guildContext } from '../lib/automod.js';
import { logToChannel } from '../lib/moderation.js';
import { isMissingTable } from '../lib/settings.js';
import { cut } from '../lib/text.js';

const who = (m) => `<@${m.author.id}> (${cut(m.author.globalName || m.author.username, 100)})`;

export function deletedEmbed(message) {
  const files = message.attachments?.size ?? 0;
  return {
    title: 'Nachricht gelöscht',
    description: `${who(message)} in <#${message.channelId}>`,
    fields: [{ name: 'Text', value: message.content ? cut(message.content, 1000) : files ? `(nur Anhang${files > 1 ? 'e' : ''})` : '(leer)' }]
      .concat(files && message.content ? [{ name: 'Anhänge', value: String(files), inline: true }] : []),
    color: 0x71717a,
    timestamp: new Date().toISOString(),
  };
}

export function editedEmbed(before, after) {
  return {
    title: 'Nachricht geändert',
    description: `${who(after)} in <#${after.channelId}> · [zur Nachricht](${after.url})`,
    fields: [
      { name: 'Vorher', value: cut(before.content || '(leer)', 1000) },
      { name: 'Nachher', value: cut(after.content || '(leer)', 1000) },
    ],
    color: 0x0ea5e9,
    timestamp: new Date().toISOString(),
  };
}

export function registerMessageLog(client, { pool, log }) {
  async function send(guildId, build) {
    try {
      const ctx = await guildContext(pool, guildId);
      if (ctx !== null) await logToChannel(client, pool, ctx.org.id, { embeds: [build()] }, log);
    } catch (err) {
      if (!isMissingTable(err)) log.warn(`Nachrichten-Protokoll fehlgeschlagen: ${err.message}`);
    }
  }

  client.on(Events.MessageDelete, (message) => {
    // Unbekannte (nicht im Speicher gewesene) Nachrichten und Bots werden nicht gemeldet
    if (!message.guildId || message.partial || message.author?.bot || wasSelfDeleted(message.id)) return;
    send(message.guildId, () => deletedEmbed(message));
  });

  client.on(Events.MessageUpdate, (before, after) => {
    if (!after.guildId || before.partial || after.partial || after.author?.bot) return;
    if (before.content === after.content) return; // z. B. nur eine Link-Vorschau kam dazu
    send(after.guildId, () => editedEmbed(before, after));
  });
}
