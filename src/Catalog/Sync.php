<?php

declare(strict_types=1);

namespace Hangar\Catalog;

use Hangar\Achievements;
use Hangar\Db;

/** Gesamter Katalog-Abgleich: Matrix → (FleetYards-Ergänzung) → Rüstungen → Hangar neu verknüpfen. */
final class Sync
{
    /**
     * @return list<array{kind:string,fetched:int,saved:int,skipped:int,note:?string}>
     * @throws CatalogError wenn die Ship Matrix nicht brauchbar ist (dann bleibt der Katalog unverändert)
     */
    public static function run(?string $matrixUrl = null, int $minShips = ShipMatrix::MIN_SHIPS): array
    {
        $results = [self::shipStep($matrixUrl, $minShips)];

        // 4. Rüstungen aus der Wiki-API (Fehler hier lassen die Schiffe unberührt)
        try {
            $armorRaw = WikiArmor::fetch();
            $armor = array_values(array_filter(array_map([WikiArmor::class, 'map'], $armorRaw)));
            Store::save($armor);
            $results[] = ['kind' => 'ARMOR', 'fetched' => count($armorRaw), 'saved' => count($armor), 'skipped' => count($armorRaw) - count($armor), 'note' => null];
        } catch (CatalogError $e) {
            $results[] = ['kind' => 'ARMOR', 'fetched' => 0, 'saved' => 0, 'skipped' => 0, 'note' => 'Wiki-API-Fehler: ' . $e->getMessage()];
        }

        // 5. Hangar-Einträge ohne Katalogbezug mit dem (neuen) Katalog verknüpfen
        $linked = Relink::orphans();
        if ($linked > 0) {
            foreach (Db::all('SELECT DISTINCT user_id FROM owned_items') as $u) {
                Achievements::recompute($u['user_id']);
            }
            $last = count($results) - 1;
            $results[$last]['note'] = trim(($results[$last]['note'] ?? '') . " $linked Hangar-Einträge neu verknüpft");
        }
        return $results;
    }

    /**
     * Nur die Schiffe (Matrix, FleetYards, Module) und die Verknüpfung unverknüpfter Schiffe im Hangar.
     * @return list<array{kind:string,fetched:int,saved:int,skipped:int,note:?string}>
     */
    public static function ships(?string $matrixUrl = null, int $minShips = ShipMatrix::MIN_SHIPS): array
    {
        $result = self::shipStep($matrixUrl, $minShips);
        $linked = Relink::orphans(['SHIP']);
        if ($linked > 0) {
            foreach (Db::all('SELECT DISTINCT user_id FROM owned_items') as $u) {
                Achievements::recompute($u['user_id']);
            }
            $result['note'] = trim(($result['note'] ?? '') . " $linked Hangar-Einträge neu verknüpft");
        }
        return [$result];
    }

    /** @return array{kind:string,fetched:int,saved:int,skipped:int,note:?string} */
    private static function shipStep(?string $matrixUrl, int $minShips): array
    {
        // 1. Schiffe aus der Ship Matrix (Pflicht)
        $raw = ShipMatrix::fetch($matrixUrl, $minShips);
        $ships = ShipMatrix::mapAll($raw);

        // 2. Ergänzung durch FleetYards (optional)
        $note = null;
        try {
            $ships = FleetYards::enrich($ships, FleetYards::fetchModels());
        } catch (CatalogError $e) {
            $note = 'FleetYards nicht erreichbar, Katalog ohne Ergänzung: ' . $e->getMessage();
        }
        $ids = Store::save($ships);

        // 3. Module der Schiffe, die welche mitbringen (optional)
        $moduleShips = 0;
        try {
            foreach ($ships as $s) {
                if (!empty($s['has_modules']) && !empty($s['image_slug'])) {
                    Store::saveModules($ids['SHIP:' . $s['slug']], FleetYards::fetchModules($s['image_slug']));
                    $moduleShips++;
                }
            }
        } catch (CatalogError $e) {
            $note = trim(($note ?? '') . ' Module nicht vollständig: ' . $e->getMessage());
        }
        return [
            'kind' => 'SHIP', 'fetched' => count($raw), 'saved' => count($ships), 'skipped' => count($raw) - count($ships),
            'note' => trim(($note ?? '') . ($moduleShips > 0 ? " Module für $moduleShips Schiffe." : '')) ?: null,
        ];
    }
}
