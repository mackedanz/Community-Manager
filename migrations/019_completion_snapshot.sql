-- Bestandene Ausbildungen bleiben erhalten, auch wenn die Ausbildung später gelöscht wird:
-- Der Eintrag merkt sich Orga, Bereich, Thema, Stufe und Titel; course_id wird dann NULL.
ALTER TABLE training_completions
    ADD COLUMN org_id VARCHAR(32) NULL,
    ADD COLUMN area   VARCHAR(20) NULL,
    ADD COLUMN topic  VARCHAR(80) NULL,
    ADD COLUMN level  INT UNSIGNED NULL,
    ADD COLUMN title  VARCHAR(120) NULL;

UPDATE training_completions d JOIN training_courses t ON t.id = d.course_id
   SET d.org_id = t.org_id, d.area = t.area, d.topic = t.topic, d.level = t.level, d.title = t.title;

ALTER TABLE training_completions
    MODIFY org_id VARCHAR(32) NOT NULL,
    MODIFY area   VARCHAR(20) NOT NULL,
    MODIFY topic  VARCHAR(80) NOT NULL,
    MODIFY level  INT UNSIGNED NOT NULL,
    MODIFY title  VARCHAR(120) NOT NULL;

ALTER TABLE training_completions ADD UNIQUE KEY uq_training_done (user_id, course_id);
ALTER TABLE training_completions DROP FOREIGN KEY fk_training_done_course;
ALTER TABLE training_completions DROP PRIMARY KEY;
ALTER TABLE training_completions ADD COLUMN id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST;
ALTER TABLE training_completions MODIFY course_id VARCHAR(32) NULL;
ALTER TABLE training_completions ADD KEY idx_training_done_org (org_id, user_id);
ALTER TABLE training_completions ADD CONSTRAINT fk_training_done_course FOREIGN KEY (course_id) REFERENCES training_courses (id) ON DELETE SET NULL;
ALTER TABLE training_completions ADD CONSTRAINT fk_training_done_org FOREIGN KEY (org_id) REFERENCES organizations (id) ON DELETE CASCADE;
