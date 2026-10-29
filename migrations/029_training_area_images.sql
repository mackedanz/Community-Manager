-- Eigene Bilder für die sieben Ausbildungsbereiche einer Orga (statt der Standard-Symbole). Pflegen dürfen Orga-Admins
-- unter "Orga verwalten". image ist der Dateiname im Bildordner (training/area-<hash>.png); ohne Eintrag gilt das Standard-Symbol.
CREATE TABLE training_area_images (
    org_id VARCHAR(32) NOT NULL,
    area   VARCHAR(20) NOT NULL,
    image  VARCHAR(60) NOT NULL,
    PRIMARY KEY (org_id, area),
    CONSTRAINT fk_training_area_images_org FOREIGN KEY (org_id) REFERENCES organizations (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
