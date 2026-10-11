<?php

declare(strict_types=1);

namespace Hangar\Http;

final class Request
{
    /** Mehr als das wird nie gelesen (Import: 5 MB); größere Anfragen sind ohnehin abzulehnen. */
    public const MAX_BODY_BYTES = 5 * 1024 * 1024 + 1;

    /**
     * @param array<string,mixed> $query
     * @param array<string,mixed> $post
     * @param array<string,string> $headers Kleingeschriebene Namen
     * @param array<string,string> $cookies
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly array $post = [],
        public readonly array $headers = [],
        public readonly array $cookies = [],
        public readonly string $body = '',
        public readonly string $ip = '0.0.0.0',
        /** @var array<string,mixed> hochgeladene Dateien ($_FILES) */
        public readonly array $files = [],
    ) {
    }

    public static function fromGlobals(): self
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = (string) parse_url($uri, PHP_URL_PATH);
        $path = '/' . trim(rawurldecode($path), '/');
        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = (string) $v;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }
        $in = fopen('php://input', 'r');
        $body = $in === false ? '' : (string) stream_get_contents($in, self::MAX_BODY_BYTES);
        return new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            $path,
            $_GET,
            $_POST,
            $headers,
            $_COOKIE,
            $body,
            (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'),
            $_FILES,
        );
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function query(string $key, ?string $default = null): ?string
    {
        $v = $this->query[$key] ?? null;
        return is_string($v) ? $v : $default;
    }

    public function input(string $key, ?string $default = null): ?string
    {
        $v = $this->post[$key] ?? null;
        return is_string($v) ? $v : $default;
    }

    public function isJson(): bool
    {
        return str_starts_with(strtolower($this->header('content-type') ?? ''), 'application/json');
    }

    public function json(): mixed
    {
        return json_decode($this->body, true);
    }

    public function bearerToken(): ?string
    {
        $h = $this->header('authorization');
        if ($h === null) {
            return null;
        }
        return preg_match('/^Bearer\s+(\S+)$/i', $h, $m) ? $m[1] : '';
    }
}
