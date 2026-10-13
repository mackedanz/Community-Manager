// Terminankündigungen im Kanal der Orga und Erinnerungen vor dem Termin.
//
// Ankündigung: Jeder geplante Termin der nächsten ANNOUNCE_DAYS Tage erscheint als Nachricht mit Antwort-Knöpfen.
// Ändert sich der Termin oder eine Antwort, wird die Nachricht angepasst; wird er gelöscht oder wieder zum Entwurf,
// verschwindet sie. Welcher Stand zuletzt gezeigt wurde, steht in bot_event_posts (fingerprint).
//
// Erinnerung: 24 Stunden und 1 Stunde vor Beginn eine Direktnachricht an alle mit "Dabei" oder "Vielleicht" und
// ein Hinweis unter der Ankündigung. bot_event_reminders sorgt dafür, dass jede Erinnerung nur einmal rausgeht.
import { createHash } from 'node:crypto';
import { DiscordAPIError } from 'discord.js';
import { all, nowDb, one, run } from '../db.js';
import { eventDetail, RSVP_LABELS } from './events.js';
import { ANNOUNCE_CHANNEL } from './settings.js';
import { cut } from './text.js';

export const ANNOUNCE_DAYS = 30;
/** Ohne Ende gilt ein Termin so lange nach Beginn als laufend. */
const DEFAULT_LENGTH_H = 6;
const H = 3600_000;

const unix = (dbTime) => Math.floor(Date.parse(`${dbTime.replace(' ', 'T')}Z`) / 1000);
const dbPlus = (ms, now = Date.now()) => nowDb(new Date(now + ms));

/** Ist der Termin vorbei (Ende, sonst Beginn plus einige Stunden)? */
export function isPast(e, now = Date.now()) {
  const end = e.endsAt ? unix(e.endsAt) * 1000 : unix(e.startsAt) * 1000 + DEFAULT_LENGTH_H * H;
  return end < now;
}

/** Kasten mit den Eckdaten eines Termins; mit myStatus zusätzlich die eigene Antwort. */
export function eventEmbed(org, e, appUrl, { myStatus, past = false } = {}) {
  const names = (status) => e.rsvps.filter((r) => r.status === status).map((r) => r.name);
  const field = (name, list) => ({ name: `${name} (${list.length})`, value: list.length === 0 ? '–' : cut(list.join(', '), 1024), inline: true });
  const fields = [{
    name: 'Wann',
    value: `<t:${unix(e.startsAt)}:F> (<t:${unix(e.startsAt)}:R>)${e.endsAt ? `\nbis <t:${unix(e.endsAt)}:F>` : ''}`,
  }];
  if (e.location) fields.push({ name: 'Wo', value: cut(e.location, 1024) });
  if (e.course) fields.push({ name: 'Ausbildung', value: `${e.course.areaName} · ${e.course.topic} · Stufe ${e.course.level} („${e.course.title}“)` });
  if (e.department) fields.push({ name: 'Abteilung', value: e.department });
  const ships = shipsField(e.ships ?? []);
  if (ships) fields.push(ships);
  fields.push(field(RSVP_LABELS.YES, names('YES')), field(RSVP_LABELS.MAYBE, names('MAYBE')), field(RSVP_LABELS.NO, names('NO')));
  if (myStatus !== undefined) fields.push({ name: 'Deine Antwort', value: myStatus ? RSVP_LABELS[myStatus] : 'noch keine' });
  const cancelled = e.status === 'CANCELLED';
  const prefix = cancelled ? 'Abgesagt: ' : past ? 'Vorbei: ' : '';
  return {
    title: cut(`${prefix}${e.title}`, 256),
    url: eventUrl(org, e, appUrl),
    description: e.description ? cut(e.description, 1500) : undefined,
    color: cancelled || past ? 0x71717a : 0x0ea5e9,
    fields,
  };
}

/** Schiffe mit besetzten und offenen Plätzen; einen Platz wählt man nur im Community-Manager (Hinweis im Feld). */
export function shipsField(ships) {
  if (ships.length === 0) return null;
  const lines = ships.slice(0, 8).map((s) => {
    const taken = s.slots.filter((x) => x.holder).length;
    const places = s.slots.length === 0 ? '' : ' – ' + s.slots.map((x) => `${x.label}: ${x.holder ?? 'offen'}`).join(', ');
    return `**${s.name}**${s.task ? ` (${s.task})` : ''}${s.slots.length ? ` ${taken}/${s.slots.length} belegt` : ''}${places}`;
  });
  if (ships.length > 8) lines.push(`… und ${ships.length - 8} weitere`);
  const hint = '_Einen Platz auf einem Schiff wählst du im Community-Manager (Knopf „Im Community-Manager öffnen“)._';
  return { name: `Schiffe (${ships.length})`, value: cut(lines.join('\n'), 1024 - hint.length - 2) + '\n' + hint };
}

