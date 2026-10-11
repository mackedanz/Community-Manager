<?php

declare(strict_types=1);

// Gleicht die Mitgliederlisten der RSI-Orgas ab (nur Orgas mit hinterlegtem RSI-Kürzel). Läuft täglich per Cron.
require dirname(__DIR__) . '/vendor/autoload.php';

date_default_timezone_set('UTC');

Hangar\RateLimit::prune();

foreach (Hangar\RsiOrg::syncAll() as $slug => $result) {
    echo "$slug: $result\n";
}
