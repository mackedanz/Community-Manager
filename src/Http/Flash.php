<?php

declare(strict_types=1);

namespace Hangar\Http;

use Hangar\Config;

/**
 * Einmalige Meldung für die nächste Seite (nach einem POST mit Weiterleitung). Liegt kurz in einem
 * Cookie; angezeigt wird sie escaped, es kann also nichts eingeschleust werden.
 */
final class Flash
{
    public const COOKIE = 'ch_flash';

    /** @param 'ok'|'error' $type */
    public static function to(Response $r, string $type, string $message): Response
    {
        $value = json_encode(['t' => $type === 'error' ? 'error' : 'ok', 'm' => mb_substr($message, 0, 300)], JSON_UNESCAPED_UNICODE);
        return $r->withCookie(self::COOKIE, (string) $value, self::options(time() + 60));
    }

    public static function ok(string $to, string $message): Response
    {
        return self::to(Response::redirect($to), 'ok', $message);
    }

    public static function error(string $to, string $message): Response
    {
        return self::to(Response::redirect($to), 'error', $message);
    }

    /** @return array{t:string,m:string}|null */
    public static function read(Request $req): ?array
    {
        $raw = $req->cookies[self::COOKIE] ?? null;
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $d = json_decode($raw, true);
        return is_array($d) && is_string($d['m'] ?? null) ? ['t' => ($d['t'] ?? 'ok') === 'error' ? 'error' : 'ok', 'm' => $d['m']] : null;
    }

    public static function clear(Response $r): Response
    {
        return $r->withCookie(self::COOKIE, '', self::options(time() - 3600));
    }

    /** @return array<string,mixed> */
    private static function options(int $expires): array
    {
        return ['expires' => $expires, 'path' => '/', 'secure' => Config::isHttps(), 'httponly' => true, 'samesite' => 'Lax'];
    }
}
