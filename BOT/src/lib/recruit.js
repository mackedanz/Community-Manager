// Recruiting: Fragenkatalog je Orga (Tabelle bot_recruit_questions) und angefangene Kataloge (bot_recruit_drafts), beide Migration 026.
// Discord erlaubt höchstens 5 Felder je Eingabefenster; der Katalog wird deshalb in Teile zu je 5 Fragen geteilt. Jedes Fenster ist ein
// eigener Schritt, die Antworten liegen zwischen den Schritten im Entwurf.
import { all, nowDb, one, run } from '../db.js';
import { cut, newId } from './text.js';
import { getSetting } from './settings.js';
import { parseRoleIds } from './roles.js';

export const RECRUIT_CHANNEL = 'recruit_channel_id';
export const RECRUIT_ROLES = 'recruit_role_ids';
/** Felder je Eingabefenster (Grenze von Discord). */
export const PER_MODAL = 5;
/** Höchstzahl Fragen (5 Fenster). */
export const MAX_QUESTIONS = 25;
/** So lange bleibt ein angefangener Katalog liegen. */
export const DRAFT_TTL_MS = 24 * 3600_000;
const SHORT_MAX = 300;
const LONG_MAX = 1000;
/** Ein Embed darf zusammen 6000 Zeichen haben; darunter bleiben wir mit Reserve. */
const EMBED_BUDGET = 5000;

/** Darf diese Person /recruit nutzen? Admins immer, sonst wer eine der gewählten Rollen hat. */
export const canRecruit = ({ admin, roles }, allowed) => admin || allowed.some((r) => roles.includes(r));

export async function recruitRoleIds(db, orgId) {
  return parseRoleIds(await getSetting(db, orgId, RECRUIT_ROLES));
}

/** Die Fragen der Orga in der eingestellten Reihenfolge. @returns {Promise<Array<{label: string, hint: string, required: boolean, long: boolean}>>} */
export async function loadQuestions(db, orgId) {
  const rows = await all(db, 'SELECT label, hint, required, long_answer FROM bot_recruit_questions WHERE org_id = ? ORDER BY position ASC, id ASC LIMIT ?', [orgId, MAX_QUESTIONS]);
  return rows.map((r) => ({ label: r.label, hint: r.hint ?? '', required: Number(r.required) === 1, long: Number(r.long_answer) === 1 }));
}

/** Wie viele Eingabefenster braucht dieser Katalog? */
export const batchCount = (questions) => Math.ceil(questions.length / PER_MODAL);

/** Das Eingabefenster mit den Fragen des Teils batch (ab 0); bereits gegebene Antworten stehen schon im Feld. */
export function modalFor(draftId, questions, answers, batch) {
  const first = batch * PER_MODAL;
  const rows = questions.slice(first, first + PER_MODAL).map((q, k) => {
    const i = first + k;
    const input = {
      type: 4,
      custom_id: `q${i}`,
      label: cut(q.label, 45),
      style: q.long ? 2 : 1,
      required: q.required,
      max_length: q.long ? LONG_MAX : SHORT_MAX,
    };
    if (q.hint) input.placeholder = cut(q.hint, 100);
    if (answers[i]) input.value = cut(answers[i], input.max_length);
    return { type: 1, components: [input] };
  });
  return { custom_id: `recruit:modal:${draftId}:${batch}`, title: cut(`Recruiting, Teil ${batch + 1} von ${batchCount(questions)}`, 45), components: rows };
}

/** Fragen, die schon beantwortet sind (für die Übersicht). */
export function answered(questions, answers) {
  return questions.filter((_, i) => String(answers[i] ?? '').trim() !== '').length;
}

