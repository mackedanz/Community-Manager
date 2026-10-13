// Liest die Einstellungen aus den Umgebungsvariablen. Die Namen sind dieselben wie in der PHP-App,
// damit beide aus derselben .env versorgt werden können.
import { fileURLToPath } from 'node:url';

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

/** Prüft die Pflichtwerte und liefert alle Einstellungen als Objekt. @throws {ConfigError} */
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

  const list = (name) => (value(env, name) ?? '').split(',').map((s) => s.trim()).filter((s) => s !== '');
  let appUrl = value(env, 'AUTH_URL') ?? value(env, 'APP_URL');
  if (appUrl === null) {
    const domain = value(env, 'DOMAIN');
    appUrl = domain !== null ? `https://${domain}` : 'http://localhost:8080';
  }

  return {
    token: value(env, 'DISCORD_BOT_TOKEN'),
    // Adresse der Web-App (für Hinweise in den Antworten)
    appUrl: appUrl.replace(/\/+$/, ''),
    // Nur diese Server dürfen per /einrichten eine Orga anlegen (leer = jeder Server)
    onboardingGuildIds: list('ONBOARDING_GUILD_IDS'),
    // Betreiber der Installation; ihre Konten werden beim Abgleich nie gelöscht
    serverAdminIds: list('SERVER_ADMIN_DISCORD_ID'),
    // Anmeldung nur mit Eintrag auf einer Zugangsliste; nur dann löscht der Abgleich Konten ehemaliger Mitglieder
    loginRequiresAllowlist: ['1', 'true', 'yes', 'on'].includes((value(env, 'LOGIN_REQUIRES_ALLOWLIST') ?? '').toLowerCase()),
    // Bilderordner der Web-App (im Docker-Stack dasselbe Volume); hier legt /design Logo und Hintergrund ab
    imageDir: (value(env, 'IMAGE_DIR') ?? fileURLToPath(new URL('../../storage/images', import.meta.url))).replace(/[\\/]+$/, ''),
    // Automod und Nachrichten-Protokoll brauchen den Intent "Message Content" (Developer Portal -> Bot); ohne diesen Schalter bleiben sie aus
    messageContent: ['1', 'true', 'yes', 'on'].includes((value(env, 'BOT_MESSAGE_CONTENT') ?? '').toLowerCase()),
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
