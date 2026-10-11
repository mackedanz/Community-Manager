<?php

declare(strict_types=1);

// Lädt fehlende Schiffsbilder vorab (optional; sonst passiert das beim ersten Aufruf eines Schiffs).
// Nutzung: php bin/warm-images.php [Anzahl]   (Standard: alle)
require dirname(__DIR__) . '/vendor/autoload.php';

date_default_timezone_set('UTC');
ini_set('memory_limit', '256M');

$limit = isset($argv[1]) ? max(1, (int) $argv[1]) : PHP_INT_MAX;
$done = 0;
$failed = 0;
foreach (Hangar\Db::all("SELECT slug FROM catalog_items WHERE kind = 'SHIP' ORDER BY name ASC") as $row) {
    if ($done + $failed >= $limit) {
        break;
    }
    if (Hangar\Images\ShipImages::existing($row['slug']) !== null) {
        continue;
    }
    if (Hangar\Images\ShipImages::ensure($row['slug']) !== null) {
        $done++;
    } else {
        $failed++;
    }
    usleep(200_000);
}
echo "Bilder geladen: $done, nicht verfügbar: $failed\n";