/** Entwurf anlegen; ein früherer, nicht abgeschlossener Entwurf desselben Recruiters wird ersetzt. @returns {Promise<string>} ID */
export async function startDraft(db, { orgId, recruiterId, applicantId = null, applicantName = null, questions }) {
  const id = newId();
  await run(db, 'DELETE FROM bot_recruit_drafts WHERE org_id = ? AND recruiter_id = ?', [orgId, recruiterId]);
  await run(
    db,
    'INSERT INTO bot_recruit_drafts (id, org_id, recruiter_id, applicant_id, applicant_name, questions, answers, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?)',
    [id, orgId, recruiterId, applicantId, applicantName ? cut(applicantName, 100) : null, JSON.stringify(questions), '{}', nowDb(), nowDb()],
  );
  return id;
}

/** Ein Entwurf der Orga oder null (gibt es nicht mehr oder ist abgelaufen). */
export async function getDraft(db, orgId, id, now = Date.now()) {
  const row = await one(db, 'SELECT * FROM bot_recruit_drafts WHERE id = ? AND org_id = ?', [id, orgId]);
  if (row === null) return null;
  if (now - Date.parse(`${String(row.updated_at).replace(' ', 'T')}Z`) > DRAFT_TTL_MS) {
    await deleteDraft(db, id);
    return null;
  }
  return { ...row, questions: JSON.parse(row.questions), answers: JSON.parse(row.answers) };
}

/** Antworten eines Teils dazuschreiben. @param {Record<number, string>} given Nummer der Frage => Antwort */
export async function saveAnswers(db, draft, given) {
  const answers = { ...draft.answers };
  for (const [i, text] of Object.entries(given)) answers[i] = String(text).trim();
  await run(db, 'UPDATE bot_recruit_drafts SET answers = ?, updated_at = ? WHERE id = ?', [JSON.stringify(answers), nowDb(), draft.id]);
  return answers;
}

export async function deleteDraft(db, id) {
  return run(db, 'DELETE FROM bot_recruit_drafts WHERE id = ?', [id]);
}

/** Abgelaufene Entwürfe aufräumen. */
export async function purgeDrafts(db, now = Date.now()) {
  return run(db, 'DELETE FROM bot_recruit_drafts WHERE updated_at < ?', [nowDb(new Date(now - DRAFT_TTL_MS))]);
}

/**
 * Die Übersicht für den Kanal: Kopf mit Bewerber, Recruiter und Datum, dann jede Frage mit ihrer Antwort. Eine Nachricht je Embed, damit die
 * Grenzen von Discord (25 Felder, 1024 Zeichen je Feld, 6000 Zeichen je Nachricht) nie reißen; bei vielen langen Antworten werden es mehrere.
 * @returns {Array<{embeds: object[], allowedMentions: object}>}
 */
export function summaryMessages(draft, { at = new Date() } = {}) {
  const who = draft.applicant_id ? `<@${draft.applicant_id}>${draft.applicant_name ? ` (${draft.applicant_name})` : ''}` : (draft.applicant_name || 'nicht angegeben');
  const title = draft.applicant_name ? `Bewerbung: ${draft.applicant_name}` : 'Bewerbung';
  const head = `Bewerber: ${who}\nRecruiter: <@${draft.recruiter_id}>\nDatum: <t:${Math.floor(at.getTime() / 1000)}:f>`;
  const embeds = [];
  let fields = [];
  let used = title.length + head.length;
  const flush = () => {
    embeds.push({ title: cut(embeds.length === 0 ? title : `${title} (Fortsetzung)`, 256), ...(embeds.length === 0 ? { description: head } : {}), fields, color: 0x0ea5e9 });
    fields = [];
    used = title.length + 20;
  };
  draft.questions.forEach((q, i) => {
    const field = { name: cut(`${i + 1}. ${q.label}`, 256), value: cut(String(draft.answers[i] ?? '').trim() || '–', 1024) };
    if (fields.length > 0 && (fields.length >= 25 || used + field.name.length + field.value.length > EMBED_BUDGET)) flush();
    fields.push(field);
    used += field.name.length + field.value.length;
  });
  flush();
  return embeds.map((e) => ({ embeds: [e], allowedMentions: { parse: [] } }));
}
