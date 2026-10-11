-- Einstellungen der ganzen Installation (Schlüssel/Wert), z. B. Logo, Hintergrund und Deckkraft (Branding).
CREATE TABLE app_settings (
    name  VARCHAR(40) NOT NULL PRIMARY KEY,
    value TEXT        NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;