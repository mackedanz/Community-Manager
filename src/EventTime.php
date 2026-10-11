<?php

declare(strict_types=1);

namespace Hangar;

use DateTimeImmutable;
use DateTimeZone;
use IntlDateFormatter;

/**
 * Event-Zeiten werden als Europe/Berlin-Ortszeit eingegeben und angezeigt (die Orgas sind
 * deutschsprachig), gespeichert wird als UTC. Nicht auf die Systemzeitzone verlassen.
 */
final class EventTime
{
    public const TZ = 'Europe/Berlin';

    private static function berlin(): DateTimeZone
    {
        return new DateTimeZone(self::TZ);
    }

    /** "2026-03-28T20:00" (Berlin) → UTC-Zeitpunkt; null bei ungültiger Eingabe. */
    public static function parseBerlinLocal(string $value): ?DateTimeImmutable
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})$/', trim($value), $m)) {
            return null;
        }
        [, $y, $mo, $d, $h, $mi] = $m;
        if (!checkdate((int) $mo, (int) $d, (int) $y) || (int) $h > 23 || (int) $mi > 59) {
            return null;
        }
        $local = DateTimeImmutable::createFromFormat('!Y-m-d H:i', "$y-$mo-$d $h:$mi", self::berlin());
        if ($local === false) {
            return null;
        }
        // z. B. die ausgefallene Stunde bei der Umstellung auf Sommerzeit: PHP schiebt sie vor,
        // dann stimmt die Rückrechnung nicht mehr.
        if ($local->format('Y-m-d H:i') !== "$y-$mo-$d $h:$mi") {
            return null;
        }
        return $local->setTimezone(new DateTimeZone('UTC'));
    }

    /** UTC → "2026-03-28T20:00" in Berlin-Ortszeit, für datetime-local-Felder. */
    public static function toInput(DateTimeImmutable $date): string
    {
        return $date->setTimezone(self::berlin())->format('Y-m-d\TH:i');
    }

    /** "YYYY-MM-DD" des Tages in Berlin, zum Einsortieren in den Kalender. */
    public static function dayKey(DateTimeImmutable $date): string
    {
        return $date->setTimezone(self::berlin())->format('Y-m-d');
    }

    /** z. B. "Mi., 15.07.2026, 20:00 Uhr" */
    public static function format(DateTimeImmutable $date): string
    {
        $f = new IntlDateFormatter('de_DE', IntlDateFormatter::NONE, IntlDateFormatter::NONE, self::TZ, IntlDateFormatter::GREGORIAN, 'EEE, dd.MM.yyyy, HH:mm');
        return $f->format($date) . ' Uhr';
    }

    /** Nur die Uhrzeit, z. B. "20:00". */
    public static function formatTime(DateTimeImmutable $date): string
    {
        return $date->setTimezone(self::berlin())->format('H:i');
    }
}
