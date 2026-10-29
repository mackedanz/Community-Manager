// Tests gegen eine echte MariaDB mit dem Schema der PHP-Migrationen (in der CI: php bin/migrate.php).
// Ohne TEST_DB_HOST werden sie übersprungen. Jeder Test legt eigene Daten an und räumt sie wieder ab.
import { after, before, describe, test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtemp, readdir, rm } from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import sharp from 'sharp';
import { DiscordAPIError } from 'discord.js';
import { all, createPool, one, run, val } from '../src/db.js';
import { ensureOrg, setRoles, syncAllowlist } from '../src/lib/onboarding.js';
import { connect, sync } from '../src/lib/rsi.js';
import { current, reset, setImage, setOpacity } from '../src/lib/branding.js';
import { newId } from '../src/lib/text.js';
import { claimSlot, eventDetail, setRsvp, upcomingEvents } from '../src/lib/events.js';
import { sendOrgReminders, syncOrgPosts } from '../src/lib/announce.js';
import { ANNOUNCE_CHANNEL, setSetting } from '../src/lib/settings.js';
import { confirm, participants, qualifications } from '../src/lib/training.js';
import { nowDb } from '../src/db.js';
import { createEvent, deleteEvent, setCancelled } from '../src/lib/eventadmin.js';
import { addCreator, checkCreator, checkTwitch, listCreators, removeCreator } from '../src/lib/creators.js';
import { execute as recruitExecute, handleComponent as recruitComponent } from '../src/commands/recruit.js';
import { getDraft, purgeDrafts, RECRUIT_CHANNEL, RECRUIT_ROLES, saveAnswers, startDraft } from '../src/lib/recruit.js';
import { banGuild, deleteOrg, listAllOrgs, listBans, orgCounts, renameOrg, setDiscordEventsMode, unbanGuild } from '../src/lib/orgadmin.js';
import { activeWarnings, addCase, listCases, modRoleIds, MOD_ROLES, removeWarning } from '../src/lib/moderation.js';
import { AUTOMOD_SETTING, DEFAULTS, guildContext, loadConfig, saveConfig } from '../src/lib/automod.js';
import { planSetup, runSetup } from '../src/lib/serversetup.js';
import { RULES_CHANNEL, VISITOR_ROLE, WELCOME_CHANNEL, WELCOME_TEXT, welcomeMessage } from '../src/lib/welcome.js';
import { MOD_LOG_CHANNEL } from '../src/lib/moderation.js';
import { getSetting } from '../src/lib/settings.js';
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

    // Orga-Leitung: eigene Spalte, zählt für die Zugangsliste mit
    const R_BOSS = '777777777777777777';
    await setRoles(pool, org.id, 'admin', [R_BOSS], { [R_BOSS]: 'Leitung' });
    assert.equal(await val(pool, 'SELECT admin_role_ids FROM organizations WHERE id = ?', [org.id]), R_BOSS);
    assert.equal(await val(pool, 'SELECT member_role_ids FROM organizations WHERE id = ?', [org.id]), R_USE, 'andere Rollen bleiben');

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

    // Avatar und Server-Icon werden mit abgeglichen; ein Fehler beim Icon lässt das gespeicherte stehen
    const uid = 'u-avatar-test';
    await pool.query('INSERT INTO users (id, name, discord_id, image) VALUES (?,?,?,?)', [uid, 'Alt', '400000000000000001', 'alt.png']);
    await pool.query('INSERT INTO org_memberships (user_id, org_id) VALUES (?,?)', [uid, org.id]);
    await syncAllowlist(pool, config, org.id, async () => [{ ...member('400000000000000001', [R_USE]), avatar: 'neu.png' }], async () => 'icon-neu.png');
    assert.equal(await val(pool, 'SELECT image FROM users WHERE id = ?', [uid]), 'neu.png');
    assert.equal(await val(pool, 'SELECT icon_url FROM organizations WHERE id = ?', [org.id]), 'icon-neu.png');
    await syncAllowlist(pool, config, org.id, async () => [member('400000000000000001', [R_USE])], async () => { throw new Error('offline'); });
    assert.equal(await val(pool, 'SELECT icon_url FROM organizations WHERE id = ?', [org.id]), 'icon-neu.png');
    assert.equal(await val(pool, 'SELECT image FROM users WHERE id = ?', [uid]), 'neu.png', 'ohne Avatar bleibt das Bild');
    await syncAllowlist(pool, config, org.id, async () => [member('400000000000000001', [R_USE])], async () => null);
    assert.equal(await val(pool, 'SELECT icon_url FROM organizations WHERE id = ?', [org.id]), null);

    // Wer nur die Leitungsrolle hat, kommt ebenfalls auf die Zugangsliste
    const onlyBoss = await syncAllowlist(pool, config, org.id, async () => [member('400000000000000001', [R_USE]), member('400000000000000005', [R_BOSS])]);
    assert.equal(onlyBoss.added, 1);
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
    const mining = newId();
    await run(pool, "INSERT INTO training_areas (id, org_id, name) VALUES (?,?, 'Mining und Salvage')", [mining, org.id]);
    await run(pool, "INSERT INTO training_courses (id, org_id, area_id, topic, level, title) VALUES (?,?,?, 'Laser', 1, 'Grundlagen'), (?,?,?, 'Laser', 2, 'Fortgeschritten')", [c1, org.id, mining, c2, org.id, mining]);
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
    await run(pool, "INSERT INTO training_completions (user_id, course_id, org_id, area_name, topic, level, title) VALUES (?,?,?, 'Mining und Salvage', 'Laser', 1, 'Grundlagen')", [userId, c1, org.id]);
    await setRsvp(pool, org.id, userId, kurs, 'YES', member);

    // Wer nicht mehr "Dabei" ist, gibt seinen Platz frei
    const shipId = newId();
    const slotId = newId();
    await run(pool, 'INSERT INTO event_ships (id, event_id, custom_name) VALUES (?,?,?)', [shipId, kurs, 'Prospector']);
    await run(pool, 'INSERT INTO event_slots (id, event_ship_id, label, user_id) VALUES (?,?,?,?)', [slotId, shipId, 'Pilot', userId]);
    await run(pool, 'UPDATE event_ships SET task = ? WHERE id = ?', ['Erz abbauen', shipId]);
    assert.deepEqual((await eventDetail(pool, org.id, kurs)).ships, [{ name: 'Prospector', task: 'Erz abbauen', slots: [{ id: slotId, label: 'Pilot', userId, holder: 'Anna' }] }]);
    await setRsvp(pool, org.id, userId, kurs, 'MAYBE', member);
    assert.equal(await val(pool, 'SELECT user_id FROM event_slots WHERE id = ?', [slotId]), null);
    assert.deepEqual((await eventDetail(pool, org.id, kurs)).ships[0].slots, [{ id: slotId, label: 'Pilot', userId: null, holder: null }]);

    // Platz einnehmen: erst mit "Dabei", ein Platz pro Person, Wechsel nur auf freie Plätze
    const slot2 = newId();
    await run(pool, 'INSERT INTO event_slots (id, event_ship_id, label, sort) VALUES (?,?,?,1)', [slot2, shipId, 'Copilot']);
    await assert.rejects(claimSlot(pool, org.id, userId, kurs, slotId, member), /zuerst mit „Dabei“/);
    await setRsvp(pool, org.id, userId, kurs, 'YES', member);
    await claimSlot(pool, org.id, userId, kurs, slotId, member);
    assert.equal(await val(pool, 'SELECT user_id FROM event_slots WHERE id = ?', [slotId]), userId);
    await claimSlot(pool, org.id, userId, kurs, slot2, member); // wechselt: alter Platz wird frei
    assert.equal(await val(pool, 'SELECT user_id FROM event_slots WHERE id = ?', [slotId]), null);
    assert.equal(await val(pool, 'SELECT user_id FROM event_slots WHERE id = ?', [slot2]), userId);
    await assert.rejects(claimSlot(pool, org.id, userId, kurs, 'x'.repeat(24), member), /Platz nicht gefunden/);
    assert.equal(await val(pool, 'SELECT user_id FROM event_slots WHERE id = ?', [slot2]), userId); // Fehler lässt den Platz unberührt
    await claimSlot(pool, org.id, userId, kurs, null, member);
    assert.equal(await val(pool, 'SELECT user_id FROM event_slots WHERE id = ?', [slot2]), null);
    await setRsvp(pool, org.id, userId, kurs, 'MAYBE', member);

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
    const space = newId();
    await run(pool, "INSERT INTO training_areas (id, org_id, name) VALUES (?,?, 'Raumkampf')", [space, org.id]);
    await run(pool, "INSERT INTO training_courses (id, org_id, area_id, topic, level, title) VALUES (?,?,?, 'Jagd', 1, 'Grundlagen')", [c1, org.id, space]);
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

  test('Creator: vorhandene Videos gelten als bekannt, neue werden genau einmal gepostet', async () => {
    const guildId = freshGuild();
    const org = await ensureOrg(pool, config, { guildId, invokerId: '300000000000000001', invokerName: 'Micha', guild: { name: 'Creator-Test' }, iconUrl: null });
    const UC = 'UC' + 'x'.repeat(22);
    let videos = [['vid0000001', false]];
    const feed = () => `<feed><author><name>Testkanal</name></author>${videos.map(([id, short]) =>
      `<entry><yt:videoId>${id}</yt:videoId><title>Video ${id}</title><link rel="alternate" href="https://www.youtube.com/${short ? 'shorts/' : 'watch?v='}${id}"/></entry>`).join('')}</feed>`;
    const fetchFn = async () => ({ status: 200, text: async () => feed() });
    const sent = [];
    const client = { channels: { fetch: async () => ({ send: async (m) => sent.push(m) }) } };

    const a = await addCreator(pool, org.id, { channelId: UC, name: 'Testkanal', discordChannelId: '901', mentionRoleId: null, skipShorts: true }, fetchFn);
    assert.equal(a.updated, false);
    const creator = async () => (await listCreators(pool, org.id))[0];
    assert.equal(await checkCreator(pool, client, await creator(), { fetchFn }), 0, 'schon vorhandene Videos nicht posten');

    // Neues Video und neuer Short (Shorts sind ausgeschaltet); Feed liefert neueste zuerst
    videos = [['short00001', true], ['vid0000002', false], ['vid0000001', false]];
    assert.equal(await checkCreator(pool, client, await creator(), { fetchFn }), 1);
    assert.match(sent[0].content, /\*\*Testkanal\*\* hat ein neues Video veröffentlicht: \*\*Video vid0000002\*\*/);
    assert.equal(await checkCreator(pool, client, await creator(), { fetchFn }), 0, 'kein zweites Mal');

    // Erneut hinzufügen ändert nur Kanal und Rolle
    const b = await addCreator(pool, org.id, { channelId: UC, name: 'Testkanal', discordChannelId: '902', mentionRoleId: '42', skipShorts: false }, fetchFn);
    assert.deepEqual([b.updated, b.id], [true, a.id]);
    assert.deepEqual([(await creator()).discord_channel_id, (await creator()).mention_role_id], ['902', '42']);

    // Feed nicht erreichbar: Fehler wird gemerkt, nichts gepostet
    assert.equal(await checkCreator(pool, client, await creator(), { fetchFn: async () => ({ status: 404, text: async () => '' }) }), 0);
    assert.match((await creator()).last_error, /gibt es nicht/);
    assert.equal(await removeCreator(pool, org.id, a.id), 1);
    assert.deepEqual(await listCreators(pool, org.id), []);
  });

  test('Recruiting: Fragenkatalog in Teilen durchgehen, Übersicht im Kanal, Entwurf danach weg', async () => {
    const guildId = freshGuild();
    const org = await ensureOrg(pool, config, { guildId, invokerId: '300000000000000001', invokerName: 'Micha', guild: { name: 'Recruit-Test' }, iconUrl: null });
    for (let i = 1; i <= 7; i++) {
      await run(pool, 'INSERT INTO bot_recruit_questions (id, org_id, position, label, hint, required, long_answer) VALUES (?,?,?,?,?,?,?)', [newId(), org.id, i, `Frage ${i}`, i === 2 ? 'Tipp' : null, i === 7 ? 0 : 1, i === 3 ? 1 : 0]);
    }
    const RECRUITER = '800000000000000001';
    const sent = [];
    const client = { channels: { fetch: async () => ({ send: async (m) => { sent.push(m); return { url: 'https://discord.test/msg' }; } }) } };
    const fake = (extra) => {
      const out = { replies: [], updates: [], modals: [] };
      const i = {
        guildId, client, user: { id: RECRUITER, username: 'recruiter' }, memberPermissions: { has: () => false }, member: { roles: ['555'] },
        deferReply: async () => {}, editReply: async (p) => out.replies.push(p), reply: async (p) => out.replies.push(p), update: async (p) => out.updates.push(p),
        showModal: async (m) => out.modals.push(m), isFromMessage: () => true,
        options: { getUser: () => null }, ...extra,
      };
      return [i, out];
    };

    // Ohne Rolle, ohne Kanal geht nichts
    let [i, out] = fake({});
    await recruitExecute(i, { pool });
    assert.match(out.replies[0].content, /Das dürfen nur Recruiter/);
    await setSetting(pool, org.id, RECRUIT_ROLES, '555');
    [i, out] = fake({});
    await recruitExecute(i, { pool });
    assert.match(out.replies[0].content, /noch kein Kanal/);
    await setSetting(pool, org.id, RECRUIT_CHANNEL, '901');

    // Start: 7 Fragen in 2 Teilen, Bewerber als Auswahl
    [i, out] = fake({ options: { getUser: () => ({ id: '700000000000000007', username: 'bewerber', globalName: 'Bewerberin', toString: () => '<@700000000000000007>' }) } });
    await recruitExecute(i, { pool });
    assert.match(out.replies[0].content, /7 Fragen in 2 Teilen/);
    const goId = out.replies[0].components[0].components[0].custom_id;
    const draftId = goId.split(':')[2];
    assert.equal(goId, `recruit:go:${draftId}:0`);

    // Ein anderer Recruiter darf diesen Katalog nicht öffnen
    [i, out] = fake({ customId: goId, user: { id: '800000000000000002' } });
    await recruitComponent(i, { pool });
    assert.match(out.replies[0].content, /anderen Recruiters/);

    // Teil 1 öffnen: 5 Felder, Hilfetext, mehrzeilig
    [i, out] = fake({ customId: goId });
    await recruitComponent(i, { pool });
    assert.deepEqual(out.modals[0].components.map((r) => r.components[0].custom_id), ['q0', 'q1', 'q2', 'q3', 'q4']);
    assert.equal(out.modals[0].components[1].components[0].placeholder, 'Tipp');
    assert.equal(out.modals[0].components[2].components[0].style, 2);

    // Teil 1 abschicken: gespeichert, „Weiter“ führt zu Teil 2
    const fields = (map) => ({ getTextInputValue: (id) => map[id] ?? '' });
    [i, out] = fake({ customId: `recruit:modal:${draftId}:0`, fields: fields({ q0: ' A1 ', q1: 'A2', q2: 'A3', q3: 'A4', q4: 'A5' }) });
    await recruitComponent(i, { pool });
    assert.match(out.updates[0].content, /Teil 1 von 2 ist gespeichert \(5 von 7/);
    assert.equal(out.updates[0].components[0].components[0].custom_id, `recruit:go:${draftId}:1`);
    assert.deepEqual((await getDraft(pool, org.id, draftId)).answers, { 0: 'A1', 1: 'A2', 2: 'A3', 3: 'A4', 4: 'A5' });
    assert.equal(sent.length, 0);

    // Teil 2 abschicken: Übersicht im Kanal, Entwurf gelöscht
    [i, out] = fake({ customId: `recruit:modal:${draftId}:1`, fields: fields({ q5: 'A6', q6: '' }) });
    await recruitComponent(i, { pool });
    assert.match(out.updates[0].content, /Fertig\. Die Übersicht steht in <#901>/);
    assert.equal(sent.length, 1);
    const embed = sent[0].embeds[0];
    assert.equal(embed.title, 'Bewerbung: Bewerberin');
    assert.match(embed.description, new RegExp(`Recruiter: <@${RECRUITER}>`));
    assert.deepEqual(embed.fields.map((f) => f.value), ['A1', 'A2', 'A3', 'A4', 'A5', 'A6', '–']);
    assert.equal(await getDraft(pool, org.id, draftId), null);
    [i, out] = fake({ customId: goId });
    await recruitComponent(i, { pool });
    assert.match(out.updates[0].content, /abgelaufen oder erledigt/);

    // Posten schlägt fehl: Entwurf bleibt, „Erneut senden“; Abbrechen löscht
    const id2 = await startDraft(pool, { orgId: org.id, recruiterId: RECRUITER, questions: [{ label: 'Nur eine', hint: '', required: true, long: false }] });
    const broken = { channels: { fetch: async () => { throw new DiscordAPIError({ message: 'Missing Access' }, 50001, 403, 'GET', 'x', {}); } } };
    [i, out] = fake({ customId: `recruit:modal:${id2}:0`, fields: fields({ q0: 'Antwort' }), client: broken });
    await recruitComponent(i, { pool });
    assert.match(out.updates[0].content, /konnte nicht gepostet werden/);
    assert.equal(out.updates[0].components[0].components[0].custom_id, `recruit:send:${id2}`);
    [i, out] = fake({ customId: `recruit:send:${id2}` });
    await recruitComponent(i, { pool });
    assert.equal(sent.length, 2);
    assert.equal(await getDraft(pool, org.id, id2), null);
    const id3 = await startDraft(pool, { orgId: org.id, recruiterId: RECRUITER, questions: [{ label: 'x', hint: '', required: true, long: false }] });
    [i, out] = fake({ customId: `recruit:cancel:${id3}` });
    await recruitComponent(i, { pool });
    assert.equal(await getDraft(pool, org.id, id3), null);
    assert.equal(sent.length, 2, 'nach Abbrechen nichts gepostet');
  });

  test('Recruiting: ein neuer Entwurf ersetzt den alten, abgelaufene werden gelöscht', async () => {
    const guildId = freshGuild();
    const org = await ensureOrg(pool, config, { guildId, invokerId: '300000000000000001', invokerName: 'Micha', guild: { name: 'Recruit-Test 2' }, iconUrl: null });
    const questions = [{ label: 'Eins', hint: '', required: true, long: false }];
    const a = await startDraft(pool, { orgId: org.id, recruiterId: '800000000000000009', questions });
    const b = await startDraft(pool, { orgId: org.id, recruiterId: '800000000000000009', questions });
    assert.equal(await getDraft(pool, org.id, a), null);
    const draft = await getDraft(pool, org.id, b);
    await saveAnswers(pool, draft, { 0: '  Ja  ' });
    assert.deepEqual((await getDraft(pool, org.id, b)).answers, { 0: 'Ja' });
    // 25 Stunden später ist er abgelaufen
    assert.equal(await getDraft(pool, org.id, b, Date.now() + 25 * 3600_000), null);
    const c = await startDraft(pool, { orgId: org.id, recruiterId: '800000000000000008', questions });
    assert.equal(await purgeDrafts(pool, Date.now() + 25 * 3600_000) >= 1, true);
    assert.equal(await getDraft(pool, org.id, c), null);
  });

  test('Twitch: neue Kanäle werden geprüft, nur neue Streams gemeldet, nicht doppelt und nicht beim Eintragen', async () => {
    const guildId = freshGuild();
    const org = await ensureOrg(pool, config, { guildId, invokerId: '300000000000000001', invokerName: 'Micha', guild: { name: 'Twitch-Test' }, iconUrl: null });
    // So legt die Web-App den Kanal an: ungeprüft, Name = Anmeldename
    const id = newId();
    await run(pool, "INSERT INTO bot_creators (id, org_id, platform, channel_id, name, discord_channel_id, mention_role_id, resolved) VALUES (?,?, 'twitch', 'knusper', 'knusper', '901', '42', 0)", [id, org.id]);
    const bad = newId();
    await run(pool, "INSERT INTO bot_creators (id, org_id, platform, channel_id, name, discord_channel_id, resolved) VALUES (?,?, 'twitch', 'gibtsnicht', 'gibtsnicht', '901', 0)", [bad, org.id]);

    let live = new Map([['knusper', { id: '500', title: 'Abend', game: 'Star Citizen', name: 'Knusper' }]]);
    const twitch = {
      users: async (logins) => new Map(logins.filter((l) => l === 'knusper').map((l) => [l, { id: '1', login: l, name: 'Knusper' }])),
      streams: async () => live,
    };
    const sent = [];
    const client = { channels: { fetch: async () => ({ send: async (m) => sent.push(m) }) } };
    const rows = async () => (await all(pool, "SELECT * FROM bot_creators WHERE org_id = ? AND platform = 'twitch' ORDER BY channel_id", [org.id]));
    const t0 = Date.now();

    // Schon beim Eintragen live: gilt als bekannt, keine Meldung. Unbekannter Kanal: Fehler.
    assert.equal(await checkTwitch(pool, client, await rows(), twitch, { now: t0 }), 0);
    let [unknown, knusper] = await rows();
    assert.deepEqual([knusper.name, Number(knusper.resolved), knusper.last_stream_id], ['Knusper', 1, '500']);
    assert.match(unknown.last_error, /gibt es nicht/);
    assert.equal(Number(unknown.resolved), 0);
    assert.equal(sent.length, 0);

    // Derselbe Stream läuft weiter: nichts. Neuer Stream: eine Meldung mit Rolle.
    assert.equal(await checkTwitch(pool, client, await rows(), twitch, { now: t0 + 1 }), 0);
    live = new Map([['knusper', { id: '501', title: 'Neuer Stream', game: '', name: 'Knusper' }]]);
    assert.equal(await checkTwitch(pool, client, await rows(), twitch, { now: t0 + 2 }), 1);
    assert.match(sent[0].content, /^<@&42> \*\*Knusper\*\* ist live auf Twitch: \*\*Neuer Stream\*\*/);
    assert.equal(await checkTwitch(pool, client, await rows(), twitch, { now: t0 + 3 }), 0, 'kein zweites Mal');

    // Verbindungsabbruch mit neuer Stream-ID innerhalb von 30 Minuten: keine zweite Meldung; danach wieder
    live = new Map([['knusper', { id: '502', title: 'Zurück', game: '', name: 'Knusper' }]]);
    assert.equal(await checkTwitch(pool, client, await rows(), twitch, { now: t0 + 5 * 60_000 }), 0);
    assert.equal((await rows())[1].last_stream_id, '502');
    live = new Map([['knusper', { id: '503', title: 'Später', game: '', name: 'Knusper' }]]);
    assert.equal(await checkTwitch(pool, client, await rows(), twitch, { now: t0 + 3 * 3600_000 }), 1);
    assert.equal(sent.length, 2);

    // Discord-Kanal nicht erreichbar: Fehler wird gemerkt
    live = new Map([['knusper', { id: '504', title: 'x', game: '', name: 'Knusper' }]]);
    const broken = { channels: { fetch: async () => { throw new DiscordAPIError({ message: 'weg' }, 10003, 404, 'GET', 'x', {}); } } };
    assert.equal(await checkTwitch(pool, broken, await rows(), twitch, { now: t0 + 9 * 3600_000 }), 0);
    assert.match((await rows())[1].last_error, /nicht erreichbar/);
  });

  test('Moderation: Fälle speichern, Verwarnungen zählen und zurücknehmen, Moderatoren-Rollen', async () => {
    const guildId = freshGuild();
    const org = await ensureOrg(pool, config, { guildId, invokerId: '300000000000000003', invokerName: 'Mod', guild: { name: 'Mod-Orga' }, iconUrl: null });
    assert.deepEqual(await modRoleIds(pool, org.id), []);
    await setSetting(pool, org.id, MOD_ROLES, '111111111111111111,222222222222222222');
    assert.deepEqual(await modRoleIds(pool, org.id), ['111111111111111111', '222222222222222222']);

    const target = '400000000000000001';
    const w1 = await addCase(pool, { orgId: org.id, targetId: target, targetName: 'Anna', moderatorId: '300000000000000003', action: 'WARN', reason: 'Ton' });
    const w2 = await addCase(pool, { orgId: org.id, targetId: target, targetName: 'Anna', moderatorId: '300000000000000003', action: 'WARN', reason: 'Spam' });
    await addCase(pool, { orgId: org.id, targetId: target, targetName: 'Anna', moderatorId: '300000000000000003', action: 'TIMEOUT', detail: '1 Stunde' });
    assert.ok(w2 > w1);
    assert.equal(await activeWarnings(pool, org.id, target), 2);
    assert.deepEqual((await listCases(pool, org.id, target)).map((c) => c.action), ['TIMEOUT', 'WARN', 'WARN']);
    await removeWarning(pool, org.id, w1);
    assert.equal(await activeWarnings(pool, org.id, target), 1);
    await assert.rejects(removeWarning(pool, org.id, w1), /gibt es nicht/); // schon zurückgenommen
    await assert.rejects(removeWarning(pool, org.id, 999999999), /gibt es nicht/);
    // Fälle einer anderen Orga lassen sich nicht zurücknehmen
    const other = await ensureOrg(pool, config, { guildId: freshGuild(), invokerId: '300000000000000004', invokerName: 'X', guild: { name: 'Andere' }, iconUrl: null });
    await assert.rejects(removeWarning(pool, other.id, w2), /gibt es nicht/);
    assert.equal(await activeWarnings(pool, org.id, target), 1);
  });

  test('Automod: Einstellungen speichern, kaputte Werte fallen auf Standard, Kontext je Server', async () => {
    const guildId = freshGuild();
    assert.equal(await guildContext(pool, guildId), null); // keine Orga
    const org = await ensureOrg(pool, config, { guildId, invokerId: '300000000000000005', invokerName: 'A', guild: { name: 'Auto-Orga' }, iconUrl: null });
    assert.deepEqual(await loadConfig(pool, org.id), DEFAULTS);
    await saveConfig(pool, org.id, { ...DEFAULTS, spam: true, mentions: 5, words: ['x'], domains: ['a.de'], exemptChannels: ['9'] });
    assert.deepEqual(await loadConfig(pool, org.id), { spam: true, invites: false, links: false, mentions: 5, words: ['x'], domains: ['a.de'], exemptChannels: ['9'] });
    await setSetting(pool, org.id, AUTOMOD_SETTING, '{kaputt');
    assert.deepEqual(await loadConfig(pool, org.id), DEFAULTS);
    await setSetting(pool, org.id, AUTOMOD_SETTING, JSON.stringify({ spam: 'ja', mentions: -3, words: 'x', domains: [1, 'b.de'] }));
    assert.deepEqual(await loadConfig(pool, org.id), { ...DEFAULTS, domains: ['b.de'] });
    const ctx = await guildContext(pool, guildId, Date.now() + 60_000); // nach Ablauf der 30 Sekunden Zwischenspeicher
    assert.equal(ctx.org.id, org.id);
    assert.deepEqual(ctx.modRoles, []);
  });

  test('/discord-setup: legt Rollen und Kanäle an, trägt alles ein, zweiter Lauf ist leer', async () => {
    const guildId = freshGuild();
    const org = await ensureOrg(pool, config, { guildId, invokerId: '300000000000000006', invokerName: 'S', guild: { name: 'Setup-Orga' }, iconUrl: null });
    let seq = 100000000000000000n;
    const next = () => String((seq += 1n));
    const rolesCache = new Map();
    const created = [];
    const sent = [];
    const guild = {
      id: guildId,
      roles: { cache: rolesCache, create: async (o) => { const r = { id: next(), name: o.name }; rolesCache.set(r.id, r); created.push(['role', o.name]); return r; } },
      channels: {
        create: async (o) => { created.push(['channel', o.name, o.parent ?? null, o.permissionOverwrites?.length ?? 0]); return { id: next() }; },
        fetch: async () => ({ send: async (m) => { sent.push(m); } }),
      },
    };
    const state = async () => {
      const settings = {};
      for (const [key, name] of [['rules', RULES_CHANNEL], ['welcome', WELCOME_CHANNEL], ['announce', ANNOUNCE_CHANNEL], ['modlog', MOD_LOG_CHANNEL], ['admin', 'admin_channel_id']]) settings[key] = await getSetting(pool, org.id, name);
      const fresh = await one(pool, 'SELECT * FROM organizations WHERE id = ?', [org.id]);
      return { org: fresh, modRoles: await modRoleIds(pool, org.id), visitorRoles: [await getSetting(pool, org.id, VISITOR_ROLE)].filter(Boolean), settings, roles: [...rolesCache.values()], channels: createdChannels };
    };
    const createdChannels = [];
    const origCreate = guild.channels.create;
    guild.channels.create = async (o) => { const c = await origCreate(o); createdChannels.push({ id: c.id, name: o.name, type: o.type }); return c; };

    const plan = planSetup(await state());
    const report = await runSetup({ pool, guild, org, plan, botId: '999999999999999999' });
    // Rollen, dann alle Kategorien, dann die Kanäle
    assert.deepEqual(created.slice(0, 5).map((c) => c[1]), ['Mitglied', 'Planer', 'Ausbilder', 'Moderator', 'Besucher']);
    assert.deepEqual(created.slice(5, 14).map((c) => c[1]), ['Eingangshalle', 'Intern', 'Events', 'Star Citizen Info', 'Öffentlich', 'Öffentliche Sprachkanäle', 'Gaming Area', 'Abteilungen', 'Administrative']);
    assert.equal(created.length, 37);
    const parentOf = (name) => createdChannels.find((c) => c.id === created.find((x) => x[1] === name)[2])?.name;
    const expected = [
      ['┋! -server-regeln-!', 'Eingangshalle'], ['┋willkommen', 'Eingangshalle'], ['┋team', 'Intern'], ['┋abteilungen', 'Intern'], ['┋recruits', 'Intern'],
      ['┋operations-plan', 'Events'], ['┋op-chat', 'Events'],
      ['┋patchnotes', 'Star Citizen Info'], ['┋sc-news', 'Star Citizen Info'], ['┋youtube-news', 'Star Citizen Info'], ['┋tips und tricks', 'Star Citizen Info'],
      ['┋issue-council', 'Star Citizen Info'], ['┋leaks', 'Star Citizen Info'], ['┋screenshots', 'Öffentlich'], ['┋chat', 'Öffentlich'], ['┋wer streamt gerade', 'Öffentlich'],
      ['┋Lobby', 'Öffentliche Sprachkanäle'], ['┋Gruppe 1', 'Öffentliche Sprachkanäle'], ['┋Gruppe 2', 'Öffentliche Sprachkanäle'],
      ['┋Gaming 1', 'Gaming Area'], ['┋Gaming 2', 'Gaming Area'], ['┋mod-protokoll', 'Administrative'], ['┋bot-einstellungen', 'Administrative'],
    ];
    assert.deepEqual(created.slice(14).map((c) => [c[1], parentOf(c[1])]), expected);
    // Rechte: Anzahl der Einträge je Kategorie bzw. Kanal (everyone, Rollen, Bot)
    const overwrites = Object.fromEntries(created.slice(5).map((c) => [c[1], c[3]]));
    assert.deepEqual(overwrites, {
      // Besucher-Bereiche: everyone gesperrt, Besucher, die vier Rollen mit Zugang (Mitglied, Planer, Ausbilder, Moderator), der Bot
      Eingangshalle: 1, Intern: 6, Events: 6, 'Star Citizen Info': 7, Öffentlich: 7, 'Öffentliche Sprachkanäle': 7, 'Gaming Area': 7, Abteilungen: 6, Administrative: 2,
      '┋! -server-regeln-!': 2, '┋willkommen': 2, '┋team': 6, '┋abteilungen': 6, '┋recruits': 6, '┋operations-plan': 6, '┋op-chat': 6,
      '┋patchnotes': 7, '┋sc-news': 7, '┋youtube-news': 7, '┋tips und tricks': 7, '┋issue-council': 7, '┋leaks': 7,
      '┋screenshots': 7, '┋chat': 7, '┋wer streamt gerade': 7, '┋Lobby': 6, '┋Gruppe 1': 6, '┋Gruppe 2': 6, '┋Gaming 1': 6, '┋Gaming 2': 6,
      '┋mod-protokoll': 3, '┋bot-einstellungen': 2,
    });
    assert.ok(report.every((l) => !l.startsWith('⚠')), report.join('\n'));
    assert.equal(sent.length, 2); // Regeln mit Bestätigen-Knopf und die Hinweise für Admins
    assert.match(sent[1].content, /Bot-Einstellungen für Admins/);
    assert.match(sent[0].components[0].components[0].custom_id, /^rules:ok:\d+$/);

    const fresh = await one(pool, 'SELECT * FROM organizations WHERE id = ?', [org.id]);
    const roleId = (name) => [...rolesCache.values()].find((r) => r.name === name).id;
    assert.equal(fresh.member_role_ids, roleId('Mitglied'));
    assert.equal(fresh.planner_role_ids, roleId('Planer'));
    assert.equal(fresh.instructor_role_ids, roleId('Ausbilder'));
    assert.deepEqual(await modRoleIds(pool, org.id), [roleId('Moderator')]);
    // „Bestätigen“ vergibt die Besucher-Rolle, nicht Mitglied
    assert.equal(sent[0].components[0].components[0].custom_id, `rules:ok:${roleId('Besucher')}`);
    assert.equal(await getSetting(pool, org.id, VISITOR_ROLE), roleId('Besucher'));
    assert.notEqual(roleId('Besucher'), roleId('Mitglied'));
    for (const [name, key] of [[ANNOUNCE_CHANNEL, '┋operations-plan'], [MOD_LOG_CHANNEL, '┋mod-protokoll'], [WELCOME_CHANNEL, '┋willkommen'], [RULES_CHANNEL, '┋! -server-regeln-!'], ['admin_channel_id', '┋bot-einstellungen']]) {
      assert.equal(await getSetting(pool, org.id, name), createdChannels.find((c) => c.name === key).id);
    }

    // Zweiter Lauf: alles vorhanden, nichts zu tun
    assert.equal(planSetup(await state()).empty, true);

    // Willkommensnachricht nutzt Kanal, Standardtext und den Regeln-Kanal
    const member = { id: '777', guild: { name: 'Setup-Orga' } };
    const msg = await welcomeMessage(pool, org.id, member);
    assert.equal(msg.channelId, await getSetting(pool, org.id, WELCOME_CHANNEL));
    assert.match(msg.payload.content, /^Willkommen <@777> auf \*\*Setup-Orga\*\*! Lies bitte <#\d+>/);
    await setSetting(pool, org.id, WELCOME_TEXT, 'Hallo {user}');
    assert.equal((await welcomeMessage(pool, org.id, member)).payload.content, 'Hallo <@777>');
    await setSetting(pool, org.id, WELCOME_CHANNEL, null);
    assert.equal(await welcomeMessage(pool, org.id, member), null);
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
