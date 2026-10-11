<?php

declare(strict_types=1);

// Schlägt Hangar-Einträge ohne Katalogobjekt (Loot, Ausrüstung) in der Wiki nach. Läuft per Cron.
require dirname(__DIR__) . '/vendor/autoload.php';

date_default_timezone_set('UTC');
ini_set('memory_limit', '256M');

try {
    $r = Hangar\Items\Enrich::enrichPending();
    echo "Geprüft {$r['checked']}, gefunden {$r['found']}, Fehler {$r['failed']}\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Item-Abgleich fehlgeschlagen: ' . $e->getMessage() . "\n");
    exit(1);
}
