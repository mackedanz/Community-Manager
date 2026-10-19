// Temporäre Sprachkanäle: Wer den Auslöser-Kanal betritt, bekommt einen eigenen Sprachkanal mit seinem Namen und wird hineinverschoben.
// Ist der Kanal leer, wird er nach drei Minuten gelöscht. Wer in der Zeit zurückkommt, rettet ihn.
import { ChannelType } from 'discord.js';
import { orgForGuild } from '../db.js';
import { getSetting, setSetting } from './settings.js';

/** Einstellung: ID des Auslöser-Kanals ("Sprachkanal erstellen"). */
export const TEMPVOICE_TRIGGER = 'tempvoice_trigger';
/** Einstellung: IDs der vom Bot angelegten Kanäle (JSON-Liste), damit sie einen Neustart überleben. */
export const TEMPVOICE_CHANNELS = 'tempvoice_channels';
export const DELETE_DELAY_MS = 3 * 60_000;
const MAX_NAME = 100;
const UNKNOWN_CHANNEL = 10003;

/** Kanalname aus dem Namen des Mitglieds (Discord erlaubt höchstens 100 Zeichen). */
export function channelNameFor(member) {
  const name = String(member?.displayName ?? member?.user?.username ?? '').trim();
  return (name === '' ? 'Sprachkanal' : name).slice(0, MAX_NAME);
}

function parseIds(value) {
  try {
    const list = JSON.parse(value ?? '[]');
    return Array.isArray(list) ? list.map(String) : [];
  } catch {
    return [];
  }
}

export function createTempVoice({ pool, log, delayMs = DELETE_DELAY_MS, setTimer = setTimeout, clearTimer = clearTimeout, store }) {
  const db = store ?? {
    org: (guildId) => orgForGuild(pool, guildId),
    get: (orgId, name) => getSetting(pool, orgId, name),
    set: (orgId, name, value) => setSetting(pool, orgId, name, value),
  };
  /** Kanal-ID => Orga-ID der Kanäle, die der Bot angelegt hat */
  const tracked = new Map();
  const timers = new Map();
  const creating = new Set();

  async function persist(orgId) {
    const ids = [...tracked].filter(([, org]) => org === orgId).map(([id]) => id);
    await db.set(orgId, TEMPVOICE_CHANNELS, ids.length === 0 ? null : JSON.stringify(ids));
  }

  function cancelDelete(channelId) {
    clearTimer(timers.get(channelId));
    timers.delete(channelId);
  }

  async function untrack(channelId) {
    cancelDelete(channelId);
    const orgId = tracked.get(channelId);
    if (orgId === undefined) return;
    tracked.delete(channelId);
    await persist(orgId);
  }

  function scheduleDelete(channel) {
    cancelDelete(channel.id);
    const timer = setTimer(async () => {
      timers.delete(channel.id);
      try {
        const guild = channel.guild;
        const current = guild.channels.cache.get(channel.id) ?? (await guild.channels.fetch(channel.id).catch((err) => {
          if (err?.code === UNKNOWN_CHANNEL) return null;
          throw err;
        }));
        if (current && current.members.size > 0) return;
        if (current) await current.delete('Temporärer Sprachkanal ist seit 3 Minuten leer');
        await untrack(channel.id);
      } catch (err) {
        log.warn(`Temporärer Sprachkanal ${channel.id} konnte nicht gelöscht werden: ${err.message}`);
      }
    }, delayMs);
    timer.unref?.();
    timers.set(channel.id, timer);
  }

  async function create(member, trigger, orgId) {
    const channel = await trigger.guild.channels.create({
      name: channelNameFor(member),
      type: ChannelType.GuildVoice,
      parent: trigger.parentId,
      bitrate: trigger.bitrate,
      permissionOverwrites: trigger.permissionOverwrites.cache.map((o) => ({ id: o.id, type: o.type, allow: o.allow, deny: o.deny })),
      reason: `Temporärer Sprachkanal für ${member.user.username}`,
    });
    tracked.set(channel.id, orgId);
    await persist(orgId);
    try {
      await member.voice.setChannel(channel);
    } catch (err) {
      // Wer schon wieder weg ist, lässt einen leeren Kanal zurück: der verschwindet nach der Frist von selbst
      log.warn(`${member.id} konnte nicht in den neuen Sprachkanal verschoben werden: ${err.message}`);
      scheduleDelete(channel);
    }
  }

  /** Reagiert auf Betreten und Verlassen von Sprachkanälen (Ereignis voiceStateUpdate). */
  async function handleVoiceState(oldState, newState) {
    const member = newState.member ?? oldState.member;
    if (member?.user?.bot) return;
    const left = oldState.channelId && oldState.channelId !== newState.channelId ? oldState.channel : null;
    const joinedId = newState.channelId && newState.channelId !== oldState.channelId ? newState.channelId : null;

    if (joinedId !== null) {
      if (tracked.has(joinedId)) cancelDelete(joinedId);
      else if (!creating.has(member.id)) {
        const org = await db.org(newState.guild.id);
        if (org !== null && (await db.get(org.id, TEMPVOICE_TRIGGER)) === joinedId) {
          creating.add(member.id);
          try {
            await create(member, newState.channel, org.id);
          } finally {
            creating.delete(member.id);
          }
        }
      }
    }
    if (left !== null && tracked.has(left.id) && left.members.size === 0) scheduleDelete(left);
  }

  /** Beim Start: gemerkte Kanäle wieder aufnehmen, verschwundene vergessen, leere zum Löschen vormerken. */
  async function sweep(client) {
    for (const guild of client.guilds.cache.values()) {
      const org = await db.org(guild.id);
      if (org === null) continue;
      for (const id of parseIds(await db.get(org.id, TEMPVOICE_CHANNELS))) {
        const channel = guild.channels.cache.get(id) ?? (await guild.channels.fetch(id).catch(() => null));
        tracked.set(id, org.id);
        if (channel === null) await untrack(id);
        else if (channel.members.size === 0) scheduleDelete(channel);
      }
    }
  }

  function stop() {
    for (const t of timers.values()) clearTimer(t);
    timers.clear();
  }

  return { handleVoiceState, sweep, stop, trackedIds: () => [...tracked.keys()], pending: () => timers.size };
}
