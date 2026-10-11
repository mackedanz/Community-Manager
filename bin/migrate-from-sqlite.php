<?php

declare(strict_types=1);

/**
 * Übernimmt die Daten der bisherigen Next.js-Version (SQLite-Datei, z. B. /data/hangar.db) nach MariaDB.
 *
 * Ablauf: 1. docker compose up (leere MariaDB, Migrationen laufen automatisch)
 *         2. php bin/catalog-sync.php           (Katalog aus der Ship Matrix aufbauen)
 *         3. php bin/migrate-from-sqlite.php /pfad/hangar.db --dry-run   (Zahlen prüfen, schreibt nichts)
 *         4. php bin/migrate-from-sqlite.php /pfad/hangar.db             (echte Übernahme)
 * Die SQLite-Datei wird nur gelesen. Immer mit einer KOPIE der Produktionsdatenbank üben.
 */
require dirname(__DIR__) . '/vendor/autoload.php';

use Hangar\Migration\SqliteImport;

date_default_timezone_set('UTC');
ini_set('memory_limit', '512M');

$args = array_slice($argv, 1);
$dry = in_array('--dry-run', $args, true);
$files = array_values(array_filter($args, fn ($a) => !str_starts_with($a, '--')));
if ($files === [] || !is_file($files[0])) {
    fwrite(STDERR, "Nutzung: php bin/migrate-from-sqlite.php <hangar.db> [--dry-run]\n");
    exit(2);
}

try {
    $src = new PDO('sqlite:' . $files[0]);
    $import = new SqliteImport($src);
    $result = $dry ? $import->dryRun() : $import->run();
} catch (Throwable $e) {
    fwrite(STDERR, 'Abbruch: ' . $e->getMessage() . "\n");
    exit(1);
}

echo ($dry ? "TROCKENLAUF (nichts wurde geschrieben)\n" : "Übernahme abgeschlossen\n");
printf("%-20s %8s %10s\n", 'Tabelle', 'Quelle', 'übernommen');
foreach ($result['counts'] as $table => $c) {
    printf("%-20s %8d %10d%s\n", $table, $c['source'], $c['imported'], $c['source'] === $c['imported'] ? '' : '  <-- Abweichung');
}
$notes = array_count_values($result['notes']);
if ($notes !== []) {
    echo "\nHinweise:\n";
    foreach (array_slice($notes, 0, 30, true) as $n => $times) {
        echo " - $n" . ($times > 1 ? " (x$times)" : '') . "\n";
    }
}
