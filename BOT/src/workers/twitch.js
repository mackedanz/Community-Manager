// Läuft alle 2 Minuten: Twitch-Kanäle prüfen und melden, wer live gegangen ist, für jede Orga, deren Discord-Server der Bot kennt.
// Eine Abfrage deckt alle Kanäle ab (bis 100 je Anfrage). Ohne Twitch-Zugangsdaten (twitch = null) tut der Worker nichts.
import { all } from '../db.js';
import { checkTwitch } from '../lib/creators.js';
import { OrgError } from '../lib/errors.js';
import { isMissingTable } from '../lib/settings.js';

export const INTERVAL_MS = 2 * 60_000;

export function startTwitchWatch(client, { pool, log, twitch, intervalMs = INTERVAL_MS }) {
  if (twitch === null || twitch === undefined) return { tick: async () => {}, stop: () => {} };
  let running = false;
  let warned = false;

  async function tick() {
    if (running) return;
    running = true;
    try {
      const rows = await all(
        pool,
        "SELECT c.*, o.discord_guild_id FROM bot_creators c JOIN organizations o ON o.id = c.org_id WHERE c.platform = 'twitch'",
      );
      const mine = rows.filter((c) => client.guilds.cache.has(String(c.discord_guild_id)));
      if (mine.length > 0) {
        const n = await checkTwitch(pool, client, mine, twitch, { log });
        if (n > 0) log.info(`${n} Twitch-Live-Meldung(en) gepostet`);
      }
      warned = false;
    } catch (err) {
      if (isMissingTable(err) || err?.code === 'ER_BAD_FIELD_ERROR') {
        if (!warned) log.warn('Twitch-Meldungen warten auf Migration 025 (Web-App aktualisieren).');
        warned = true;
      } else if (err instanceof OrgError) {
        if (!warned) log.warn(`Twitch-Abfrage nicht möglich: ${err.message}`);
        warned = true;
      } else {
        log.error('Twitch-Lauf fehlgeschlagen', err);
      }
    } finally {
      running = false;
    }
  }

  const timer = setInterval(tick, intervalMs);
  timer.unref?.();
  const first = setTimeout(tick, 45_000);
  first.unref?.();
  return { tick, stop: () => { clearInterval(timer); clearTimeout(first); } };
}
