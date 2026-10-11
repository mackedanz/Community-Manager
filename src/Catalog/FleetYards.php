<?php

declare(strict_types=1);

namespace Hangar\Catalog;

use Hangar\Text;

/**
 * FleetYards ergänzt die Ship Matrix: Schiffscode (für den HangarXPLOR-Import), Pledge-Name,
 * Slug für Bilder und die Module einzelner Schiffe. Fällt FleetYards aus, bleibt der Katalog
 * aus der Matrix vollständig, nur ohne diese Ergänzungen.
 */
final class FleetYards
{
    public const API = 'https://api.fleetyards.net/v1';
    private const PAGE_SIZE = 100;

    /** @return list<array<string,mixed>> */
    public static function fetchModels(): array
    {
        $all = [];
        for ($page = 1;; $page++) {
            $body = Fetch::json(self::API . '/models?perPage=' . self::PAGE_SIZE . '&page=' . $page);
            if (!is_array($body) || !isset($body['items']) || !is_array($body['items'])) {
                throw new CatalogError('FleetYards: unerwartetes Format');
            }
            // Pro Modell sind die Rohdaten groß (Bilder, Maße, Geschwindigkeiten): nur Benötigtes behalten.
            $keep = array_flip(['slug', 'name', 'rsiName', 'rsiId', 'scIdentifier', 'hasModules', 'pledgePrice']);
            foreach ($body['items'] as $item) {
                if (is_array($item)) {
                    $all[] = array_intersect_key($item, $keep);
                }
            }
            $total = (int) ($body['meta']['pagination']['totalPages'] ?? 1);
            unset($body);
            if ($page >= $total) {
                return $all;
            }
        }
    }

    /**
     * Ordnet die Modelle den Matrix-Schiffen zu: zuerst über die RSI-ID, sonst über den normalisierten
     * Namen oder Pledge-Namen.
     *
     * @param list<array<string,mixed>> $ships  Katalogeinträge aus der Matrix
     * @param list<array<string,mixed>> $models FleetYards-Modelle
     * @return list<array<string,mixed>> Katalogeinträge, angereichert um code_key, alt_match_key, image_slug und data.msrp/hasModules
     */
    public static function enrich(array $ships, array $models): array
    {
        $byId = [];
        $byName = [];
        foreach ($models as $m) {
            if (!is_array($m) || !isset($m['slug'])) {
                continue;
            }
            if (isset($m['rsiId']) && is_numeric($m['rsiId'])) {
                $byId[(int) $m['rsiId']] = $m;
            }
            foreach ([$m['name'] ?? null, $m['rsiName'] ?? null] as $n) {
                if (is_string($n) && $n !== '') {
                    $byName[Text::normalizeName($n)] ??= $m;
                }
            }
        }
        foreach ($ships as &$s) {
            $m = $byId[$s['rsi_id']] ?? $byName[$s['match_key']] ?? null;
            if ($m === null) {
                continue;
            }
            $s['image_slug'] = (string) $m['slug'];
            if (!empty($m['scIdentifier']) && is_string($m['scIdentifier'])) {
                $s['code_key'] = Text::normalizeName($m['scIdentifier']);
            }
            $rsiName = isset($m['rsiName']) && is_string($m['rsiName']) ? Text::normalizeName($m['rsiName']) : '';
            if ($rsiName !== '' && $rsiName !== $s['match_key']) {
                $s['alt_match_key'] = $rsiName;
            }
            if (isset($m['pledgePrice']) && is_numeric($m['pledgePrice'])) {
                $s['data']['msrp'] = $m['pledgePrice'] + 0;
            }
            $s['has_modules'] = !empty($m['hasModules']);
        }
        unset($s);
        return $ships;
    }

    /** Module eines Schiffs. @return list<array{name:string,slug:string}> */
    public static function fetchModules(string $fleetyardsSlug): array
    {
        $body = Fetch::json(self::API . '/models/' . rawurlencode($fleetyardsSlug) . '/modules');
        $items = is_array($body) ? ($body['items'] ?? null) : null;
        if (!is_array($items)) {
            return [];
        }
        $out = [];
        foreach ($items as $i) {
            if (is_array($i) && is_string($i['name'] ?? null) && trim($i['name']) !== '') {
                $name = trim($i['name']);
                $out[] = ['name' => $name, 'slug' => is_string($i['slug'] ?? null) && $i['slug'] !== '' ? $i['slug'] : Text::slugify($name, 120, 'modul')];
            }
        }
        return $out;
    }
}
