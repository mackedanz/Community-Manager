<?php

declare(strict_types=1);

namespace Hangar\Import;

use Hangar\Text;

/**
 * Abgleich von Hangar-Namen mit dem Katalog. Ein Katalogeintrag ist ein Array mit
 * id, kind, slug, name, match_key, alt_match_key, code_key, manufacturer.
 */
final class Matcher
{
    /** Bekannte Fälle, in denen RSI-Pledge-Name und Katalogname auseinanderlaufen (normalisierte Namen). */
    private const NAME_ALIASES = [
        'ursarover' => 'ursa',
        'ursaroverfortuna' => 'ursafortuna',
    ];

    /**
     * @param list<array<string,mixed>> $rows
     * @return array<string,list<array<string,mixed>>> Schlüssel "KIND:normalisierterName"
     */
    public static function buildIndex(array $rows): array
    {
        $index = [];
        $add = static function (string $kind, ?string $key, array $row) use (&$index): void {
            if ($key === null || $key === '') {
                return;
            }
            $k = $kind . ':' . $key;
            foreach ($index[$k] ?? [] as $existing) {
                if ($existing['id'] === $row['id']) {
                    return;
                }
            }
            $index[$k][] = $row;
        };
        foreach ($rows as $row) {
            $add($row['kind'], $row['match_key'] ?? null, $row);
            // "RSI_Ursa_Rover" (Schiffscode) entspricht dem Slug "rsi-ursa-rover".
            $add($row['kind'], Text::normalizeName((string) $row['slug']), $row);
            $add($row['kind'], $row['alt_match_key'] ?? null, $row);
            $add($row['kind'], $row['code_key'] ?? null, $row);
            // "Aegis Dynamics Avenger Titan" soll ebenfalls "Avenger Titan" treffen.
            if (!empty($row['manufacturer'])) {
                $add($row['kind'], Text::normalizeName($row['manufacturer'] . ' ' . $row['name']), $row);
            }
        }
        return $index;
    }

    /** Bei mehreren Treffern (gleicher Name, mehrere Varianten) gewinnt der kürzeste Slug. @param list<array<string,mixed>>|null $list */
    private static function pick(?array $list): ?array
    {
        if ($list === null || $list === []) {
            return null;
        }
        usort($list, static fn ($a, $b) => strlen($a['slug']) <=> strlen($b['slug']) ?: strcmp($a['slug'], $b['slug']));
        return $list[0];
    }

    /**
     * Sucht den passenden Katalogeintrag. Von genau nach ungenau:
     * Name, Alias, Originalname, Name ohne "Edition", Schiffscode, Name ohne letztes Wort.
     *
     * Der Schiffscode kommt bewusst spät: Exporte vergeben ihn nicht immer eindeutig (z. B.
     * "RSI_Ursa" für zwei verschiedene Ursa-Varianten). Namen sind verlässlicher.
     *
     * @param array<string,list<array<string,mixed>>> $index
     * @param array{title:string,altTitle?:string,code?:string} $q
     * @return array<string,mixed>|null
     */
    public static function find(array $index, string $kind, array $q): ?array
    {
        $key = Text::normalizeName($q['title']);
        $words = preg_split('/\s+/', trim($q['title'])) ?: [];
        $candidates = [
            $key,
            self::NAME_ALIASES[$key] ?? '',
            isset($q['altTitle']) ? Text::normalizeName($q['altTitle']) : '',
            (string) preg_replace('/edition$/', '', $key),
            isset($q['code']) ? Text::normalizeName($q['code']) : '',
            // Letzter Ausweg für Varianten ohne eigenen Katalogeintrag ("Constellation Phoenix Emerald"
            // -> "Constellation Phoenix"). Nur bei mindestens zwei verbleibenden Wörtern.
            $kind === 'SHIP' && count($words) >= 3 ? Text::normalizeName(implode(' ', array_slice($words, 0, -1))) : '',
        ];
        foreach ($candidates as $c) {
            if ($c === '') {
                continue;
            }
            $hit = self::pick($index[$kind . ':' . $c] ?? null);
            if ($hit !== null) {
                return $hit;
            }
        }
        return null;
    }
}
