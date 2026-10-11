<?php

declare(strict_types=1);

namespace Hangar;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/** Zeiten sind in der Datenbank immer UTC (DATETIME ohne Zone). */
final class Time
{
    /** @var DateTimeImmutable|null Für Tests einfrierbar */
    private static ?DateTimeImmutable $fixed = null;

    public static function freeze(?DateTimeImmutable $now): void
    {
        self::$fixed = $now;
    }

    public static function now(): DateTimeImmutable
    {
        return self::$fixed ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public static function db(DateTimeInterface $d): string
    {
        return DateTimeImmutable::createFromInterface($d)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    public static function nowDb(): string
    {
        return self::db(self::now());
    }

    public static function parse(?string $dbValue): ?DateTimeImmutable
    {
        if ($dbValue === null || $dbValue === '') {
            return null;
        }
        return new DateTimeImmutable($dbValue, new DateTimeZone('UTC'));
    }
}
