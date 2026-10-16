// Nachrichten, die nur eine Person sieht (ephemeral), räumen sich nach einer Weile selbst weg.
// Wer in dieser Zeit nichts mehr damit macht, braucht sie später auch nicht mehr; sonst füllt sich der Chat mit Altem.
// Jede weitere Aktion auf derselben Nachricht (Knopf, Auswahl) startet die Zeit neu.
import { MessageFlags } from 'discord.js';

export const EPHEMERAL_TTL_MS = 10 * 60_000;

/** Ist die Antwort auf diese Interaktion eine Nachricht, die nur die Person sieht? */
export function isEphemeral(interaction) {
  if (interaction.isAutocomplete?.()) return false;
  if (!interaction.deferred && !interaction.replied) return false;
  if (interaction.ephemeral) return true;
  // Knopf oder Menü auf einer privaten Nachricht, die dabei nur angepasst wird
  return interaction.message?.flags?.has?.(MessageFlags.Ephemeral) === true;
}

export function createAutoDelete({ ttlMs = EPHEMERAL_TTL_MS, setTimer = setTimeout, clearTimer = clearTimeout } = {}) {
  const timers = new Map();

  /** Merkt die Nachricht zum Löschen vor (oder verlängert die Frist). Fehler beim Abfragen oder Löschen sind egal. */
  async function track(interaction) {
    if (!isEphemeral(interaction)) return;
    let key;
    try {
      key = interaction.message?.id ?? (await interaction.fetchReply()).id;
    } catch {
      return;
    }
    clearTimer(timers.get(key));
    const timer = setTimer(async () => {
      timers.delete(key);
      try {
        await interaction.deleteReply();
      } catch {
        // schon verworfen oder die Frist von Discord (15 Minuten) ist vorbei
      }
    }, ttlMs);
    timer.unref?.();
    timers.set(key, timer);
  }

  function stop() {
    for (const t of timers.values()) clearTimer(t);
    timers.clear();
  }

  return { track, stop, size: () => timers.size };
}
