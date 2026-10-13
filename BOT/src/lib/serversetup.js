// /discord-setup: legt die Standard-Struktur eines Servers an (Rollen, Kategorien, Kanäle, Regeln, Willkommensnachricht) und trägt
// die Kanäle des Bots in die Einstellungen ein. Es ändert nie, was schon da ist: vorhandene Einstellungen bleiben, gleichnamige Rollen
// und Kanäle werden übernommen, und Rechte (Permission Overwrites) setzt es nur für Kategorien und Kanäle, die es selbst neu anlegt.
// planSetup() ist reine Berechnung (testbar), runSetup() führt den Plan aus.
import { ChannelType, PermissionFlagsBits as P } from 'discord.js';
import { rulesMessage } from '../commands/serverregeln.js';
import { MOD_LOG_CHANNEL, MOD_ROLES } from './moderation.js';
import { findOrg, setRoles } from './onboarding.js';
import { allAccessRoleIds, parseRoleIds, ROLE_KINDS } from './roles.js';
import { ANNOUNCE_CHANNEL, setSetting } from './settings.js';
import { RULES_CHANNEL, VISITOR_ROLE, WELCOME_CHANNEL } from './welcome.js';

export const ADMIN_CHANNEL = 'admin_channel_id';
/** Neue Kanäle beginnen mit diesem Zeichen (wie die vorhandenen auf dem Server, z. B. „┋chat“). */
export const CHANNEL_PREFIX = '┋';

export const DEFAULT_RULES = '**1.** Geh respektvoll miteinander um. Beleidigungen, Hetze und Diskriminierung gibt es hier nicht.\n'
  + '**2.** Kein Spam, keine Werbung und keine Einladungslinks zu anderen Servern.\n'
  + '**3.** Bleib beim Thema des jeweiligen Kanals.\n'
  + '**4.** Den Anweisungen der Moderatoren ist zu folgen.\n\n'
  + 'Mit „Bestätigen“ erklärst du dich einverstanden und bekommst Zugang.';

export const ADMIN_NOTE = '**Bot-Einstellungen für Admins**\n'
  + '`/einrichten` Orga, RSI-Orga und Rollen · `/design` Logo und Hintergrund · `/orga` Name und Discord-Events\n'
  + '`/termine-kanal` Terminankündigungen · `/serverregeln` Regeln mit „Bestätigen“ · `/willkommen` Begrüßung\n'
  + '`/mod-einstellungen` Moderatoren und Protokoll · `/automod` automatische Moderation · `/creator` neue YouTube-Videos\n'
  + '`/discord-setup` fehlende Rollen und Kanäle anlegen · `/hilfe` alle Befehle';

/**
 * Rollen: kind = Rollenart der Orga (roles.js), sonst steht die Rolle in bot_settings (Moderator: mod_role_ids, Besucher: visitor_role_id).
 * „Besucher“ bekommt man mit „Bestätigen“ unter den Regeln; „Mitglied“ (Nutzung des Tools) vergeben Admins und Recruiter.
 */
export const ROLE_DEFS = [
  { key: 'use', name: 'Mitglied', kind: 'use' },
  { key: 'plan', name: 'Planer', kind: 'plan' },
  { key: 'instruct', name: 'Ausbilder', kind: 'instruct' },
  { key: 'mod', name: 'Moderator', kind: null },
  { key: 'visitor', name: 'Besucher', kind: null },
];

/**
 * Wer eine Kategorie sieht: public = alle (auch wer die Regeln noch nicht bestätigt hat), visitors = Besucher (Regeln bestätigt) und
 * Mitglieder, members = nur Mitglieder (alle Rollen mit Zugang zum Tool und die Moderatoren), mods = nur Moderatoren, admins = nur Admins.
 */
export const VISIBILITY_LABELS = { public: 'alle', visitors: 'Besucher und Mitglieder', members: 'nur Mitglieder', mods: 'nur Moderatoren', admins: 'nur Admins' };

