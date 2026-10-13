<?php

declare(strict_types=1);

namespace Hangar;

/**
 * Zugangsliste (org_allowed_members): Mitglieder mit den Rollen der Orga. Orga anlegen und Rollen wählen erledigt der
 * Discord-Bot (BOT/, /einrichten); hier laufen der stündliche Abgleich, der Abgleich aus "Orga verwalten" und die
 * Anmelde-Sperre. Mit LOGIN_REQUIRES_ALLOWLIST=1 dürfen sich nur Personen anmelden, die in mindestens einer
 * Zugangsliste stehen (oder Server-Admin sind).
 */
final class Onboarding
{
    /** Ab so vielen Abgängen auf einmal (und mehr als der Hälfte der Liste) wird beim Abgleich nichts gelöscht. */
    public const DELETE_BRAKE_MIN = 5;

    public static function gateEnabled(): bool
    {
        return in_array(strtolower((string) Env::get('LOGIN_REQUIRES_ALLOWLIST', '')), ['1', 'true', 'yes', 'on'], true);
    }

    /** Darf sich diese Discord-ID anmelden? Ohne eingeschalteten Schalter immer ja. */
    public static function mayLogin(?string $discordId): bool
    {
        if (!self::gateEnabled()) {
            return true;
        }
        if ($discordId === null || $discordId === '') {
            return false;
        }
        return in_array($discordId, Config::serverAdminIds(), true)
            || Db::val('SELECT 1 FROM org_allowed_members WHERE discord_id = ? LIMIT 1', [$discordId]) !== null;
    }

    /** Speichert, wo im Server-Nickname der RSI-Handle steht (RsiOrg::HANDLE_STYLES). */
    public static function setHandleStyle(string $orgId, string $style): void
    {
        if (!isset(RsiOrg::HANDLE_STYLES[$style])) {
            throw new OrgError('Ungültige Auswahl.');
        }
        Db::run('UPDATE organizations SET handle_style = ? WHERE id = ?', [$style, $orgId]);
    }

    /**
     * Gleicht die Zugangsliste mit Discord ab: wer eine Nutzungs- oder Planer-Rolle hat, steht drin.
     * Ohne gewählte Rolle passiert nichts (sonst stünde der ganze Server auf der Liste).
     * Wer dadurch auf keiner Zugangsliste mehr steht, verliert sein Konto samt Hangar (nur bei eingeschalteter
     * Anmelde-Sperre). Kommt die Person zurück, legt der nächste Login ein neues Konto an; ein Sync füllt den Hangar.
     * @return array{total:int,added:int,removed:int,deleted:int,skipped:int}
     */
    public static function syncAllowlist(string $orgId): array
    {
        $org = Orgs::find($orgId) ?? throw new OrgError('Orga nicht gefunden.');
        $use = Orgs::parseRoleIds($org['member_role_ids']);
        if ($use === []) {
            throw new OrgError('Wähle zuerst mindestens eine Rolle, die das Tool nutzen darf.');
        }
        $wanted = array_values(array_unique([...$use, ...Orgs::parseRoleIds($org['planner_role_ids']), ...Orgs::parseRoleIds($org['recruiter_role_ids'] ?? null), ...Orgs::parseRoleIds($org['instructor_role_ids'] ?? null), ...Orgs::parseRoleIds($org['lead_role_ids'] ?? null)]));
        $guildMembers = DiscordBot::members((string) $org['discord_guild_id']);
        self::refreshNames($orgId, $guildMembers);
        $members = array_values(array_filter(
            $guildMembers,
            static fn (array $m): bool => array_intersect($wanted, $m['roles']) !== [],
        ));
        $now = Time::nowDb();
        $result = Db::transaction(function () use ($orgId, $members, $now): array {
            $before = array_column(Db::all('SELECT discord_id FROM org_allowed_members WHERE org_id = ?', [$orgId]), 'discord_id');
            $fixed = array_column(Db::all('SELECT discord_id FROM org_allowed_members WHERE org_id = ? AND fixed = 1', [$orgId]), 'discord_id');
            foreach ($members as $m) {
                Db::run(
                    'INSERT INTO org_allowed_members (org_id, discord_id, name, avatar_url, synced_at) VALUES (?,?,?,?,?)
                     ON DUPLICATE KEY UPDATE name = VALUES(name), avatar_url = VALUES(avatar_url), synced_at = VALUES(synced_at)',
                    [$orgId, $m['id'], mb_substr($m['name'], 0, 190), $m['avatar'], $now],
                );
            }
            $ids = array_column($members, 'id');
            $removed = Db::run(
                'DELETE FROM org_allowed_members WHERE org_id = ? AND fixed = 0' . ($ids === [] ? '' : ' AND discord_id NOT IN (' . Db::in($ids) . ')'),
                [$orgId, ...$ids],
            )->rowCount();
            Db::run('UPDATE organizations SET allowlist_synced_at = ? WHERE id = ?', [$now, $orgId]);
            $total = (int) Db::val('SELECT COUNT(*) FROM org_allowed_members WHERE org_id = ?', [$orgId]);
            return ['total' => $total, 'added' => count(array_diff($ids, $before)), 'removed' => $removed, 'before' => count($before), 'gone' => array_values(array_diff($before, $ids, $fixed))];
        });
        $gone = $result['gone'];
        $before = $result['before'];
        unset($result['gone'], $result['before']);
        $deleted = 0;
        $skipped = 0;
        // Eine leere Mitgliederliste ist verdächtig (Discord-Panne?): dann wird nichts gelöscht.
        if ($members !== [] && self::gateEnabled()) {
            if (count($gone) >= self::DELETE_BRAKE_MIN && count($gone) * 2 > $before) {
                // Sicherheitsbremse: so viele auf einmal sehen nach einem Rollenumbau oder Fehler aus, nicht nach Abgängen.
                $skipped = count(self::formerMemberUserIds($gone));
            } else {
                $deleted = self::deleteFormerMembers($gone);
            }
        }
        return $result + ['deleted' => $deleted, 'skipped' => $skipped];
    }

