<?php

declare(strict_types=1);

namespace Hangar\Catalog;

use Hangar\Http\Client;
use Hangar\Http\NetworkError;

/** JSON abrufen; bei kurzzeitigen Fehlern (Rate-Limit, 5xx, Verbindungsabbruch) mit wachsender Pause wiederholen. */
final class Fetch
{
    /** @var (callable(int):void)|null Für Tests: ersetzt die Wartezeit */
    public static $sleep = null;

    public static function json(string $url, int $timeout = 60, int $attempts = 4): mixed
    {
        for ($attempt = 1;; $attempt++) {
            try {
                $res = Client::get($url, ['Accept' => 'application/json'], $timeout);
                if ($res['status'] >= 200 && $res['status'] < 300) {
                    $data = json_decode($res['body'], true);
                    if ($data === null && trim($res['body']) !== 'null') {
                        throw new CatalogError("$url: Antwort ist kein JSON");
                    }
                    return $data;
                }
                if ($attempt >= $attempts || ($res['status'] < 500 && $res['status'] !== 429)) {
                    throw new CatalogError("$url: HTTP {$res['status']}");
                }
            } catch (NetworkError $e) {
                if ($attempt >= $attempts) {
                    throw new CatalogError("$url: " . $e->getMessage(), 0, $e);
                }
            }
            $wait = $attempt * 2;
            if (self::$sleep !== null) {
                (self::$sleep)($wait);
            } else {
                sleep($wait);
            }
        }
    }
}
