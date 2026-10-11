<?php

declare(strict_types=1);

namespace Hangar;

use Normalizer;

/** Textfunktionen für Abgleich und URLs. */
final class Text
{
    /**
     * Normalisiert einen Namen für den Abgleich: Kleinschreibung, Akzente weg, alles außer
     * Buchstaben und Ziffern entfernt ("Avenger Stalker" = "avenger-stalker").
     */
    public static function normalizeName(string $name): string
    {
        $n = Normalizer::normalize($name, Normalizer::FORM_KD);
        if ($n === false) {
            $n = $name;
        }
        $n = preg_replace('/\p{Mn}+/u', '', $n) ?? $n;
        $n = mb_strtolower($n, 'UTF-8');
        return preg_replace('/[^a-z0-9]+/', '', $n) ?? '';
    }

    /**
     * Volltextsuche: jedes Wort der Suche muss irgendwo in den Feldern vorkommen (Groß-/Kleinschreibung,
     * Akzente, Leer- und Sonderzeichen egal, "mk ii" findet "F7A Hornet Mk II"). Leere Suche trifft immer.
     * @param list<?string> $fields
     */
    public static function matchesQuery(array $fields, string $query): bool
    {
        $words = preg_split('/\s+/u', trim($query), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (!$words) {
            return true;
        }
        $hay = self::normalizeName(implode(' ', array_filter($fields, static fn ($f) => $f !== null && $f !== '')));
        foreach ($words as $w) {
            $n = self::normalizeName($w);
            if ($n !== '' && !str_contains($hay, $n)) {
                return false;
            }
        }
        return true;
    }

    /** URL-tauglicher Name; Umlaute werden umschrieben, Standard "orga" bei leerem Ergebnis. */
    public static function slugify(string $name, int $max = 40, string $fallback = 'orga'): string
    {
        $s = mb_strtolower($name, 'UTF-8');
        $s = str_replace(['ä', 'ö', 'ü', 'ß'], ['ae', 'oe', 'ue', 'ss'], $s);
        $n = Normalizer::normalize($s, Normalizer::FORM_KD);
        $s = $n === false ? $s : $n;
        $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
        $s = trim($s, '-');
        $s = substr($s, 0, $max);
        $s = trim($s, '-');
        return $s === '' ? $fallback : $s;
    }

    /** Erster Buchstabe jedes Wortes groß, Rest klein ("combat" → "Combat"). */
    public static function titleCase(string $s): string
    {
        return mb_convert_case(trim($s), MB_CASE_TITLE, 'UTF-8');
    }
}
