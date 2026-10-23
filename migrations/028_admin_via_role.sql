-- Admin der Orga nur über die Orga-Leitungs-Rolle (nicht als Discord-Server-Admin): kein Zugriff auf Rollen und Rechte,
-- Discord-Setup und Temp Sprachkanal. Bestehende Admins waren Server-Admins, deshalb Standard 0 (voller Zugriff).
ALTER TABLE org_memberships ADD COLUMN admin_via_role TINYINT(1) NOT NULL DEFAULT 0;
