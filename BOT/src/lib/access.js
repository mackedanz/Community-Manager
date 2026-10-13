// Was darf die aufrufende Person in der Orga dieses Servers? Ermittelt aus den aktuellen Discord-Rollen und -Rechten,
// nach denselben Regeln wie Orgs::syncMemberships() der Web-App (dort nur alle 24 Stunden geprüft, hier sofort).
import { one } from '../db.js';
import { isGuildAdmin, memberRoleIds } from './guild.js';
import { parseRoleIds } from './roles.js';

/**
 * @returns {{member: boolean, admin: boolean, canPlan: boolean, canInstruct: boolean}}
 */
export function accessIn(org, { admin, roles }) {
  if (admin) return { member: true, admin: true, canPlan: true, canInstruct: true };
  const has = (col) => parseRoleIds(org[col]).some((r) => roles.includes(r));
  const required = parseRoleIds(org.member_role_ids);
  return {
    member: required.length === 0 || has('member_role_ids'),
    admin: false,
    canPlan: has('planner_role_ids'),
    canInstruct: has('instructor_role_ids'),
  };
}

export function accessFor(org, interaction) {
  return accessIn(org, { admin: isGuildAdmin(interaction), roles: memberRoleIds(interaction) });
}

/** Konto der Web-App zu einer Discord-ID, oder null wenn sich die Person dort noch nie angemeldet hat. */
export async function userByDiscordId(db, discordId) {
  return one(db, 'SELECT id, name FROM users WHERE discord_id = ?', [discordId]);
}
