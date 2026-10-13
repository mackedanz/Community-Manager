// Prüft jede neue Nachricht gegen die Automod-Regeln der Orga (lib/automod.js). Läuft nur, wenn der Bot Nachrichten lesen darf
// (BOT_MESSAGE_CONTENT=1 und "Message Content Intent" im Developer Portal).
import { Events, PermissionFlagsBits } from 'discord.js';
import { nowDb, val } from '../db.js';
import {
  anyRule, ESCALATE_AFTER, ESCALATE_TIMEOUT_MS, evaluate, guildContext, markSelfDeleted, SpamTracker,
} from '../lib/automod.js';
import { addCase, caseEmbed, dmText, durationText, logToChannel } from '../lib/moderation.js';
import { isMissingTable } from '../lib/settings.js';

const NOTICE_MS = 8000;
const HOUR_MS = 3600_000;

/** Nachrichten von Admins und Moderatoren werden nie geprüft. */
function exempt(member, ctx) {
  if (member === null) return false;
  if (member.permissions.has(PermissionFlagsBits.Administrator) || member.permissions.has(PermissionFlagsBits.ManageGuild)) return true;
  return ctx.modRoles.some((r) => member.roles.cache.has(r));
}

export function registerAutomod(client, { pool, log }) {
  const spam = new SpamTracker();

  async function check(message) {
    if (!message.inGuild() || message.author.bot || message.system || message.webhookId) return;
    const ctx = await guildContext(pool, message.guildId);
    if (ctx === null || !anyRule(ctx.cfg)) return;
    const { cfg } = ctx;
    const channel = message.channel;
    if (cfg.exemptChannels.includes(channel.id) || (channel.parentId && cfg.exemptChannels.includes(channel.parentId))) return;
    const member = message.member ?? (await message.guild.members.fetch(message.author.id).catch(() => null));
    if (exempt(member, ctx)) return;

    let hit = evaluate(message.content, { mentions: message.mentions.users.size + message.mentions.roles.size }, cfg);
    let ids = [message.id];
    if (hit === null && cfg.spam) {
      const burst = spam.record(`${message.guildId}:${message.author.id}`, message.id);
      if (burst !== null) {
        hit = { rule: 'spam', reason: 'Bitte nicht so schnell hintereinander schreiben.' };
        ids = burst;
      }
    }
    if (hit === null) return;
    await punish({ message, member, ctx, hit, ids });
  }

  async function punish({ message, member, ctx, hit, ids }) {
    const channel = message.channel;
    markSelfDeleted(...ids);
    try {
      if (ids.length > 1) await channel.bulkDelete(ids, true);
      else await message.delete();
    } catch (err) {
      log.warn(`Automod konnte die Nachricht nicht löschen (Recht „Nachrichten verwalten“?): ${err.message}`);
    }
    // Kurzer Hinweis im Kanal, der sich selbst wieder löscht
    try {
      const notice = await channel.send({ content: `<@${message.author.id}>, ${hit.reason}`, allowedMentions: { users: [message.author.id] } });
      setTimeout(() => notice.delete().catch(() => {}), NOTICE_MS).unref();
    } catch {
      // Kanal nicht beschreibbar: der Fall wird trotzdem gespeichert
    }
    const name = message.author.globalName || message.author.username;
    const c = { orgId: ctx.org.id, targetId: message.author.id, targetName: name, action: 'AUTOMOD', reason: hit.reason, detail: `Regel: ${hit.rule}` };
    const id = await addCase(pool, c);
    await logToChannel(client, pool, ctx.org.id, { embeds: [caseEmbed({ id, org_id: ctx.org.id, moderator_id: null, created_at: nowDb(), target_id: c.targetId, target_name: name, action: c.action, reason: c.reason, detail: c.detail })] }, log);

    // Wiederholte Verstöße: automatisch Timeout
    const since = nowDb(new Date(Date.now() - HOUR_MS));
    const count = Number(await val(pool, "SELECT COUNT(*) FROM bot_mod_cases WHERE org_id = ? AND target_id = ? AND action = 'AUTOMOD' AND created_at >= ?", [ctx.org.id, c.targetId, since]));
    if (count >= ESCALATE_AFTER && member?.moderatable && !member.isCommunicationDisabled()) {
      await member.timeout(ESCALATE_TIMEOUT_MS, `Automod: ${count} Verstöße in einer Stunde`);
      const detail = durationText(ESCALATE_TIMEOUT_MS);
      const reason = `Automod: ${count} Verstöße in einer Stunde`;
      const tid = await addCase(pool, { orgId: ctx.org.id, targetId: c.targetId, targetName: name, action: 'TIMEOUT', reason, detail });
      await member.user.send({ content: dmText(message.guild.name, 'TIMEOUT', reason, detail), allowedMentions: { parse: [] } }).catch(() => {});
      await logToChannel(client, pool, ctx.org.id, { embeds: [caseEmbed({ id: tid, org_id: ctx.org.id, moderator_id: null, created_at: nowDb(), target_id: c.targetId, target_name: name, action: 'TIMEOUT', reason, detail })] }, log);
    }
  }

  client.on(Events.MessageCreate, async (message) => {
    try {
      await check(message);
    } catch (err) {
      if (!isMissingTable(err)) log.warn(`Automod fehlgeschlagen: ${err.message}`);
    }
  });
}
