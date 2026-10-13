// Willkommensnachricht für neue Mitglieder. Kanal und Text stehen in bot_settings (welcome_channel_id, welcome_text);
// rules_channel_id (vom /discord-setup gesetzt) macht {regeln} im Text zum Kanal-Link.
import { getSetting } from './settings.js';
import { cut } from './text.js';

export const WELCOME_CHANNEL = 'welcome_channel_id';
export const WELCOME_TEXT = 'welcome_text';
export const RULES_CHANNEL = 'rules_channel_id';
/** Rolle „Besucher“: bekommt man mit „Bestätigen“ unter den Regeln (vom /discord-setup gesetzt, Standard bei /serverregeln). */
export const VISITOR_ROLE = 'visitor_role_id';
export const MAX_WELCOME_TEXT = 500;

export const DEFAULT_WELCOME = 'Willkommen {user} auf **{server}**! Lies bitte {regeln} und bestätige sie, dann bist du dabei.';

/** Setzt {user}, {server} und {regeln} ein (ohne Regeln-Kanal heißt {regeln} einfach „die Regeln“). */
export function welcomeText(template, { userId, serverName, rulesChannelId = null }) {
  return cut(
    String(template || DEFAULT_WELCOME)
      .replaceAll('{user}', `<@${userId}>`)
      .replaceAll('{server}', serverName)
      .replaceAll('{regeln}', rulesChannelId ? `<#${rulesChannelId}>` : 'die Regeln'),
    2000,
  );
}

/** Nachricht an den Willkommens-Kanal; null, wenn kein Kanal eingestellt ist. */
export async function welcomeMessage(db, orgId, member) {
  const channelId = await getSetting(db, orgId, WELCOME_CHANNEL);
  if (channelId === null) return null;
  const content = welcomeText(await getSetting(db, orgId, WELCOME_TEXT), {
    userId: member.id,
    serverName: member.guild.name,
    rulesChannelId: await getSetting(db, orgId, RULES_CHANNEL),
  });
  return { channelId, payload: { content, allowedMentions: { users: [member.id] } } };
}