export function eventUrl(org, e, appUrl) {
  return `${appUrl}/o/${encodeURIComponent(org.slug)}/events/${e.id}`;
}

/** Öffentliche Ankündigung: Kasten und Antwort-Knöpfe (evt:pub:…), deren Antwort nur die drückende Person sieht. */
export function announceView(org, e, appUrl, now = Date.now()) {
  const past = isPast(e, now);
  const cancelled = e.status === 'CANCELLED';
  const button = (status, label, style) => ({
    type: 2, style, label, custom_id: `evt:pub:${e.id}:${status}`, disabled: past || (cancelled && status !== 'NO'),
  });
  return {
    content: '',
    allowedMentions: { parse: [] },
    embeds: [eventEmbed(org, e, appUrl, { past })],
    components: [{ type: 1, components: [
      button('YES', RSVP_LABELS.YES, 3), button('MAYBE', RSVP_LABELS.MAYBE, 1), button('NO', RSVP_LABELS.NO, 4),
      { type: 2, style: 5, label: 'Im Community-Manager öffnen', url: eventUrl(org, e, appUrl) },
    ] }],
  };
}

export function fingerprint(view) {
  return createHash('sha1').update(JSON.stringify(view)).digest('hex');
}

/** Geplante und abgesagte Termine im Ankündigungszeitraum (laufende und gerade vorbei gegangene eingeschlossen). */
async function announceableEventIds(db, orgId, now) {
  const rows = await all(
    db,
    `SELECT id FROM events WHERE org_id = ? AND status IN ('PLANNED', 'CANCELLED') AND starts_at < ?
        AND COALESCE(ends_at, DATE_ADD(starts_at, INTERVAL ${DEFAULT_LENGTH_H} HOUR)) >= ? ORDER BY starts_at ASC`,
    [orgId, dbPlus(ANNOUNCE_DAYS * 24 * H, now), dbPlus(-24 * H, now)],
  );
  return rows.map((r) => r.id);
}

async function fetchChannel(client, id) {
  try {
    return await client.channels.fetch(id);
  } catch (err) {
    if (err instanceof DiscordAPIError) return null;
    throw err;
  }
}

/**
 * Bringt die Ankündigung eines Termins auf den aktuellen Stand: neu posten, anpassen oder entfernen.
 * @returns {Promise<'posted'|'edited'|'removed'|'unchanged'|'skipped'>}
 */
export async function syncPost(pool, client, org, eventId, { channelId, appUrl, now = Date.now(), log }) {
  const post = await one(pool, 'SELECT channel_id, message_id, fingerprint FROM bot_event_posts WHERE event_id = ?', [eventId]);
  const e = await eventDetail(pool, org.id, eventId);
  const gone = e === null; // gelöscht oder wieder Entwurf
  if (gone) {
    if (post === null) return 'skipped';
    if (post.message_id) {
      const ch = await fetchChannel(client, post.channel_id);
      await ch?.messages.delete(post.message_id).catch(() => {});
    }
    await run(pool, 'DELETE FROM bot_event_posts WHERE event_id = ?', [eventId]);
    return 'removed';
  }
  if (post !== null && post.message_id === '') return 'skipped';
  const view = announceView(org, e, appUrl, now);
  const fp = fingerprint(view);
  if (post !== null && post.fingerprint === fp) return 'unchanged';

  if (post !== null) {
    const ch = await fetchChannel(client, post.channel_id);
    try {
      if (ch === null) throw Object.assign(new Error('Kanal fehlt'), { code: 10003 });
      await ch.messages.edit(post.message_id, view);
      await run(pool, 'UPDATE bot_event_posts SET fingerprint = ? WHERE event_id = ?', [fp, eventId]);
      return 'edited';
    } catch (err) {
      // 10008: Nachricht gelöscht, 10003: Kanal gelöscht. Dann nicht erneut posten (jemand wollte sie weg haben).
      if (err.code === 10008 || err.code === 10003) {
        await run(pool, "UPDATE bot_event_posts SET message_id = '', fingerprint = ? WHERE event_id = ?", [fp, eventId]);
        return 'skipped';
      }
      throw err;
    }
  }

  // Neue Ankündigung nur für Termine, die noch nicht vorbei sind
  if (isPast(e, now) || channelId === null) return 'skipped';
  const ch = await fetchChannel(client, channelId);
  if (ch === null) {
    log?.warn(`Ankündigungskanal ${channelId} von ${org.name} nicht erreichbar`);
    return 'skipped';
  }
  const msg = await ch.send(view);
  await run(
    pool,
    'INSERT INTO bot_event_posts (event_id, org_id, channel_id, message_id, fingerprint) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE channel_id = VALUES(channel_id), message_id = VALUES(message_id), fingerprint = VALUES(fingerprint)',
    [eventId, org.id, channelId, msg.id, fp],
  );
  return 'posted';
}

