// Zugriff auf die MariaDB des Community-Managers. Die Tabellen gehören den PHP-Migrationen
// (migrations/ im Hauptordner); der Bot legt selbst keine Tabellen an und ändert sie nicht.
import mysql from 'mysql2/promise';

export function createPool(db) {
  return mysql.createPool({
    host: db.host,
    port: db.port,
    database: db.database,
    user: db.user,
    password: db.password,
    charset: 'utf8mb4_unicode_ci',
    // Zeiten in der Datenbank sind UTC (die PHP-App arbeitet ebenfalls mit UTC)
    timezone: 'Z',
    // DATETIME als Text "YYYY-MM-DD HH:MM:SS" wie in der PHP-App, nicht als Date-Objekt
    dateStrings: true,
    // JSON-Spalten als Text wie in der PHP-App; ausgewertet wird dort, wo sie gebraucht werden
    jsonStrings: true,
    connectionLimit: 5,
    waitForConnections: true,
    enableKeepAlive: true,
  });
}

/** Alle Zeilen einer Abfrage. Funktioniert mit dem Pool und mit einer Verbindung in einer Transaktion. */
export async function all(db, sql, params = []) {
  const [rows] = await db.query(sql, params);
  return rows;
}

/** Erste Zeile oder null. */
export async function one(db, sql, params = []) {
  return (await all(db, sql, params))[0] ?? null;
}

/** Erster Wert der ersten Zeile oder null. */
export async function val(db, sql, params = []) {
  const row = await one(db, sql, params);
  return row === null ? null : Object.values(row)[0] ?? null;
}

/** Führt eine Änderung aus und liefert die Zahl der betroffenen Zeilen. */
export async function run(db, sql, params = []) {
  const [res] = await db.query(sql, params);
  return res.affectedRows ?? 0;
}

/** Platzhalter für IN (...): in(['a','b']) => '?,?' */
export function inList(values) {
  return values.map(() => '?').join(',');
}

/** Führt fn(conn) in einer Transaktion aus; bei einem Fehler wird alles zurückgenommen. */
export async function transaction(pool, fn) {
  const conn = await pool.getConnection();
  try {
    await conn.beginTransaction();
    const result = await fn(conn);
    await conn.commit();
    return result;
  } catch (err) {
    await conn.rollback().catch(() => {});
    throw err;
  } finally {
    conn.release();
  }
}

/** Aktuelle Zeit im Format der Datenbank (UTC, wie Time::nowDb() der PHP-App). */
export function nowDb(date = new Date()) {
  return date.toISOString().slice(0, 19).replace('T', ' ');
}

/** Prüft die Verbindung und liefert Antwortzeit und den zuletzt eingespielten Migrationsstand. */
export async function checkDatabase(pool) {
  const start = performance.now();
  await pool.query('SELECT 1');
  const ms = Math.round(performance.now() - start);
  const migration = await val(pool, 'SELECT name FROM schema_migrations ORDER BY name DESC LIMIT 1');
  return { ms, migration };
}

/** Die Orga, die zu einem Discord-Server gehört, oder null. */
export async function orgForGuild(pool, guildId) {
  return one(pool, 'SELECT * FROM organizations WHERE discord_guild_id = ? LIMIT 1', [guildId]);
}
