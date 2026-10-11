<?php

declare(strict_types=1);

namespace Hangar;

/**
 * Der Hangar besteht aus importierten Einträgen (RSI-Sync, siehe Import\Importer; jeder Sync ersetzt
 * sie) und manuell hinzugefügten (source MANUAL, bleiben bei Syncs erhalten).
 */
final class Hangar
{
    /**
     * Alle Einträge eines Nutzers, mit Katalogdaten und (bei Loot/Ausrüstung) Zusatzinfos aus der Wiki-API.
     * @return list<array<string,mixed>>
     */
    public static function listHangar(string $userId): array
    {
        $rows = Db::all(
            'SELECT o.*, c.id AS c_id, c.kind AS c_kind, c.slug AS c_slug, c.name AS c_name, c.manufacturer AS c_manufacturer,
                    c.image_url AS c_image_url, c.data AS c_data
               FROM owned_items o LEFT JOIN catalog_items c ON c.id = o.catalog_item_id
              WHERE o.user_id = ? ORDER BY o.kind ASC, o.created_at ASC, o.id ASC',
            [$userId],
        );

        $keys = [];
        foreach ($rows as $r) {
            if ($r['kind'] === 'ITEM' && $r['c_id'] === null && $r['custom_name'] !== null) {
                $keys[Text::normalizeName($r['custom_name'])] = true;
            }
        }
        $infos = [];
        if ($keys !== []) {
            $k = array_keys($keys);
            foreach (Db::all('SELECT * FROM item_info WHERE found = 1 AND match_key IN (' . Db::in($k) . ')', $k) as $i) {
                $infos[$i['match_key']] = $i;
            }
        }

        return array_map(function ($r) use ($infos) {
            $catalog = $r['c_id'] === null ? null : [
                'id' => $r['c_id'], 'kind' => $r['c_kind'], 'slug' => $r['c_slug'], 'name' => $r['c_name'],
                'manufacturer' => $r['c_manufacturer'], 'image_url' => $r['c_image_url'], 'data' => $r['c_data'],
                'imageSrc' => Community::imageSrc($r['c_kind'], $r['c_slug'], $r['c_image_url']),
            ];
            return [
                'id' => $r['id'], 'userId' => $r['user_id'], 'kind' => $r['kind'], 'quantity' => (int) $r['quantity'],
                'lti' => (bool) $r['lti'], 'source' => $r['source'], 'customName' => $r['custom_name'],
                'pledgeName' => $r['pledge_name'], 'createdAt' => $r['created_at'],
                'catalogItem' => $catalog,
                'info' => $r['kind'] === 'ITEM' && $r['custom_name'] !== null ? ($infos[Text::normalizeName($r['custom_name'])] ?? null) : null,
            ];
        }, $rows);
    }

    /** @param array{catalogItem:?array{name:string},customName:?string} $entry */
    public static function entryName(array $entry): string
    {
        return $entry['catalogItem']['name'] ?? $entry['customName'] ?? 'Unbekannt';
    }

    /** Volltextsuche im eigenen Hangar: Name, Hersteller, Typ, Pledge-Name und Katalogdaten. */
    public static function matchesText(array $entry, string $q): bool
    {
        $c = $entry['catalogItem'] ?? null;
        $s = FleetFilter::parseSpecs($c['data'] ?? null);
        return Text::matchesQuery([
            self::entryName($entry), $c['manufacturer'] ?? null, $entry['info']['type_label'] ?? null,
            $entry['pledgeName'] ?? null, $entry['lti'] ? 'LTI' : null,
            $s['career'], $s['role'], $s['status'], $s['sizeLabel'], $s['size'],
        ], $q);
    }

    /**
     * @template T of array{kind:string}
     * @param list<T> $entries
     * @return array<string,list<T>> nach Typ gruppiert, in der Reihenfolge des ersten Auftretens
     */
    public static function groupByKind(array $entries): array
    {
        $groups = [];
        foreach ($entries as $e) {
            $groups[$e['kind']][] = $e;
        }
        return $groups;
    }

