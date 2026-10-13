// /ausbildung: bestandene Ausbildungen anzeigen, die eigenen oder (für Admins und Ausbilder) die eines Mitglieds.
import { MessageFlags } from 'discord.js';
import { orgForGuild } from '../db.js';
import { accessFor, userByDiscordId } from '../lib/access.js';
import { cut } from '../lib/text.js';
import { qualifications } from '../lib/training.js';

/** Für /hilfe: nutzbar für Mitglieder der Orga */
export const audience = 'members';

export const data = {
  name: 'ausbildung',
  description: 'Bestandene Ausbildungen anzeigen (eigene, oder als Ausbilder die eines Mitglieds)',
  type: 1,
  options: [{ type: 6, name: 'mitglied', description: 'Nur für Admins und Ausbilder: Ausbildungen dieses Mitglieds', required: false }],
  contexts: [0],
};

const day = new Intl.DateTimeFormat('de-DE', { timeZone: 'Europe/Berlin', day: '2-digit', month: '2-digit', year: 'numeric' });

/** Bestandene Stufen nach Bereich und Thema als Text. */
export function qualificationsText(heading, list) {
  if (list.length === 0) return `**${heading}**\nNoch keine Ausbildung bestanden.`;
  const byArea = new Map();
  for (const q of list) {
    const topics = byArea.get(q.areaName) ?? new Map();
    const levels = topics.get(q.topic) ?? [];
    const when = q.completedAt ? day.format(new Date(Date.parse(`${q.completedAt.replace(' ', 'T')}Z`))) : '';
    levels.push(`Stufe ${q.level} „${q.title}“${when ? ` (${when})` : ''}${q.retired ? ' – Ausbildung inzwischen gelöscht' : ''}`);
    topics.set(q.topic, levels);
    byArea.set(q.areaName, topics);
  }
  const parts = [...byArea].map(([area, topics]) => `__${area}__\n${[...topics].map(([topic, levels]) => `**${topic}:** ${levels.join(', ')}`).join('\n')}`);
  return cut(`**${heading}**\n${parts.join('\n\n')}`, 2000);
}

export async function execute(interaction, { pool, config }) {
  const reply = (content) => interaction.reply({ content, flags: MessageFlags.Ephemeral, allowedMentions: { parse: [] } });
  const org = await orgForGuild(pool, interaction.guildId);
  if (org === null) {
    await reply('Für diesen Server ist noch keine Orga eingerichtet. Das erledigt ein Server-Admin mit /einrichten.');
    return;
  }
  const access = accessFor(org, interaction);
  if (!access.member) {
    await reply('Das sehen nur Mitglieder der Orga.');
    return;
  }
  const target = interaction.options.getUser('mitglied') ?? interaction.user;
  const self = target.id === interaction.user.id;
  if (!self && !access.admin && !access.canInstruct) {
    await reply('Die Ausbildungen anderer sehen nur Admins und Ausbilder. Deine eigenen: /ausbildung');
    return;
  }
  const user = await userByDiscordId(pool, target.id);
  if (user === null) {
    await reply(self
      ? `Du hast noch kein Konto im Community-Manager. Melde dich einmal auf ${config.appUrl} an.`
      : `${target.displayName ?? target.username} hat noch kein Konto im Community-Manager.`);
    return;
  }
  const heading = self ? 'Deine Ausbildungen' : `Ausbildungen von ${interaction.options.getMember('mitglied')?.displayName ?? target.globalName ?? target.username}`;
  await reply(qualificationsText(heading, await qualifications(pool, org.id, user.id)));
}
