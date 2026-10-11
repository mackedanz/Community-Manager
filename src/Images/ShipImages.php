<?php

declare(strict_types=1);

namespace Hangar\Images;

use Hangar\Config;
use Hangar\Constants;
use Hangar\Db;
use Hangar\Http\Client;
use Hangar\Http\NetworkError;
use Hangar\Time;

/**
 * Schiffsbilder von RSI (erstes Bild der Store-Seite), lazy auf dem Server gespeichert: Beim ersten Aufruf eines
 * Schiffs wird geprüft, ob das Bild schon vorhanden ist. Ja: das gespeicherte Bild wird genutzt.
 * Nein: es wird heruntergeladen, geprüft, gespeichert und angezeigt.
 */
final class ShipImages
{
    private const QUERY = 'query ShipImage($query: SearchQuery) { store(browse: true) { search(query: $query) { resources { url ... on RSIShip { media { thumbnail { slideshow } } } } } } }';
    /** Nur von diesem Host wird geladen (RSI-Bildspeicher), nur per https. */
    public const ALLOWED_HOSTS = ['media.robertsspaceindustries.com'];
    /**
     * Ausnahmen für Einträge, zu denen RSI kein Bild liefert (z. B. Sondereditionen ohne Store-Seite).
     * Schlüssel: Name kleingeschrieben, Nicht-Buchstaben/-Ziffern zu "-" (siehe fallbackKey). Nur diese festen
     * Adressen werden geladen, nur von FALLBACK_HOSTS. Quelle: FleetYards (storage.fltyrd.net).
     */
    public const FALLBACK = [
        'dragonfly-star-kitten-edition' => 'https://storage.fltyrd.net/iq6atqxvsaqzbx56e49paaq36yfj',
    ];
    public const FALLBACK_HOSTS = ['storage.fltyrd.net'];
    public const MAX_BYTES = 5 * 1024 * 1024;
    /** Nach einem Fehlschlag wird frühestens nach dieser Zeit erneut versucht. */
    public const RETRY_SECONDS = 86400;
    private const EXT = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
    public const MIME = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];

    public static function validSlug(string $slug): bool
    {
        return (bool) preg_match('/^[a-z0-9][a-z0-9\-]{0,119}$/', $slug);
    }

    private static function dir(): string
    {
        return Config::imageDir() . '/ships';
    }

    /** Pfad der gespeicherten Datei, falls vorhanden. @return array{path:string,mime:string}|null */
    public static function existing(string $slug): ?array
    {
        if (!self::validSlug($slug)) {
            return null;
        }
        foreach (self::MIME as $ext => $mime) {
            $path = self::dir() . "/$slug.$ext";
            if (is_file($path)) {
                return ['path' => $path, 'mime' => $mime];
            }
        }
        return null;
    }

    /**
     * Liefert das Bild für ein Schiff des Katalogs, lädt es bei Bedarf herunter.
     * @return array{path:string,mime:string}|null null = kein Bild verfügbar (Platzhalter zeigen)
     */
    public static function ensure(string $slug): ?array
    {
        if (!self::validSlug($slug)) {
            return null;
        }
        // 1. Schon gespeichert?
        if (($have = self::existing($slug)) !== null) {
            return $have;
        }
        $row = Db::one("SELECT id, data, image_checked_at FROM catalog_items WHERE kind = 'SHIP' AND slug = ?", [$slug]);
        if ($row === null) {
            return null;
        }
        // 2. Kürzlich schon vergeblich versucht?
        $checked = Time::parse($row['image_checked_at']);
        if ($checked !== null && Time::now()->getTimestamp() - $checked->getTimestamp() < self::RETRY_SECONDS) {
            return null;
        }

        $dir = self::dir();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return null;
        }
        // Parallele Aufrufe desselben Bildes: nur einer lädt, die anderen warten und nutzen das Ergebnis.
        $lock = fopen($dir . "/.lock-$slug", 'c');
        if ($lock === false) {
            return null;
        }
        try {
            flock($lock, LOCK_EX);
            if (($have = self::existing($slug)) !== null) {
                return $have;
            }
            $saved = null;
            try {
                $url = self::imageUrl($row);
                $img = $url !== null ? self::download($url) : null;
                if ($img !== null) {
                    $saved = self::store($slug, $img['body'], $img['ext']);
                }
            } catch (NetworkError | \RuntimeException) {
                $saved = null;
            }
            if ($saved === null) {
                Db::run('UPDATE catalog_items SET image_checked_at = ? WHERE id = ?', [Time::nowDb(), $row['id']]);
            }
            return $saved;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
            @unlink($dir . "/.lock-$slug");
        }
    }

    /**
     * Bild-URL des Schiffs von RSI: Die Store-Seite des Schiffs (Link aus der Ship Matrix) liefert per
     * GraphQL ihr erstes Bild. Es steht unter media.thumbnail.slideshow.
     * @param array<string,mixed> $row
     */
    private static function imageUrl(array $row): ?string
    {
        $data = json_decode((string) ($row['data'] ?? ''), true);
        $webUrl = is_array($data) && is_string($data['webUrl'] ?? null) ? $data['webUrl'] : '';
        if (!str_starts_with($webUrl, Constants::RSI_BASE_URL . '/pledge/')) {
            return null;
        }
        $path = substr($webUrl, strlen(Constants::RSI_BASE_URL));

        $res = Client::request('POST', Constants::RSI_BASE_URL . '/graphql', ['Content-Type' => 'application/json', 'Accept' => 'application/json'], json_encode([
            'query' => self::QUERY,
            'variables' => ['query' => ['ships' => ['urls' => [$path]]]],
        ], JSON_THROW_ON_ERROR), 15);
        $body = $res['status'] === 200 ? json_decode($res['body'], true) : null;
        foreach ($body['data']['store']['search']['resources'] ?? [] as $r) {
            if (is_array($r) && ($r['url'] ?? null) === $path) {
                $url = $r['media']['thumbnail']['slideshow'] ?? null;
                return is_string($url) && $url !== '' ? $url : null;
            }
        }
        return null;
    }

    /** @param list<string> $hosts */
    public static function hostAllowed(string $url, array $hosts = self::ALLOWED_HOSTS): bool
    {
        $p = parse_url($url);
        return is_array($p) && ($p['scheme'] ?? '') === 'https' && in_array(strtolower($p['host'] ?? ''), $hosts, true);
    }

    /**
     * Lädt ein Bild. Weiterleitungen werden einzeln verfolgt, jeder Schritt muss auf einen erlaubten
     * Host zeigen. @param list<string> $hosts @return array{body:string,ext:string}|null
     */
    public static function download(string $url, array $hosts = self::ALLOWED_HOSTS): ?array
    {
        for ($i = 0; $i < 5; $i++) {
            if (!self::hostAllowed($url, $hosts)) {
                return null;
            }
            $res = Client::get($url, ['Accept' => 'image/*'], 15, false, self::MAX_BYTES);
            if ($res['status'] >= 300 && $res['status'] < 400 && !empty($res['headers']['location'])) {
                $url = self::absolute($url, $res['headers']['location']);
                continue;
            }
            if ($res['status'] !== 200 || $res['body'] === '' || strlen($res['body']) > self::MAX_BYTES) {
                return null;
            }
            $info = @getimagesizefromstring($res['body']);
            if ($info === false || !isset(self::EXT[$info[2]])) {
                return null;
            }
            return ['body' => $res['body'], 'ext' => self::EXT[$info[2]]];
        }
        return null;
    }

    private static function absolute(string $base, string $location): string
    {
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }
        $p = parse_url($base);
        return ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '') . '/' . ltrim($location, '/');
    }

    /** Schreibt atomar (Temp-Datei + rename). @return array{path:string,mime:string}|null */
    private static function store(string $slug, string $body, string $ext): ?array
    {
        return self::storeIn(self::dir(), $slug, $body, $ext);
    }

    /** Schreibt atomar in einen beliebigen Bildordner. @return array{path:string,mime:string}|null */
    public static function storeIn(string $dir, string $name, string $body, string $ext): ?array
    {
        $path = $dir . "/$name.$ext";
        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($tmp, $body) === false) {
            return null;
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            return is_file($path) ? ['path' => $path, 'mime' => self::MIME[$ext]] : null;
        }
        return ['path' => $path, 'mime' => self::MIME[$ext]];
    }

    /** Schlüssel der Ausnahmeliste für einen Eintragsnamen, oder null, wenn es keine Ausnahme gibt. */
    public static function fallbackKey(?string $name): ?string
    {
        $key = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower((string) $name)), '-');
        return isset(self::FALLBACK[$key]) ? $key : null;
    }

    /** Bildadresse für die Anzeige, falls der Name auf der Ausnahmeliste steht. */
    public static function fallbackSrc(?string $name): ?string
    {
        $key = self::fallbackKey($name);
        return $key === null ? null : '/img/extra/' . $key;
    }

    /**
     * Bild aus der Ausnahmeliste: gespeichert ausliefern, sonst einmal laden, prüfen und speichern.
     * Nach einem Fehlschlag wird eine Stunde lang nicht erneut versucht.
     * @return array{path:string,mime:string}|null
     */
    public static function ensureFallback(string $key): ?array
    {
        if (!isset(self::FALLBACK[$key])) {
            return null;
        }
        $name = 'extra-' . $key;
        $dir = self::dir();
        foreach (self::MIME as $ext => $mime) {
            if (is_file("$dir/$name.$ext")) {
                return ['path' => "$dir/$name.$ext", 'mime' => $mime];
            }
        }
        $fail = "$dir/.fail-$name";
        if (is_file($fail) && time() - (int) filemtime($fail) < 3600) {
            return null;
        }
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return null;
        }
        try {
            $img = self::download(self::FALLBACK[$key], self::FALLBACK_HOSTS);
            $saved = $img !== null ? self::storeIn($dir, $name, $img['body'], $img['ext']) : null;
        } catch (NetworkError | \RuntimeException) {
            $saved = null;
        }
        if ($saved === null) {
            @touch($fail);
        }
        return $saved;
    }

    /** Platzhalter, wenn kein Bild verfügbar ist. */
    public static function placeholderSvg(): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 400 300"><rect width="400" height="300" fill="#27272a"/>'
            . '<path d="M200 105l70 90h-45l-25-30-25 30h-45z" fill="#3f3f46"/></svg>';
    }
}
