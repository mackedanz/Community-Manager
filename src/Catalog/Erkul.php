<?php

declare(strict_types=1);

namespace Hangar\Catalog;

use Hangar\Config;
use Hangar\Http\Client;
use Hangar\Http\NetworkError;
use Hangar\Text;

/**
 * Link zum Erkul-DPS-Rechner. Erkul benennt Schiffe nach den Spielkennungen (z. B. rsi_apollo_triage), die nicht
 * mit den RSI-Namen übereinstimmen. Eine Tabelle ordnet normalisierte Schiffsnamen den Kennungen zu: erkul-ships.json
 * ist der mitgelieferte Stand, refresh() lädt die aktuelle Schiffsliste von Erkul und legt sie im Bilder-Volume ab
 * (erkul-ships.json dort hat Vorrang). Schiffe, die Erkul nicht kennt, bekommen keinen Link.
 * erkul-overrides.json: Zuordnungen für Schiffe, deren Name bei RSI und Erkul zu weit auseinanderliegt.
 */
final class Erkul
{
    private const BASE = 'https://www.erkul.games/ship/';
    private const CDN = 'https://cdn.erkul.games/LIVE/';
    private const MIN_SHIPS = 100;

    /** @var ?array<string,string> */
    private static ?array $map = null;

    public static function url(string $name, ?string $manufacturer = null): ?string
    {
        self::$map ??= self::load();
        foreach ([$name, trim(($manufacturer ?? '') . ' ' . $name)] as $candidate) {
            $key = Text::normalizeName($candidate);
            if ($key !== '' && isset(self::$map[$key])) {
                return self::BASE . self::$map[$key];
            }
        }
        return null;
    }

    public static function reset(): void
    {
        self::$map = null;
    }

    /** @return array<string,string> */
    private static function load(): array
    {
        foreach ([self::cacheFile(), __DIR__ . '/erkul-ships.json'] as $file) {
            $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
            if (is_array($data) && $data !== []) {
                return $data;
            }
        }
        return [];
    }

    private static function cacheFile(): string
    {
        return Config::imageDir() . '/erkul-ships.json';
    }

    /**
     * Lädt die Schiffsliste von Erkul und baut die Namenstabelle neu. Bei jedem Fehler bleibt die bisherige Tabelle.
     * @return int Anzahl der Zuordnungen
     * @throws CatalogError
     */
    public static function refresh(): int
    {
        $catalog = self::inflate(self::fetch('catalog.bin'));
        $path = null;
        foreach ($catalog['singles'] ?? [] as $single) {
            if (($single['kind'] ?? null) === 'index' && is_string($single['path'] ?? null) && preg_match('/^[\w.\-]+$/', $single['path'])) {
                $path = $single['path'];
            }
        }
        if ($path === null) {
            throw new CatalogError('Erkul: Schiffsindex nicht gefunden');
        }
        $ships = self::inflate(self::fetch($path))['ships'] ?? [];
        if (!is_array($ships) || count($ships) < self::MIN_SHIPS) {
            throw new CatalogError('Erkul: zu wenige Schiffe in der Liste');
        }

        $map = [];
        $valid = [];
        foreach ($ships as $s) {
            $class = is_array($s) && is_string($s['className'] ?? null) ? $s['className'] : '';
            if (!preg_match('/^[a-z0-9_]+$/', $class)) {
                continue;
            }
            $valid[$class] = true;
            foreach (['name', 'displayName'] as $field) {
                $key = is_string($s[$field] ?? null) ? Text::normalizeName($s[$field]) : '';
                if ($key !== '') {
                    $map[$key] ??= $class;
                }
            }
        }
        $overrides = json_decode((string) file_get_contents(__DIR__ . '/erkul-overrides.json'), true);
        foreach (is_array($overrides) ? $overrides : [] as $name => $class) {
            if (isset($valid[$class])) {
                $map[Text::normalizeName((string) $name)] = $class;
            }
        }
        ksort($map);

        $file = self::cacheFile();
        $tmp = $file . '.tmp';
        if (file_put_contents($tmp, json_encode($map)) === false || !rename($tmp, $file)) {
            throw new CatalogError('Erkul: Tabelle konnte nicht gespeichert werden');
        }
        self::$map = $map;
        return count($map);
    }

    private static function fetch(string $file): string
    {
        try {
            $res = Client::get(self::CDN . $file, [], 30, true, 5_000_000);
        } catch (NetworkError $e) {
            throw new CatalogError('Erkul: ' . $e->getMessage(), 0, $e);
        }
        if ($res['status'] !== 200) {
            throw new CatalogError("Erkul: HTTP {$res['status']} für $file");
        }
        return $res['body'];
    }

    /** @return array<string,mixed> */
    private static function inflate(string $raw): array
    {
        $json = @gzinflate($raw, 20_000_000);
        $data = $json === false ? null : json_decode($json, true);
        if (!is_array($data)) {
            throw new CatalogError('Erkul: unbekanntes Datenformat');
        }
        return $data;
    }
}
