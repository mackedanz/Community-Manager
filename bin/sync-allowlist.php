<?php

declare(strict_types=1);

// Gleicht die Zugangslisten aller Orgas mit den Discord-Rollen ab (Bot-Token nötig). Läuft stündlich per Cron.
require dirname(__DIR__) . '/vendor/autoload.php';

date_default_timezone_set('UTC');

if (Hangar\Env::get('DISCORD_BOT_TOKEN') === null) {
    echo "DISCORD_BOT_TOKEN nicht gesetzt, nichts zu tun.\n";
    exit(0);
}
foreach (Hangar\Onboarding::syncAll() as $slug => $result) {
    echo "$slug: $result\n";
}
