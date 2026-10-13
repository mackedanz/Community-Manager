-- Discord-Bot (BOT/): YouTube-Kanäle, deren neue Videos der Bot in einen Discord-Kanal postet (Ersatz für MEE6).
-- channel_id ist die YouTube-Kanal-ID (UC…), discord_channel_id der Discord-Kanal, mention_role_id optional die Rolle,
-- die beim Posten erwähnt wird, skip_shorts = 1 lässt Shorts aus. last_error/checked_at zeigen in /creator liste, ob die
-- Abfrage klappt.
CREATE TABLE bot_creators (
    id                 VARCHAR(32)  NOT NULL PRIMARY KEY,
    org_id             VARCHAR(32)  NOT NULL,
    platform           VARCHAR(16)  NOT NULL DEFAULT 'youtube',
    channel_id         VARCHAR(64)  NOT NULL,
    name               VARCHAR(190) NOT NULL,
    discord_channel_id VARCHAR(32)  NOT NULL,
    mention_role_id    VARCHAR(32)  NULL,
    skip_shorts        TINYINT(1)   NOT NULL DEFAULT 0,
    created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    checked_at         DATETIME     NULL,
    last_error         VARCHAR(190) NULL,
    UNIQUE KEY uq_bot_creators (org_id, platform, channel_id),
    CONSTRAINT fk_bot_creators_org FOREIGN KEY (org_id) REFERENCES organizations (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Schon bekannte Videos je Creator, damit jedes nur einmal gepostet wird (auch nach einem Neustart des Bots).
CREATE TABLE bot_creator_videos (
    creator_id VARCHAR(32) NOT NULL,
    video_id   VARCHAR(32) NOT NULL,
    posted     TINYINT(1)  NOT NULL DEFAULT 0,
    seen_at    DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (creator_id, video_id),
    CONSTRAINT fk_bot_creator_videos FOREIGN KEY (creator_id) REFERENCES bot_creators (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
