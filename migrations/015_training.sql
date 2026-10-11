-- Ausbildung: Ausbilder-Rolle (Discord-Rollen-IDs wie bei Planern und Recruitern), Ausbildungen je Bereich und Abschlüsse.
-- Ausbilder und Admins pflegen die Ausbildungen und bestätigen Abschlüsse.
ALTER TABLE organizations ADD COLUMN instructor_role_ids VARCHAR(400) NULL;
ALTER TABLE org_memberships ADD COLUMN can_instruct TINYINT(1) NOT NULL DEFAULT 0;

-- Eine Ausbildung gehört zu einem Bereich (area, feste Schlüssel in Hangar\Training), einem Thema (Reihe) und hat eine Stufe.
-- Innerhalb eines Themas gilt die Reihenfolge der Stufen: erst Stufe 1, dann Stufe 2 usw.
CREATE TABLE training_courses (
    id         VARCHAR(32)  NOT NULL,
    org_id     VARCHAR(32)  NOT NULL,
    area       VARCHAR(20)  NOT NULL,
    topic      VARCHAR(80)  NOT NULL,
    level      INT UNSIGNED NOT NULL,
    title      VARCHAR(120) NOT NULL,
    content    TEXT         NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_training_level (org_id, area, topic, level),
    KEY idx_training_org_area (org_id, area),
    CONSTRAINT fk_training_courses_org FOREIGN KEY (org_id) REFERENCES organizations (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bestandene Ausbildungen: wer, welche, von wem bestätigt, wann.
CREATE TABLE training_completions (
    user_id      VARCHAR(32) NOT NULL,
    course_id    VARCHAR(32) NOT NULL,
    confirmed_by VARCHAR(32) NULL,
    completed_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, course_id),
    CONSTRAINT fk_training_done_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_training_done_course FOREIGN KEY (course_id) REFERENCES training_courses (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
