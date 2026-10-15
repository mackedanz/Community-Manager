// Twitch ohne Anmeldung der Nutzer: App-Token (Client Credentials) und die Helix-Abfragen "Users" und "Streams".
// Gebraucht werden TWITCH_CLIENT_ID und TWITCH_CLIENT_SECRET einer auf dev.twitch.tv angelegten Anwendung.
import { OrgError } from './errors.js';

const API = 'https://api.twitch.tv/helix';
const TOKEN_URL = 'https://id.twitch.tv/oauth2/token';
const LOGIN = /^[a-z0-9_]{3,25}$/;
const CHUNK = 100;

/** Anmeldename aus einem Link (twitch.tv/name), @Name oder dem Namen selbst; klein geschrieben, null wenn es keiner sein kann. */
export function parseLogin(input) {
  let s = String(input ?? '').trim();
  const link = s.match(/^(?:https?:\/\/)?(?:www\.|m\.)?twitch\.tv\/([A-Za-z0-9_]+)\/?(?:[?#].*)?$/i);
  if (link) s = link[1];
  s = s.replace(/^@/, '').toLowerCase();
  return LOGIN.test(s) ? s : null;
}

/**
 * @param {{clientId: string, clientSecret: string}} cfg
 * @param {typeof fetch} [fetchFn]
 */
export function createTwitch({ clientId, clientSecret }, fetchFn = fetch) {
  let token = null;
  let expires = 0;

  async function fetchToken() {
    let res;
    try {
      res = await fetchFn(TOKEN_URL, {
        method: 'POST',
        body: new URLSearchParams({ client_id: clientId, client_secret: clientSecret, grant_type: 'client_credentials' }),
        signal: AbortSignal.timeout(20_000),
      });
    } catch {
      throw new OrgError('Twitch ist gerade nicht erreichbar.');
    }
    if (res.status === 400 || res.status === 401 || res.status === 403) throw new OrgError('Twitch lehnt die Zugangsdaten ab (TWITCH_CLIENT_ID und TWITCH_CLIENT_SECRET prüfen).');
    if (res.status !== 200) throw new OrgError(`Twitch antwortet mit Status ${res.status}.`);
    const body = await res.json();
    token = body.access_token;
    expires = Date.now() + Math.max(60, Number(body.expires_in ?? 3600) - 60) * 1000;
  }

  async function get(path) {
    if (token === null || Date.now() >= expires) await fetchToken();
    for (let attempt = 0; attempt < 2; attempt++) {
      let res;
      try {
        res = await fetchFn(`${API}${path}`, { headers: { 'Client-Id': clientId, Authorization: `Bearer ${token}` }, signal: AbortSignal.timeout(20_000) });
      } catch {
        throw new OrgError('Twitch ist gerade nicht erreichbar.');
      }
      if (res.status === 401 && attempt === 0) {
        await fetchToken();
        continue;
      }
      if (res.status !== 200) throw new OrgError(`Twitch antwortet mit Status ${res.status}.`);
      return (await res.json()).data ?? [];
    }
    return [];
  }

  const query = (name, logins) => logins.map((l) => `${name}=${encodeURIComponent(l)}`).join('&');

  return {
    /** Kanäle zu Anmeldenamen. @returns {Promise<Map<string, {id: string, login: string, name: string}>>} */
    async users(logins) {
      const out = new Map();
      for (let i = 0; i < logins.length; i += CHUNK) {
        for (const u of await get(`/users?${query('login', logins.slice(i, i + CHUNK))}`)) {
          out.set(String(u.login).toLowerCase(), { id: String(u.id), login: String(u.login).toLowerCase(), name: String(u.display_name ?? u.login) });
        }
      }
      return out;
    },
    /** Wer von diesen Kanälen gerade live ist. @returns {Promise<Map<string, {id: string, title: string, game: string, name: string}>>} */
    async streams(logins) {
      const out = new Map();
      for (let i = 0; i < logins.length; i += CHUNK) {
        for (const s of await get(`/streams?first=100&${query('user_login', logins.slice(i, i + CHUNK))}`)) {
          if (s.type !== 'live') continue;
          out.set(String(s.user_login).toLowerCase(), { id: String(s.id), title: String(s.title ?? ''), game: String(s.game_name ?? ''), name: String(s.user_name ?? s.user_login) });
        }
      }
      return out;
    },
  };
}
