-- Community-Hangar: Grundschema (MariaDB 10.6+ / MySQL 8)
-- Erlaubte Werte der Textspalten stehen in src/Constants.php und werden im PHP-Code geprüft.

CREATE TABLE users (
    id                       VARCHAR(32)  NOT NULL PRIMARY KEY,
    name                     VARCHAR(190) NULL,
    email                    VARCHAR(190) NULL,
    image                    VARCHAR(500) NULL,
    discord_id               VARCHAR(32)  NULL,
    rsi_handle               VARCHAR(100) NULL,
    hangar_visibility        VARCHAR(16)  NOT NULL DEFAULT 'MEMBERS',
    achievements_visibility  VARCHAR(16)  NOT NULL DEFAULT 'MEMBERS',
    membership_checked_at    DATETIME     NULL,
    membership_status        VARCHAR(16)  NULL,
    created_at               DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_users_email (email),
    UNIQUE KEY uq_users_discord (discord_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE accounts (
    id                  VARCHAR(32)  NOT NULL PRIMARY KEY,
    user_id             VARCHAR(32)  NOT NULL,
    provider            VARCHAR(32)  NOT NULL,
    provider_account_id VARCHAR(64)  NOT NULL,
    access_token        TEXT         NULL,
    refresh_token       TEXT         NULL,
    expires_at          BIGINT       NULL,
    token_type          VARCHAR(32)  NULL,
    scope               VARCHAR(500) NULL,
    UNIQUE KEY uq_accounts_provider (provider, provider_account_id),
    KEY idx_accounts_user (user_id),
    CONSTRAINT fk_accounts_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sitzungen: im Cookie liegt der Zufallswert, hier nur dessen SHA-256
CREATE TABLE sessions (
    id          VARCHAR(32) NOT NULL PRIMARY KEY,
    token_hash  CHAR(64)    NOT NULL,
    user_id     VARCHAR(32) NOT NULL,
    csrf_token  CHAR(32)    NOT NULL,
    expires_at  DATETIME    NOT NULL,
    created_at  DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_sessions_token (token_hash),
    KEY idx_sessions_user (user_id),
    CONSTRAINT fk_sessions_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE organizations (
    id               VARCHAR(32)  NOT NULL PRIMARY KEY,
    slug             VARCHAR(64)  NOT NULL,
    name             VARCHAR(120) NOT NULL,
    discord_guild_id VARCHAR(32)  NOT NULL,
    icon_url         VARCHAR(500) NULL,
    member_role_ids  VARCHAR(400) NULL,
    planner_role_ids VARCHAR(400) NULL,
    role_labels      JSON         NULL,
    created_by_id    VARCHAR(32)  NOT NULL,
    created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_orgs_slug (slug),
    UNIQUE KEY uq_orgs_guild (discord_guild_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE org_memberships (
    user_id     VARCHAR(32) NOT NULL,
    org_id      VARCHAR(32) NOT NULL,
    role        VARCHAR(16) NOT NULL DEFAULT 'MEMBER',
    can_plan    TINYINT(1)  NOT NULL DEFAULT 0,
    verified_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, org_id),
    KEY idx_memberships_org (org_id),
    CONSTRAINT fk_memberships_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_memberships_org FOREIGN KEY (org_id) REFERENCES organizations (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Katalog: Schiffe aus der RSI Ship Matrix (source RSI_MATRIX), Rüstungen aus der Wiki-API (WIKI)
CREATE TABLE catalog_items (
    id               VARCHAR(32)  NOT NULL PRIMARY KEY,
    kind             VARCHAR(16)  NOT NULL,
    slug             VARCHAR(120) NOT NULL,
    name             VARCHAR(160) NOT NULL,
    match_key        VARCHAR(190) NOT NULL,
    alt_match_key    VARCHAR(190) NULL,
    code_key         VARCHAR(190) NULL,
    source           VARCHAR(16)  NOT NULL DEFAULT 'WIKI',
    rsi_id           INT          NULL,
    manufacturer     VARCHAR(80)  NULL,
    image_url        VARCHAR(500) NULL,
    image_slug       VARCHAR(120) NULL,
    image_checked_at DATETIME     NULL,
    data             JSON         NULL,
    updated_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_catalog_kind_slug (kind, slug),
    KEY idx_catalog_kind_name (kind, name),
    KEY idx_catalog_match (match_key),
    KEY idx_catalog_alt (alt_match_key),
    KEY idx_catalog_code (code_key),
    KEY idx_catalog_rsi (rsi_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ship_modules (
    id      VARCHAR(32)  NOT NULL PRIMARY KEY,
    ship_id VARCHAR(32)  NOT NULL,
    sort    SMALLINT     NOT NULL DEFAULT 0,
    name    VARCHAR(120) NOT NULL,
    slug    VARCHAR(120) NOT NULL,
    source  VARCHAR(16)  NOT NULL DEFAULT 'FLEETYARDS',
    UNIQUE KEY uq_modules_ship_slug (ship_id, slug),
    CONSTRAINT fk_modules_ship FOREIGN KEY (ship_id) REFERENCES catalog_items (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE owned_items (
    id              VARCHAR(32)  NOT NULL PRIMARY KEY,
    user_id         VARCHAR(32)  NOT NULL,
    catalog_item_id VARCHAR(32)  NULL,
    custom_name     VARCHAR(190) NULL,
    kind            VARCHAR(16)  NOT NULL,
    quantity        INT          NOT NULL DEFAULT 1,
    lti             TINYINT(1)   NOT NULL DEFAULT 0,
    source          VARCHAR(16)  NOT NULL DEFAULT 'MANUAL',
    pledge_name     VARCHAR(250) NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_owned_user (user_id),
    KEY idx_owned_catalog (catalog_item_id),
    CONSTRAINT fk_owned_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_owned_catalog FOREIGN KEY (catalog_item_id) REFERENCES catalog_items (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE achievements (
    id          VARCHAR(32)  NOT NULL PRIMARY KEY,
    `key`       VARCHAR(64)  NOT NULL,
    title       VARCHAR(120) NOT NULL,
    description VARCHAR(400) NOT NULL,
    UNIQUE KEY uq_achievements_key (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_achievements (
    user_id        VARCHAR(32) NOT NULL,
    achievement_id VARCHAR(32) NOT NULL,
    earned_at      DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, achievement_id),
    CONSTRAINT fk_ua_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_ua_ach FOREIGN KEY (achievement_id) REFERENCES achievements (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE api_tokens (
    id           VARCHAR(32)  NOT NULL PRIMARY KEY,
    user_id      VARCHAR(32)  NOT NULL,
    name         VARCHAR(120) NOT NULL,
    token_hash   CHAR(64)     NOT NULL,
    last_used_at DATETIME     NULL,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_api_tokens_hash (token_hash),
    KEY idx_api_tokens_user (user_id),
    CONSTRAINT fk_api_tokens_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE import_logs (
    id         VARCHAR(32) NOT NULL PRIMARY KEY,
    user_id    VARCHAR(32) NOT NULL,
    source     VARCHAR(16) NOT NULL,
    created    INT         NOT NULL,
    updated    INT         NOT NULL,
    unmatched  INT         NOT NULL,
    created_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_import_logs_user (user_id, created_at),
    CONSTRAINT fk_import_logs_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE item_info (
    match_key   VARCHAR(190) NOT NULL PRIMARY KEY,
    found       TINYINT(1)   NOT NULL,
    name        VARCHAR(250) NULL,
    description TEXT         NULL,
    type_label  VARCHAR(120) NULL,
    image_url   VARCHAR(500) NULL,
    web_url     VARCHAR(500) NULL,
    checked_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE events (
    id            VARCHAR(32)  NOT NULL PRIMARY KEY,
    org_id        VARCHAR(32)  NOT NULL,
    title         VARCHAR(160) NOT NULL,
    description   TEXT         NULL,
    location      VARCHAR(160) NULL,
    starts_at     DATETIME     NOT NULL,
    ends_at       DATETIME     NULL,
    status        VARCHAR(16)  NOT NULL DEFAULT 'PLANNED',
    created_by_id VARCHAR(32)  NOT NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_events_org_start (org_id, starts_at),
    CONSTRAINT fk_events_org FOREIGN KEY (org_id) REFERENCES organizations (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE event_ships (
    id              VARCHAR(32)  NOT NULL PRIMARY KEY,
    event_id        VARCHAR(32)  NOT NULL,
    catalog_item_id VARCHAR(32)  NULL,
    custom_name     VARCHAR(190) NULL,
    task            VARCHAR(160) NULL,
    sort            INT          NOT NULL DEFAULT 0,
    KEY idx_event_ships_event (event_id),
    CONSTRAINT fk_event_ships_event FOREIGN KEY (event_id) REFERENCES events (id) ON DELETE CASCADE,
    CONSTRAINT fk_event_ships_catalog FOREIGN KEY (catalog_item_id) REFERENCES catalog_items (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE event_slots (
    id            VARCHAR(32)  NOT NULL PRIMARY KEY,
    event_ship_id VARCHAR(32)  NOT NULL,
    label         VARCHAR(120) NOT NULL,
    user_id       VARCHAR(32)  NULL,
    sort          INT          NOT NULL DEFAULT 0,
    KEY idx_event_slots_ship (event_ship_id),
    CONSTRAINT fk_event_slots_ship FOREIGN KEY (event_ship_id) REFERENCES event_ships (id) ON DELETE CASCADE,
    CONSTRAINT fk_event_slots_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE event_rsvps (
    event_id   VARCHAR(32) NOT NULL,
    user_id    VARCHAR(32) NOT NULL,
    status     VARCHAR(16) NOT NULL,
    updated_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (event_id, user_id),
    KEY idx_rsvps_user (user_id),
    CONSTRAINT fk_rsvps_event FOREIGN KEY (event_id) REFERENCES events (id) ON DELETE CASCADE,
    CONSTRAINT fk_rsvps_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE banned_guilds (
    discord_guild_id VARCHAR(32)  NOT NULL PRIMARY KEY,
    name             VARCHAR(160) NOT NULL,
    reason           VARCHAR(400) NULL,
    banned_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    banned_by_id     VARCHAR(32)  NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Zähler für das Rate-Limit der Import-API (feste Zeitfenster)
CREATE TABLE rate_limits (
    bucket       VARCHAR(190) NOT NULL PRIMARY KEY,
    window_start BIGINT       NOT NULL,
    hits         INT          NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