    /**
     * Fügt ein Katalogobjekt manuell hinzu. Gleiche manuelle Einträge (Objekt + LTI) werden addiert.
     * @throws HangarError
     */
    public static function addItem(string $userId, string $catalogItemId, mixed $quantity, bool $lti): void
    {
        if ($catalogItemId === '') {
            throw new HangarError('Bitte ein Schiff auswählen.');
        }
        if (!is_numeric($quantity) || (float) $quantity != (int) $quantity) {
            throw new HangarError('Bitte eine ganze Zahl angeben.');
        }
        $qty = (int) $quantity;
        if ($qty < 1) {
            throw new HangarError('Mindestens 1.');
        }
        if ($qty > 99) {
            throw new HangarError('Höchstens 99.');
        }
        $item = Db::one('SELECT id, kind FROM catalog_items WHERE id = ?', [$catalogItemId]);
        if ($item === null || !in_array($item['kind'], ['SHIP', 'ARMOR'], true)) {
            throw new HangarError('Dieses Objekt gibt es im Katalog nicht.');
        }

        $existing = Db::one(
            "SELECT id, quantity FROM owned_items WHERE user_id = ? AND source = 'MANUAL' AND catalog_item_id = ? AND lti = ? LIMIT 1",
            [$userId, $item['id'], $lti],
        );
        if ($existing !== null) {
            Db::run('UPDATE owned_items SET quantity = ? WHERE id = ?', [min(99, (int) $existing['quantity'] + $qty), $existing['id']]);
        } else {
            Db::insert('owned_items', [
                'id' => new_id(), 'user_id' => $userId, 'catalog_item_id' => $item['id'], 'kind' => $item['kind'],
                'quantity' => $qty, 'lti' => $lti ? 1 : 0, 'source' => 'MANUAL',
            ]);
        }
        Achievements::recompute($userId);
    }

    /**
     * Entfernt einen Eintrag aus dem eigenen Hangar. Importierte Einträge kehren beim nächsten
     * RSI-Sync zurück, weil RSI die Quelle der Wahrheit ist.
     */
    public static function removeItem(string $userId, string $itemId): bool
    {
        $n = Db::exec('DELETE FROM owned_items WHERE id = ? AND user_id = ?', [$itemId, $userId]);
        if ($n > 0) {
            Achievements::recompute($userId);
        }
        return $n > 0;
    }

    /** Entfernt alle eigenen Einträge eines Typs (importierte wie manuelle). */
    public static function removeAllOfKind(string $userId, string $kind): int
    {
        $n = Db::exec('DELETE FROM owned_items WHERE user_id = ? AND kind = ?', [$userId, $kind]);
        if ($n > 0) {
            Achievements::recompute($userId);
        }
        return $n;
    }

    /** Sucht Schiffe und Rüstungen im Katalog für das Hinzufügen. @return list<array<string,mixed>> */
    public static function searchCatalog(string $term, int $take = 12): array
    {
        $key = Text::normalizeName($term);
        if ($key === '') {
            return [];
        }
        $like = '%' . addcslashes($key, '%_\\') . '%';
        $rows = Db::all(
            "SELECT id, kind, slug, name, manufacturer, image_url FROM catalog_items
              WHERE kind IN ('SHIP','ARMOR') AND (match_key LIKE ? OR alt_match_key LIKE ?)
              ORDER BY name ASC LIMIT $take",
            [$like, $like],
        );
        return array_map(fn ($r) => $r + ['imageSrc' => Community::imageSrc($r['kind'], $r['slug'], $r['image_url'])], $rows);
    }

    /**
     * Flache Zeilen für den Export; optional nur eine Kategorie.
     * @param list<array<string,mixed>> $entries
     * @return list<array{kategorie:string,name:string,hersteller:string,anzahl:int,lti:bool,quelle:string}>
     */
    public static function exportRows(array $entries, ?string $kind = null): array
    {
        $out = [];
        foreach ($entries as $e) {
            if ($kind !== null && $kind !== '' && $e['kind'] !== $kind) {
                continue;
            }
            $out[] = [
                'kategorie' => Constants::KIND_LABELS[$e['kind']] ?? $e['kind'],
                'name' => self::entryName($e),
                'hersteller' => $e['catalogItem']['manufacturer'] ?? '',
                'anzahl' => $e['quantity'],
                'lti' => $e['lti'],
                'quelle' => $e['source'] === 'MANUAL' ? 'manuell' : 'RSI',
            ];
        }
        return $out;
    }

    /**
     * CSV (Semikolon, UTF-8 mit BOM für Excel). Zellen mit führendem =, +, -, @ werden entschärft.
     * @param list<array{kategorie:string,name:string,hersteller:string,anzahl:int,lti:bool,quelle:string}> $rows
     */
    public static function toCsv(array $rows): string
    {
        $cell = static function (string|int|bool $v): string {
            $s = is_bool($v) ? ($v ? 'ja' : 'nein') : (string) $v;
            if (preg_match('/^[=+\-@\t\r]/', $s)) {
                $s = "'" . $s;
            }
            return preg_match('/[";\n\r]/', $s) ? '"' . str_replace('"', '""', $s) . '"' : $s;
        };
        $lines = array_map(
            fn ($r) => implode(';', array_map($cell, [$r['kategorie'], $r['name'], $r['hersteller'], $r['anzahl'], $r['lti'], $r['quelle']])),
            $rows,
        );
        return "\xEF\xBB\xBF" . implode("\r\n", ['Kategorie;Name;Hersteller;Anzahl;LTI;Quelle', ...$lines]) . "\r\n";
    }
}
