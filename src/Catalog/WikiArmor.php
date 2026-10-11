<?php

declare(strict_types=1);

namespace Hangar\Catalog;

use Hangar\Constants;
use Hangar\Text;

/**
 * Rüstungen stammen aus der Star Citizen Wiki API. Die API ist eine Community-Schnittstelle: Wir
 * verlangen nur das Nötigste und lassen alles andere optional, damit Formatänderungen den Sync
 * nicht brechen.
 */
final class WikiArmor
{
    public const URL = 'https://api.star-citizen.wiki/api/v2/armor';
    private const PAGE_SIZE = 100;

    /** @return list<mixed> */
    public static function fetch(): array
    {
        $url = fn (int $page) => self::URL . '?page[size]=' . self::PAGE_SIZE . '&page[number]=' . $page;
        $first = Fetch::json($url(1));
        if (!is_array($first) || !isset($first['data']) || !is_array($first['data'])) {
            throw new CatalogError('Wiki-API: unerwartetes Format');
        }
        $all = $first['data'];
        $last = (int) ($first['meta']['last_page'] ?? 1);
        for ($page = 2; $page <= $last; $page++) {
            $next = Fetch::json($url($page));
            if (is_array($next) && isset($next['data']) && is_array($next['data'])) {
                array_push($all, ...$next['data']);
            }
        }
        return $all;
    }

    /** Bilder als Objekt oder Liste. */
    private static function imageUrl(mixed $value): ?string
    {
        if (!is_array($value)) {
            return null;
        }
        $first = array_is_list($value) ? ($value[0] ?? null) : $value;
        if (!is_array($first)) {
            return null;
        }
        foreach (['thumbnail_url', 'original_url'] as $k) {
            if (isset($first[$k]) && is_string($first[$k]) && $first[$k] !== '') {
                return $first[$k];
            }
        }
        return null;
    }

    /** @return array<string,mixed>|null */
    public static function map(mixed $raw): ?array
    {
        if (!is_array($raw) || !is_string($raw['slug'] ?? null) || !is_string($raw['name'] ?? null) || $raw['slug'] === '' || $raw['name'] === '') {
            return null;
        }
        $mf = $raw['manufacturer']['name'] ?? null;
        return [
            'kind' => 'ARMOR',
            'source' => Constants::SOURCE_WIKI,
            'rsi_id' => null,
            'slug' => $raw['slug'],
            'name' => $raw['name'],
            'match_key' => Text::normalizeName($raw['name']),
            'alt_match_key' => null,
            'code_key' => null,
            'manufacturer' => is_string($mf) ? $mf : null,
            'image_url' => self::imageUrl($raw['images'] ?? null),
            'data' => [
                'type' => is_string($raw['type_label'] ?? null) ? $raw['type_label'] : null,
                'subType' => is_string($raw['sub_type_label'] ?? null) ? $raw['sub_type_label'] : null,
                'size' => is_int($raw['size'] ?? null) || is_float($raw['size'] ?? null) ? $raw['size'] : null,
                'webUrl' => is_string($raw['web_url'] ?? null) ? $raw['web_url'] : null,
            ],
        ];
    }
}
