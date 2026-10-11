<?php

declare(strict_types=1);

// Übernimmt die Discord-Server-Events als Termine (nur Orgas mit eingeschalteter Übernahme). Läuft alle paar Minuten per Cron.
require dirname(__DIR__) . '/vendor/autoload.php';

date_default_timezone_set('UTC');

if (Hangar\Env::get('DISCORD_BOT_TOKEN') === null) {
    echo "DISCORD_BOT_TOKEN nicht gesetzt, nichts zu tun.\n";
    exit(0);
}
foreach (Hangar\DiscordEvents::syncAll() as $slug => $result) {
    echo "$slug: $result\n";
}
// Danach vorgemerkte Hangar-Termine nach Discord schreiben
foreach (Hangar\DiscordEvents::pushAll() as $slug => $result) {
    echo "$slug (nach Discord): $result\n";
}