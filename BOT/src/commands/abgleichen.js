// /abgleichen: gleicht die Zugangsliste mit den Discord-Rollen ab. Dürfen: Server-Admins und alle mit einer
// Nutzungs-Rolle der Orga. Legt nie eine Orga an (das geht nur über /einrichten).
import { MessageFlags } from 'discord.js';
import { orgForGuild } from '../db.js';
import { OrgError } from '../lib/errors.js';
import { currentGuildIconUrl, guildMembers, isGuildAdmin, memberRoleIds } from '../lib/guild.js';
import { guildAllowed, syncAllowlist, syncNote } from '../lib/onboarding.js';
import { parseRoleIds } from '../lib/roles.js';

/** Mindestabstand zwischen zwei Abgleichen derselben Orga per /abgleichen (Sekunden). */
const COOLDOWN_SECONDS = 30;

/** Für /hilfe: nutzbar für Mitglieder der Orga */
export const audience = 'members';

export const data = {
  name: 'abgleichen',
  description: 'Mitglieder mit den Discord-Rollen abgleichen: wer darf sich im Community-Hangar anmelden',
  type: 1,
  contexts: [0], // für alle sichtbar; der Bot erlaubt es nur Mitgliedern der Orga und Server-Admins
};

/** Prüft, ob der Abgleich erlaubt ist. Liefert den Grund als Text, oder null wenn alles passt. */
export function refusal(org, { guildId, roles, admin, config, now = Date.now() }) {
  if (org === null) return 'Für diesen Server ist noch keine Orga eingerichtet. Das erledigt ein Server-Admin mit /einrichten.';
  if (!guildAllowed(config, guildId)) return 'Dieser Server ist nicht freigeschaltet.';
  const use = parseRoleIds(org.member_role_ids);
  if (!admin && !use.some((r) => roles.includes(r))) {
    return 'Das dürfen nur Mitglieder mit einer Nutzungs-Rolle der Orga und Server-Admins.';
  }
  const last = org.allowlist_synced_at ? Date.parse(`${org.allowlist_synced_at.replace(' ', 'T')}Z`) : NaN;
  if (Number.isFinite(last) && now - last < COOLDOWN_SECONDS * 1000) {
    return 'Die Mitglieder wurden gerade erst abgeglichen. Bitte warte kurz.';
  }
  if (use.length === 0) {
    return 'Es ist noch keine Rolle gewählt, die das Tool nutzen darf. Das erledigt ein Server-Admin mit /einrichten.';
  }
  return null;
}

export async function execute(interaction, { pool, config }) {
  const org = await orgForGuild(pool, interaction.guildId);
  const reason = refusal(org, {
    guildId: interaction.guildId,
    roles: memberRoleIds(interaction),
    admin: isGuildAdmin(interaction),
    config,
  });
  if (reason !== null) {
    await interaction.reply({ content: reason, flags: MessageFlags.Ephemeral, allowedMentions: { parse: [] } });
    return;
  }
  await interaction.deferReply({ flags: MessageFlags.Ephemeral });
  let note;
  try {
    note = syncNote(await syncAllowlist(pool, config, org.id, (guildId) => guildMembers(interaction.client, guildId), (guildId) => currentGuildIconUrl(interaction.client, guildId)));
  } catch (err) {
    if (!(err instanceof OrgError)) throw err;
    note = `Abgleich nicht möglich: ${err.message}`;
  }
  await interaction.editReply({ content: note, allowedMentions: { parse: [] } });
}
