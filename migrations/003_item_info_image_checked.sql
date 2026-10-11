-- Zeitpunkt des letzten vergeblichen Bild-Downloads (Ausrüstung/Loot), damit nicht bei jedem Aufruf neu versucht wird.
ALTER TABLE item_info ADD COLUMN image_checked_at DATETIME NULL;
