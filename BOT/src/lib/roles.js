// Discord-Rollen einer Orga: gespeichert als kommagetrennte IDs, die Namen als JSON (wie Orgs.php der PHP-App).
import { OrgError } from './errors.js';
import { cut } from './text.js';

export const MAX_ROLES = 10;

/** Die sechs Rollenarten, ihre Spalte in "organizations" und der Schlüssel der Rollenauswahl im Panel. */
export const ROLE_KINDS = {
  use: 'member_role_ids',
  plan: 'planner_role_ids',
  recruit: 'recruiter_role_ids',
  instruct: 'instructor_role_ids',
  lead: 'lead_role_ids',
  admin: 'admin_role_ids',
};

/** Gespeicherte Rollen-IDs als Liste. */
export function parseRoleIds(stored) {
  return String(stored ?? '').split(',').filter((s) => s !== '');
}

/** Gespeicherte Rollennamen (JSON-Objekt) als Map Rollen-ID => Name. */
export function parseRoleLabels(stored) {
  if (typeof stored !== 'string' || stored === '') return {};
  let v;
  try {
    v = JSON.parse(stored);
  } catch {
    return {};
  }
  if (v === null || typeof v !== 'object' || (Array.isArray(v) && v.length > 0)) return {};
  const out = {};
  for (const [k, label] of Object.entries(v)) {
    if (typeof label === 'string') out[k] = label;
  }
  return out;
}

/** Behält nur Namen zu den eingetragenen Rollen-IDs; leere Namen entfallen. null, wenn nichts übrig bleibt. */
export function buildRoleLabels(ids, names) {
  const out = {};
  for (const id of ids) {
    const label = cut(String(names[id] ?? '').trim(), 40);
    if (label !== '') out[id] = label;
  }
  return Object.keys(out).length === 0 ? null : JSON.stringify(out);
}

/** Prüft Rollen-IDs, fasst doppelte zusammen. null, wenn keine übrig bleibt. */
export function cleanRoleIds(ids) {
  const out = new Set();
  for (const raw of ids) {
    const id = String(raw).trim();
    if (id === '') continue;
    if (!/^\d{17,20}$/.test(id)) throw new OrgError('Eine Rollen-ID besteht aus 17 bis 20 Ziffern');
    out.add(id);
  }
  if (out.size > MAX_ROLES) throw new OrgError(`Höchstens ${MAX_ROLES} Rollen`);
  return out.size === 0 ? null : [...out].join(',');
}

/** Alle Rollen-IDs einer Orga, mit denen man das Tool nutzen darf (Nutzung, Planung, Recruiter, Ausbilder, Abteilungsleiter, Orga-Leitung). */
export function allAccessRoleIds(org) {
  return [...new Set(Object.values(ROLE_KINDS).flatMap((col) => parseRoleIds(org[col])))];
}
