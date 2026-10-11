-- RSI-Orga einer Orga der App: Kürzel (SID), Name und Stand des letzten Abgleichs der öffentlichen Mitgliederliste.
-- rsi_redacted = Anzahl Mitglieder, die ihre Zugehörigkeit auf RSI verbergen (nicht prüfbar).
ALTER TABLE organizations
    ADD COLUMN rsi_sid       VARCHAR(20)  NULL,
    ADD COLUMN rsi_org_name  VARCHAR(190) NULL,
    ADD COLUMN rsi_redacted  INT          NOT NULL DEFAULT 0,
    ADD COLUMN rsi_synced_at DATETIME     NULL;

-- Sichtbare Mitglieder der RSI-Orga (Handle ohne Beachtung der Groß-/Kleinschreibung). main = Hauptorga, sonst Affiliate.
CREATE TABLE org_rsi_members (
    org_id VARCHAR(32)  NOT NULL,
    handle VARCHAR(100) NOT NULL,
    main   TINYINT(1)   NOT NULL DEFAULT 1,
    PRIMARY KEY (org_id, handle),
    CONSTRAINT fk_rsi_members_org FOREIGN KEY (org_id) REFERENCES organizations (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
