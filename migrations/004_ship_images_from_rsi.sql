-- Schiffsbilder kommen jetzt von RSI statt von FleetYards: Fehlversuche der alten Quelle sollen nicht mehr sperren.
UPDATE catalog_items SET image_checked_at = NULL WHERE kind = 'SHIP';
