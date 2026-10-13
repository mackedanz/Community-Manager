// /discord-setup: legt fehlende Rollen, Kanäle, Regeln und die Willkommensnachricht an und trägt alles in die Einstellungen ein.
// Es ändert nie, was schon da ist: vorhandene Einstellungen bleiben, gleichnamige Rollen und Kanäle werden übernommen, und die
// Rechte (Permission Overwrites) setzt es nur für Kanäle, die es selbst neu anlegt. planSetup() ist reine Berechnung (testbar),
// runSetup() führt den Plan aus.
import { ChannelType, PermissionFlagsBits as P } from 'discord.js';
import { rulesMessage } from '../commands/serverregeln.js';
import { MOD_LOG_CHANNEL, MOD_ROLES } from './moderation.js';
import { findOrg, setRoles } from './onboarding.js';
import { parseRoleIds, ROLE_KINDS } from './roles.js';
import { ANNOUNCE_CHANNEL, setSetting } from './settings.js';
import { RULES_CHANNEL, WELCOME_CHANNEL } from './welcome.js';

export const CATEGORY_NAME = 'Community';
export const DEFAULT_RULES = '**1.** Geh respektvoll miteinander um. Beleidigungen, Hetze und Diskriminierung gibt es hier nicht.\n'
  + '**2.** Kein Spam, keine Werbung und keine Einladungslinks zu anderen Servern.\n'
  + '**3.** Bleib beim Thema des jeweiligen Kanals.\n'
  + '**4.** Den Anweisungen der Moderatoren ist zu folgen.\n\n'
  + 'Mit „Bestätigen“ erklärst du dich einverstanden und bekommst Zugang.';

/** Rollen: kind = Rollenart der Orga (roles.js), ohne kind = Moderatoren-Rolle (bot_settings). */
export const ROLE_DEFS = [
  { key: 'use', name: 'Mitglied', kind: 'use' },
  { key: 'plan', name: 'Planer', kind: 'plan' },
  { key: 'instruct', name: 'Ausbilder', kind: 'instruct' },
  { key: 'mod', name: 'Moderator', kind: null },
];

/** Kanäle in dieser Reihenfolge; access: 'read' = alle lesen, nur der Bot schreibt; 'mods' = nur Moderatoren und Admins. */
export const CHANNEL_DEFS = [
  { key: 'rules', name: 'regeln', setting: RULES_CHANNEL, access: 'read', topic: 'Bitte lesen und mit „Bestätigen“ zustimmen' },
  { key: 'welcome', name: 'willkommen', setting: WELCOME_CHANNEL, access: 'read', topic: 'Hier werden neue Mitglieder begrüßt' },
  { key: 'announce', name: 'termine', setting: ANNOUNCE_CHANNEL, access: 'read', topic: 'Termine der Orga: hier zu- oder absagen und Plätze wählen' },
  { key: 'modlog', name: 'mod-protokoll', setting: MOD_LOG_CHANNEL, access: 'mods', topic: 'Protokoll der Moderation (nur für Moderatoren)' },
];

const TEXT_TYPES = new Set([ChannelType.GuildText, ChannelType.GuildAnnouncement]);
const sameName = (a, b) => String(a).toLowerCase() === String(b).toLowerCase();

/**
 * Was ist zu tun? Aktionen je Rolle und Kanal: 'configured' (ist schon eingestellt, bleibt), 'use' (vorhandene gleichnamige
 * Rolle bzw. Kanal übernehmen) oder 'create' (neu anlegen).
 * @param {{org: object, modRoles: string[], settings: Record<string, string|null>, roles: Array<{id: string, name: string, managed?: boolean}>,
 *   channels: Array<{id: string, name: string, type: number}>}} s
 */
export function planSetup(s) {
  const roleById = new Map(s.roles.map((r) => [r.id, r]));
  const roles = ROLE_DEFS.map((def) => {
    const stored = def.kind ? parseRoleIds(s.org[ROLE_KINDS[def.kind]]) : s.modRoles;
    const valid = stored.filter((id) => roleById.has(id));
    if (valid.length > 0) return { ...def, action: 'configured', roleIds: valid };
    const found = s.roles.find((r) => sameName(r.name, def.name) && !r.managed);
    return found ? { ...def, action: 'use', roleIds: [found.id] } : { ...def, action: 'create', roleIds: [] };
  });

  const chById = new Map(s.channels.map((c) => [c.id, c]));
  const channels = CHANNEL_DEFS.map((def) => {
    const stored = s.settings[def.key];
    if (stored && TEXT_TYPES.has(chById.get(stored)?.type)) return { ...def, action: 'configured', channelId: stored };
    const found = s.channels.find((c) => TEXT_TYPES.has(c.type) && sameName(c.name, def.name));
    return found ? { ...def, action: 'use', channelId: found.id } : { ...def, action: 'create', channelId: null };
  });

  const needsCategory = channels.some((c) => c.action === 'create');
  const category = !needsCategory ? null
    : (s.channels.find((c) => c.type === ChannelType.GuildCategory && sameName(c.name, CATEGORY_NAME)) ?? null);
  const memberChange = roles.find((r) => r.key === 'use').action !== 'configured';
  return {
    roles,
    channels,
    category: needsCategory ? { action: category ? 'use' : 'create', channelId: category?.id ?? null } : null,
    postRules: channels.find((c) => c.key === 'rules').action === 'create',
    memberChange,
    empty: roles.every((r) => r.action === 'configured') && channels.every((c) => c.action === 'configured'),
  };
}

const MARK = { configured: '✅ vorhanden', use: '🔗 wird übernommen', create: '➕ wird angelegt' };

