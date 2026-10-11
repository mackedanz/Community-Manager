-- Prüfung des RSI-Handles pro Mitglied: UEE Citizen Record (feste Kennung des RSI-Kontos), zu dem der Handle bei der
-- letzten erfolgreichen Prüfung gehörte. status 'conflict' = der Handle gehört inzwischen zu einer anderen Nummer.
-- suggested_handle = Handle in der RSI-Orga, der dieselbe Nummer trägt, wenn der bisherige nicht mehr passt.
-- Nichts davon ändert Handle, Nickname oder Rahmen von selbst; es dient nur als Hinweis für Admins.
CREATE TABLE rsi_checks (
    user_id           VARCHAR(32)  NOT NULL,
    handle            VARCHAR(100) NULL,
    record_no         INT UNSIGNED NULL,
    status            VARCHAR(10)  NOT NULL DEFAULT 'ok',
    record_checked_at DATETIME     NULL,
    suggested_handle  VARCHAR(100) NULL,
    PRIMARY KEY (user_id),
    CONSTRAINT fk_rsi_checks_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
