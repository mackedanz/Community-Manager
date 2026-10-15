-- Discord-Bot (BOT/): Befehl /recruit. Der Bot führt Recruiter durch einen Fragenkatalog und postet die Antworten als Übersicht in einen Kanal.
-- bot_recruit_questions: die Fragen je Orga in der Reihenfolge position. label ist die Frage im Eingabefenster (Discord erlaubt 45 Zeichen),
-- hint der graue Hilfetext im Antwortfeld (100 Zeichen), long_answer = 1 ein mehrzeiliges Feld.
-- bot_recruit_drafts: der angefangene Fragenkatalog eines Recruiters (je Fenster ein Schritt). questions hält die Fragen zum Zeitpunkt des
-- Starts (JSON), answers die bisherigen Antworten (JSON, Nummer der Frage => Text). Der Entwurf wird nach dem Posten oder nach 24 Stunden gelöscht.
-- Kanal und Rollen stehen in bot_settings (recruit_channel_id, recruit_role_ids).
CREATE TABLE bot_recruit_questions (
    id          VARCHAR(32)  NOT NULL PRIMARY KEY,
    org_id      VARCHAR(32)  NOT NULL,
    position    INT          NOT NULL,
    label       VARCHAR(45)  NOT NULL,
    hint        VARCHAR(100) NULL,
    required    TINYINT(1)   NOT NULL DEFAULT 1,
    long_answer TINYINT(1)   NOT NULL DEFAULT 0,
    KEY idx_recruit_questions (org_id, position),
    CONSTRAINT fk_recruit_questions_org FOREIGN KEY (org_id) REFERENCES organizations (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE bot_recruit_drafts (
    id             VARCHAR(32)  NOT NULL PRIMARY KEY,
    org_id         VARCHAR(32)  NOT NULL,
    recruiter_id   VARCHAR(32)  NOT NULL,
    applicant_id   VARCHAR(32)  NULL,
    applicant_name VARCHAR(100) NULL,
    questions      MEDIUMTEXT   NOT NULL,
    answers        MEDIUMTEXT   NOT NULL,
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_recruit_drafts (org_id, recruiter_id),
    CONSTRAINT fk_recruit_drafts_org FOREIGN KEY (org_id) REFERENCES organizations (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
