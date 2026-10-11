<?php

declare(strict_types=1);

namespace Hangar;

/** Liest Einstellungen aus der Umgebung; lokal zusätzlich aus php/.env (nicht im Repository). */
final class Env
{
    /** @var array<string,string>|null */
    private static ?array $file = null;
    /** @var array<string,string> */
    private static array $override = [];

    public static function get(string $key, ?string $default = null): ?string
    {
        if (array_key_exists($key, self::$override)) {
            return self::$override[$key];
        }
        $v = getenv($key);
        if ($v !== false && $v !== '') {
            return $v;
        }
        $file = self::file();
        if (isset($file[$key]) && $file[$key] !== '') {
            return $file[$key];
        }
        return $default;
    }

    /** Nur für Tests. */
    public static function set(string $key, ?string $value): void
    {
        if ($value === null) {
            unset(self::$override[$key]);
        } else {
            self::$override[$key] = $value;
        }
    }

    public static function reset(): void
    {
        self::$override = [];
        self::$file = null;
    }

    /** @return array<string,string> */
    private static function file(): array
    {
        if (self::$file !== null) {
            return self::$file;
        }
        self::$file = [];
        $path = dirname(__DIR__) . '/.env';
        if (is_file($path)) {
            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                    continue;
                }
                [$k, $v] = explode('=', $line, 2);
                self::$file[trim($k)] = trim(trim($v), "\"'");
            }
        }
        return self::$file;
    }
}
