-- Recruiter-Rollen einer Orga (Discord-Rollen-IDs wie bei den Planern). Recruiter und Admins sehen in der Mitgliederliste
-- die RSI-Zugehörigkeit (Rahmen, Filter, Hinweise zum Handle); normale Mitglieder sehen die Liste ohne.
ALTER TABLE organizations ADD COLUMN recruiter_role_ids VARCHAR(400) NULL;
ALTER TABLE org_memberships ADD COLUMN can_recruit TINYINT(1) NOT NULL DEFAULT 0;