/** Alle Ankündigungen einer Orga abgleichen: Termine im Zeitraum und Nachrichten zu gelöschten Terminen. */
export async function syncOrgPosts(pool, client, org, { appUrl, now = Date.now(), log }) {
  const channelId = (await one(pool, 'SELECT value FROM bot_settings WHERE org_id = ? AND name = ?', [org.id, ANNOUNCE_CHANNEL]))?.value ?? null;
  const ids = new Set(await announceableEventIds(pool, org.id, now));
  // Nachrichten, deren Termin es nicht mehr gibt oder der wieder Entwurf ist
  const stale = await all(
    pool,
    `SELECT p.event_id FROM bot_event_posts p LEFT JOIN events e ON e.id = p.event_id
      WHERE p.org_id = ? AND (e.id IS NULL OR e.status = 'DRAFT')`,
    [org.id],
  );
  for (const r of stale) ids.add(r.event_id);
  // Ohne Kanal (Ankündigungen ausgeschaltet) werden nur schon gepostete Nachrichten aktuell gehalten (syncPost: 'skipped')
  const counts = {};
  for (const id of ids) {
    const r = await syncPost(pool, client, org, id, { channelId, appUrl, now, log });
    counts[r] = (counts[r] ?? 0) + 1;
  }
  return counts;
}

/** Welche Erinnerung ist fällig? '24h' (noch mehr als 1 Stunde), '1h' (höchstens 1 Stunde), sonst null. */
export function dueReminder(startsAt, now = Date.now()) {
  const until = unix(startsAt) * 1000 - now;
  if (until <= 0 || until > 24 * H) return null;
  return until <= H ? '1h' : '24h';
}

/** Text der Direktnachricht an eine Person mit Antwort. */
export function reminderText(org, e, status, appUrl) {
  return `Erinnerung: **${e.title}** (${org.name}) beginnt <t:${unix(e.startsAt)}:R>, am <t:${unix(e.startsAt)}:F>.\n`
    + `Deine Antwort: ${RSVP_LABELS[status]}. Wenn sich etwas ändert, sag bitte mit /termine oder auf der Webseite ab.\n${eventUrl(org, e, appUrl)}`;
}

/** Erinnerungen einer Orga verschicken. @returns {Promise<number>} Anzahl verschickter Erinnerungen (Termine) */
export async function sendOrgReminders(pool, client, org, { appUrl, now = Date.now(), log }) {
  const rows = await all(
    pool,
    "SELECT id, starts_at FROM events WHERE org_id = ? AND status = 'PLANNED' AND starts_at > ? AND starts_at <= ?",
    [org.id, nowDb(new Date(now)), dbPlus(24 * H, now)],
  );
  let sent = 0;
  for (const row of rows) {
    const kind = dueReminder(row.starts_at, now);
    if (kind === null) continue;
    // Erst eintragen, dann senden: so geht eine Erinnerung auch bei einem Neustart nie doppelt raus
    const inserted = await run(pool, 'INSERT IGNORE INTO bot_event_reminders (event_id, kind) VALUES (?,?)', [row.id, kind]);
    if (inserted === 0) continue;
    if (kind === '1h') await run(pool, "INSERT IGNORE INTO bot_event_reminders (event_id, kind) VALUES (?, '24h')", [row.id]);
    const e = await eventDetail(pool, org.id, row.id);
    if (e === null) continue;
    const people = await all(
      pool,
      "SELECT r.status, u.discord_id FROM event_rsvps r JOIN users u ON u.id = r.user_id WHERE r.event_id = ? AND r.status IN ('YES', 'MAYBE') AND u.discord_id IS NOT NULL",
      [row.id],
    );
    for (const p of people) {
      try {
        const user = await client.users.fetch(p.discord_id);
        await user.send({ content: reminderText(org, e, p.status, appUrl), allowedMentions: { parse: [] } });
      } catch (err) {
        // 50007: Person nimmt keine Direktnachrichten an
        if (!(err instanceof DiscordAPIError)) throw err;
      }
    }
    const post = await one(pool, "SELECT channel_id, message_id FROM bot_event_posts WHERE event_id = ? AND message_id <> ''", [row.id]);
    if (post !== null && people.length > 0) {
      const ch = await fetchChannel(client, post.channel_id);
      const ids = people.map((p) => p.discord_id);
      await ch?.send({
        content: `Erinnerung: **${e.title}** beginnt <t:${unix(e.startsAt)}:R>. ${ids.map((id) => `<@${id}>`).join(' ')}`,
        reply: { messageReference: post.message_id, failIfNotExists: false },
        allowedMentions: { users: ids },
      }).catch((err) => log?.warn(`Erinnerung im Kanal für ${e.title} nicht möglich: ${err.message}`));
    }
    sent++;
  }
  return sent;
}