    /**
     * Übernimmt Server-Nickname und Kontoname aller Mitglieder, die schon ein Konto im Tool haben, damit Namensänderungen
     * ohne neue Anmeldung ankommen.
     * @param list<array{id:string,nick:?string,accountName:string}> $guildMembers
     */
    private static function refreshNames(string $orgId, array $guildMembers): void
    {
        foreach ($guildMembers as $m) {
            $nick = $m['nick'] !== null ? mb_substr($m['nick'], 0, 190) : null;
            Db::run(
                'UPDATE org_memberships m JOIN users u ON u.id = m.user_id SET m.nick = ?, u.name = ? WHERE m.org_id = ? AND u.discord_id = ?',
                [$nick, mb_substr($m['accountName'], 0, 190), $orgId, $m['id']],
            );
        }
    }

    /** Kurztext zum Löschen für Meldungen (leer, wenn nichts gelöscht oder übersprungen wurde). @param array{deleted:int,skipped:int} $r */
    public static function deletionNote(array $r): string
    {
        $out = $r['deleted'] > 0 ? ", {$r['deleted']} Konten gelöscht" : '';
        if ($r['skipped'] > 0) {
            $out .= ", {$r['skipped']} Konten NICHT gelöscht (Sicherheitsbremse: zu viele auf einmal; prüfen und dann auf dem Server bin/purge-former-members.php ausführen)";
        }
        return $out;
    }

    /**
     * Konten (Benutzer-IDs) von Personen, die auf keiner Zugangsliste mehr stehen. Server-Admins sind ausgenommen.
     * @param list<string> $discordIds @return list<string>
     */
    private static function formerMemberUserIds(array $discordIds): array
    {
        $out = [];
        foreach ($discordIds as $discordId) {
            if (in_array($discordId, Config::serverAdminIds(), true)
                || Db::val('SELECT 1 FROM org_allowed_members WHERE discord_id = ? LIMIT 1', [$discordId]) !== null) {
                continue;
            }
            $userId = Db::val("SELECT user_id FROM accounts WHERE provider = 'discord' AND provider_account_id = ?", [$discordId]);
            if ($userId !== null) {
                $out[] = (string) $userId;
            }
        }
        return $out;
    }

    /**
     * Löscht alle Konten, deren Discord-ID auf keiner Zugangsliste steht (Server-Admins ausgenommen). Gedacht für den
     * ausdrücklichen Aufruf (bin/purge-former-members.php), etwa nachdem die Sicherheitsbremse beim Abgleich gegriffen hat.
     * @return list<string> Namen der betroffenen Konten
     */
    public static function purgeFormerMembers(bool $dryRun): array
    {
        if (!self::gateEnabled()) {
            throw new OrgError('Die Anmeldesperre (LOGIN_REQUIRES_ALLOWLIST) ist aus; ohne sie gibt es keine „ehemaligen“ Mitglieder.');
        }
        $names = [];
        $rows = Db::all(
            "SELECT a.user_id, a.provider_account_id AS discord_id, u.name FROM accounts a JOIN users u ON u.id = a.user_id
             WHERE a.provider = 'discord' AND a.provider_account_id NOT IN (SELECT discord_id FROM org_allowed_members)",
        );
        foreach ($rows as $r) {
            if (in_array($r['discord_id'], Config::serverAdminIds(), true)) {
                continue;
            }
            $names[] = (string) ($r['name'] ?? $r['discord_id']);
            if (!$dryRun) {
                Db::run('DELETE FROM users WHERE id = ?', [$r['user_id']]);
            }
        }
        return $names;
    }

    /**
     * Löscht die Konten von Personen, die auf keiner Zugangsliste mehr stehen (Server-Admins ausgenommen).
     * @param list<string> $discordIds @return int Anzahl gelöschter Konten
     */
    private static function deleteFormerMembers(array $discordIds): int
    {
        if (!self::gateEnabled()) {
            return 0;
        }
        $deleted = 0;
        foreach (self::formerMemberUserIds($discordIds) as $userId) {
            $deleted += Db::run('DELETE FROM users WHERE id = ?', [$userId])->rowCount();
        }
        return $deleted;
    }

    /** Alle Orgas abgleichen (Cron). Fehler einer Orga stoppen die anderen nicht. @return array<string,string> Orga-Slug => Ergebnis */
    public static function syncAll(): array
    {
        $out = [];
        foreach (Db::all('SELECT id, slug FROM organizations WHERE member_role_ids IS NOT NULL ORDER BY slug') as $o) {
            try {
                $r = self::syncAllowlist($o['id']);
                $out[$o['slug']] = "{$r['total']} auf der Liste (+{$r['added']}, -{$r['removed']})" . self::deletionNote($r);
            } catch (OrgError | DiscordAuthError | DiscordUnavailableError $e) {
                $out[$o['slug']] = 'Fehler: ' . $e->getMessage();
            }
        }
        return $out;
    }
}
