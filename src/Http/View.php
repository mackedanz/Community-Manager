<?php

declare(strict_types=1);

namespace Hangar\Http;

/** Einfache PHP-Templates unter views/. $layout (optional) bekommt den Inhalt als $content. */
final class View
{
    /** @var array<string,mixed> Variablen, die jedes Template sieht (z. B. Viewer, CSRF-Token) */
    private static array $shared = [];

    /** @param array<string,mixed> $vars */
    public static function share(array $vars): void
    {
        self::$shared = array_merge(self::$shared, $vars);
    }

    public static function resetShared(): void
    {
        self::$shared = [];
    }

    /** @param array<string,mixed> $data */
    public static function render(string $name, array $data = [], ?string $layout = 'layout'): string
    {
        $content = self::file($name, $data);
        if ($layout === null) {
            return $content;
        }
        return self::file($layout, array_merge($data, ['content' => $content]));
    }

    /** @param array<string,mixed> $data */
    public static function partial(string $name, array $data = []): string
    {
        return self::file($name, $data);
    }

    /** @param array<string,mixed> $data */
    private static function file(string $name, array $data): string
    {
        if (!preg_match('#^[a-z0-9_/\-]+$#i', $name)) {
            throw new \InvalidArgumentException('Ungültiger Template-Name');
        }
        $path = dirname(__DIR__, 2) . '/views/' . $name . '.php';
        $vars = array_merge(self::$shared, $data);
        return (static function (string $__path, array $__vars): string {
            extract($__vars, EXTR_SKIP);
            ob_start();
            try {
                include $__path;
            } catch (\Throwable $e) {
                ob_end_clean();
                throw $e;
            }
            return (string) ob_get_clean();
        })($path, $vars);
    }
}
