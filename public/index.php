<?php

declare(strict_types=1);

use Hangar\App;
use Hangar\Http\Request;
use Hangar\Http\Response;

require dirname(__DIR__) . '/vendor/autoload.php';

date_default_timezone_set('UTC');

try {
    App::handle(Request::fromGlobals())->finish();
} catch (Throwable $e) {
    error_log((string) $e);
    Response::text("Interner Fehler\n", 500)->send();
}
