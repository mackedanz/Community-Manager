<?php

declare(strict_types=1);

namespace Hangar\Controllers;

use Hangar\Http\Response;
use Hangar\Http\View;

/** Gemeinsame Helfer der Seiten-Handler. */
abstract class Controller
{
    /** @param array<string,mixed> $data */
    protected static function page(string $view, array $data = [], string $title = ''): Response
    {
        return Response::html(View::render($view, $data + ['title' => $title]));
    }

    /** Weiterleitung mit einmaliger Meldung (Flash) für die nächste Seite. */
    protected static function back(string $to): Response
    {
        return Response::redirect($to);
    }
}
