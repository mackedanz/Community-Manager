// Tests gegen eine echte MariaDB mit dem Schema der PHP-Migrationen (in der CI: php bin/migrate.php).
// Ohne TEST_DB_HOST werden sie übersprungen. Jeder Test legt eigene Daten an und räumt sie wieder ab.
import { after, before, describe, test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtemp, readdir, rm } from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import sharp from 'sharp';
import { createPool, one, run, val } from '../src/db.js';
import { ensureOrg, setRoles, syncAllowlist } from '../src/lib/onboarding.js';
import { connect, sync } from '../src/lib/rsi.js';
import { current, reset, setImage, setOpacity } from '../src/lib/branding.js';
import { newId } from '../src/lib/text.js';
import { eventDetail, setRsvp, upcomingEvents } from '../src/lib/events.js';

const enabled = Boolean(process.env.TEST_DB_HOST);
const R_USE = '111111111111111111';
const R_PLAN = '222222222222222222';
const config = { onboardingGuildIds: [], serverAdminIds: ['900000000000000001'], loginRequiresAllowlist: false };

let pool;
const guilds = [];
/** Neue, zufällige Server-ID für einen Test; die Orga dazu wird am Ende gelöscht. */
const freshGuild = () => {
  const id = `8${String(Date.now()).slice(-9)}${String(Math.floor(Math.random() * 1e8)).padStart(8, '0')}`;
  guilds.push(id);
  return id;
};
const member = (id, roles) => ({ id, name: `N${id}`, nick: null, accountName: `A${id}`, avatar: null, roles });

