-- Ausbildungsbereiche sind nicht mehr fest (sieben Schlüssel im Code), sondern werden je Orga angelegt:
-- Name, optional Bild und Beschreibung, wie bei den Abteilungen. Ausbildungen gehören zu einem Bereich (area_id).
-- Image ist der Dateiname im Bildordner (training/area-<hash>.png).
CREATE TABLE training_areas (
    id          VARCHAR(32)  NOT NULL,
    org_id      VARCHAR(32)  NOT NULL,
    name        VARCHAR(80)  NOT NULL,
    description TEXT         NULL,
    image       VARCHAR(60)  NULL,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_training_area_name (org_id, name),
    KEY idx_training_area_image (image),
    CONSTRAINT fk_training_areas_org FOREIGN KEY (org_id) REFERENCES organizations (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bestandene Ausbildungen merken sich statt des alten Schlüssels den Namen des Bereichs.
ALTER TABLE training_completions ADD COLUMN area_name VARCHAR(80) NULL;
UPDATE training_completions SET area_name = CASE area
    WHEN 'mining' THEN 'Mining und Salvage'
    WHEN 'engineering' THEN 'Engineering und Betankung'
    WHEN 'transport' THEN 'Cargo, Passagiere, Daten'
    WHEN 'ground' THEN 'Bodenkampf'
    WHEN 'space' THEN 'Raumkampf'
    WHEN 'science' THEN 'Medizin und Forschung'
    WHEN 'exploration' THEN 'Pathfinding und Exploration'
    ELSE area END;
ALTER TABLE training_completions MODIFY area_name VARCHAR(80) NOT NULL, DROP COLUMN area;

-- Neustart: Die bisherigen Ausbildungen gehörten zu den festen Bereichen und entfallen. Termine bleiben als normale
-- Termine bestehen, bestandene Ausbildungen bleiben als Nachweis erhalten (course_id wird NULL).
DELETE FROM training_courses;
ALTER TABLE training_courses CHANGE area area_id VARCHAR(32) NOT NULL;
ALTER TABLE training_courses ADD CONSTRAINT fk_training_courses_area FOREIGN KEY (area_id) REFERENCES training_areas (id) ON DELETE CASCADE;

DROP TABLE training_area_images;
