<?php

declare(strict_types=1);

namespace Hangar;

/** Rate-Limit mit festen Zeitfenstern, in der Datenbank (gilt damit auch über mehrere PHP-Prozesse hinweg). */
final class RateLimit
{
    /** @return array{ok:bool,retryAfterSec:int} */
    public static function hit(string $key, int $limit, int $windowSec, ?int $now = null): array
    {
        $now ??= Time::now()->getTimestamp();
        $bucket = substr($key, 0, 190);
        Db::run(
            'INSERT INTO rate_limits (bucket, window_start, hits) VALUES (?, ?, 1)
             ON DUPLICATE KEY UPDATE
               hits = IF(window_start + ? <= ?, 1, hits + 1),
               window_start = IF(window_start + ? <= ?, ?, window_start)',
            [$bucket, $now, $windowSec, $now, $windowSec, $now, $now],
        );
        $row = Db::one('SELECT window_start, hits FROM rate_limits WHERE bucket = ?', [$bucket]);
        if ($row === null || (int) $row['hits'] <= $limit) {
            return ['ok' => true, 'retryAfterSec' => 0];
        }
        return ['ok' => false, 'retryAfterSec' => max(1, (int) $row['window_start'] + $windowSec - $now)];
    }

    /** Räumt abgelaufene Zähler auf (Fenster länger als eine Stunde vorbei). */
    public static function prune(?int $now = null): void
    {
        $now ??= Time::now()->getTimestamp();
        Db::run('DELETE FROM rate_limits WHERE window_start < ?', [$now - 3600]);
    }
}
