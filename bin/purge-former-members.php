<?php

declare(strict_types=1);

// Löscht die Konten aller Personen, die auf keiner Zugangsliste stehen (Server-Admins ausgenommen).
// Ohne --yes werden die Konten nur aufgelistet. Nur mit LOGIN_REQUIRES_ALLOWLIST=1.
// Gedacht für den Fall, dass die Sicherheitsbremse beim Abgleich das Löschen übersprungen hat.
// Nutzung: php bin/purge-former-members.php [--yes]
require dirname(__DIR__) . '/vendor/autoload.php';

date_default_timezone_set('UTC');

$yes = in_array('--yes', $argv, true);
try {
    $names = Hangar\Onboarding::purgeFormerMembers(!$yes);
} catch (Hangar\OrgError $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
if ($names === []) {
    echo "Keine Konten ohne Zugangsliste.\n";
    exit(0);
}
echo ($yes ? 'Gelöscht: ' : 'Würden gelöscht (mit --yes ausführen): ') . count($names) . "\n";
foreach ($names as $n) {
    echo " - $n\n";
}
