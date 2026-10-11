-- Hangar-Termine als Discord-Event anlegen (Haken pro Termin).
-- discord_push: Planer will den Termin in Discord. discord_dirty: der Cron-Lauf muss etwas nach Discord schreiben.
-- discord_origin: IMPORT = aus Discord übernommen (Discord ist maßgeblich), PUSH = vom Hangar angelegt (der Hangar ist maßgeblich).
ALTER TABLE events
    ADD COLUMN discord_push   TINYINT(1)   NOT NULL DEFAULT 0,
    ADD COLUMN discord_dirty  TINYINT(1)   NOT NULL DEFAULT 0,
    ADD COLUMN discord_origin VARCHAR(8)   NULL,
    ADD COLUMN discord_error  VARCHAR(190) NULL;
UPDATE events SET discord_origin = 'IMPORT' WHERE discord_event_id IS NOT NULL;

-- Gelöschte Termine, deren Discord-Event noch entfernt werden muss.
CREATE TABLE discord_event_deletions (
    id               VARCHAR(32) NOT NULL PRIMARY KEY,
    org_id           VARCHAR(32) NOT NULL,
    discord_event_id VARCHAR(32) NOT NULL,
    created_at       DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_discord_deletions_org FOREIGN KEY (org_id) REFERENCES organizations (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;