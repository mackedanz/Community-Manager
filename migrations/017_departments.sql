-- Abteilungen: Abteilungsleiter-Rolle (Discord-Rollen-IDs wie bei Planern und Ausbildern), Abteilungen je Orga,
-- Trainings und Übungen als Termine im Kalender. Abteilungsleiter und Admins pflegen die Abteilungen.
ALTER TABLE organizations ADD COLUMN lead_role_ids VARCHAR(400) NULL;
ALTER TABLE org_memberships ADD COLUMN can_lead TINYINT(1) NOT NULL DEFAULT 0;

CREATE TABLE departments (
    id          VARCHAR(32)  NOT NULL,
    org_id      VARCHAR(32)  NOT NULL,
    name        VARCHAR(80)  NOT NULL,
    description TEXT         NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_departments_name (org_id, name),
    CONSTRAINT fk_departments_org FOREIGN KEY (org_id) REFERENCES organizations (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ein Termin kann ein Training oder eine Übung einer Abteilung sein (kind: TRAINING oder EXERCISE).
-- Wird die Abteilung gelöscht, bleibt der Termin als normaler Termin bestehen.
ALTER TABLE events ADD COLUMN department_id VARCHAR(32) NULL;
ALTER TABLE events ADD COLUMN department_kind VARCHAR(10) NULL;
ALTER TABLE events ADD CONSTRAINT fk_events_department FOREIGN KEY (department_id) REFERENCES departments (id) ON DELETE SET NULL;