export const CATEGORY_DEFS = [
  { key: 'entrance', name: 'Eingangshalle', visibility: 'public' },
  { key: 'intern', name: 'Intern', visibility: 'members' },
  { key: 'events', name: 'Events', visibility: 'members' },
  { key: 'info', name: 'Star Citizen Info', visibility: 'visitors' },
  { key: 'open', name: 'Öffentlich', visibility: 'visitors' },
  { key: 'voice', name: 'Öffentliche Sprachkanäle', visibility: 'visitors' },
  { key: 'gaming', name: 'Gaming Area', visibility: 'visitors' },
  { key: 'departments', name: 'Abteilungen', visibility: 'members' },
  { key: 'admin', name: 'Administrative', visibility: 'admins' },
];

/**
 * Kanäle in dieser Reihenfolge. write 'bot' = nur der Bot schreibt, 'all' = alle, die den Kanal sehen. setting = der Kanal wird in den
 * Einstellungen des Bots eingetragen. visibility fehlt = wie die Kategorie. aliases = weitere Namen, unter denen ein vorhandener Kanal gilt.
 */
export const CHANNEL_DEFS = [
  { key: 'rules', name: '! -server-regeln-!', aliases: ['regeln'], category: 'entrance', write: 'bot', setting: RULES_CHANNEL, topic: 'Bitte lesen und mit „Bestätigen“ zustimmen' },
  { key: 'welcome', name: 'willkommen', category: 'entrance', write: 'bot', setting: WELCOME_CHANNEL, topic: 'Hier werden neue Mitglieder begrüßt' },
  { name: 'team', category: 'intern', write: 'all' },
  { name: 'abteilungen', category: 'intern', write: 'all' },
  { name: 'recruits', category: 'intern', write: 'all' },
  { key: 'announce', name: 'operations-plan', aliases: ['termine'], category: 'events', write: 'bot', setting: ANNOUNCE_CHANNEL, topic: 'Termine der Orga: hier zu- oder absagen und Plätze wählen' },
  { name: 'op-chat', category: 'events', write: 'all' },
  { name: 'patchnotes', category: 'info', write: 'all' },
  { name: 'sc-news', category: 'info', write: 'all' },
  { name: 'youtube-news', category: 'info', write: 'all' },
  { name: 'tips und tricks', category: 'info', write: 'all' },
  { name: 'issue-council', category: 'info', write: 'all' },
  { name: 'leaks', category: 'info', write: 'all' },
  { name: 'screenshots', category: 'open', write: 'all' },
  { name: 'chat', category: 'open', write: 'all' },
  { name: 'wer streamt gerade', category: 'open', write: 'all' },
  { name: 'Lobby', voice: true, category: 'voice' },
  { name: 'Gruppe 1', voice: true, category: 'voice' },
  { name: 'Gruppe 2', voice: true, category: 'voice' },
  { name: 'Gaming 1', voice: true, category: 'gaming' },
  { name: 'Gaming 2', voice: true, category: 'gaming' },
  { key: 'modlog', name: 'mod-protokoll', category: 'admin', visibility: 'mods', write: 'bot', setting: MOD_LOG_CHANNEL, topic: 'Protokoll der Moderation (nur für Moderatoren)' },
  { key: 'admin', name: 'bot-einstellungen', category: 'admin', write: 'all', setting: ADMIN_CHANNEL, topic: 'Einstellungen des Bots (nur für Admins)' },
];

const TEXT_TYPES = new Set([ChannelType.GuildText, ChannelType.GuildAnnouncement]);
/** Name ohne Zeichen wie „┋“, „・“ oder „!“ vorn und hinten, klein geschrieben, Leerzeichen als „-“: „┋Regeln“ und „regeln“ sind derselbe Kanal. */
export const baseName = (name) => String(name).replace(/^[^\p{L}\p{N}]+|[^\p{L}\p{N}]+$/gu, '').replace(/\s+/g, '-').toLowerCase();
const sameName = (a, b) => baseName(a) === baseName(b);
const channelMatches = (channel, def) => (def.voice ? channel.type === ChannelType.GuildVoice : TEXT_TYPES.has(channel.type))
  && [def.name, ...(def.aliases ?? [])].some((n) => sameName(channel.name, n));

