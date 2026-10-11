<?php

declare(strict_types=1);

// Verschlüsselt die noch unverschlüsselt gespeicherten Discord-Tokens der Nutzer mit APP_KEY (wiederholbar, ändert Fertiges nicht).
// Läuft bei jedem Start des Containers nach der Migration. Nutzung: php bin/encrypt-tokens.php
require dirname(__DIR__) . '/vendor/autoload.php';

date_default_timezone_set('UTC');

try {
    $n = Hangar\Secrets::encryptStoredTokens();
} catch (\RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
echo $n === 0 ? "Alle Tokens sind verschlüsselt.\n" : "$n Konten verschlüsselt.\n";