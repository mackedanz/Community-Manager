<?php

declare(strict_types=1);

namespace Hangar\Http;

final class Response
{
    /** @param array<string,string> $headers */
    public function __construct(
        public int $status = 200,
        public string $body = '',
        public array $headers = [],
        /** @var list<array{0:string,1:string,2:array<string,mixed>}> */
        public array $cookies = [],
        /** @var list<callable():void> Arbeit, die nach dem Senden der Antwort läuft (z. B. Hintergrundabgleich) */
        public array $after = [],
    ) {
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($status, $body, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    /** @param mixed $data */
    public static function json(mixed $data, int $status = 200): self
    {
        return new self($status, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: 'null', [
            'Content-Type' => 'application/json; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    public static function redirect(string $to, int $status = 303): self
    {
        return new self($status, '', ['Location' => $to]);
    }

    public static function text(string $body, int $status = 200, string $type = 'text/plain; charset=utf-8'): self
    {
        return new self($status, $body, ['Content-Type' => $type]);
    }

    public static function notFound(): self
    {
        return self::html(View::render('errors/404', [], 'layout'), 404);
    }

    public function withHeader(string $k, string $v): self
    {
        $this->headers[$k] = $v;
        return $this;
    }

    /** @param array<string,mixed> $options expires, path, secure, httponly, samesite */
    public function withCookie(string $name, string $value, array $options): self
    {
        $this->cookies[] = [$name, $value, $options];
        return $this;
    }

    /** @param callable():void $fn */
    public function afterSend(callable $fn): self
    {
        $this->after[] = $fn;
        return $this;
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $k => $v) {
            header($k . ': ' . $v);
        }
        foreach ($this->cookies as [$name, $value, $options]) {
            setcookie($name, $value, $options);
        }
        echo $this->body;
    }

    /** Antwort beenden und die Nacharbeiten ausführen; Fehler dort betreffen den Nutzer nicht mehr. */
    public function finish(): void
    {
        if ($this->after !== []) {
            // Ohne fastcgi_finish_request (Apache mit mod_php) gilt die Antwort erst als fertig, wenn Länge und
            // Verbindungsende feststehen; sonst wartet der Aufrufer (z. B. Discord, 3 Sekunden) auf die Nacharbeit.
            $this->headers['Content-Length'] = (string) strlen($this->body);
            $this->headers['Connection'] = 'close';
            ignore_user_abort(true);
            set_time_limit(120);
        }
        $this->send();
        if ($this->after === []) {
            return;
        }
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } else {
            while (ob_get_level() > 0) {
                @ob_end_flush();
            }
            flush();
        }
        foreach ($this->after as $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                error_log((string) $e);
            }
        }
    }
}
