<?php

// Router für den PHP-Entwicklungsserver (php -S): Dateien aus public/ direkt ausliefern, sonst index.php.
$path = parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = __DIR__ . $path;
if ($path !== '/' && is_file($file) && !str_ends_with($file, '.php')) {
    return false;
}
require __DIR__ . '/index.php';
