-- Discord-Server-Vorlagen je Orga (Web-App, Bereich "Vorlagen" der Bot-Seite): Rollen, Kategorien und Kanäle mit Sichtbarkeit,
-- gespeichert als JSON (Rollen werden darin über ihren Namen angesprochen, damit eine Vorlage auf jeden Server passt).
CREATE TABLE discord_templates (
    id          VARCHAR(32)  NOT NULL PRIMARY KEY,
    org_id      VARCHAR(32)  NOT NULL,
    name        VARCHAR(100) NOT NULL,
    description VARCHAR(300) NULL,
    data        MEDIUMTEXT   NOT NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_discord_templates_name (org_id, name),
    CONSTRAINT fk_discord_templates_org FOREIGN KEY (org_id) REFERENCES organizations (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
