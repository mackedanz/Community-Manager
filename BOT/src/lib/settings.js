// Einstellungen des Bots je Orga (Tabelle bot_settings, Migration 020).
import { run, val } from '../db.js';

export const ANNOUNCE_CHANNEL = 'announce_channel_id';
/** '1' = der Bot läuft mit Message Content (Automod und Nachrichten-Protokoll aktiv), '0' = nicht; schreibt der Bot beim Start, die Web-App zeigt es an. */
export const MESSAGE_CONTENT_STATUS = 'message_content_active';

export async function getSetting(db, orgId, name) {
  const v = await val(db, 'SELECT value FROM bot_settings WHERE org_id = ? AND name = ?', [orgId, name]);
  return typeof v === 'string' ? v : null;
}

/** Setzt eine Einstellung; null entfernt sie. */
export async function setSetting(db, orgId, name, value) {
  if (value === null) {
    await run(db, 'DELETE FROM bot_settings WHERE org_id = ? AND name = ?', [orgId, name]);
    return;
  }
  await run(db, 'INSERT INTO bot_settings (org_id, name, value) VALUES (?,?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)', [orgId, name, value]);
}

/** Fehlt eine Tabelle, weil die Web-App (Migrationen) noch nicht aktualisiert ist? */
export function isMissingTable(err) {
  return err?.code === 'ER_NO_SUCH_TABLE' || err?.errno === 1146;
}

export const MISSING_TABLE_TEXT = 'Die Datenbank ist noch nicht auf dem Stand für diese Funktion. Bitte die Web-App aktualisieren (docker compose pull && docker compose up -d).';