describe('Datenbank', { skip: !enabled && 'TEST_DB_HOST nicht gesetzt' }, () => {
  before(() => {
    pool = createPool({
      host: process.env.TEST_DB_HOST,
      port: Number(process.env.TEST_DB_PORT ?? 3306),
      database: process.env.TEST_DB_NAME ?? 'hangar_test',
      user: process.env.TEST_DB_USER ?? 'hangar',
      password: process.env.TEST_DB_PASSWORD ?? 'hangar',
    });
  });

  after(async () => {
    for (const g of guilds) {
      await run(pool, 'DELETE FROM organizations WHERE discord_guild_id = ?', [g]);
      await run(pool, 'DELETE FROM banned_guilds WHERE discord_guild_id = ?', [g]);
    }
    await pool.end();
  });

  test('/einrichten legt die Orga an und trägt die aufrufende Person fest ein', async () => {
    const guildId = freshGuild();
    const org = await ensureOrg(pool, config, { guildId, invokerId: '300000000000000001', invokerName: 'Micha', guild: { name: 'Ärger & Co' }, iconUrl: null });
    assert.equal(org.name, 'Ärger & Co');
    assert.match(org.slug, /^aerger-co(-\d+)?$/);
    assert.equal(org.id.length, 24);
    const again = await ensureOrg(pool, config, { guildId, invokerId: '300000000000000001', invokerName: 'Micha 2', guild: null, iconUrl: null });
    assert.equal(again.id, org.id);
    const row = await one(pool, 'SELECT name, fixed FROM org_allowed_members WHERE org_id = ? AND discord_id = ?', [org.id, '300000000000000001']);
    assert.deepEqual({ ...row }, { name: 'Micha 2', fixed: 1 });
  });

  test('gesperrte und nicht freigegebene Server', async () => {
    const guildId = freshGuild();
    await run(pool, 'INSERT INTO banned_guilds (discord_guild_id, name, banned_by_id) VALUES (?,?,?)', [guildId, 'Gesperrt', '1']);
    await assert.rejects(ensureOrg(pool, config, { guildId, invokerId: '1', invokerName: 'x', guild: null, iconUrl: null }), /gesperrt/);
    await assert.rejects(
      ensureOrg(pool, { ...config, onboardingGuildIds: ['42'] }, { guildId: freshGuild(), invokerId: '1', invokerName: 'x', guild: null, iconUrl: null }),
      /nicht freigeschaltet/,
    );
  });

  test('Rollen speichern, Namen merken und Abgleich der Zugangsliste', async () => {
    const guildId = freshGuild();
    const org = await ensureOrg(pool, config, { guildId, invokerId: '300000000000000001', invokerName: 'Micha', guild: { name: 'Rollen-Test' }, iconUrl: null });
    await assert.rejects(syncAllowlist(pool, config, org.id, async () => []), /mindestens eine Rolle/);

    await setRoles(pool, org.id, 'use', [R_USE], { [R_USE]: 'Mitglied' });
    await setRoles(pool, org.id, 'plan', [R_PLAN], { [R_PLAN]: 'Planer' });
    const saved = await one(pool, 'SELECT member_role_ids, planner_role_ids, role_labels FROM organizations WHERE id = ?', [org.id]);
    assert.equal(saved.member_role_ids, R_USE);
    assert.equal(saved.planner_role_ids, R_PLAN);
    assert.deepEqual(JSON.parse(saved.role_labels), { [R_USE]: 'Mitglied', [R_PLAN]: 'Planer' });

    const first = await syncAllowlist(pool, config, org.id, async () => [
      member('400000000000000001', [R_USE]),
      member('400000000000000002', [R_PLAN]),
      member('400000000000000003', []),
    ]);
    assert.deepEqual(first, { total: 3, added: 2, removed: 0, deleted: 0, skipped: 0 });

    const second = await syncAllowlist(pool, config, org.id, async () => [member('400000000000000001', [R_USE])]);
    assert.deepEqual(second, { total: 2, added: 0, removed: 1, deleted: 0, skipped: 0 });
    const ids = (await pool.query('SELECT discord_id FROM org_allowed_members WHERE org_id = ? ORDER BY discord_id', [org.id]))[0].map((r) => r.discord_id);
    // Die Person, die /einrichten ausgeführt hat, bleibt immer (fixed)
    assert.deepEqual(ids, ['300000000000000001', '400000000000000001']);
    assert.notEqual(await val(pool, 'SELECT allowlist_synced_at FROM organizations WHERE id = ?', [org.id]), null);
  });

  test('mit Anmeldesperre verlieren ehemalige Mitglieder ihr Konto', async () => {
    const gate = { ...config, loginRequiresAllowlist: true };
    const guildId = freshGuild();
    const org = await ensureOrg(pool, gate, { guildId, invokerId: '300000000000000001', invokerName: 'Micha', guild: { name: 'Sperr-Test' }, iconUrl: null });
    await setRoles(pool, org.id, 'use', [R_USE], {});
    const discordId = `5${String(Date.now()).slice(-17)}`;
    const userId = newId();
    await run(pool, 'INSERT INTO users (id, name, discord_id) VALUES (?,?,?)', [userId, 'Weg', discordId]);
    await run(pool, "INSERT INTO accounts (id, user_id, provider, provider_account_id) VALUES (?,?, 'discord', ?)", [newId(), userId, discordId]);

    await syncAllowlist(pool, gate, org.id, async () => [member(discordId, [R_USE]), member('400000000000000009', [R_USE])]);
    const r = await syncAllowlist(pool, gate, org.id, async () => [member('400000000000000009', [R_USE])]);
    assert.equal(r.deleted, 1);
    assert.equal(await val(pool, 'SELECT 1 FROM users WHERE id = ?', [userId]), null);
  });

  test('RSI-Orga verbinden und Mitgliederliste übernehmen', async () => {
    const guildId = freshGuild();
    const org = await ensureOrg(pool, config, { guildId, invokerId: '300000000000000001', invokerName: 'Micha', guild: { name: 'RSI-Test' }, iconUrl: null });
    const card = (handle, cls) => `<li class="member-item ${cls}" data-org-name="Explorer">${handle ? `<a href="/citizens/${handle}">` : ''}</li>`;
    const pages = { 1: card('Eins', 'org-main') + card('Zwei', 'org-affiliate'), 2: card(null, 'org-main') };
    const fetchFn = async (url, init) => {
      const page = JSON.parse(init.body).page;
      return { status: 200, json: async () => ({ success: 1, data: { totalrows: 33, html: pages[page] ?? '' } }) };
    };
    assert.equal(await connect(pool, org.id, 'expg', fetchFn), 'Explorer');
    assert.deepEqual(await sync(pool, org.id, { fetchFn, pauseMs: 0 }), { members: 2, redacted: 1 });
    const rows = (await pool.query('SELECT handle, main FROM org_rsi_members WHERE org_id = ? ORDER BY handle', [org.id]))[0];
    assert.deepEqual(rows.map((r) => [r.handle, r.main]), [['Eins', 1], ['Zwei', 0]]);
    const o = await one(pool, 'SELECT rsi_sid, rsi_org_name, rsi_redacted FROM organizations WHERE id = ?', [org.id]);
    assert.deepEqual({ ...o }, { rsi_sid: 'EXPG', rsi_org_name: 'Explorer', rsi_redacted: 1 });
  });

  test('Termine: Liste, Antworten und Vorstufen der Ausbildung', async () => {
    const guildId = freshGuild();
    const org = await ensureOrg(pool, config, { guildId, invokerId: '300000000000000001', invokerName: 'Micha', guild: { name: 'Termin-Test' }, iconUrl: null });
    const userId = newId();
    await run(pool, 'INSERT INTO users (id, name, discord_id) VALUES (?,?,?)', [userId, 'Anna', `6${String(Date.now()).slice(-17)}`]);
    const c1 = newId();
    const c2 = newId();
    await run(pool, "INSERT INTO training_courses (id, org_id, area, topic, level, title) VALUES (?,?, 'mining', 'Laser', 1, 'Grundlagen'), (?,?, 'mining', 'Laser', 2, 'Fortgeschritten')", [c1, org.id, c2, org.id]);
    const ev = (id, title, startsAt, status = 'PLANNED', courseId = null) =>
      run(pool, 'INSERT INTO events (id, org_id, title, starts_at, status, created_by_id, course_id) VALUES (?,?,?,?,?,?,?)', [id, org.id, title, startsAt, status, userId, courseId]);
    const kurs = newId();
    const entwurf = newId();
    const alt = newId();
    await ev(kurs, 'Laser Stufe 2', '2099-01-02 18:00:00', 'PLANNED', c2);
    await ev(entwurf, 'Entwurf', '2099-01-01 18:00:00', 'DRAFT');
    await ev(alt, 'Vorbei', '2000-01-01 18:00:00');

    const list = await upcomingEvents(pool, org.id);
    assert.deepEqual(list.map((e) => [e.title, e.training]), [['Laser Stufe 2', 'Laser · Stufe 2']]);
    assert.equal(await eventDetail(pool, org.id, entwurf), null);
    assert.notEqual(await eventDetail(pool, org.id, entwurf, { canPlan: true }), null);

    const member = { admin: false, canPlan: false, canInstruct: false };
    await assert.rejects(setRsvp(pool, org.id, userId, kurs, 'YES', member), /Stufe 1 \(„Grundlagen“\)/);
    await setRsvp(pool, org.id, userId, kurs, 'NO', member); // Absagen geht immer
    await setRsvp(pool, org.id, userId, kurs, 'YES', { ...member, canInstruct: true }); // Ausbilder sind ausgenommen
    await run(pool, "INSERT INTO training_completions (user_id, course_id, org_id, area, topic, level, title) VALUES (?,?,?, 'mining', 'Laser', 1, 'Grundlagen')", [userId, c1, org.id]);
    await setRsvp(pool, org.id, userId, kurs, 'YES', member);

    // Wer nicht mehr "Dabei" ist, gibt seinen Platz frei
    const shipId = newId();
    const slotId = newId();
    await run(pool, 'INSERT INTO event_ships (id, event_id, custom_name) VALUES (?,?,?)', [shipId, kurs, 'Prospector']);
    await run(pool, 'INSERT INTO event_slots (id, event_ship_id, label, user_id) VALUES (?,?,?,?)', [slotId, shipId, 'Pilot', userId]);
    await setRsvp(pool, org.id, userId, kurs, 'MAYBE', member);
    assert.equal(await val(pool, 'SELECT user_id FROM event_slots WHERE id = ?', [slotId]), null);

    const detail = await eventDetail(pool, org.id, kurs);
    assert.deepEqual(detail.rsvps, [{ userId, status: 'MAYBE', name: 'Anna' }]);
    assert.equal(detail.course.areaName, 'Mining und Salvage');
    assert.equal(detail.course.level, 2);

    await setRsvp(pool, org.id, userId, kurs, 'NONE', member);
    assert.deepEqual((await eventDetail(pool, org.id, kurs)).rsvps, []);
    await run(pool, "UPDATE events SET status = 'CANCELLED' WHERE id = ?", [kurs]);
    await assert.rejects(setRsvp(pool, org.id, userId, kurs, 'YES', member), /abgesagt/);
    await assert.rejects(setRsvp(pool, org.id, userId, entwurf, 'YES', member), /nicht gefunden/);
    await run(pool, 'DELETE FROM users WHERE id = ?', [userId]);
  });

  test('/design: Bilder ablegen, alte Dateien entfernen, zurücksetzen', async () => {
    const dir = await mkdtemp(path.join(os.tmpdir(), 'brand-'));
    const saved = (await pool.query("SELECT name, value FROM app_settings WHERE name LIKE 'brand\\_%'"))[0];
    try {
      const png = (color) => sharp({ create: { width: 300, height: 300, channels: 4, background: color } }).png().toBuffer();
      await setImage(pool, dir, 'logo', await png('#ff0000'));
      await setImage(pool, dir, 'logo', await png('#00ff00'));
      await setOpacity(pool, 25, null);
      assert.equal((await readdir(path.join(dir, 'brand'))).length, 1);
      assert.deepEqual(await current(pool, dir), { logoCustom: true, backgroundCustom: false, opacityDark: 25, opacityLight: 40 });
      await assert.rejects(setOpacity(pool, 101, null), /zwischen 0 und 100/);
      await reset(pool, dir, 'all');
      assert.deepEqual(await current(pool, dir), { logoCustom: false, backgroundCustom: false, opacityDark: 10, opacityLight: 40 });
      assert.equal((await readdir(path.join(dir, 'brand'))).length, 0);
    } finally {
      // Einstellungen anderer Tests bzw. der lokalen Installation wiederherstellen
      await run(pool, "DELETE FROM app_settings WHERE name LIKE 'brand\\_%'");
      for (const s of saved) await run(pool, 'INSERT INTO app_settings (name, value) VALUES (?,?)', [s.name, s.value]);
      await rm(dir, { recursive: true, force: true });
    }
  });
});
