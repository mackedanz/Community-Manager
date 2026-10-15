// Creator (YouTube-Kanäle) je Orga und das Posten neuer Videos (Tabellen bot_creators, bot_creator_videos; Migration 021).
import { DiscordAPIError } from 'discord.js';
import { all, nowDb, one, run } from '../db.js';
import { OrgError } from './errors.js';
import { cut, newId } from './text.js';
import { fetchFeed } from './youtube.js';

/** Höchstzahl Creator je Orga, damit die Abfragen bei YouTube überschaubar bleiben. */
export const MAX_CREATORS = 50;

export async function listCreators(db, orgId) {
  return all(db, 'SELECT * FROM bot_creators WHERE org_id = ? ORDER BY name ASC', [orgId]);
}

/**
 * Creator hinzufügen (oder Kanal/Rolle eines vorhandenen ändern). Alle Videos, die jetzt schon im Feed stehen, gelten als
 * bekannt: gepostet wird erst das nächste neue Video.
 * @param {{channelId: string, name: string, discordChannelId: string, mentionRoleId: string|null, skipShorts: boolean}} c
 * @returns {Promise<{id: string, updated: boolean}>} @throws {OrgError}
 */
export async function addCreator(pool, orgId, c, fetchFn = fetch) {
  const existing = await one(pool, "SELECT id FROM bot_creators WHERE org_id = ? AND platform = 'youtube' AND channel_id = ?", [orgId, c.channelId]);
  if (existing === null) {
    const count = Number((await one(pool, 'SELECT COUNT(*) AS n FROM bot_creators WHERE org_id = ?', [orgId])).n);
    if (count >= MAX_CREATORS) throw new OrgError(`Höchstens ${MAX_CREATORS} Creator je Orga.`);
  }
  const feed = await fetchFeed(c.channelId, fetchFn);
  const id = existing?.id ?? newId();
  if (existing === null) {
    await run(
      pool,
      "INSERT INTO bot_creators (id, org_id, platform, channel_id, name, discord_channel_id, mention_role_id, skip_shorts, checked_at) VALUES (?,?, 'youtube', ?,?,?,?,?,?)",
      [id, orgId, c.channelId, cut(c.name, 190), c.discordChannelId, c.mentionRoleId, c.skipShorts ? 1 : 0, nowDb()],
    );
  } else {
    await run(
      pool,
      'UPDATE bot_creators SET name = ?, discord_channel_id = ?, mention_role_id = ?, skip_shorts = ?, last_error = NULL WHERE id = ?',
      [cut(c.name, 190), c.discordChannelId, c.mentionRoleId, c.skipShorts ? 1 : 0, id],
    );
  }
  for (const v of feed.videos) {
    await run(pool, 'INSERT IGNORE INTO bot_creator_videos (creator_id, video_id) VALUES (?,?)', [id, v.id]);
  }
  return { id, updated: existing !== null };
}

export async function removeCreator(pool, orgId, creatorId) {
  return run(pool, 'DELETE FROM bot_creators WHERE id = ? AND org_id = ?', [creatorId, orgId]);
}

/** Text der Nachricht zu einem neuen Video; Discord zeigt den YouTube-Link als Vorschau mit Bild an. */
export function videoMessage(creator, video) {
  const ping = creator.mention_role_id ? `<@&${creator.mention_role_id}> ` : '';
  const what = video.short ? 'einen neuen Short' : 'ein neues Video';
  return {
    content: `${ping}**${creator.name}** hat ${what} veröffentlicht: **${video.title}**\n${video.url}`,
    allowedMentions: creator.mention_role_id ? { roles: [creator.mention_role_id] } : { parse: [] },
  };
}

/**
 * Neue Videos eines Creators posten. Neu ist, was noch nicht in bot_creator_videos steht; ein Video wird vor dem Posten
 * eingetragen, damit es auch bei einem Neustart nie doppelt erscheint. Höchstens drei auf einmal (der Rest gilt als
 * bekannt), damit ein Fehler im Feed den Kanal nicht flutet.
 * @returns {Promise<number>} Anzahl geposteter Videos
 */
export async function checkCreator(pool, client, creator, { fetchFn = fetch, log } = {}) {
  let feed;
  try {
    feed = await fetchFeed(creator.channel_id, fetchFn);
  } catch (err) {
    if (!(err instanceof OrgError)) throw err;
    await run(pool, 'UPDATE bot_creators SET checked_at = ?, last_error = ? WHERE id = ?', [nowDb(), cut(err.message, 190), creator.id]);
    return 0;
  }
  // Älteste zuerst posten, damit die Reihenfolge im Kanal stimmt
  const fresh = [];
  for (const v of [...feed.videos].reverse()) {
    const inserted = await run(pool, 'INSERT IGNORE INTO bot_creator_videos (creator_id, video_id) VALUES (?,?)', [creator.id, v.id]);
    if (inserted > 0) fresh.push(v);
  }
  let posted = 0;
  let error = null;
  const toPost = fresh.filter((v) => !(Number(creator.skip_shorts) && v.short)).slice(-3);
  if (toPost.length > 0) {
    let channel = null;
    try {
      channel = await client.channels.fetch(creator.discord_channel_id);
    } catch (err) {
      if (!(err instanceof DiscordAPIError)) throw err;
    }
    if (channel === null) {
      error = 'Der Discord-Kanal ist nicht erreichbar (gelöscht oder keine Rechte).';
    } else {
      for (const v of toPost) {
        try {
          await channel.send(videoMessage(creator, v));
          await run(pool, 'UPDATE bot_creator_videos SET posted = 1 WHERE creator_id = ? AND video_id = ?', [creator.id, v.id]);
          posted++;
        } catch (err) {
          if (!(err instanceof DiscordAPIError)) throw err;
          error = `Posten nicht möglich: ${err.message}`;
          log?.warn(`Video von ${creator.name} nicht gepostet: ${err.message}`);
        }
      }
    }
  }
  await run(pool, 'UPDATE bot_creators SET name = ?, checked_at = ?, last_error = ? WHERE id = ?', [cut(feed.name ?? creator.name, 190), nowDb(), error, creator.id]);
  return posted;
}

