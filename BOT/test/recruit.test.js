import { test } from 'node:test';
import assert from 'node:assert/strict';
import { answered, batchCount, canRecruit, modalFor, PER_MODAL, summaryMessages } from '../src/lib/recruit.js';
import { audienceOf, helpText } from '../src/commands/help.js';
import { commands } from '../src/commands/index.js';

const q = (n, extra = {}) => ({ label: `Frage ${n}`, hint: '', required: true, long: false, ...extra });
const questions = (n) => Array.from({ length: n }, (_, i) => q(i + 1));

test('Recruiting: Admins und gewählte Rollen dürfen, sonst niemand', () => {
  assert.equal(canRecruit({ admin: true, roles: [] }, []), true);
  assert.equal(canRecruit({ admin: false, roles: ['1', '2'] }, ['2']), true);
  assert.equal(canRecruit({ admin: false, roles: ['1'] }, ['2']), false);
  assert.equal(canRecruit({ admin: false, roles: ['1'] }, []), false);
});

test('Recruiting: höchstens 5 Fragen je Eingabefenster', () => {
  assert.equal(PER_MODAL, 5);
  assert.deepEqual([1, 5, 6, 20, 25].map((n) => batchCount(questions(n))), [1, 1, 2, 4, 5]);
  const m = modalFor('abc', questions(12), {}, 2);
  assert.equal(m.custom_id, 'recruit:modal:abc:2');
  assert.equal(m.title, 'Recruiting, Teil 3 von 3');
  assert.deepEqual(m.components.map((r) => r.components[0].custom_id), ['q10', 'q11']);
  assert.equal(modalFor('abc', questions(12), {}, 0).components.length, 5);
});

test('Recruiting: Feldart, Pflicht, Hilfetext und bisherige Antwort im Eingabefenster', () => {
  const m = modalFor('x', [q(1, { long: true, required: false, hint: 'z. B. mein Hangar' }), q(2, { label: 'x'.repeat(60), hint: 'h'.repeat(150) })], { 0: 'schon da' }, 0);
  const [a, b] = m.components.map((r) => r.components[0]);
  assert.deepEqual([a.style, a.required, a.placeholder, a.value], [2, false, 'z. B. mein Hangar', 'schon da']);
  assert.deepEqual([b.style, b.required, b.label.length, b.placeholder.length, 'value' in b], [1, true, 45, 100, false]);
});

test('Recruiting: Übersicht mit Kopf, Fragen und Antworten; lange Kataloge werden aufgeteilt', () => {
  const draft = {
    applicant_id: '123456789012345678', applicant_name: 'Pilot', recruiter_id: '223456789012345678',
    questions: [q(1), q(2, { required: false })], answers: { 0: 'Antwort eins' },
  };
  const [only] = summaryMessages(draft, { at: new Date(1_800_000_000_000) });
  assert.equal(summaryMessages(draft).length, 1);
  assert.equal(only.embeds[0].title, 'Bewerbung: Pilot');
  assert.match(only.embeds[0].description, /Bewerber: <@123456789012345678> \(Pilot\)\nRecruiter: <@223456789012345678>\nDatum: <t:1800000000:f>/);
  assert.deepEqual(only.embeds[0].fields, [{ name: '1. Frage 1', value: 'Antwort eins' }, { name: '2. Frage 2', value: '–' }]);
  assert.deepEqual(only.allowedMentions, { parse: [] });

  const long = { ...draft, applicant_id: null, applicant_name: null, questions: questions(25), answers: Object.fromEntries(Array.from({ length: 25 }, (_, i) => [i, 'a'.repeat(1000)])) };
  const parts = summaryMessages(long);
  assert.ok(parts.length > 1, 'aufgeteilt');
  assert.equal(parts[0].embeds[0].title, 'Bewerbung');
  assert.match(parts[0].embeds[0].description, /Bewerber: nicht angegeben/);
  assert.match(parts[1].embeds[0].title, /Fortsetzung/);
  for (const p of parts) {
    const e = p.embeds[0];
    const size = (e.title?.length ?? 0) + (e.description?.length ?? 0) + e.fields.reduce((s, f) => s + f.name.length + f.value.length, 0);
    assert.ok(e.fields.length <= 25 && size <= 6000, `Grenzen von Discord eingehalten (${size})`);
  }
  assert.equal(parts.flatMap((p) => p.embeds[0].fields).length, 25, 'keine Frage geht verloren');
  assert.equal(answered(draft.questions, draft.answers), 1);
});

test('Recruiting: /recruit steht in der Hilfe für Recruiter und Admins', () => {
  const recruit = commands.find((c) => c.data.name === 'recruit');
  assert.equal(audienceOf(recruit), 'recruiters');
  assert.match(helpText(commands, { admin: false, recruiter: true, appUrl: 'x' }), /\*\*Für Recruiter und Admins\*\*\n`\/recruit`/);
  assert.match(helpText(commands, { admin: true, appUrl: 'x' }), /`\/recruit`/);
  assert.doesNotMatch(helpText(commands, { admin: false, appUrl: 'x' }), /\/recruit/);
});
