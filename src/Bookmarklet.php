<?php

declare(strict_types=1);

namespace Hangar;

/** Baut den javascript:-Link für das RSI-Sync-Lesezeichen mit der Adresse dieser App. */
final class Bookmarklet
{
    public static function build(string $appOrigin, ?string $sourceFile = null): string
    {
        $source = (string) file_get_contents($sourceFile ?? dirname(__DIR__) . '/bookmarklet/rsi-sync.js');
        // Führenden Kommentarblock entfernen, Platzhalter ersetzen
        $code = preg_replace('#^/\*.*?\*/\s*#s', '', $source, 1) ?? $source;
        $code = str_replace('__APP_URL__', rtrim($appOrigin, '/'), $code);
        // encodeURIComponent lässt A-Z a-z 0-9 - _ . ! ~ * ' ( ) unkodiert; rawurlencode kodiert ' ( ) ! * zusätzlich,
        // das ist für einen javascript:-Link ebenfalls gültig.
        return 'javascript:' . rawurlencode($code);
    }
}