/** Vorschau für den Admin. */
export function planText(plan) {
  const lines = ['**Das würde /discord-setup tun**', '', '**Rollen**'];
  for (const r of plan.roles) lines.push(`${MARK[r.action]}: ${r.action === 'configured' ? r.roleIds.map((id) => `<@&${id}>`).join(', ') : r.action === 'use' ? `<@&${r.roleIds[0]}>` : `„${r.name}“`}`);
  lines.push('', '**Kanäle**');
  for (const c of plan.channels) lines.push(`${MARK[c.action]}: ${c.action === 'create' ? `#${c.name}` : `<#${c.channelId}>`}`);
  if (plan.category) lines.push(`${MARK[plan.category.action]}: Kategorie „${CATEGORY_NAME}“`);
  if (plan.postRules) lines.push('', 'In #regeln erscheint ein Standard-Regeltext mit „Bestätigen“ (ändern mit /serverregeln).');
  lines.push('', 'Vorhandenes wird nicht verändert. Neue Kanäle bekommen passende Rechte: #regeln, #willkommen und #termine kann jeder lesen, nur der Bot schreibt; '
    + '#mod-protokoll sehen nur Moderatoren und Admins.');
  if (plan.memberChange) {
    lines.push('', '⚠ **Wichtig:** Die Rolle für „Mitglied“ wird in der Orga eingetragen. Ab dann dürfen nur Mitglieder mit dieser Rolle das Tool nutzen. '
      + 'Bestehende Mitglieder bekommen sie durch „Bestätigen“ in #regeln oder von Hand.');
  }
  return lines.join('\n');
}

/** Rechte eines neuen Kanals. ids: everyone (= Server-ID), bot, mods (Moderatoren-Rollen). */
export function overwritesFor(access, ids) {
  const botAllow = [P.ViewChannel, P.SendMessages, P.EmbedLinks, P.ReadMessageHistory];
  const bot = { id: ids.bot, type: 1, allow: botAllow };
  if (access === 'mods') {
    return [
      { id: ids.everyone, type: 0, deny: [P.ViewChannel] },
      ...ids.mods.map((id) => ({ id, type: 0, allow: [P.ViewChannel, P.ReadMessageHistory] })),
      bot,
    ];
  }
  return [
    { id: ids.everyone, type: 0, allow: [P.ViewChannel, P.ReadMessageHistory], deny: [P.SendMessages, P.SendMessagesInThreads, P.CreatePublicThreads, P.CreatePrivateThreads] },
    bot,
  ];
}

/**
 * Führt den Plan aus und liefert den Bericht (Zeilen). Bei einem Fehler endet es dort mit einem Hinweis;
 * was bis dahin angelegt wurde, bleibt und ist eingetragen, ein zweiter Aufruf macht den Rest.
 */
export async function runSetup({ pool, guild, org, plan, botId, reason = '/discord-setup' }) {
  const report = [];
  const ids = {};
  let step = 'Rollen';
  try {
    for (const r of plan.roles) {
      let roleIds = r.roleIds;
      if (r.action === 'create') {
        const role = await guild.roles.create({ name: r.name, mentionable: false, permissions: [], reason });
        roleIds = [role.id];
        report.push(`➕ Rolle <@&${role.id}> angelegt`);
      } else if (r.action === 'use') {
        report.push(`🔗 Rolle <@&${roleIds[0]}> übernommen`);
      }
      ids[r.key] = roleIds;
      if (r.action !== 'configured') {
        if (r.kind) await setRoles(pool, org.id, r.kind, roleIds, { [roleIds[0]]: guild.roles.cache?.get?.(roleIds[0])?.name ?? r.name });
        else await setSetting(pool, org.id, MOD_ROLES, roleIds.join(','));
      }
    }

    step = 'Kanäle';
    let parent = plan.category?.channelId ?? null;
    if (plan.category?.action === 'create') {
      parent = (await guild.channels.create({ name: CATEGORY_NAME, type: ChannelType.GuildCategory, reason })).id;
      report.push(`➕ Kategorie „${CATEGORY_NAME}“ angelegt`);
    }
    const channelIds = {};
    for (const c of plan.channels) {
      let id = c.channelId;
      if (c.action === 'create') {
        const channel = await guild.channels.create({
          name: c.name, type: ChannelType.GuildText, parent, topic: c.topic, reason,
          permissionOverwrites: overwritesFor(c.access, { everyone: guild.id, bot: botId, mods: ids.mod }),
        });
        id = channel.id;
        report.push(`➕ Kanal <#${id}> angelegt`);
      } else if (c.action === 'use') {
        report.push(`🔗 Kanal <#${id}> übernommen`);
      }
      channelIds[c.key] = id;
      if (c.action !== 'configured') await setSetting(pool, org.id, c.setting, id);
    }

    step = 'Regeln';
    if (plan.postRules) {
      const fresh = await findOrg(pool, org.id);
      const memberRole = parseRoleIds(fresh?.member_role_ids)[0] ?? ids.use[0];
      const channel = await guild.channels.fetch(channelIds.rules);
      await channel.send(rulesMessage('Serverregeln', DEFAULT_RULES, memberRole));
      report.push(`➕ Regeln mit „Bestätigen“ in <#${channelIds.rules}> veröffentlicht`);
    }
  } catch (err) {
    report.push(`⚠ Abgebrochen bei „${step}“: ${err.message}. Bitte Rechte und Rollenreihenfolge des Bots prüfen und /discord-setup erneut ausführen; Fertiges bleibt bestehen.`);
  }
  return report;
}
