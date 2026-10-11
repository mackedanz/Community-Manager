<?php

declare(strict_types=1);

// Gleicht den Katalog ab (Ship Matrix, FleetYards-Ergänzung, Wiki-Rüstungen, Erkul-Namen). Läuft per Cron, Standard alle 2 Stunden.
require dirname(__DIR__) . '/vendor/autoload.php';

date_default_timezone_set('UTC');
ini_set('memory_limit', '512M');

try {
    $r = Hangar\Catalog\Refresh::run();
} catch (Throwable $e) {
    fwrite(STDERR, 'Katalog-Sync fehlgeschlagen: ' . $e->getMessage() . "\n");
    exit(1);
}
foreach ($r['results'] as $x) {
    echo sprintf("%-6s geladen %d, gespeichert %d, übersprungen %d%s\n", $x['kind'], $x['fetched'], $x['saved'], $x['skipped'], $x['note'] ? ' (' . $x['note'] . ')' : '');
}
echo $r['erkul'] === null ? "ERKUL  nicht aktualisiert\n" : sprintf("ERKUL  %d Zuordnungen aktualisiert\n", $r['erkul']);