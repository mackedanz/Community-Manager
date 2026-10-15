-- Twitch-Live-Meldungen (BOT/): bot_creators bekommt neben YouTube die Plattform 'twitch'.
-- Bei Twitch ist channel_id der Anmeldename des Kanals (klein geschrieben). resolved = 0: der Bot hat den Kanal noch nicht bei Twitch
-- geprüft (die Web-App legt ihn so an, der Bot trägt Anzeigenamen ein oder meldet in last_error, dass es ihn nicht gibt).
-- last_stream_id merkt sich den Stream, für den schon gemeldet wurde; notified_at die Zeit der letzten Meldung (Pause nach einem
-- Verbindungsabbruch, damit derselbe Stream nicht zweimal gemeldet wird).
ALTER TABLE bot_creators
    ADD COLUMN resolved       TINYINT(1)  NOT NULL DEFAULT 1 AFTER skip_shorts,
    ADD COLUMN last_stream_id VARCHAR(32) NULL,
    ADD COLUMN notified_at    DATETIME    NULL;
