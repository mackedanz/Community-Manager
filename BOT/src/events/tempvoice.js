// Temporäre Sprachkanäle (/sprachkanal): legt Kanäle an, wenn jemand den Auslöser-Kanal betritt, und räumt leere weg.
import { Events } from 'discord.js';
import { createTempVoice } from '../lib/tempvoice.js';
import { isMissingTable } from '../lib/settings.js';

export function registerTempVoice(client, { pool, log }) {
  const tempVoice = createTempVoice({ pool, log });
  client.on(Events.VoiceStateUpdate, async (oldState, newState) => {
    try {
      await tempVoice.handleVoiceState(oldState, newState);
    } catch (err) {
      if (!isMissingTable(err)) log.warn(`Temporärer Sprachkanal fehlgeschlagen: ${err.message}`);
    }
  });
  client.once(Events.ClientReady, async (c) => {
    try {
      await tempVoice.sweep(c);
    } catch (err) {
      if (!isMissingTable(err)) log.warn(`Temporäre Sprachkanäle konnten nicht geprüft werden: ${err.message}`);
    }
  });
  return tempVoice;
}