/**
 * Was ist zu tun? Rollen und Kanäle mit Setting: 'configured' (ist schon eingestellt, bleibt), 'use' (vorhandenes gleichnamiges
 * übernehmen und eintragen) oder 'create' (neu anlegen). Kanäle ohne Setting: 'configured', wenn es sie gibt, sonst 'create'.
 * @param {{org: object, modRoles: string[], settings: Record<string, string|null>, roles: Array<{id: string, name: string, managed?: boolean}>,
 *   channels: Array<{id: string, name: string, type: number}>}} s
 */
export function planSetup(s) {
  const roleById = new Map(s.roles.map((r) => [r.id, r]));
  const roles = ROLE_DEFS.map((def) => {
    const stored = def.kind ? parseRoleIds(s.org[ROLE_KINDS[def.kind]]) : def.key === 'visitor' ? (s.visitorRoles ?? []) : s.modRoles;
    const valid = stored.filter((id) => roleById.has(id));
    if (valid.length > 0) return { ...def, action: 'configured', roleIds: valid };
    const found = s.roles.find((r) => sameName(r.name, def.name) && !r.managed);
    return found ? { ...def, action: 'use', roleIds: [found.id] } : { ...def, action: 'create', roleIds: [] };
  });

  const chById = new Map(s.channels.map((c) => [c.id, c]));
  const channels = CHANNEL_DEFS.map((def) => {
    const stored = def.key ? s.settings[def.key] : null;
    if (stored && TEXT_TYPES.has(chById.get(stored)?.type)) return { ...def, action: 'configured', channelId: stored };
    const found = s.channels.find((c) => channelMatches(c, def));
    if (found) return { ...def, action: def.setting ? 'use' : 'configured', channelId: found.id };
    return { ...def, action: 'create', channelId: null };
  });

  // Eine Kategorie wird gebraucht, wenn darin ein Kanal neu entsteht oder sie von sich aus leer vorgesehen ist (z. B. „Abteilungen“)
  const categories = CATEGORY_DEFS.map((def) => {
    const found = s.channels.find((c) => c.type === ChannelType.GuildCategory && sameName(c.name, def.name));
    const inCategory = channels.filter((c) => c.category === def.key);
    const needed = inCategory.length === 0 ? !found : inCategory.some((c) => c.action === 'create');
    return { ...def, needed, action: found ? 'use' : 'create', channelId: found?.id ?? null };
  }).filter((k) => k.needed);

  return {
    roles,
    channels,
    categories,
    postRules: channels.find((c) => c.key === 'rules').action === 'create',
    postAdminNote: channels.find((c) => c.key === 'admin').action === 'create',
    memberChange: roles.find((r) => r.key === 'use').action !== 'configured',
    empty: roles.every((r) => r.action === 'configured') && channels.every((c) => c.action === 'configured') && categories.every((k) => k.action === 'use'),
  };
}

const MARK = { configured: '✅', use: '🔗', create: '➕' };

