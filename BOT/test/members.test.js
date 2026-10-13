import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createScheduler, hasAccessRole, memberChangeMatters } from '../src/events/members.js';

const R_USE = '111111111111111111';
const R_OTHER = '333333333333333333';
const org = { member_role_ids: R_USE, planner_role_ids: null };
const m = (roles, extra = {}) => ({ _roles: roles, nickname: null, avatar: null, user: { username: 'micha', globalName: 'Micha' }, ...extra });
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

test('Rollen der Orga und Namensänderungen lösen den Abgleich aus, andere Rollen nicht', () => {
  assert.equal(memberChangeMatters(org, m([]), m([R_USE])), true);
  assert.equal(memberChangeMatters(org, m([R_USE]), m([])), true);
  assert.equal(memberChangeMatters(org, m([R_USE]), m([R_USE, R_OTHER])), false);
  assert.equal(memberChangeMatters(org, m([R_USE]), m([R_USE], { nickname: 'Neu' })), true);
  assert.equal(memberChangeMatters(org, m([]), m([], { user: { username: 'micha', globalName: 'Anders' } })), true);
  assert.equal(memberChangeMatters(org, { partial: true }, m([])), true);
  assert.equal(memberChangeMatters(org, null, m([])), true);
});

test('Zugangsrolle erkennen', () => {
  assert.equal(hasAccessRole(org, m([R_OTHER, R_USE])), true);
  assert.equal(hasAccessRole(org, m([R_OTHER])), false);
});

test('mehrere Ereignisse kurz hintereinander ergeben einen Abgleich', async () => {
  const calls = [];
  const s = createScheduler(async (g) => calls.push(g), { quietMs: 30, maxWaitMs: 1000 });
  s.schedule('A');
  await sleep(10);
  s.schedule('A');
  s.schedule('B');
  await sleep(10);
  s.schedule('A');
  assert.deepEqual(calls, []);
  await sleep(60);
  assert.deepEqual(calls.sort(), ['A', 'B']);
  assert.equal(s.pendingCount(), 0);
});

test('bei Dauerfeuer wird spätestens nach der Höchstwartezeit abgeglichen', async () => {
  const calls = [];
  const s = createScheduler(async (g) => calls.push(g), { quietMs: 40, maxWaitMs: 80 });
  for (let i = 0; i < 8; i++) {
    s.schedule('A');
    await sleep(15);
  }
  assert.ok(calls.length >= 1, 'Abgleich trotz laufender Ereignisse');
  s.stop();
});

test('während eines laufenden Abgleichs folgt genau ein weiterer', async () => {
  let running = 0;
  let max = 0;
  const calls = [];
  const s = createScheduler(async (g) => {
    running++;
    max = Math.max(max, running);
    calls.push(g);
    await sleep(50);
    running--;
  }, { quietMs: 5, maxWaitMs: 100 });
  s.schedule('A');
  await sleep(20); // erster Abgleich läuft
  s.schedule('A');
  await sleep(10);
  s.schedule('A');
  await sleep(150);
  assert.equal(max, 1, 'nie zwei Abgleiche desselben Servers gleichzeitig');
  assert.equal(calls.length, 2);
});
