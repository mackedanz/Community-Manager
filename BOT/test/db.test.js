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
import { sendOrgReminders, syncOrgPosts } from '../src/lib/announce.js';
import { ANNOUNCE_CHANNEL, setSetting } from '../src/lib/settings.js';
import { confirm, participants, qualifications } from '../src/lib/training.js';
import { nowDb } from '../src/db.js';
import { createEvent, deleteEvent, setCancelled } from '../src/lib/eventadmin.js';
import { banGuild, deleteOrg, listAllOrgs, listBans, orgCounts, renameOrg, setDiscordEventsMode, unbanGuild } from '../src/lib/orgadmin.js';
import { hangarItems, memberAccount, orgFleet, rsiHandleOf, searchShips, shipOwners } from '../src/lib/hangar.js';

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

  test('Ankündigungen posten, anpassen, entfernen; Erinnerungen nur einmal; Ausbilder bestätigen', async () => {
    const guildId = freshGuild();
    const org = await ensureOrg(pool, config, { guildId, invokerId: '300000000000000001', invokerName: 'Micha', guild: { name: 'Ankündigungs-Test' }, iconUrl: null });
    const anna = newId();
    const annaDiscord = `7${String(Date.now()).slice(-17)}`;
    await run(pool, 'INSERT INTO users (id, name, discord_id) VALUES (?,?,?)', [anna, 'Anna', annaDiscord]);
    await run(pool, "INSERT INTO org_memberships (user_id, org_id, role) VALUES (?,?, 'MEMBER')", [anna, org.id]);

    // Discord-Ersatz: merkt sich gesendete, geänderte und gelöschte Nachrichten
    const log = { sent: [], edited: [], deleted: [], dms: [] };
    let nextId = 1000;
    const channel = {
      send: async (m) => { log.sent.push(m); return { id: String(nextId++) }; },
      messages: { edit: async (id, m) => log.edited.push([id, m]), delete: async (id) => log.deleted.push(id) },
    };
    const client = {
      channels: { fetch: async () => channel },
      users: { fetch: async (id) => ({ send: async (m) => log.dms.push([id, m]) }) },
    };
    const opts = { appUrl: 'https://cm.example' };

    const c1 = newId();
    await run(pool, "INSERT INTO training_courses (id, org_id, area, topic, level, title) VALUES (?,?, 'space', 'Jagd', 1, 'Grundlagen')", [c1, org.id]);
    const soon = nowDb(new Date(Date.now() + 2 * 3600_000));
    const ev = newId();
    await run(pool, "INSERT INTO events (id, org_id, title, starts_at, status, created_by_id, course_id) VALUES (?,?,?,?, 'PLANNED', ?, ?)", [ev, org.id, 'Jagdflug', soon, anna, c1]);

    // Ohne Kanal wird nichts gepostet
    assert.deepEqual(await syncOrgPosts(pool, client, org, opts), { skipped: 1 });
    await setSetting(pool, org.id, ANNOUNCE_CHANNEL, '900000000000000123');
    assert.deepEqual(await syncOrgPosts(pool, client, org, opts), { posted: 1 });
    assert.deepEqual(await syncOrgPosts(pool, client, org, opts), { unchanged: 1 });

    await setRsvp(pool, org.id, anna, ev, 'YES', { admin: false, canPlan: false, canInstruct: false });
    assert.deepEqual(await syncOrgPosts(pool, client, org, opts), { edited: 1 });
    assert.match(JSON.stringify(log.edited[0][1].embeds[0].fields), /Dabei \(1\)/);

    // Erinnerung (2 Stunden vorher = 24h-Erinnerung) genau einmal, als DM und unter der Ankündigung
    assert.equal(await sendOrgReminders(pool, client, org, opts), 1);
    assert.equal(await sendOrgReminders(pool, client, org, opts), 0);
    assert.equal(log.dms.length, 1);
    assert.equal(log.dms[0][0], annaDiscord);
    assert.match(log.sent.at(-1).content, new RegExp(`<@${annaDiscord}>`));
    assert.equal(log.sent.at(-1).reply.messageReference, '1000');

    // Ausbilder bestätigt: bestanden, dann wieder zurückgenommen
    await confirm(pool, org.id, ev, null, [anna], [anna]);
    assert.deepEqual((await participants(pool, org.id, ev, c1)).map((p) => [p.name, p.passed]), [['Anna', true]]);
    assert.deepEqual((await qualifications(pool, org.id, anna)).map((q) => [q.areaName, q.topic, q.level]), [['Raumkampf', 'Jagd', 1]]);
    await confirm(pool, org.id, ev, null, [], [anna]);
    assert.deepEqual(await qualifications(pool, org.id, anna), []);

    // Gelöschter Termin: Nachricht verschwindet
    await run(pool, 'DELETE FROM events WHERE id = ?', [ev]);
    assert.deepEqual(await syncOrgPosts(pool, client, org, opts), { removed: 1 });
    assert.deepEqual(log.deleted, ['1000']);
    await run(pool, 'DELETE FROM users WHERE id = ?', [anna]);
  });

  test('Hangar, Flotte, Wer hat, RSI-Handle', async () => {
    const guildId = freshGuild();
    const org = await ensureOrg(pool, config, { guildId, invokerId: '300000000000000001', invokerName: 'Micha', guild: { name: 'Hangar-Test' }, iconUrl: null });
    await run(pool, "UPDATE organizations SET rsi_sid = 'HTEST', handle_style = 'round' WHERE id = ?", [org.id]);
    const stamp = String(Date.now()).slice(-16);
    let n = 10;
    const mk = async (name, vis, nick) => {
      const id = newId();
      const discordId = `9${stamp}${n++}`;
      await run(pool, 'INSERT INTO users (id, name, discord_id, hangar_visibility) VALUES (?,?,?,?)', [id, name, discordId, vis]);
      await run(pool, "INSERT INTO org_memberships (user_id, org_id, role, nick) VALUES (?,?, 'MEMBER', ?)", [id, org.id, nick]);
      return { id, discordId };
    };
    const offen = await mk('Offen', 'MEMBERS', 'Anna (anna_sc)');
    const privat = await mk('Privat', 'PRIVATE', null);
    const ship = newId();
    const slug = `testschiff-${stamp}`;
    await run(pool, "INSERT INTO catalog_items (id, kind, slug, name, match_key, manufacturer) VALUES (?, 'SHIP', ?, ?, ?, 'Testwerft')", [ship, slug, `Testschiff ${stamp}`, slug]);
    const own = (userId, q, lti) => run(pool, "INSERT INTO owned_items (id, user_id, catalog_item_id, kind, quantity, lti) VALUES (?,?,?, 'SHIP', ?, ?)", [newId(), userId, ship, q, lti]);
    await own(offen.id, 2, 1);
    await own(privat.id, 1, 0);
    await run(pool, "INSERT INTO owned_items (id, user_id, custom_name, kind, quantity) VALUES (?,?, 'Eigenbau', 'SHIP', 1)", [newId(), privat.id]);

    // Flotte ist anonym und zählt alle, auch private Hangars
    const fleet = await orgFleet(pool, org.id);
    assert.deepEqual(fleet.entries.map((e) => [e.name, e.count]), [[`Testschiff ${stamp}`, 3], ['Eigenbau', 1]]);
    assert.deepEqual([fleet.totalShips, fleet.owners], [4, 2]);

    // Wer hat: nur freigegebene Hangars, der eigene immer
    assert.deepEqual((await shipOwners(pool, org.id, ship, null)).map((o) => [o.name, o.quantity, o.lti]), [['Anna (anna_sc)', 2, true]]);
    assert.equal((await shipOwners(pool, org.id, ship, privat.id)).length, 2);
    assert.equal((await searchShips(pool, `schiff ${stamp}`))[0].id, ship);

    const acc = await memberAccount(pool, org.id, offen.discordId);
    assert.equal(Number(acc.in_org), 1);
    assert.equal((await hangarItems(pool, offen.id)).length, 1);
    // Orga mit RSI-Kürzel: Handle aus dem Nickname (runde Klammern)
    assert.equal(await rsiHandleOf(pool, acc), 'anna_sc');
    await run(pool, 'DELETE FROM users WHERE id IN (?, ?)', [offen.id, privat.id]);
    await run(pool, 'DELETE FROM catalog_items WHERE id = ?', [ship]);
  });

  test('Termin anlegen, absagen, löschen; Orga verwalten; Betreiber sperrt und löscht', async () => {
    const guildId = freshGuild();
    const org = await ensureOrg(pool, config, { guildId, invokerId: '300000000000000001', invokerName: 'Micha', guild: { name: 'Admin-Test' }, iconUrl: null });
    const planer = newId();
    await run(pool, 'INSERT INTO users (id, name) VALUES (?, ?)', [planer, 'Planer']);
    const dept = newId();
    await run(pool, 'INSERT INTO departments (id, org_id, name) VALUES (?,?,?)', [dept, org.id, 'Bergbau']);

    const evId = await createEvent(pool, org.id, planer, { title: 'Übung', startsAt: '14.10.2099 20:00' }, { departmentId: dept, departmentKind: 'EXERCISE', discord: true });
    const ev = await one(pool, 'SELECT status, starts_at, department_kind, discord_push, discord_dirty FROM events WHERE id = ?', [evId]);
    assert.deepEqual({ ...ev }, { status: 'PLANNED', starts_at: '2099-10-14 18:00:00', department_kind: 'EXERCISE', discord_push: 1, discord_dirty: 1 });
    await assert.rejects(createEvent(pool, org.id, planer, { title: 'X', startsAt: '14.10.2099 20:00' }, {}), /Titel zu kurz/);
    await assert.rejects(createEvent(pool, org.id, planer, { title: 'Xyz', startsAt: '14.10.2099 20:00' }, { courseId: newId() }), /Ausbildung gibt es/);
    const draftId = await createEvent(pool, org.id, planer, { title: 'Entwurf', startsAt: '15.10.2099 20:00' }, { draft: true });
    assert.equal(await val(pool, 'SELECT status FROM events WHERE id = ?', [draftId]), 'DRAFT');
    assert.deepEqual((await upcomingEvents(pool, org.id, 10, undefined, { drafts: true })).map((e) => [e.title, e.draft]), [['Übung', false], ['Entwurf', true]]);

    await setCancelled(pool, org.id, evId, true);
    assert.equal(await val(pool, 'SELECT status FROM events WHERE id = ?', [evId]), 'CANCELLED');
    await setCancelled(pool, org.id, evId, false);
    // Von der Web-App angelegtes Discord-Event: beim Löschen vormerken, damit der Abgleich es in Discord entfernt
    await run(pool, "UPDATE events SET discord_origin = 'PUSH', discord_event_id = '123456789012345678' WHERE id = ?", [evId]);
    await deleteEvent(pool, org.id, evId);
    assert.equal(await val(pool, 'SELECT 1 FROM events WHERE id = ?', [evId]), null);
    assert.equal(await val(pool, "SELECT discord_event_id FROM discord_event_deletions WHERE org_id = ?", [org.id]), '123456789012345678');
    await assert.rejects(deleteEvent(pool, org.id, evId), /nicht gefunden/);

    await renameOrg(pool, org.id, '  Neuer Name ');
    await setDiscordEventsMode(pool, org.id, 'PUBLISHED');
    assert.deepEqual({ ...(await one(pool, 'SELECT name, discord_events_mode FROM organizations WHERE id = ?', [org.id])) }, { name: 'Neuer Name', discord_events_mode: 'PUBLISHED' });
    await assert.rejects(setDiscordEventsMode(pool, org.id, 'IRGENDWAS'), /Ungültige Auswahl/);
    assert.equal((await orgCounts(pool, org.id)).events, 0);

    // Betreiber: Sperren löscht die Orga, Entsperren hebt die Sperre auf
    assert.equal(await banGuild(pool, guildId, null, 'Test', planer), 'Neuer Name');
    assert.equal(await val(pool, 'SELECT 1 FROM organizations WHERE id = ?', [org.id]), null);
    assert.ok((await listBans(pool)).some((b) => b.discord_guild_id === guildId));
    await assert.rejects(banGuild(pool, 'abc', null, null, planer), /Ungültige Discord-Server-ID/);
    assert.equal(await unbanGuild(pool, guildId), 1);
    const org2 = await ensureOrg(pool, config, { guildId, invokerId: '300000000000000001', invokerName: 'Micha', guild: { name: 'Wieder da' }, iconUrl: null });
    assert.ok((await listAllOrgs(pool)).some((o) => o.id === org2.id));
    assert.equal(await deleteOrg(pool, org2.id), 1);
    await run(pool, 'DELETE FROM users WHERE id = ?', [planer]);
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
