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
    connectionLimit: 5,
    waitForConnections: true,
    enableKeepAlive: true,
  });
}

/** Prüft die Verbindung und liefert Antwortzeit und den zuletzt eingespielten Migrationsstand. */
export async function checkDatabase(pool) {
  const start = performance.now();
  await pool.query('SELECT 1');
  const ms = Math.round(performance.now() - start);
  const [rows] = await pool.query('SELECT name FROM schema_migrations ORDER BY name DESC LIMIT 1');
  return { ms, migration: rows[0]?.name ?? null };
}

/** Die Orga, die zu einem Discord-Server gehört, oder null. */
export async function orgForGuild(pool, guildId) {
  const [rows] = await pool.query(
    'SELECT id, slug, name FROM organizations WHERE discord_guild_id = ? LIMIT 1',
    [guildId],
  );
  return rows[0] ?? null;
}
