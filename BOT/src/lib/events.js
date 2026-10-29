// Termine einer Orga lesen und Zu-/Absagen speichern (wie Events.php und Training.php der Web-App).
import { all, nowDb, one, run, transaction, val } from '../db.js';
import { OrgError } from './errors.js';

export const RSVP_STATUSES = ['YES', 'MAYBE', 'NO'];
export const RSVP_LABELS = { YES: 'Dabei', MAYBE: 'Vielleicht', NO: 'Kann nicht' };

const DEPARTMENT_KINDS = { TRAINING: 'Training', EXERCISE: 'Übung' };

/** Die nächsten geplanten Termine (laufende eingeschlossen, ohne abgesagte; Entwürfe nur mit drafts für Planer). */
export async function upcomingEvents(db, orgId, take = 10, now = nowDb(), { drafts = false } = {}) {
  const rows = await all(
    db,
    `SELECT e.id, e.title, e.starts_at, e.ends_at, e.status,
            (SELECT CONCAT(tc.topic, ' · Stufe ', tc.level) FROM training_courses tc WHERE tc.id = e.course_id) AS training,
            (SELECT d.name FROM departments d WHERE d.id = e.department_id) AS department, e.department_kind,
            (SELECT COUNT(*) FROM event_rsvps r WHERE r.event_id = e.id AND r.status = 'YES') AS yes_count
       FROM events e
      WHERE e.org_id = ? AND e.status IN ('PLANNED'${drafts ? ", 'DRAFT'" : ''}) AND (e.starts_at >= ? OR e.ends_at >= ?)
      ORDER BY e.starts_at ASC LIMIT ${Number(take)}`,
    [orgId, now, now],
  );
  return rows.map((e) => ({
    id: e.id,
    title: e.title,
    startsAt: e.starts_at,
    endsAt: e.ends_at,
    training: e.training,
    department: e.department === null ? null : `${DEPARTMENT_KINDS[e.department_kind] ?? 'Termin'}: ${e.department}`,
    yes: Number(e.yes_count),
    draft: e.status === 'DRAFT',
  }));
}

/** Ein Termin mit Ausbildung, Abteilung und Antworten; null wenn es ihn in der Orga nicht gibt (Entwürfe nur für Planer). */
export async function eventDetail(db, orgId, eventId, { canPlan = false } = {}) {
  const e = await one(db, 'SELECT * FROM events WHERE id = ? AND org_id = ?', [eventId, orgId]);
  if (e === null || (e.status === 'DRAFT' && !canPlan)) return null;
  const course = e.course_id === null ? null
    : await one(db, 'SELECT t.id, t.area_id, a.name AS area_name, t.topic, t.level, t.title FROM training_courses t JOIN training_areas a ON a.id = t.area_id WHERE t.org_id = ? AND t.id = ?', [orgId, e.course_id]);
  const department = e.department_id === null ? null
    : await val(db, 'SELECT name FROM departments WHERE org_id = ? AND id = ?', [orgId, e.department_id]);
  const rsvps = await all(
    db,
    `SELECT r.user_id, r.status, COALESCE(om.nick, u.name) AS name FROM event_rsvps r JOIN users u ON u.id = r.user_id
       LEFT JOIN org_memberships om ON om.user_id = r.user_id AND om.org_id = ? WHERE r.event_id = ?`,
    [orgId, eventId],
  );
  // Schiffe mit ihren Plätzen (wie in der Web-App; Plätze einnehmen geht nur dort)
  const slots = await all(
    db,
    `SELECT s.id AS ship_id, s.task, COALESCE(c.name, s.custom_name, 'Unbekanntes Schiff') AS ship_name, x.id AS slot_id, x.label,
            x.user_id AS holder_id, COALESCE(om.nick, u.name) AS holder
       FROM event_ships s
       LEFT JOIN catalog_items c ON c.id = s.catalog_item_id
       LEFT JOIN event_slots x ON x.event_ship_id = s.id
       LEFT JOIN users u ON u.id = x.user_id
       LEFT JOIN org_memberships om ON om.user_id = x.user_id AND om.org_id = ?
      WHERE s.event_id = ? ORDER BY s.sort ASC, s.id ASC, x.sort ASC, x.id ASC`,
    [orgId, eventId],
  );
  const ships = [];
  for (const r of slots) {
    let ship = ships.find((s) => s.id === r.ship_id);
    if (!ship) ships.push(ship = { id: r.ship_id, name: r.ship_name, task: r.task, slots: [] });
    if (r.label !== null) ship.slots.push({ id: r.slot_id, label: r.label, userId: r.holder_id, holder: r.holder });
  }
  rsvps.sort((a, b) => String(a.name ?? '').localeCompare(String(b.name ?? ''), 'de', { sensitivity: 'base' }));
  return {
    id: e.id,
    title: e.title,
    description: e.description,
    location: e.location,
    voiceChannelId: e.voice_channel_id ?? null,
    startsAt: e.starts_at,
    endsAt: e.ends_at,
    status: e.status,
    course: course === null ? null : { id: course.id, area: course.area_id, areaName: course.area_name, topic: course.topic, level: Number(course.level), title: course.title },
    department: department === null ? null : `${DEPARTMENT_KINDS[e.department_kind] ?? 'Termin'}: ${department}`,
    ships: ships.map(({ name, task, slots: list }) => ({ name, task, slots: list.map((s) => ({ id: s.id, label: s.label, userId: s.userId ?? null, holder: s.holder ?? null })) })),
    rsvps: rsvps.map((r) => ({ userId: r.user_id, status: r.status, name: r.name ?? 'Unbekannt' })),
  };
}

