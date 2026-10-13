// Liest die Einstellungen aus den Umgebungsvariablen. Die Namen sind dieselben wie in der PHP-App,
// damit beide aus derselben .env versorgt werden können.

export class ConfigError extends Error {}

const REQUIRED = {
  DISCORD_BOT_TOKEN: 'Token des Bots (Discord Developer Portal -> Bot -> Token)',
  AUTH_DISCORD_ID: 'ID der Discord-Anwendung (Developer Portal -> General Information -> Application ID)',
  DB_HOST: 'Adresse der MariaDB',
  DB_USER: 'Benutzer der MariaDB',
  DB_PASSWORD: 'Passwort der MariaDB',
};

function value(env, name) {
  const v = env[name];
  return typeof v === 'string' && v.trim() !== '' ? v.trim() : null;
}

/** @returns {{token: string, applicationId: string, devGuildId: string|null, db: {host: string, port: number, database: string, user: string, password: string}}} */
export function readConfig(env = process.env) {
  const missing = Object.keys(REQUIRED).filter((name) => value(env, name) === null);
  if (missing.length > 0) {
    const lines = missing.map((name) => `  ${name}: ${REQUIRED[name]}`);
    throw new ConfigError(`Diese Einstellungen fehlen:\n${lines.join('\n')}`);
  }

  const port = Number(value(env, 'DB_PORT') ?? 3306);
  if (!Number.isInteger(port) || port < 1 || port > 65535) {
    throw new ConfigError(`DB_PORT ist keine gültige Portnummer: ${env.DB_PORT}`);
  }

  return {
    token: value(env, 'DISCORD_BOT_TOKEN'),
    applicationId: value(env, 'AUTH_DISCORD_ID'),
    // Nur für die Entwicklung: Befehle sofort auf diesem einen Server statt global registrieren
    devGuildId: value(env, 'BOT_DEV_GUILD_ID'),
    db: {
      host: value(env, 'DB_HOST'),
      port,
      database: value(env, 'DB_NAME') ?? 'hangar',
      user: value(env, 'DB_USER'),
      password: value(env, 'DB_PASSWORD'),
    },
  };
}
