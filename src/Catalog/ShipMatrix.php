<?php

declare(strict_types=1);

namespace Hangar\Catalog;

use Hangar\Config;
use Hangar\Constants;
use Hangar\Text;

/**
 * Quelle der Wahrheit für Schiffe: die RSI Ship Matrix (feste URL, JSON). Anreicherung
 * (Schiffscode, Bild-Slug, Module) kommt separat aus FleetYards und ist optional.
 */
final class ShipMatrix
{
    /** Weniger Schiffe als hier gelten als fehlerhafter Abruf; der Katalog wird dann nicht angetastet. */
    public const MIN_SHIPS = 100;

    /** @return list<array<string,mixed>> Rohdaten der Schiffe */
    public static function fetch(?string $url = null, int $min = self::MIN_SHIPS): array
    {
        $body = Fetch::json($url ?? Config::shipMatrixUrl());
        if (!is_array($body) || (int) ($body['success'] ?? 0) !== 1 || !isset($body['data']) || !is_array($body['data'])) {
            throw new CatalogError('Ship Matrix: unerwartetes Format');
        }
        // Die Rohdaten sind groß (Bilder, Komponenten je Schiff): nur die genutzten Felder behalten.
        $keep = array_flip(['id', 'name', 'type', 'size', 'focus', 'production_status', 'min_crew', 'max_crew', 'cargocapacity', 'length', 'beam', 'height', 'mass', 'scm_speed', 'url']);
        $ships = [];
        foreach ($body['data'] as $s) {
            if (!is_array($s)) {
                continue;
            }
            $slim = array_intersect_key($s, $keep);
            $slim['manufacturer'] = ['name' => is_array($s['manufacturer'] ?? null) ? ($s['manufacturer']['name'] ?? null) : null];
            $ships[] = $slim;
        }
        unset($body);
        if (count($ships) < $min) {
            throw new CatalogError('Ship Matrix: nur ' . count($ships) . ' Schiffe, Abruf verworfen');
        }
        return $ships;
    }

    /** Karriere wie in der Oberfläche: einheitliche Schreibweise (die Matrix mischt Groß-/Kleinschreibung). */
    public static function careerLabel(?string $type): ?string
    {
        $t = strtolower(trim((string) $type));
        if ($t === '') {
            return null;
        }
        return match ($t) {
            'multi', 'multi-role', 'multirole' => 'Multi-Role',
            'transport', 'transporter' => 'Transporter',
            default => Text::titleCase($t),
        };
    }

    private static function num(mixed $v): int|float|null
    {
        return is_int($v) || is_float($v) ? $v : (is_string($v) && is_numeric($v) ? $v + 0 : null);
    }

    /**
     * Bildet ein Schiff aus der Matrix auf einen Katalogeintrag ab; null bei unbrauchbaren Daten.
     * @param array<string,mixed> $raw
     * @return array<string,mixed>|null
     */
    public static function map(array $raw): ?array
    {
        $name = isset($raw['name']) && is_string($raw['name']) ? trim($raw['name']) : '';
        $id = isset($raw['id']) && is_numeric($raw['id']) ? (int) $raw['id'] : null;
        if ($name === '' || $id === null) {
            return null;
        }
        $size = isset($raw['size']) && is_string($raw['size']) && trim($raw['size']) !== '' ? strtolower(trim($raw['size'])) : null;
        $manufacturer = isset($raw['manufacturer']) && is_array($raw['manufacturer']) && is_string($raw['manufacturer']['name'] ?? null)
            ? trim($raw['manufacturer']['name']) : null;
        $focus = isset($raw['focus']) && is_string($raw['focus']) && trim($raw['focus']) !== '' ? trim($raw['focus']) : null;
        $url = isset($raw['url']) && is_string($raw['url']) && str_starts_with($raw['url'], '/') ? Constants::RSI_BASE_URL . $raw['url'] : null;

        return [
            'kind' => 'SHIP',
            'source' => Constants::SOURCE_MATRIX,
            'rsi_id' => $id,
            'slug' => Text::slugify($name, 120, 'ship'),
            'name' => $name,
            'match_key' => Text::normalizeName($name),
            'alt_match_key' => null,
            'code_key' => null,
            'manufacturer' => $manufacturer !== '' ? $manufacturer : null,
            'image_url' => null,
            'data' => [
                'career' => self::careerLabel(is_string($raw['type'] ?? null) ? $raw['type'] : null),
                'role' => $focus,
                'status' => is_string($raw['production_status'] ?? null) ? $raw['production_status'] : null,
                'size' => $size,
                'sizeLabel' => $size !== null ? Text::titleCase($size) : null,
                'length' => self::num($raw['length'] ?? null),
                'beam' => self::num($raw['beam'] ?? null),
                'height' => self::num($raw['height'] ?? null),
                'mass' => self::num($raw['mass'] ?? null),
                'scmSpeed' => self::num($raw['scm_speed'] ?? null),
                'crewMin' => self::num($raw['min_crew'] ?? null),
                'crewMax' => self::num($raw['max_crew'] ?? null),
                'cargo' => self::num($raw['cargocapacity'] ?? null),
                'pledgeName' => $name,
                'webUrl' => $url,
            ],
        ];
    }

    /**
     * Bildet alle Schiffe ab. Doppelte Slugs (z. B. "Mk I" und "Mk. I") bekommen die RSI-ID als Suffix.
     * @param list<array<string,mixed>> $raw
     * @return list<array<string,mixed>>
     */
    public static function mapAll(array $raw): array
    {
        $out = [];
        $slugs = [];
        foreach ($raw as $r) {
            if (!is_array($r) || ($rec = self::map($r)) === null) {
                continue;
            }
            if (isset($slugs[$rec['slug']])) {
                $rec['slug'] = substr($rec['slug'], 0, 110) . '-' . $rec['rsi_id'];
            }
            $slugs[$rec['slug']] = true;
            $out[] = $rec;
        }
        return $out;
    }
}