/** Nach einer Meldung wird derselbe Kanal 30 Minuten lang nicht erneut gemeldet (Twitch vergibt nach einem Verbindungsabbruch oft eine neue Stream-ID). */
export const NOTIFY_COOLDOWN_MS = 30 * 60_000;

const dbMs = (dbTime) => Date.parse(`${String(dbTime).replace(' ', 'T')}Z`);

/** Text der Nachricht, wenn ein Twitch-Kanal live geht; Discord zeigt den Link als Vorschau. */
export function liveMessage(creator, stream) {
  const ping = creator.mention_role_id ? `<@&${creator.mention_role_id}> ` : '';
  const game = stream.game ? ` (${stream.game})` : '';
  return {
    content: `${ping}**${creator.name}** ist live auf Twitch: **${stream.title || 'ohne Titel'}**${game}\nhttps://www.twitch.tv/${creator.channel_id}`,
    allowedMentions: creator.mention_role_id ? { roles: [creator.mention_role_id] } : { parse: [] },
  };
}

/**
 * Twitch-Creator prüfen: neue Kanäle bei Twitch nachschlagen (Anzeigename, oder Fehler, wenn es sie nicht gibt), dann mit einer
 * Abfrage für alle sehen, wer live ist, und je neuem Stream einmal melden. Ein Kanal, der schon beim Eintragen live ist, meldet
 * sich erst mit dem nächsten Stream. Der Stream wird vor dem Posten vermerkt, damit auch ein Neustart nichts doppelt meldet.
 * @param {Array<object>} creators Zeilen aus bot_creators mit platform = 'twitch'
 * @param {{users: Function, streams: Function}} twitch Ergebnis von createTwitch()
 * @returns {Promise<number>} Anzahl Meldungen @throws {OrgError} wenn Twitch nicht zu erreichen ist
 */
export async function checkTwitch(pool, client, creators, twitch, { log, now = Date.now() } = {}) {
  const baseline = new Set();
  const fresh = creators.filter((c) => !Number(c.resolved));
  const known = fresh.length > 0 ? await twitch.users([...new Set(fresh.map((c) => c.channel_id))]) : new Map();
  const active = [];
  for (const c of creators) {
    if (Number(c.resolved)) {
      active.push(c);
      continue;
    }
    const u = known.get(c.channel_id);
    if (u === undefined) {
      await run(pool, 'UPDATE bot_creators SET checked_at = ?, last_error = ? WHERE id = ?', [nowDb(), 'Diesen Twitch-Kanal gibt es nicht.', c.id]);
      continue;
    }
    await run(pool, 'UPDATE bot_creators SET resolved = 1, name = ?, last_error = NULL WHERE id = ?', [cut(u.name, 190), c.id]);
    c.name = u.name;
    baseline.add(c.id);
    active.push(c);
  }
  if (active.length === 0) return 0;

  const live = await twitch.streams([...new Set(active.map((c) => c.channel_id))]);
  let posted = 0;
  for (const c of active) {
    const s = live.get(c.channel_id);
    let error = null;
    if (s !== undefined && s.id !== c.last_stream_id) {
      // Wer den Stream zuerst vermerkt, meldet ihn (verhindert Doppelte, falls zwei Läufe sich überschneiden)
      const claimed = await run(pool, 'UPDATE bot_creators SET last_stream_id = ? WHERE id = ? AND (last_stream_id IS NULL OR last_stream_id <> ?)', [s.id, c.id, s.id]);
      const cooling = c.notified_at && now - dbMs(c.notified_at) < NOTIFY_COOLDOWN_MS;
      if (claimed > 0 && !baseline.has(c.id) && !cooling) {
        let channel = null;
        try {
          channel = await client.channels.fetch(c.discord_channel_id);
        } catch (err) {
          if (!(err instanceof DiscordAPIError)) throw err;
        }
        if (channel === null) {
          error = 'Der Discord-Kanal ist nicht erreichbar (gelöscht oder keine Rechte).';
        } else {
          try {
            await channel.send(liveMessage(c, s));
            await run(pool, 'UPDATE bot_creators SET notified_at = ? WHERE id = ?', [nowDb(), c.id]);
            posted++;
          } catch (err) {
            if (!(err instanceof DiscordAPIError)) throw err;
            error = `Posten nicht möglich: ${err.message}`;
            log?.warn(`Live-Meldung von ${c.name} nicht gepostet: ${err.message}`);
          }
        }
      }
    }
    await run(pool, 'UPDATE bot_creators SET checked_at = ?, last_error = ? WHERE id = ?', [nowDb(), error, c.id]);
  }
  return posted;
}
