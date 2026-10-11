<?php

declare(strict_types=1);

namespace Hangar\Migration;

use Hangar\Achievements;
use Hangar\Db;
use Hangar\Import\Matcher;
use PDO;

/**
 * Einmalige Übernahme der Daten der bisherigen Next.js-Version (SQLite, Prisma) nach MariaDB.
 *
 * Übernommen werden Nutzer, Discord-Konten (Tokens), Orgas, Mitgliedschaften, Hangar, Import-Protokolle,
 * Item-Infos, Events samt Schiffen/Plätzen/Zusagen, API-Tokens und Sperrliste. Nicht übernommen werden
 * Sitzungen (alle melden sich einmal neu an) und der Katalog: Er wird frisch aus der Ship Matrix
 * aufgebaut (php bin/catalog-sync.php), danach ordnet dieses Skript Hangar- und Event-Schiffe über den
 * Namen neu zu. Schiffe, die es im neuen Katalog nicht gibt, behalten ihren Namen als freien Namen.
 * Errungenschaften werden neu berechnet, ihr Datum bleibt erhalten.
 */
final class SqliteImport
{
    /** @var array<string,int> */
    private array $counts = [];
    /** @var list<string> */
    private array $notes = [];

    public function __construct(private readonly PDO $src)
    {
        $this->src->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->src->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }

    /**
     * @return array{counts:array<string,array{source:int,imported:int}>,notes:list<string>}
     * @throws \RuntimeException wenn das Ziel nicht leer ist oder der Katalog fehlt
     */
    public function run(bool $dryRun = false, int $minShips = 100): array
    {
        if ((int) Db::val('SELECT COUNT(*) FROM users') > 0 || (int) Db::val('SELECT COUNT(*) FROM organizations') > 0) {
            throw new \RuntimeException('Die Zieldatenbank enthält schon Nutzer oder Orgas. Abbruch, damit nichts doppelt oder überschrieben wird.');
        }
        $ships = (int) Db::val("SELECT COUNT(*) FROM catalog_items WHERE kind = 'SHIP'");
        if ($ships < $minShips) {
            throw new \RuntimeException("Der neue Katalog hat nur $ships Schiffe. Erst php bin/catalog-sync.php ausführen, damit Hangar-Einträge zugeordnet werden können.");
        }

        $sourceRows = [];
        $result = Db::transaction(function () use ($dryRun, &$sourceRows): array {
            $index = Matcher::buildIndex(Db::all("SELECT id, kind, slug, name, match_key, alt_match_key, code_key, manufacturer FROM catalog_items WHERE kind IN ('SHIP','ARMOR')"));
            $oldCatalog = [];
            foreach ($this->rows('SELECT id, kind, name FROM CatalogItem') as $c) {
                $oldCatalog[$c['id']] = $c;
            }

            $this->users($sourceRows);
            $this->simple('Account', 'accounts', $sourceRows, fn ($r) => [
                'id' => $r['id'], 'user_id' => $r['userId'], 'provider' => $r['provider'], 'provider_account_id' => $r['providerAccountId'],
                'access_token' => $r['access_token'], 'refresh_token' => $r['refresh_token'], 'expires_at' => $r['expires_at'],
                'token_type' => $r['token_type'], 'scope' => $r['scope'],
            ], "provider = 'discord'");
            $this->simple('Organization', 'organizations', $sourceRows, fn ($r) => [
                'id' => $r['id'], 'slug' => $r['slug'], 'name' => $r['name'], 'discord_guild_id' => $r['discordGuildId'],
                'icon_url' => $r['iconUrl'], 'member_role_ids' => $r['memberRoleIds'], 'planner_role_ids' => $r['plannerRoleIds'],
                'role_labels' => $this->json($r['roleLabels']), 'created_by_id' => $r['createdById'], 'created_at' => $this->date($r['createdAt']),
            ]);
            $this->simple('OrgMembership', 'org_memberships', $sourceRows, fn ($r) => [
                'user_id' => $r['userId'], 'org_id' => $r['orgId'], 'role' => $r['role'], 'can_plan' => (int) $r['canPlan'],
                'verified_at' => $this->date($r['verifiedAt']),
            ]);
            $this->ownedItems($sourceRows, $oldCatalog, $index);
            $this->simple('ImportLog', 'import_logs', $sourceRows, fn ($r) => [
                'id' => $r['id'], 'user_id' => $r['userId'], 'source' => $r['source'], 'created' => $r['created'],
                'updated' => $r['updated'], 'unmatched' => $r['unmatched'], 'created_at' => $this->date($r['createdAt']),
            ]);
            $this->simple('ItemInfo', 'item_info', $sourceRows, fn ($r) => [
                'match_key' => $r['matchKey'], 'found' => (int) $r['found'], 'name' => $r['name'], 'description' => $r['description'],
                'type_label' => $r['typeLabel'], 'image_url' => $r['imageUrl'], 'web_url' => $r['webUrl'], 'checked_at' => $this->date($r['checkedAt']),
            ]);
            $this->simple('ApiToken', 'api_tokens', $sourceRows, fn ($r) => [
                'id' => $r['id'], 'user_id' => $r['userId'], 'name' => $r['name'], 'token_hash' => $r['tokenHash'],
                'last_used_at' => $this->date($r['lastUsedAt']), 'created_at' => $this->date($r['createdAt']),
            ]);
            $this->simple('BannedGuild', 'banned_guilds', $sourceRows, fn ($r) => [
                'discord_guild_id' => $r['discordGuildId'], 'name' => $r['name'], 'reason' => $r['reason'],
                'banned_at' => $this->date($r['bannedAt']), 'banned_by_id' => $r['bannedById'],
            ]);
            $this->events($sourceRows, $oldCatalog, $index);
            $this->achievements($sourceRows);

            $report = [];
            foreach ($sourceRows as $table => $n) {
                $report[$table] = ['source' => $n, 'imported' => $this->counts[$table] ?? 0];
            }
            if ($dryRun) {
                throw new DryRunDone($report);
            }
            return $report;
        });
        return ['counts' => $result, 'notes' => $this->notes];
    }

