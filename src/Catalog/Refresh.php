<?php

declare(strict_types=1);

namespace Hangar\Catalog;

use Hangar\Db;
use Hangar\RateLimit;
use Hangar\Time;

/**
 * Katalog-Abgleich (komplett: Ship Matrix, FleetYards, Wiki, Erkul-Namen; oder nur die Schiffe) und Hangar neu verknüpfen mit Sperre gegen
 * doppelte Läufe und Merker für den letzten Lauf. Läuft per Cron (bin/catalog-sync.php) und automatisch nach einem
 * Import, bei dem Schiffe nicht im Katalog gefunden wurden (dann nur die Schiffe).
 */
final class Refresh
{
    private const LOCK = 'hangar-catalog-sync';
    private const SETTING = 'catalog_last_run';
    /** Ein Lauf, der jünger ist, macht einen weiteren automatischen Abgleich überflüssig. */
    public const FRESH_SECONDS = 900;
    /** Höchstens ein automatischer Abgleich pro Stunde. */
    public const AUTO_WINDOW_SECONDS = 3600;

    /**
     * @return array{results:list<array<string,mixed>>,erkul:?int}
     * @throws CatalogError bei unbrauchbarer Ship Matrix oder wenn schon ein Abgleich läuft
     */
    public static function run(?string $matrixUrl = null, int $minShips = ShipMatrix::MIN_SHIPS): array
    {
        if ((int) Db::val('SELECT GET_LOCK(?, 0)', [self::LOCK]) !== 1) {
            throw new CatalogError('Ein Katalog-Abgleich läuft bereits.');
        }
        try {
            // Die Rohdaten der Matrix sind groß; im Webserver gilt sonst das kleinere Speicherlimit.
            if ((int) ini_get('memory_limit') !== -1 && (int) ini_get('memory_limit') < 512) {
                ini_set('memory_limit', '512M');
            }
            @set_time_limit(300);
            try {
                $results = Sync::run($matrixUrl, $minShips);
            } catch (CatalogError $e) {
                self::record(['ok' => false, 'scope' => 'full', 'error' => $e->getMessage()]);
                throw $e;
            }
            // Namenstabelle für die Erkul-Links: optional, bei Fehlern bleibt die bisherige
            try {
                $erkul = Erkul::refresh();
            } catch (\Throwable) {
                $erkul = null;
            }
            self::record(['ok' => true, 'scope' => 'full', 'results' => $results]);
            return ['results' => $results, 'erkul' => $erkul];
        } finally {
            Db::val('SELECT RELEASE_LOCK(?)', [self::LOCK]);
        }
    }

    /**
     * Nur die Schiffe: Ship Matrix und FleetYards neu einlesen und unverknüpfte Schiffe im Hangar verknüpfen. Rüstungen und Erkul-Namen
     * bleiben dem regelmäßigen Lauf überlassen (schneller und schont die Quellen).
     * @return list<array<string,mixed>>
     */
    public static function runShips(?string $matrixUrl = null, int $minShips = ShipMatrix::MIN_SHIPS): array
    {
        if ((int) Db::val('SELECT GET_LOCK(?, 0)', [self::LOCK]) !== 1) {
            throw new CatalogError('Ein Katalog-Abgleich läuft bereits.');
        }
        try {
            if ((int) ini_get('memory_limit') !== -1 && (int) ini_get('memory_limit') < 512) {
                ini_set('memory_limit', '512M');
            }
            @set_time_limit(300);
            try {
                $results = Sync::ships($matrixUrl, $minShips);
            } catch (CatalogError $e) {
                self::record(['ok' => false, 'scope' => 'ships', 'error' => $e->getMessage()]);
                throw $e;
            }
            self::record(['ok' => true, 'scope' => 'ships', 'results' => $results]);
            return $results;
        } finally {
            Db::val('SELECT RELEASE_LOCK(?)', [self::LOCK]);
        }
    }

    /** @param array<string,mixed> $info */
    private static function record(array $info): void
    {
        $value = json_encode(['at' => Time::now()->format('c')] + $info, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        Db::run('INSERT INTO app_settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', [self::SETTING, $value]);
    }

    /** @return array{at:string,ok:bool,error?:string,results?:list<array<string,mixed>>}|null */
    public static function lastRun(): ?array
    {
        $v = Db::val('SELECT value FROM app_settings WHERE name = ?', [self::SETTING]);
        $d = is_string($v) ? json_decode($v, true) : null;
        return is_array($d) && isset($d['at']) ? $d : null;
    }

    /**
     * Entscheidet nach einem Import, ob der Katalog sofort aktualisiert werden soll: nur wenn Schiffe keinen
     * Katalogeintrag fanden, der letzte Lauf nicht gerade erst war und in dieser Stunde noch keiner automatisch angestoßen wurde.
     */
    public static function shouldRefreshAfterImport(int $unmatchedShips, ?int $now = null): bool
    {
        if ($unmatchedShips <= 0) {
            return false;
        }
        $now ??= Time::now()->getTimestamp();
        $last = self::lastRun();
        if ($last !== null && $now - (int) strtotime((string) $last['at']) < self::FRESH_SECONDS) {
            return false;
        }
        return RateLimit::hit('catalog-auto', 1, self::AUTO_WINDOW_SECONDS, $now)['ok'];
    }

    /** Nacharbeit für die Antwort eines Imports: aktualisiert bei Bedarf den Katalog; Fehler betreffen den Nutzer nicht. @return (callable():void)|null */
    public static function afterImport(int $unmatchedShips): ?callable
    {
        if (!self::shouldRefreshAfterImport($unmatchedShips)) {
            return null;
        }
        return static function (): void {
            try {
                self::runShips();
            } catch (\Throwable) {
                // läuft schon oder Quelle nicht erreichbar: der nächste Cron-Lauf holt es nach
            }
        };
    }
}