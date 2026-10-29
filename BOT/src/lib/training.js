// Ausbildung: bestandene Stufen einer Person und das Bestätigen der Teilnehmer eines Ausbildungstermins
// (wie Training::qualifications(), participants() und confirm() der Web-App).
import { all, nowDb, one, run, transaction, val } from '../db.js';
import { OrgError } from './errors.js';

/** Bestandene Ausbildungen einer Person in einer Orga, nach Bereich, Thema und Stufe. */
export async function qualifications(db, orgId, userId) {
  const rows = await all(
    db,
    `SELECT d.id, t.area_id, COALESCE(a.name, d.area_name) AS area_label, COALESCE(t.topic, d.topic) AS topic, COALESCE(t.level, d.level) AS level,
            COALESCE(t.title, d.title) AS title, d.completed_at, (d.course_id IS NULL) AS retired
       FROM training_completions d LEFT JOIN training_courses t ON t.id = d.course_id LEFT JOIN training_areas a ON a.id = t.area_id
      WHERE d.user_id = ? AND d.org_id = ? ORDER BY area_label ASC, topic ASC, level ASC`,
    [userId, orgId],
  );
  return rows.map((r) => ({
    area: r.area_id ?? '', areaName: r.area_label, topic: r.topic, level: Number(r.level), title: r.title,
    completedAt: r.completed_at, retired: Boolean(Number(r.retired)),
  }));
}

/** Teilnehmer eines Ausbildungstermins (Zusage "Dabei") und ob sie die Ausbildung schon bestanden haben. */
export async function participants(db, orgId, eventId, courseId) {
  const rows = await all(
    db,
    `SELECT r.user_id, COALESCE(om.nick, u.name) AS name, (d.user_id IS NOT NULL) AS passed
       FROM event_rsvps r JOIN users u ON u.id = r.user_id
       JOIN org_memberships om ON om.user_id = r.user_id AND om.org_id = ?
       LEFT JOIN training_completions d ON d.user_id = r.user_id AND d.course_id = ?
      WHERE r.event_id = ? AND r.status = 'YES'`,
    [orgId, courseId, eventId],
  );
  return rows
    .map((r) => ({ userId: r.user_id, name: r.name ?? 'Unbekannt', passed: Boolean(Number(r.passed)) }))
    .sort((a, b) => a.name.localeCompare(b.name, 'de', { sensitivity: 'base' }));
}

/**
 * Hält fest, wer die Ausbildung eines Termins bestanden hat. Betroffen sind die Teilnehmer mit Zusage, und davon nur die
 * in onlyUserIds (die in Discord gezeigt wurden): Wer in passed steht, gilt als bestanden, die übrigen verlieren
 * einen früheren Eintrag. Die Berechtigung (Admin oder Ausbilder) prüft der Aufrufer.
 * @param {string|null} confirmerId Konto der bestätigenden Person in der Web-App
 * @throws {OrgError}
 */
export async function confirm(pool, orgId, eventId, confirmerId, passed, onlyUserIds) {
  const courseId = await val(pool, 'SELECT course_id FROM events WHERE id = ? AND org_id = ?', [eventId, orgId]);
  if (courseId === null) throw new OrgError('Zu diesem Termin gehört keine Ausbildung.');
  const c = await one(pool, 'SELECT a.name AS area_name, t.topic, t.level, t.title FROM training_courses t JOIN training_areas a ON a.id = t.area_id WHERE t.org_id = ? AND t.id = ?', [orgId, courseId]);
  if (c === null) throw new OrgError('Ausbildung nicht gefunden.');
  const yes = new Set(passed);
  const shown = new Set(onlyUserIds);
  await transaction(pool, async (conn) => {
    for (const p of await participants(conn, orgId, eventId, courseId)) {
      if (!shown.has(p.userId)) continue;
      if (yes.has(p.userId)) {
        // Orga, Bereich, Thema, Stufe und Titel werden mitgespeichert: der Eintrag überlebt das Löschen der Ausbildung
        await run(
          conn,
          `INSERT INTO training_completions (user_id, course_id, org_id, area_name, topic, level, title, confirmed_by, completed_at) VALUES (?,?,?,?,?,?,?,?,?)
           ON DUPLICATE KEY UPDATE user_id = user_id`,
          [p.userId, courseId, orgId, c.area_name, c.topic, Number(c.level), c.title, confirmerId, nowDb()],
        );
      } else {
        await run(conn, 'DELETE FROM training_completions WHERE user_id = ? AND course_id = ?', [p.userId, courseId]);
      }
    }
  });
}