    /** Trockenlauf: alles wird ausgeführt und am Ende zurückgerollt. @return array{counts:array<string,array{source:int,imported:int}>,notes:list<string>} */
    public function dryRun(int $minShips = 100): array
    {
        try {
            $this->run(true, $minShips);
        } catch (DryRunDone $d) {
            return ['counts' => $d->report, 'notes' => $this->notes];
        }
        throw new \LogicException('Trockenlauf wurde nicht zurückgerollt');
    }

    // --- Hilfen ------------------------------------------------------------------------------

    /** @return list<array<string,mixed>> */
    private function rows(string $sql): array
    {
        return $this->src->query($sql)->fetchAll();
    }

    /** Prisma speichert DateTime in SQLite als Millisekunden seit 1970 (selten auch als Text). */
    private function date(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_numeric($v)) {
            $ms = (float) $v;
            // Sekunden statt Millisekunden erkennen
            $sec = $ms > 100000000000 ? $ms / 1000 : $ms;
            return gmdate('Y-m-d H:i:s', (int) floor($sec));
        }
        $t = strtotime((string) $v . (preg_match('/[zZ]|[+-]\d{2}:?\d{2}$/', (string) $v) ? '' : ' UTC'));
        return $t === false ? null : gmdate('Y-m-d H:i:s', $t);
    }

    private function json(mixed $v): ?string
    {
        if (!is_string($v) || $v === '') {
            return null;
        }
        json_decode($v);
        return json_last_error() === JSON_ERROR_NONE ? $v : null;
    }

    private function count(string $table, int $n = 1): void
    {
        $this->counts[$table] = ($this->counts[$table] ?? 0) + $n;
    }

    /** @param array<string,int> $sourceRows */
    private function users(array &$sourceRows): void
    {
        $rows = $this->rows('SELECT * FROM User');
        $sourceRows['users'] = count($rows);
        foreach ($rows as $r) {
            Db::insert('users', [
                'id' => $r['id'], 'name' => $r['name'], 'image' => $r['image'], 'discord_id' => $r['discordId'],
                'rsi_handle' => $r['rsiHandle'], 'hangar_visibility' => $r['hangarVisibility'] ?? 'MEMBERS',
                'achievements_visibility' => $r['achievementsVisibility'] ?? 'MEMBERS',
                'membership_checked_at' => $this->date($r['membershipCheckedAt']), 'membership_status' => $r['membershipStatus'],
                'created_at' => $this->date($r['createdAt']) ?? gmdate('Y-m-d H:i:s'),
            ]);
            $this->count('users');
        }
    }

    /**
     * @param array<string,int> $sourceRows
     * @param callable(array<string,mixed>):array<string,mixed> $map
     */
    private function simple(string $from, string $into, array &$sourceRows, callable $map, string $where = '1'): void
    {
        $rows = $this->rows("SELECT * FROM $from WHERE $where");
        $sourceRows[$into] = count($rows);
        foreach ($rows as $r) {
            $row = $map($r);
            foreach (['created_at', 'verified_at', 'checked_at', 'banned_at'] as $k) {
                if (array_key_exists($k, $row) && $row[$k] === null) {
                    unset($row[$k]);   // Standardwert der Datenbank
                }
            }
            Db::insert($into, $row);
            $this->count($into);
        }
    }

    /**
     * Ordnet einen alten Katalogeintrag dem neuen Katalog zu (über den Namen).
     * @param array<string,array<string,mixed>> $oldCatalog
     * @param array<string,list<array<string,mixed>>> $index
     * @return array{0:?string,1:?string} neue Katalog-ID und ggf. freier Name
     */
    private function remap(?string $oldId, ?string $customName, string $kind, array $oldCatalog, array $index): array
    {
        if ($oldId === null) {
            return [null, $customName];
        }
        $old = $oldCatalog[$oldId] ?? null;
        if ($old === null) {
            return [null, $customName];
        }
        $hit = in_array($kind, ['SHIP', 'ARMOR'], true) ? Matcher::find($index, $kind, ['title' => (string) $old['name']]) : null;
        if ($hit !== null) {
            return [$hit['id'], null];
        }
        $this->notes[] = "Nicht im neuen Katalog, als freier Name übernommen: {$old['name']}";
        return [null, (string) $old['name']];
    }

    /**
     * @param array<string,int> $sourceRows
     * @param array<string,array<string,mixed>> $oldCatalog
     * @param array<string,list<array<string,mixed>>> $index
     */
    private function ownedItems(array &$sourceRows, array $oldCatalog, array $index): void
    {
        $rows = $this->rows('SELECT * FROM OwnedItem');
        $sourceRows['owned_items'] = count($rows);
        foreach ($rows as $r) {
            [$catalogId, $custom] = $this->remap($r['catalogItemId'], $r['customName'], $r['kind'], $oldCatalog, $index);
            Db::insert('owned_items', [
                'id' => $r['id'], 'user_id' => $r['userId'], 'catalog_item_id' => $catalogId, 'custom_name' => $custom,
                'kind' => $r['kind'], 'quantity' => $r['quantity'], 'lti' => (int) $r['lti'], 'source' => $r['source'],
                'pledge_name' => $r['pledgeName'],
                'created_at' => $this->date($r['createdAt']) ?? gmdate('Y-m-d H:i:s'),
                'updated_at' => $this->date($r['updatedAt']) ?? gmdate('Y-m-d H:i:s'),
            ]);
            $this->count('owned_items');
        }
    }

    /**
     * @param array<string,int> $sourceRows
     * @param array<string,array<string,mixed>> $oldCatalog
     * @param array<string,list<array<string,mixed>>> $index
     */
    private function events(array &$sourceRows, array $oldCatalog, array $index): void
    {
        $events = $this->rows('SELECT * FROM Event');
        $sourceRows['events'] = count($events);
        foreach ($events as $r) {
            Db::insert('events', [
                'id' => $r['id'], 'org_id' => $r['orgId'], 'title' => $r['title'], 'description' => $r['description'],
                'location' => $r['location'], 'starts_at' => $this->date($r['startsAt']), 'ends_at' => $this->date($r['endsAt']),
                'status' => $r['status'], 'created_by_id' => $r['createdById'],
                'created_at' => $this->date($r['createdAt']) ?? gmdate('Y-m-d H:i:s'),
                'updated_at' => $this->date($r['updatedAt']) ?? gmdate('Y-m-d H:i:s'),
            ]);
            $this->count('events');
        }
        $ships = $this->rows('SELECT * FROM EventShip');
        $sourceRows['event_ships'] = count($ships);
        foreach ($ships as $r) {
            [$catalogId, $custom] = $this->remap($r['catalogItemId'], $r['customName'], 'SHIP', $oldCatalog, $index);
            Db::insert('event_ships', [
                'id' => $r['id'], 'event_id' => $r['eventId'], 'catalog_item_id' => $catalogId, 'custom_name' => $custom,
                'task' => $r['task'], 'sort' => $r['sort'],
            ]);
            $this->count('event_ships');
        }
        $this->simple('EventSlot', 'event_slots', $sourceRows, fn ($r) => [
            'id' => $r['id'], 'event_ship_id' => $r['eventShipId'], 'label' => $r['label'], 'user_id' => $r['userId'], 'sort' => $r['sort'],
        ]);
        $this->simple('EventRsvp', 'event_rsvps', $sourceRows, fn ($r) => [
            'event_id' => $r['eventId'], 'user_id' => $r['userId'], 'status' => $r['status'], 'updated_at' => $this->date($r['updatedAt']),
        ]);
    }

    /** @param array<string,int> $sourceRows */
    private function achievements(array &$sourceRows): void
    {
        $old = $this->rows('SELECT ua.userId, ua.earnedAt, a.key FROM UserAchievement ua JOIN Achievement a ON a.id = ua.achievementId');
        $sourceRows['user_achievements'] = count($old);
        foreach (array_column(Db::all('SELECT id FROM users'), 'id') as $userId) {
            Achievements::recompute($userId);
        }
        foreach ($old as $r) {
            $n = Db::exec(
                'UPDATE user_achievements ua JOIN achievements a ON a.id = ua.achievement_id
                    SET ua.earned_at = ? WHERE ua.user_id = ? AND a.`key` = ?',
                [$this->date($r['earnedAt']), $r['userId'], $r['key']],
            );
            $this->count('user_achievements', $n);
        }
    }
}
