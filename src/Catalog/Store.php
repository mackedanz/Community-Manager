<?php

declare(strict_types=1);

namespace Hangar\Catalog;

use Hangar\Db;

/** Schreibt Katalogeinträge (Upsert über kind + slug) und Schiffsmodule. */
final class Store
{
    /**
     * @param list<array<string,mixed>> $records
     * @return array<string,string> Zuordnung "kind:slug" → Datenbank-ID
     */
    public static function save(array $records): array
    {
        $ids = [];
        foreach (array_chunk($records, 200) as $chunk) {
            Db::transaction(function () use ($chunk, &$ids): void {
                foreach ($chunk as $r) {
                    self::renameByRsiId($r);
                    $data = json_encode($r['data'] ?? new \stdClass(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    Db::run(
                        'INSERT INTO catalog_items
                           (id, kind, slug, name, match_key, alt_match_key, code_key, source, rsi_id, manufacturer, image_url, image_slug, data)
                         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
                         ON DUPLICATE KEY UPDATE
                           name = VALUES(name), match_key = VALUES(match_key),
                           alt_match_key = COALESCE(VALUES(alt_match_key), alt_match_key),
                           code_key = COALESCE(VALUES(code_key), code_key),
                           source = VALUES(source), rsi_id = VALUES(rsi_id), manufacturer = VALUES(manufacturer),
                           image_url = COALESCE(VALUES(image_url), image_url),
                           image_slug = COALESCE(VALUES(image_slug), image_slug),
                           data = VALUES(data)',
                        [
                            new_id(), $r['kind'], $r['slug'], $r['name'], $r['match_key'], $r['alt_match_key'] ?? null,
                            $r['code_key'] ?? null, $r['source'], $r['rsi_id'] ?? null, $r['manufacturer'] ?? null,
                            $r['image_url'] ?? null, $r['image_slug'] ?? null, $data,
                        ],
                    );
                    $ids[$r['kind'] . ':' . $r['slug']] = (string) Db::val('SELECT id FROM catalog_items WHERE kind = ? AND slug = ?', [$r['kind'], $r['slug']]);
                }
            });
        }
        return $ids;
    }

    /** Wurde ein Schiff umbenannt (gleiche RSI-ID, neuer Slug), behält die Zeile ihre ID und damit alle Hangar-Verknüpfungen. */
    private static function renameByRsiId(array $r): void
    {
        if ($r['kind'] !== 'SHIP' || empty($r['rsi_id'])) {
            return;
        }
        $existing = Db::one('SELECT id, slug FROM catalog_items WHERE kind = ? AND rsi_id = ?', ['SHIP', $r['rsi_id']]);
        if ($existing !== null && $existing['slug'] !== $r['slug']
            && Db::val('SELECT 1 FROM catalog_items WHERE kind = ? AND slug = ?', ['SHIP', $r['slug']]) === null) {
            Db::run('UPDATE catalog_items SET slug = ? WHERE id = ?', [$r['slug'], $existing['id']]);
        }
    }

    /**
     * Ersetzt die Module eines Schiffs: neue werden angelegt, vorhandene aktualisiert, entfallene gelöscht.
     * @param list<array{name:string,slug:string}> $modules
     */
    public static function saveModules(string $shipId, array $modules, string $source = 'FLEETYARDS'): void
    {
        Db::transaction(function () use ($shipId, $modules, $source): void {
            $keep = [];
            foreach ($modules as $i => $m) {
                $keep[] = $m['slug'];
                Db::run(
                    'INSERT INTO ship_modules (id, ship_id, sort, name, slug, source) VALUES (?,?,?,?,?,?)
                     ON DUPLICATE KEY UPDATE sort = VALUES(sort), name = VALUES(name), source = VALUES(source)',
                    [new_id(), $shipId, $i, $m['name'], $m['slug'], $source],
                );
            }
            if ($keep === []) {
                Db::run('DELETE FROM ship_modules WHERE ship_id = ?', [$shipId]);
            } else {
                Db::run('DELETE FROM ship_modules WHERE ship_id = ? AND slug NOT IN (' . Db::in($keep) . ')', [$shipId, ...$keep]);
            }
        });
    }
}
