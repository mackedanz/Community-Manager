<?php

declare(strict_types=1);

namespace Hangar\Http;

/** Wird aus Handlern geworfen, um sofort eine bestimmte Antwort zu liefern (404, Weiterleitung, ...). */
final class HttpException extends \RuntimeException
{
    public function __construct(public readonly Response $response)
    {
        parent::__construct('HTTP ' . $response->status);
    }

    public static function notFound(): self
    {
        return new self(Response::notFound());
    }

    public static function redirect(string $to): self
    {
        return new self(Response::redirect($to));
    }

    public static function forbidden(string $msg = 'Nicht erlaubt'): self
    {
        return new self(Response::text($msg, 403));
    }
}
