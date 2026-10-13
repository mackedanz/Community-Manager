// Läuft jede Minute: Terminankündigungen abgleichen und fällige Erinnerungen verschicken, für jede Orga, deren
// Discord-Server der Bot kennt. Ein Lauf beginnt erst, wenn der vorige fertig ist.
import { all } from '../db.js';
import { sendOrgReminders, syncOrgPosts } from '../lib/announce.js';
import { isMissingTable } from '../lib/settings.js';

export const INTERVAL_MS = 60_000;

export function startAnnouncer(client, { pool, config, log, intervalMs = INTERVAL_MS }) {
  let running = false;
  let warnedMissing = false;

  async function tick() {
    if (running) return;
    running = true;
    try {
      const orgs = await all(pool, 'SELECT id, slug, name, discord_guild_id FROM organizations');
      for (const org of orgs) {
        if (!client.guilds.cache.has(String(org.discord_guild_id))) continue;
        try {
          const posts = await syncOrgPosts(pool, client, org, { appUrl: config.appUrl, log });
          if (posts.posted || posts.removed) log.info(`Ankündigungen ${org.name}: ${posts.posted ?? 0} neu, ${posts.removed ?? 0} entfernt`);
          const sent = await sendOrgReminders(pool, client, org, { appUrl: config.appUrl, log });
          if (sent > 0) log.info(`Erinnerungen ${org.name}: ${sent} Termin(e)`);
        } catch (err) {
          if (isMissingTable(err)) throw err;
          log.error(`Ankündigungen/Erinnerungen für ${org.name} fehlgeschlagen`, err);
        }
      }
      warnedMissing = false;
    } catch (err) {
      if (isMissingTable(err)) {
        if (!warnedMissing) log.warn('Ankündigungen und Erinnerungen warten auf Migration 020 (Web-App aktualisieren).');
        warnedMissing = true;
      } else {
        log.error('Ankündigungslauf fehlgeschlagen', err);
      }
    } finally {
      running = false;
    }
  }

  const timer = setInterval(tick, intervalMs);
  timer.unref?.();
  // Erster Lauf kurz nach dem Start, wenn die Server bekannt sind
  const first = setTimeout(tick, 10_000);
  first.unref?.();
  return { tick, stop: () => { clearInterval(timer); clearTimeout(first); } };
}
