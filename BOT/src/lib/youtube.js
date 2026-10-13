// YouTube ohne API-Schlüssel: Kanal-ID aus einem Link oder @Namen ermitteln und den öffentlichen Feed eines Kanals lesen
// (https://www.youtube.com/feeds/videos.xml?channel_id=UC…, die 15 neuesten Videos).
import { OrgError } from './errors.js';

const CHANNEL_ID = /^UC[A-Za-z0-9_-]{22}$/;
const HEADERS = {
  'User-Agent': 'Mozilla/5.0 (compatible; Community-Manager-Bot)',
  'Accept-Language': 'de-DE,de;q=0.9',
  // Ohne diese Cookies leitet YouTube aus der EU auf die Zustimmungsseite um
  Cookie: 'SOCS=CAI; CONSENT=YES+1',
};

/**
 * Was hat die Person eingegeben? Kanal-ID (UC…), Link mit /channel/UC…, @Name oder Link mit @Name.
 * @returns {{channelId: string}|{handle: string}|null}
 */
export function parseInput(input) {
  const s = String(input ?? '').trim();
  if (CHANNEL_ID.test(s)) return { channelId: s };
  const channel = s.match(/youtube\.com\/channel\/(UC[A-Za-z0-9_-]{22})/i);
  if (channel) return { channelId: channel[1] };
  const handle = s.match(/^@([A-Za-z0-9._-]{3,100})$/) ?? s.match(/youtube\.com\/@([A-Za-z0-9._%-]{3,100})/i);
  if (handle) return { handle: decodeURIComponent(handle[1]) };
  return null;
}

/** Kanal-ID und Name aus dem HTML einer Kanalseite. */
export function parseChannelPage(html) {
  const id = html.match(/<link rel="canonical" href="https:\/\/www\.youtube\.com\/channel\/(UC[A-Za-z0-9_-]{22})"/)?.[1]
    ?? html.match(/"externalId":"(UC[A-Za-z0-9_-]{22})"/)?.[1]
    ?? null;
  const name = html.match(/<meta property="og:title" content="([^"]*)"/)?.[1] ?? null;
  return { channelId: id, name: name === null ? null : decodeEntities(name) };
}

const ENTITIES = { amp: '&', lt: '<', gt: '>', quot: '"', apos: "'" };
function decodeEntities(s) {
  return s.replace(/&(#x[0-9a-f]+|#\d+|[a-z]+);/gi, (m, e) => {
    if (e[0] === '#') {
      const code = e[1] === 'x' || e[1] === 'X' ? parseInt(e.slice(2), 16) : parseInt(e.slice(1), 10);
      return Number.isFinite(code) ? String.fromCodePoint(code) : m;
    }
    return ENTITIES[e.toLowerCase()] ?? m;
  });
}

/**
 * Videos aus dem Feed (neueste zuerst, wie YouTube sie liefert).
 * @returns {{name: string|null, videos: Array<{id: string, title: string, url: string, published: string, short: boolean}>}}
 */
export function parseFeed(xml) {
  const name = xml.match(/<author>\s*<name>([^<]*)<\/name>/)?.[1] ?? null;
  const videos = [];
  for (const entry of xml.split('<entry>').slice(1)) {
    const id = entry.match(/<yt:videoId>([A-Za-z0-9_-]{6,20})<\/yt:videoId>/)?.[1];
    if (!id) continue;
    const url = entry.match(/<link rel="alternate" href="([^"]+)"/)?.[1] ?? `https://www.youtube.com/watch?v=${id}`;
    videos.push({
      id,
      title: decodeEntities(entry.match(/<title>([^<]*)<\/title>/)?.[1] ?? ''),
      url,
      published: entry.match(/<published>([^<]+)<\/published>/)?.[1] ?? '',
      short: url.includes('/shorts/'),
    });
  }
  return { name: name === null ? null : decodeEntities(name), videos };
}

/** Feed eines Kanals laden. @throws {OrgError} */
export async function fetchFeed(channelId, fetchFn = fetch) {
  let res;
  try {
    res = await fetchFn(`https://www.youtube.com/feeds/videos.xml?channel_id=${encodeURIComponent(channelId)}`, {
      headers: HEADERS, signal: AbortSignal.timeout(20_000),
    });
  } catch {
    throw new OrgError('YouTube ist gerade nicht erreichbar.');
  }
  if (res.status === 404) throw new OrgError('Diesen YouTube-Kanal gibt es nicht (mehr).');
  if (res.status !== 200) throw new OrgError(`YouTube antwortet mit Status ${res.status}.`);
  return parseFeed(await res.text());
}

/**
 * Kanal-ID und Name zu einer Eingabe ermitteln (Link, @Name oder Kanal-ID).
 * @returns {Promise<{channelId: string, name: string}>} @throws {OrgError}
 */
export async function resolveChannel(input, fetchFn = fetch) {
  const parsed = parseInput(input);
  if (parsed === null) {
    throw new OrgError('Das sieht nicht nach einem YouTube-Kanal aus. Erlaubt: Link zum Kanal (youtube.com/@Name oder youtube.com/channel/UC…), @Name oder die Kanal-ID.');
  }
  let channelId = parsed.channelId ?? null;
  let name = null;
  if (channelId === null) {
    let res;
    try {
      res = await fetchFn(`https://www.youtube.com/@${encodeURIComponent(parsed.handle)}`, { headers: HEADERS, signal: AbortSignal.timeout(20_000) });
    } catch {
      throw new OrgError('YouTube ist gerade nicht erreichbar.');
    }
    if (res.status === 404) throw new OrgError(`Einen YouTube-Kanal @${parsed.handle} gibt es nicht.`);
    if (res.status !== 200) throw new OrgError(`YouTube antwortet mit Status ${res.status}.`);
    const page = parseChannelPage(await res.text());
    if (page.channelId === null) throw new OrgError('Die Kanal-ID ließ sich nicht ermitteln. Bitte den Link mit /channel/UC… angeben.');
    channelId = page.channelId;
    name = page.name;
  }
  // Der Feed bestätigt den Kanal und liefert den Namen
  const feed = await fetchFeed(channelId, fetchFn);
  return { channelId, name: feed.name ?? name ?? channelId };
}
