-- Übernahme der Discord-Server-Events als Termine.
-- discord_events_mode: OFF (aus), DRAFT (als Entwurf übernehmen), PUBLISHED (direkt sichtbar).
ALTER TABLE organizations
    ADD COLUMN discord_events_mode      VARCHAR(10) NOT NULL DEFAULT 'OFF',
    ADD COLUMN discord_events_synced_at DATETIME    NULL;

-- discord_event_id: das Discord-Event, aus dem der Termin stammt.
-- discord_edited: ein Planer hat Titel, Zeit, Ort oder Beschreibung geändert; der Abgleich überschreibt sie dann nicht mehr.
ALTER TABLE events
    ADD COLUMN discord_event_id VARCHAR(32) NULL,
    ADD COLUMN discord_edited   TINYINT(1)  NOT NULL DEFAULT 0,
    ADD UNIQUE KEY uq_events_discord (org_id, discord_event_id);