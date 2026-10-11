<?php

declare(strict_types=1);

namespace Hangar\Images;

use Hangar\Config;
use Hangar\Db;
use Hangar\Http\NetworkError;
use Hangar\Time;

/**
 * Bilder von Rüstungen (Katalog, Star Citizen Wiki) und von Ausrüstung/Loot (item_info), lazy auf dem Server
 * gespeichert wie die Schiffsbilder: vorhanden = ausliefern, sonst herunterladen, prüfen, speichern.
 * Die Browser der Nutzer laden nie direkt von fremden Servern.
 *
 * scope "armor": key = Katalog-Slug (kind ARMOR). scope "info": key = match_key aus item_info.
 */
final class ItemImages
{
    /** Nur von diesen Hosts wird geladen, nur per https. */
    public const ALLOWED_HOSTS = ['media.starcitizen.tools', 'cstone.space', 'cdn.star-citizen.wiki'];
    public const SCOPES = ['armor', 'info'];

    public static function validKey(string $key): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9][A-Za-z0-9_.\-]{0,159}$/', $key);
    }

    private static function dir(): string
    {
        return Config::imageDir() . '/items';
    }

    /** Dateiname nur aus einem Hash: kein Pfadbestandteil aus Nutzereingaben. */
    private static function name(string $scope, string $key): string
    {
        return $scope . '-' . substr(sha1($scope . ':' . $key), 0, 40);
    }

    /** @return array{path:string,mime:string}|null */
    public static function existing(string $scope, string $key): ?array
    {
        if (!in_array($scope, self::SCOPES, true) || !self::validKey($key)) {
            return null;
        }
        foreach (ShipImages::MIME as $ext => $mime) {
            $path = self::dir() . '/' . self::name($scope, $key) . ".$ext";
            if (is_file($path)) {
                return ['path' => $path, 'mime' => $mime];
            }
        }
        return null;
    }

    /** @return array{table:string,idCol:string,id:string,url:?string,checked:?string}|null */
    private static function source(string $scope, string $key): ?array
    {
        if ($scope === 'armor') {
            $r = Db::one("SELECT id, image_url, image_checked_at FROM catalog_items WHERE kind = 'ARMOR' AND slug = ?", [$key]);
            return $r === null ? null : ['table' => 'catalog_items', 'idCol' => 'id', 'id' => (string) $r['id'], 'url' => $r['image_url'], 'checked' => $r['image_checked_at']];
        }
        $r = Db::one('SELECT match_key, image_url, image_checked_at FROM item_info WHERE found = 1 AND match_key = ?', [$key]);
        return $r === null ? null : ['table' => 'item_info', 'idCol' => 'match_key', 'id' => (string) $r['match_key'], 'url' => $r['image_url'], 'checked' => $r['image_checked_at']];
    }

    /**
     * Liefert das gespeicherte Bild, lädt es bei Bedarf herunter.
     * @return array{path:string,mime:string}|null null = kein Bild verfügbar (Platzhalter zeigen)
     */
    public static function ensure(string $scope, string $key): ?array
    {
        if (!in_array($scope, self::SCOPES, true) || !self::validKey($key)) {
            return null;
        }
        if (($have = self::existing($scope, $key)) !== null) {
            return $have;
        }
        $src = self::source($scope, $key);
        if ($src === null || $src['url'] === null || $src['url'] === '') {
            return null;
        }
        $checked = Time::parse($src['checked']);
        if ($checked !== null && Time::now()->getTimestamp() - $checked->getTimestamp() < ShipImages::RETRY_SECONDS) {
            return null;
        }

        $dir = self::dir();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return null;
        }
        $name = self::name($scope, $key);
        $lock = fopen("$dir/.lock-$name", 'c');
        if ($lock === false) {
            return null;
        }
        try {
            flock($lock, LOCK_EX);
            if (($have = self::existing($scope, $key)) !== null) {
                return $have;
            }
            $saved = null;
            try {
                $img = ShipImages::download($src['url'], self::ALLOWED_HOSTS);
                if ($img !== null) {
                    $saved = ShipImages::storeIn($dir, $name, $img['body'], $img['ext']);
                }
            } catch (NetworkError | \RuntimeException) {
                $saved = null;
            }
            if ($saved === null) {
                Db::run("UPDATE {$src['table']} SET image_checked_at = ? WHERE {$src['idCol']} = ?", [Time::nowDb(), $src['id']]);
            }
            return $saved;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
            @unlink("$dir/.lock-$name");
        }
    }
}
