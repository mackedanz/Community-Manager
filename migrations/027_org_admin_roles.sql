-- Orga-Leitung: Discord-Rollen (IDs wie bei Planern und Recruitern), deren Träger im Manager Admins der Orga sind,
-- auch ohne das Discord-Recht "Administrator" oder "Server verwalten".
-- can_moderate: Mitglieder mit einer Moderator-Rolle (bot_settings.mod_role_ids) dürfen auf der Bot-Seite Moderation,
-- Automod und die Fälle ansehen und Maßnahmen ausführen.
ALTER TABLE organizations ADD COLUMN admin_role_ids VARCHAR(400) NULL;
ALTER TABLE org_memberships ADD COLUMN can_moderate TINYINT(1) NOT NULL DEFAULT 0;