/** Vorschau für den Admin (kompakt, passt in einen Kasten). */
export function planText(plan) {
  const roleText = (r) => (r.action === 'create' ? `„${r.name}“` : r.roleIds.map((id) => `<@&${id}>`).join(' '));
  const lines = [
    `**Rollen:** ${plan.roles.map((r) => `${MARK[r.action]} ${roleText(r)}`).join(' · ')}`,
    '',
    '**Kategorien und Kanäle**',
  ];
  for (const cat of CATEGORY_DEFS) {
    const channels = plan.channels.filter((c) => c.category === cat.key);
    const entry = plan.categories.find((k) => k.key === cat.key);
    const list = channels.map((c) => `${MARK[c.action]} ${c.action === 'create' ? `${c.voice ? '🔊' : '#'}${CHANNEL_PREFIX}${c.name}` : `<#${c.channelId}>`}`);
    // Eine Kategorie, die nicht gebraucht wird (alles darin ist schon da), bleibt wie sie ist
    lines.push(`${MARK[entry?.action ?? 'configured']} **${cat.name}** (${VISIBILITY_LABELS[cat.visibility]})${list.length ? `: ${list.join(' · ')}` : ' (leer)'}`);
  }
  lines.push('', '✅ vorhanden, bleibt unverändert · 🔗 vorhandenes wird übernommen · ➕ wird neu angelegt');
  if (plan.categories.some((k) => k.visibility === 'visitors' && k.action === 'create')) {
    lines.push('Wer neu auf den Server kommt, sieht nur die Eingangshalle. Mit „Bestätigen“ in den Regeln gibt es die Rolle „Besucher“ und damit die öffentlichen Bereiche.');
  }
  if (plan.postRules) lines.push('Im neuen Regeln-Kanal erscheint ein Standard-Regeltext; „Bestätigen“ vergibt die Rolle „Besucher“ (ändern mit /serverregeln).');
  lines.push('Rechte setzt der Bot nur für das, was er neu anlegt. Vorhandenes bleibt, wie es ist.');
  if (plan.memberChange) {
    lines.push('', '⚠ **Wichtig:** Die Rolle „Mitglied“ wird in der Orga eingetragen. Ab dann dürfen nur Mitglieder mit dieser Rolle das Tool nutzen, '
      + 'und Intern, Events und Abteilungen sehen nur sie. Bestehende Mitglieder bekommen die Rolle durch „Bestätigen“ in den Regeln oder von Hand.');
  }
  return lines.join('\n').slice(0, 4000);
}

/**
 * Rechte einer Kategorie oder eines Kanals. visibility: public | members | mods | admins; write: 'bot' = nur der Bot darf schreiben.
 * ids: everyone (= Server-ID), bot, members (Rollen, die Mitglieder-Bereiche sehen), mods (Moderatoren-Rollen), visitors (Besucher-Rolle).
 * voice: Sprachkanal, dort braucht der Bot keine Rechte.
 */
export function overwritesFor(visibility, write, ids, voice = false) {
  const map = new Map();
  const entry = (id, type) => {
    if (!map.has(id)) map.set(id, { id, type, allow: [], deny: [] });
    return map.get(id);
  };
  // Besucher-Bereiche sehen auch die Mitglieder, die die Regeln-Rolle nicht haben
  const viewers = visibility === 'members' ? ids.members
    : visibility === 'visitors' ? [...new Set([...(ids.visitors ?? []), ...ids.members])]
      : visibility === 'mods' ? ids.mods : [];
  if (visibility !== 'public') entry(ids.everyone, 0).deny.push(P.ViewChannel);
  for (const id of viewers) entry(id, 0).allow.push(P.ViewChannel, P.ReadMessageHistory);
  if (write === 'bot' && !voice) {
    const everyone = entry(ids.everyone, 0);
    if (visibility === 'public') everyone.allow.push(P.ViewChannel, P.ReadMessageHistory);
    for (const e of map.values()) e.deny.push(P.SendMessages, P.SendMessagesInThreads, P.CreatePublicThreads, P.CreatePrivateThreads);
  }
  // Der Bot selbst sieht und beschreibt, was er verwaltet (auch hinter einem gesperrten @everyone)
  if (!voice) entry(ids.bot, 1).allow.push(P.ViewChannel, P.SendMessages, P.EmbedLinks, P.ReadMessageHistory);
  return [...map.values()].map(({ allow, deny, ...rest }) => ({ ...rest, ...(allow.length ? { allow } : {}), ...(deny.length ? { deny } : {}) }));
}

/**
 * Führt den Plan aus und liefert den Bericht (Zeilen). Bei einem Fehler endet es dort mit einem Hinweis;
 * was bis dahin angelegt wurde, bleibt und ist eingetragen, ein zweiter Aufruf macht den Rest.
 */
export async function runSetup({ pool, guild, org, plan, botId, reason = '/discord-setup' }) {
  const report = [];
  const ids = {};
  const counts = { roles: 0, categories: 0, channels: 0, adopted: 0 };
  let step = 'Rollen';
  try {
    for (const r of plan.roles) {
      let roleIds = r.roleIds;
      if (r.action === 'create') {
        const role = await guild.roles.create({ name: r.name, mentionable: false, permissions: [], reason });
        roleIds = [role.id];
        counts.roles += 1;
      } else if (r.action === 'use') {
        counts.adopted += 1;
      }
      ids[r.key] = roleIds;
      if (r.action !== 'configured') {
        if (r.kind) await setRoles(pool, org.id, r.kind, roleIds, { [roleIds[0]]: guild.roles.cache?.get?.(roleIds[0])?.name ?? r.name });
        else if (r.key === 'visitor') await setSetting(pool, org.id, VISITOR_ROLE, roleIds[0]);
        else await setSetting(pool, org.id, MOD_ROLES, roleIds.join(','));
      }
    }
    if (counts.roles > 0 || plan.roles.some((r) => r.action === 'use')) report.push(`Rollen: ${counts.roles} angelegt, ${counts.adopted} übernommen`);

    // Wer Mitglieder-Bereiche sieht: alle Rollen mit Zugang zum Tool sowie die Moderatoren
    const fresh = await findOrg(pool, org.id);
    const overwriteIds = {
      everyone: guild.id, bot: botId, mods: ids.mod, visitors: ids.visitor,
      members: [...new Set([...allAccessRoleIds(fresh ?? org), ...ids.mod, ...ids.use])],
    };

    step = 'Kategorien';
    const parents = new Map();
    for (const k of plan.categories) {
      if (k.action === 'create') {
        const cat = await guild.channels.create({
          name: k.name, type: ChannelType.GuildCategory, reason,
          permissionOverwrites: overwritesFor(k.visibility, 'all', overwriteIds),
        });
        parents.set(k.key, cat.id);
        counts.categories += 1;
      } else {
        parents.set(k.key, k.channelId);
      }
    }
    if (counts.categories > 0) report.push(`Kategorien: ${counts.categories} angelegt`);

    step = 'Kanäle';
    const channelIds = {};
    const settingsSet = [];
    for (const c of plan.channels) {
      let id = c.channelId;
      if (c.action === 'create') {
        const visibility = c.visibility ?? CATEGORY_DEFS.find((k) => k.key === c.category).visibility;
        const channel = await guild.channels.create({
          name: `${CHANNEL_PREFIX}${c.name}`,
          type: c.voice ? ChannelType.GuildVoice : ChannelType.GuildText,
          parent: parents.get(c.category),
          ...(c.topic ? { topic: c.topic } : {}),
          reason,
          permissionOverwrites: overwritesFor(visibility, c.write, overwriteIds, Boolean(c.voice)),
        });
        id = channel.id;
        counts.channels += 1;
      } else if (c.action === 'use') {
        counts.adopted += 1;
      }
      channelIds[c.key ?? c.name] = id;
      if (c.setting && c.action !== 'configured') {
        await setSetting(pool, org.id, c.setting, id);
        settingsSet.push(`<#${id}>`);
      }
    }
    report.push(`Kanäle: ${counts.channels} angelegt`);
    if (settingsSet.length) report.push(`Für den Bot eingetragen: ${settingsSet.join(', ')}`);

    step = 'Nachrichten';
    if (plan.postRules) {
      // „Bestätigen“ vergibt die Besucher-Rolle
      const channel = await guild.channels.fetch(channelIds.rules);
      await channel.send(rulesMessage('Serverregeln', DEFAULT_RULES, ids.visitor[0]));
      report.push(`Regeln mit „Bestätigen“ in <#${channelIds.rules}> veröffentlicht`);
    }
    if (plan.postAdminNote) {
      const channel = await guild.channels.fetch(channelIds.admin);
      await channel.send({ content: ADMIN_NOTE, allowedMentions: { parse: [] } });
    }
  } catch (err) {
    report.push(`⚠ Abgebrochen bei „${step}“: ${err.message}. Bitte Rechte und Rollenreihenfolge des Bots prüfen und /discord-setup erneut ausführen; Fertiges bleibt bestehen.`);
  }
  return report;
}
