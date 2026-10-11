<?php

declare(strict_types=1);

namespace Hangar;

/** Errungenschaften: aus dem Hangar abgeleitet, wer die Bedingung nicht mehr erfüllt, verliert sie wieder. */
final class Achievements
{
    /** @return list<array{key:string,title:string,description:string,earned:callable(array<string,mixed>):bool}> */
    public static function definitions(): array
    {
        return [
            ['key' => 'first-ship', 'title' => 'Erstes Schiff', 'description' => 'Mindestens ein Schiff im Hangar.',
                'earned' => fn ($s) => $s['distinctShips'] >= 1],
            ['key' => 'fleet-5', 'title' => 'Kleine Flotte', 'description' => '5 verschiedene Schiffe.',
                'earned' => fn ($s) => $s['distinctShips'] >= 5],
            ['key' => 'fleet-10', 'title' => 'Flottenführer', 'description' => '10 verschiedene Schiffe.',
                'earned' => fn ($s) => $s['distinctShips'] >= 10],
            ['key' => 'fleet-25', 'title' => 'Admiral', 'description' => '25 verschiedene Schiffe.',
                'earned' => fn ($s) => $s['distinctShips'] >= 25],
            ['key' => 'capital', 'title' => 'Kapitalschiff-Eigner', 'description' => 'Ein Kapitalschiff (Größenklasse Capital).',
                'earned' => fn ($s) => $s['hasCapitalShip']],
            ['key' => 'lti-3', 'title' => 'Gut versichert', 'description' => '3 Schiffe mit Lifetime Insurance.',
                'earned' => fn ($s) => $s['ltiShips'] >= 3],
            ['key' => 'manufacturers-5', 'title' => 'Markenvielfalt', 'description' => 'Schiffe von 5 verschiedenen Herstellern.',
                'earned' => fn ($s) => $s['manufacturers'] >= 5],
            ['key' => 'armor-5', 'title' => 'Gut gerüstet', 'description' => '5 Rüstungsteile im Hangar.',
                'earned' => fn ($s) => $s['armorPieces'] >= 5],
        ];
    }

    /** Die Ship Matrix liefert size = "capital"; ältere Katalogdaten hatten eine Größenklasse >= 6. */
    private static function isCapital(mixed $data): bool
    {
        if (is_string($data)) {
            $data = json_decode($data, true);
        }
        if (!is_array($data)) {
            return false;
        }
        $size = $data['size'] ?? null;
        $class = $data['sizeClass'] ?? null;
        return (is_string($size) && strtolower($size) === 'capital') || (is_int($class) || is_float($class)) && $class >= 6;
    }

    /**
     * @param list<array{kind:string,quantity:int,lti:bool,customName:?string,catalogItem:?array{id:string,manufacturer:?string,data:mixed}}> $items
     * @return array{distinctShips:int,ltiShips:int,manufacturers:int,armorPieces:int,hasCapitalShip:bool}
     */
    public static function computeStats(array $items): array
    {
        $ships = array_values(array_filter($items, fn ($i) => $i['kind'] === 'SHIP'));
        $distinct = [];
        $manufacturers = [];
        $lti = 0;
        $capital = false;
        foreach ($ships as $i) {
            $distinct[$i['catalogItem']['id'] ?? 'custom:' . $i['customName']] = true;
            if (!empty($i['catalogItem']['manufacturer'])) {
                $manufacturers[$i['catalogItem']['manufacturer']] = true;
            }
            if ($i['lti']) {
                $lti += $i['quantity'];
            }
            if (self::isCapital($i['catalogItem']['data'] ?? null)) {
                $capital = true;
            }
        }
        $armor = 0;
        foreach ($items as $i) {
            if ($i['kind'] === 'ARMOR') {
                $armor += $i['quantity'];
            }
        }
        return [
            'distinctShips' => count($distinct),
            'ltiShips' => $lti,
            'manufacturers' => count($manufacturers),
            'armorPieces' => $armor,
            'hasCapitalShip' => $capital,
        ];
    }

    /** @param array<string,mixed> $stats @return list<string> */
    public static function earnedKeys(array $stats): array
    {
        $keys = [];
        foreach (self::definitions() as $a) {
            if (($a['earned'])($stats)) {
                $keys[] = $a['key'];
            }
        }
        return $keys;
    }

    /** Berechnet die Errungenschaften eines Nutzers neu. @return list<string> die verdienten Schlüssel */
    public static function recompute(string $userId): array
    {
        $rows = Db::all(
            'SELECT o.kind, o.quantity, o.lti, o.custom_name, c.id AS cid, c.manufacturer, c.data
               FROM owned_items o LEFT JOIN catalog_items c ON c.id = o.catalog_item_id
              WHERE o.user_id = ?',
            [$userId],
        );
        $items = array_map(fn ($r) => [
            'kind' => $r['kind'],
            'quantity' => (int) $r['quantity'],
            'lti' => (bool) $r['lti'],
            'customName' => $r['custom_name'],
            'catalogItem' => $r['cid'] === null ? null : ['id' => $r['cid'], 'manufacturer' => $r['manufacturer'], 'data' => $r['data']],
        ], $rows);
        $keys = self::earnedKeys(self::computeStats($items));

        // Definitionen in der Datenbank aktuell halten.
        foreach (self::definitions() as $a) {
            Db::run(
                'INSERT INTO achievements (id, `key`, title, description) VALUES (?,?,?,?)
                 ON DUPLICATE KEY UPDATE title = VALUES(title), description = VALUES(description)',
                [new_id(), $a['key'], $a['title'], $a['description']],
            );
        }
        $defs = Db::all('SELECT id, `key` FROM achievements');
        $earnedIds = [];
        foreach ($defs as $d) {
            if (in_array($d['key'], $keys, true)) {
                $earnedIds[$d['id']] = true;
            }
        }
        $current = array_column(Db::all('SELECT achievement_id FROM user_achievements WHERE user_id = ?', [$userId]), 'achievement_id');
        $currentIds = array_flip($current);

        foreach (array_keys($earnedIds) as $id) {
            if (!isset($currentIds[$id])) {
                Db::run('INSERT INTO user_achievements (user_id, achievement_id) VALUES (?,?)', [$userId, $id]);
            }
        }
        foreach ($current as $id) {
            if (!isset($earnedIds[$id])) {
                Db::run('DELETE FROM user_achievements WHERE user_id = ? AND achievement_id = ?', [$userId, $id]);
            }
        }
        return $keys;
    }
}
