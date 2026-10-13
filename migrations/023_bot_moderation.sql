-- Discord-Bot (BOT/): Moderationsfälle je Orga (Verwarnung, Timeout, Kick, Bann, aufgeräumte Nachrichten, Automod).
-- Moderatoren-Rollen und der Protokoll-Kanal stehen in bot_settings (mod_role_ids, mod_log_channel_id).
-- removed_at: eine Verwarnung, die ein Moderator zurückgenommen hat; sie zählt dann nicht mehr als aktiv.
CREATE TABLE bot_mod_cases (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    org_id       VARCHAR(32)  NOT NULL,
    target_id    VARCHAR(32)  NOT NULL,
    target_name  VARCHAR(100) NOT NULL,
    moderator_id VARCHAR(32)  NULL,
    action       VARCHAR(16)  NOT NULL,
    reason       VARCHAR(500) NULL,
    detail       VARCHAR(200) NULL,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    removed_at   DATETIME     NULL,
    KEY idx_bot_cases_target (org_id, target_id),
    CONSTRAINT fk_bot_cases_org FOREIGN KEY (org_id) REFERENCES organizations (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
