// /profil: Profil eines Mitglieds (oder das eigene): RSI-Handle, Schiffe, Errungenschaften, Ausbildungen, letzter Sync.
// Schiffe und Errungenschaften nur, wenn die Person sie für Mitglieder freigegeben hat (eigene immer).
import { MessageFlags } from 'discord.js';
import { memberContext } from '../lib/access.js';
import { achievements, canView, groupShips, hangarItems, lastSync, memberAccount, rsiHandleOf } from '../lib/hangar.js';
import { cut } from '../lib/text.js';
import { qualifications } from '../lib/training.js';

/** Für /hilfe: nutzbar für Mitglieder der Orga */
export const audience = 'members';

export const data = {
  name: 'profil',
  description: 'Profil eines Mitglieds anzeigen: RSI-Handle, Schiffe, Errungenschaften, Ausbildungen',
  type: 1,
  options: [{ type: 6, name: 'mitglied', description: 'Profil dieses Mitglieds (ohne Angabe: dein eigenes)', required: false }],
  contexts: [0],
};

const RSI = 'https://robertsspaceindustries.com';
const unix = (dbTime) => Math.floor(Date.parse(`${dbTime.replace(' ', 'T')}Z`) / 1000);

/** Kasten des Profils. null bei Schiffen/Errungenschaften heißt: nicht freigegeben. */
export function profileEmbed(p) {
  const fields = [];
  fields.push({ name: 'RSI-Handle', value: p.rsiHandle ? `[${p.rsiHandle}](${RSI}/en/citizens/${encodeURIComponent(p.rsiHandle)})` : 'unbekannt', inline: true });
  if (p.ships === null) {
    fields.push({ name: 'Schiffe', value: 'nicht freigegeben', inline: true });
  } else {
    const total = p.ships.reduce((s, x) => s + x.count, 0);
    fields.push({ name: 'Schiffe', value: String(total), inline: true });
  }
  if (p.lastSync) fields.push({ name: 'Letzter RSI-Sync', value: `<t:${unix(p.lastSync)}:R>`, inline: true });
  if (p.ships !== null && p.ships.length > 0) {
    const top = [...p.ships].sort((a, b) => b.count - a.count || a.name.localeCompare(b.name)).slice(0, 10);
    fields.push({ name: 'Schiffe (Auswahl)', value: cut(top.map((s) => `${s.count > 1 ? `${s.count}× ` : ''}${s.name}`).join(', '), 1024) });
  }
  if (p.achievements === null) {
    fields.push({ name: 'Errungenschaften', value: 'nicht freigegeben' });
  } else if (p.achievements.length > 0) {
    fields.push({ name: `Errungenschaften (${p.achievements.length})`, value: cut(p.achievements.map((a) => a.title).join(', '), 1024) });
  }
  if (p.qualifications.length > 0) {
    fields.push({ name: `Ausbildungen (${p.qualifications.length})`, value: cut(p.qualifications.map((q) => `${q.topic} ${q.level}`).join(', '), 1024) });
  }
  return { title: cut(p.title, 256), url: p.url, fields, color: 0x0ea5e9, thumbnail: p.avatar ? { url: p.avatar } : undefined };
}

export async function execute(interaction, { pool, config }) {
  const reply = (payload) => interaction.reply({ ...payload, flags: MessageFlags.Ephemeral, allowedMentions: { parse: [] } });
  const { org, refusal } = await memberContext(interaction, pool);
  if (refusal) {
    await reply({ content: refusal });
    return;
  }
  const target = interaction.options.getUser('mitglied') ?? interaction.user;
  const self = target.id === interaction.user.id;
  const name = (self ? interaction.member?.displayName : interaction.options.getMember('mitglied')?.displayName) ?? target.globalName ?? target.username;
  const account = await memberAccount(pool, org.id, target.id);
  if (account === null) {
    await reply({ content: self ? `Du hast noch kein Konto im Community-Manager. Melde dich einmal auf ${config.appUrl} an.` : `${name} hat noch kein Konto im Community-Manager.` });
    return;
  }
  if (!self && !Number(account.in_org)) {
    await reply({ content: `${name} ist im Community-Manager kein Mitglied dieser Orga.` });
    return;
  }
  await interaction.deferReply({ flags: MessageFlags.Ephemeral });
  const showHangar = canView(account, 'hangar_visibility', self);
  const showAch = canView(account, 'achievements_visibility', self);
  const embed = profileEmbed({
    title: self ? 'Dein Profil' : `Profil von ${name}`,
    url: `${config.appUrl}/members/${account.id}`,
    avatar: target.displayAvatarURL?.({ size: 128 }) ?? null,
    rsiHandle: await rsiHandleOf(pool, account),
    ships: showHangar ? groupShips(await hangarItems(pool, account.id, { shipsOnly: true })) : null,
    lastSync: showHangar ? await lastSync(pool, account.id) : null,
    achievements: showAch ? await achievements(pool, account.id) : null,
    qualifications: await qualifications(pool, org.id, account.id),
  });
  await interaction.editReply({ embeds: [embed] });
}
