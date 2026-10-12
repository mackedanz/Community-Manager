-- Ein Termin kann eine Ausbildung sein (Teil 2 der Ausbildung). Wird die Ausbildung gelöscht, bleibt der Termin als normaler Termin bestehen.
ALTER TABLE events ADD COLUMN course_id VARCHAR(32) NULL;
ALTER TABLE events ADD CONSTRAINT fk_events_course FOREIGN KEY (course_id) REFERENCES training_courses (id) ON DELETE SET NULL;
