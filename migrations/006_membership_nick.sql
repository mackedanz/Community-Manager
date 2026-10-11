-- Server-Nickname des Mitglieds auf dem Discord-Server der Orga (wird bei der Mitgliedschaftsprüfung aktualisiert).
ALTER TABLE org_memberships ADD COLUMN nick VARCHAR(190) NULL;