/** Stufen desselben Themas unterhalb der Ausbildung, die die Person noch nicht bestanden hat (Training::missingPrerequisites). */
export async function missingPrerequisites(db, orgId, userId, courseId) {
  const c = await one(db, 'SELECT area_id, topic, level FROM training_courses WHERE org_id = ? AND id = ?', [orgId, courseId]);
  if (c === null) return [];
  const rows = await all(
    db,
    `SELECT t.level, t.title FROM training_courses t
       LEFT JOIN training_completions d ON d.course_id = t.id AND d.user_id = ?
      WHERE t.org_id = ? AND t.area_id = ? AND t.topic = ? AND t.level < ? AND d.course_id IS NULL
      ORDER BY t.level ASC`,
    [userId, orgId, c.area_id, c.topic, Number(c.level)],
  );
  return rows.map((r) => ({ level: Number(r.level), title: r.title }));
}

export function missingText(missing) {
  const list = missing.map((m) => `Stufe ${m.level} („${m.title}“)`).join(', ');
  return `Für diese Ausbildung fehlt dir noch: ${list}. Erst die früheren Stufen bestehen, dann kannst du dich anmelden.`;
}

async function releaseSlots(db, userId, eventId) {
  await run(
    db,
    'UPDATE event_slots x JOIN event_ships s ON s.id = x.event_ship_id SET x.user_id = NULL WHERE s.event_id = ? AND x.user_id = ?',
    [eventId, userId],
  );
}

/**
 * Platz auf einem Schiff einnehmen (Events::claimSlot); slotId null gibt den eigenen Platz frei. Der Platz wechselt
 * nur, wenn der neue frei ist. Voraussetzung ist die Zusage "Dabei".
 * @throws {OrgError}
 */
export async function claimSlot(db, orgId, userId, eventId, slotId, access) {
  const e = await one(db, 'SELECT status FROM events WHERE id = ? AND org_id = ?', [eventId, orgId]);
  if (e === null || (e.status === 'DRAFT' && !access.admin && !access.canPlan)) throw new OrgError('Termin nicht gefunden.');
  if (slotId === null) {
    await releaseSlots(db, userId, eventId);
    return;
  }
  if (e.status === 'CANCELLED') throw new OrgError('Der Termin ist abgesagt.');
  if (await val(db, "SELECT 1 FROM event_rsvps WHERE event_id = ? AND user_id = ? AND status = 'YES'", [eventId, userId]) === null) {
    throw new OrgError('Sage zuerst mit „Dabei“ zu.');
  }
  await transaction(db, async (conn) => {
    const ok = await val(conn, 'SELECT 1 FROM event_slots x JOIN event_ships s ON s.id = x.event_ship_id WHERE x.id = ? AND s.event_id = ?', [slotId, eventId]);
    if (ok === null) throw new OrgError('Platz nicht gefunden.');
    if (await val(conn, 'SELECT 1 FROM event_slots WHERE id = ? AND user_id = ?', [slotId, userId]) !== null) return; // schon dein Platz
    await releaseSlots(conn, userId, eventId);
    if (await run(conn, 'UPDATE event_slots SET user_id = ? WHERE id = ? AND user_id IS NULL', [userId, slotId]) === 0) {
      throw new OrgError('Der Platz ist schon vergeben.');
    }
  });
}

/**
 * Zu-/Absage speichern (Events::setRsvp); 'NONE' entfernt die Antwort. Wer nicht "Dabei" ist, gibt seinen Platz frei.
 * Für einen Ausbildungstermin müssen die früheren Stufen bestanden sein (außer für Admins und Ausbilder).
 * @param {{admin: boolean, canPlan: boolean, canInstruct: boolean}} access
 * @throws {OrgError}
 */
export async function setRsvp(db, orgId, userId, eventId, status, access) {
  const e = await one(db, 'SELECT status, course_id FROM events WHERE id = ? AND org_id = ?', [eventId, orgId]);
  if (e === null || (e.status === 'DRAFT' && !access.admin && !access.canPlan)) throw new OrgError('Termin nicht gefunden.');
  if (status === 'NONE') {
    await run(db, 'DELETE FROM event_rsvps WHERE event_id = ? AND user_id = ?', [eventId, userId]);
    await releaseSlots(db, userId, eventId);
    return;
  }
  if (!RSVP_STATUSES.includes(status)) throw new OrgError('Ungültige Antwort.');
  if (e.status === 'CANCELLED' && status !== 'NO') throw new OrgError('Der Termin ist abgesagt.');
  if (status !== 'NO' && !access.admin && !access.canInstruct && e.course_id !== null) {
    const missing = await missingPrerequisites(db, orgId, userId, e.course_id);
    if (missing.length > 0) throw new OrgError(missingText(missing));
  }
  await run(
    db,
    'INSERT INTO event_rsvps (event_id, user_id, status) VALUES (?,?,?) ON DUPLICATE KEY UPDATE status = VALUES(status)',
    [eventId, userId, status],
  );
  if (status !== 'YES') await releaseSlots(db, userId, eventId);
}
