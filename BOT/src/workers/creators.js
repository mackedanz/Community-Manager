// Läuft alle 10 Minuten: neue Videos aller Creator posten, für jede Orga, deren Discord-Server der Bot kennt.
// Zwischen zwei Feeds eine kurze Pause, aus Rücksicht auf YouTube. Ein Lauf beginnt erst, wenn der vorige fertig ist.
import { all } from '../db.js';
import { checkCreator } from '../lib/creators.js';
import { isMissingTable } from '../lib/settings.js';

export const INTERVAL_MS = 10 * 60_000;
const PAUSE_MS = 1_500;

export function startCreatorWatch(client, { pool, log, intervalMs = INTERVAL_MS, pauseMs = PAUSE_MS }) {
  let running = false;
  let warnedMissing = false;

  async function tick() {
    if (running) return;
    running = true;
    try {
      const creators = await all(
        pool,
        "SELECT c.*, o.discord_guild_id FROM bot_creators c JOIN organizations o ON o.id = c.org_id WHERE c.platform = 'youtube' ORDER BY c.checked_at IS NULL DESC, c.checked_at ASC",
      );
      for (const c of creators) {
        if (!client.guilds.cache.has(String(c.discord_guild_id))) continue;
        try {
          const n = await checkCreator(pool, client, c, { log });
          if (n > 0) log.info(`${n} neue(s) Video(s) von ${c.name} gepostet`);
        } catch (err) {
          log.error(`Creator ${c.name} fehlgeschlagen`, err);
        }
        if (pauseMs > 0) await new Promise((r) => setTimeout(r, pauseMs));
      }
      warnedMissing = false;
    } catch (err) {
      if (isMissingTable(err)) {
        if (!warnedMissing) log.warn('Creator-Videos warten auf Migration 021 (Web-App aktualisieren).');
        warnedMissing = true;
      } else {
        log.error('Creator-Lauf fehlgeschlagen', err);
      }
    } finally {
      running = false;
    }
  }

  const timer = setInterval(tick, intervalMs);
  timer.unref?.();
  const first = setTimeout(tick, 30_000);
  first.unref?.();
  return { tick, stop: () => { clearInterval(timer); clearTimeout(first); } };
}
