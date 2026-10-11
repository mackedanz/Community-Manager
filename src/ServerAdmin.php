<?php

declare(strict_types=1);

namespace Hangar;

/** Betreiber-Bereich: Orgas löschen, Discord-Server sperren und entsperren. */
final class ServerAdmin
{
    /** Ob der Nutzer (per Discord-ID) in SERVER_ADMIN_DISCORD_ID steht. Ohne Wert gibt es keinen Admin-Bereich. */
    public static function isAdmin(?string $userId): bool
    {
        $admins = Config::serverAdminIds();
        if ($userId === null || $userId === '' || $admins === []) {
            return false;
        }
        $discordId = Db::val('SELECT discord_id FROM users WHERE id = ?', [$userId]);
        return is_string($discordId) && $discordId !== '' && in_array($discordId, $admins, true);
    }

    private static function require(string $userId): void
    {
        if (!self::isAdmin($userId)) {
            throw new OrgError('Nur der Server-Admin darf das.');
        }
    }

    /** @return list<array<string,mixed>> */
    public static function listAllOrgs(string $userId): array
    {
        self::require($userId);
        return Db::all(
            'SELECT o.id, o.name, o.slug, o.discord_guild_id, o.icon_url, o.created_at,
                    (SELECT COUNT(*) FROM org_memberships m WHERE m.org_id = o.id) AS member_count,
                    (SELECT COUNT(*) FROM events e WHERE e.org_id = o.id) AS event_count
               FROM organizations o ORDER BY o.name ASC',
        );
    }

    /** @return list<array<string,mixed>> */
    public static function listBans(string $userId): array
    {
        self::require($userId);
        return Db::all('SELECT * FROM banned_guilds ORDER BY banned_at DESC');
    }

    /** Löscht die Orga samt Mitgliedschaften und Events. Konten und Hangars bleiben. */
    public static function deleteOrg(string $userId, string $orgId): void
    {
        self::require($userId);
        Db::run('DELETE FROM organizations WHERE id = ?', [$orgId]);
    }

    /** Sperrt den Discord-Server und löscht dafür eine vorhandene Orga. */
    public static function banGuild(string $userId, string $guildId, string $name, ?string $reason = null): void
    {
        self::require($userId);
        $id = trim($guildId);
        if (!preg_match('/^\d{5,25}$/', $id)) {
            throw new OrgError('Ungültige Discord-Server-ID.');
        }
        $orgName = Db::val('SELECT name FROM organizations WHERE discord_guild_id = ?', [$id]);
        $label = trim(mb_substr(trim((string) ($orgName ?? $name)), 0, 100));
        $label = $label !== '' ? $label : $id;
        $note = $reason !== null ? mb_substr(trim($reason), 0, 500) : '';
        Db::transaction(function () use ($id, $label, $note, $userId): void {
            Db::run(
                'INSERT INTO banned_guilds (discord_guild_id, name, reason, banned_by_id) VALUES (?,?,?,?)
                 ON DUPLICATE KEY UPDATE name = VALUES(name), reason = VALUES(reason)',
                [$id, $label, $note !== '' ? $note : null, $userId],
            );
            Db::run('DELETE FROM organizations WHERE discord_guild_id = ?', [$id]);
        });
    }

    public static function unbanGuild(string $userId, string $guildId): void
    {
        self::require($userId);
        Db::run('DELETE FROM banned_guilds WHERE discord_guild_id = ?', [$guildId]);
    }

    public static function isGuildBanned(string $guildId): bool
    {
        return Db::val('SELECT 1 FROM banned_guilds WHERE discord_guild_id = ?', [$guildId]) !== null;
    }
}
