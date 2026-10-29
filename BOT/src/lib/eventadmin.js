// Termine anlegen, absagen und löschen (wie Events::validate(), save(), setCancelled() und delete() der Web-App).
// Schiffe und Plätze legt nur die Webseite an.
import { all, one, run, transaction, val } from '../db.js';
import { OrgError } from './errors.js';
import { len, newId } from './text.js';

const TZ = 'Europe/Berlin';
const parts = new Intl.DateTimeFormat('en-US', {
  timeZone: TZ, hourCycle: 'h23', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit',
});

/** Berliner Ortszeit eines Zeitpunkts als [Jahr, Monat, Tag, Stunde, Minute]. */
function berlinFields(ms) {
  const p = Object.fromEntries(parts.formatToParts(new Date(ms)).map((x) => [x.type, x.value]));
  return [Number(p.year), Number(p.month), Number(p.day), Number(p.hour), Number(p.minute)];
}

/**
 * "14.10.2026 20:00", "14.10.26 20:00" oder "2026-10-14 20:00" (Berliner Zeit) => UTC-Zeit im Format der Datenbank.
 * null bei ungültiger Eingabe, auch für die ausgefallene Stunde bei der Umstellung auf Sommerzeit.
 */
export function parseBerlin(input) {
  const s = String(input ?? '').trim().replace(/\s+Uhr$/i, '');
  let m = s.match(/^(\d{1,2})\.(\d{1,2})\.(\d{2}|\d{4}),?\s+(\d{1,2})[:.](\d{2})$/);
  let y; let mo; let d; let h; let mi;
  if (m) {
    [, d, mo, y, h, mi] = m.map(Number);
    if (y < 100) y += 2000;
  } else {
    m = s.match(/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})$/);
    if (!m) return null;
    [, y, mo, d, h, mi] = m.map(Number);
  }
  if (mo < 1 || mo > 12 || d < 1 || d > 31 || h > 23 || mi > 59) return null;
  // Erst als UTC annehmen, dann um den Abstand der Berliner Zeit verschieben und prüfen
  const guess = Date.UTC(y, mo - 1, d, h, mi);
  for (const offsetH of [1, 2]) {
    const ms = guess - offsetH * 3600_000;
    const [yy, mm, dd, hh, mn] = berlinFields(ms);
    if (yy === y && mm === mo && dd === d && hh === h && mn === mi) return new Date(ms).toISOString().slice(0, 19).replace('T', ' ');
  }
  return null;
}

const text = (v, max) => {
  const s = String(v ?? '').trim();
  if (len(s) > max) throw new OrgError('Text zu lang');
  return s === '' ? null : s;
};

/** Prüft die Eingabe aus dem Fenster (wie Events::validate). @throws {OrgError} */
export function validateEvent(input) {
  const title = String(input.title ?? '').trim();
  if (len(title) < 2) throw new OrgError('Titel zu kurz');
  if (len(title) > 100) throw new OrgError('Titel zu lang');
  const startsAt = parseBerlin(input.startsAt);
  if (startsAt === null) throw new OrgError('Bitte einen gültigen Beginn angeben, z. B. 14.10.2026 20:00.');
  let endsAt = null;
  if (String(input.endsAt ?? '').trim() !== '') {
    endsAt = parseBerlin(input.endsAt);
    if (endsAt === null) throw new OrgError('Das Ende ist ungültig (z. B. 14.10.2026 22:00).');
    if (endsAt <= startsAt) throw new OrgError('Das Ende muss nach dem Beginn liegen.');
  }
  return { title, startsAt, endsAt, location: text(input.location, 100), description: text(input.description, 3000) };
}

/**
 * Legt einen Termin an. courseId/departmentId müssen zur Orga gehören; mit Abteilung ist die Art TRAINING oder EXERCISE.
 * @returns {Promise<string>} ID des Termins @throws {OrgError}
 */
export async function createEvent(pool, orgId, userId, input, { courseId = null, departmentId = null, departmentKind = null, draft = false, discord = false } = {}) {
  const data = validateEvent(input);
  if (courseId !== null && (await val(pool, 'SELECT 1 FROM training_courses WHERE org_id = ? AND id = ?', [orgId, courseId])) === null) {
    throw new OrgError('Die gewählte Ausbildung gibt es in dieser Orga nicht.');
  }
  if (departmentId !== null && (await val(pool, 'SELECT 1 FROM departments WHERE org_id = ? AND id = ?', [orgId, departmentId])) === null) {
    throw new OrgError('Die gewählte Abteilung gibt es in dieser Orga nicht.');
  }
  const kind = departmentId === null ? null : (['TRAINING', 'EXERCISE'].includes(departmentKind) ? departmentKind : 'TRAINING');
  const id = newId();
  await run(
    pool,
    `INSERT INTO events (id, org_id, created_by_id, status, title, description, location, starts_at, ends_at, course_id, department_id, department_kind, discord_push, discord_dirty)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)`,
    [id, orgId, userId, draft ? 'DRAFT' : 'PLANNED', data.title, data.description, data.location, data.startsAt, data.endsAt,
      courseId, departmentId, kind, discord ? 1 : 0, discord ? 1 : 0],
  );
  return id;
}

/** Absagen oder wieder aktivieren; ein in Discord angelegtes Event passt der Abgleich der Web-App an. */
export async function setCancelled(pool, orgId, eventId, cancelled) {
  const n = await run(pool, 'UPDATE events SET status = ? WHERE id = ? AND org_id = ?', [cancelled ? 'CANCELLED' : 'PLANNED', eventId, orgId]);
  if (n === 0) throw new OrgError('Termin nicht gefunden.');
  await run(pool, 'UPDATE events SET discord_dirty = 1 WHERE id = ? AND discord_push = 1', [eventId]);
}

/** Löscht den Termin. Ein von der Web-App angelegtes Discord-Event entfernt deren Abgleich danach. */
export async function deleteEvent(pool, orgId, eventId) {
  await transaction(pool, async (conn) => {
    const d = await one(conn, "SELECT discord_event_id FROM events WHERE id = ? AND org_id = ? AND discord_origin = 'PUSH'", [eventId, orgId]);
    if (d !== null && d.discord_event_id !== null) {
      await run(conn, 'INSERT INTO discord_event_deletions (id, org_id, discord_event_id) VALUES (?,?,?)', [newId(), orgId, d.discord_event_id]);
    }
    if ((await run(conn, 'DELETE FROM events WHERE id = ? AND org_id = ?', [eventId, orgId])) === 0) throw new OrgError('Termin nicht gefunden.');
  });
}

/** Ausbildungen der Orga für die Vorschläge beim Tippen. */
export async function searchCourses(db, orgId, term) {
  const like = `%${term.replace(/[\\%_]/g, (m) => `\\${m}`)}%`;
  return all(
    db,
    'SELECT t.id, a.name AS area_name, t.topic, t.level, t.title FROM training_courses t JOIN training_areas a ON a.id = t.area_id WHERE t.org_id = ? AND (t.topic LIKE ? OR t.title LIKE ?) ORDER BY t.topic ASC, t.level ASC LIMIT 25',
    [orgId, like, like],
  );
}

/** Abteilungen der Orga für die Vorschläge beim Tippen. */
export async function searchDepartments(db, orgId, term) {
  const like = `%${term.replace(/[\\%_]/g, (m) => `\\${m}`)}%`;
  return all(db, 'SELECT id, name FROM departments WHERE org_id = ? AND name LIKE ? ORDER BY name ASC LIMIT 25', [orgId, like]);
}
