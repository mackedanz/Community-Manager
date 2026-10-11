-- Zuletzt gesehener Discord-Status des Events (1 geplant, 2 läuft, 3 beendet, 4 abgesagt, 0 in Discord nicht mehr vorhanden).
-- Der Abgleich ändert den Status eines Termins nur, wenn sich dieser Wert in Discord ändert; so bleibt, was ein Planer
-- im Hangar entschieden hat (veröffentlicht, wieder aktiviert), erhalten.
ALTER TABLE events ADD COLUMN discord_status TINYINT NULL;