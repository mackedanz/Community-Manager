-- Discord-Bot (Ordner BOT/): Einstellungen je Orga, z. B. der Kanal für Terminankündigungen (name = announce_channel_id).
CREATE TABLE bot_settings (
    org_id VARCHAR(32) NOT NULL,
    name   VARCHAR(40) NOT NULL,
    value  TEXT        NOT NULL,
    PRIMARY KEY (org_id, name),
    CONSTRAINT fk_bot_settings_org FOREIGN KEY (org_id) REFERENCES organizations (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Vom Bot angekündigte Termine: wo die Nachricht steht und welcher Stand zuletzt gezeigt wurde (fingerprint).
-- Bewusst ohne Fremdschlüssel auf events: Wird ein Termin gelöscht, muss der Bot seine Nachricht noch finden und entfernen.
-- message_id leer = Nachricht wurde in Discord gelöscht, nicht erneut posten.
CREATE TABLE bot_event_posts (
    event_id    VARCHAR(32) NOT NULL PRIMARY KEY,
    org_id      VARCHAR(32) NOT NULL,
    channel_id  VARCHAR(32) NOT NULL,
    message_id  VARCHAR(32) NOT NULL,
    fingerprint CHAR(40)    NOT NULL,
    created_at  DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_bot_posts_org (org_id),
    CONSTRAINT fk_bot_posts_org FOREIGN KEY (org_id) REFERENCES organizations (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Verschickte Erinnerungen (kind: 24h oder 1h), damit jede nur einmal rausgeht.
CREATE TABLE bot_event_reminders (
    event_id VARCHAR(32) NOT NULL,
    kind     VARCHAR(8)  NOT NULL,
    sent_at  DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (event_id, kind),
    CONSTRAINT fk_bot_reminders_event FOREIGN KEY (event_id) REFERENCES events (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
