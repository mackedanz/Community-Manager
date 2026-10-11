-- Zugangsliste: Discord-Mitglieder der Orgas, die der Onboarding-Bot eingetragen hat.
-- "fixed" = vom Bot dauerhaft eingetragen (Person, die /einrichten ausgeführt hat), bleibt beim Abgleich bestehen.
CREATE TABLE org_allowed_members (
    org_id     VARCHAR(32)  NOT NULL,
    discord_id VARCHAR(32)  NOT NULL,
    name       VARCHAR(190) NULL,
    avatar_url VARCHAR(500) NULL,
    fixed      TINYINT(1)   NOT NULL DEFAULT 0,
    synced_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (org_id, discord_id),
    KEY idx_allowed_discord (discord_id),
    CONSTRAINT fk_allowed_org FOREIGN KEY (org_id) REFERENCES organizations (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE organizations ADD COLUMN allowlist_synced_at DATETIME NULL AFTER role_labels;
