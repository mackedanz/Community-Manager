<?php

declare(strict_types=1);

namespace Hangar\Http;

/** Ausgehende HTTP-Aufrufe (cURL). In Tests per Client::fake() ersetzbar. */
final class Client
{
    /** @var (callable(string,string,array<string,string>,?string,int):array{status:int,body:string,headers:array<string,string>})|null */
    private static $fake = null;

    /** @param (callable(string,string,array<string,string>,?string,int):array{status:int,body:string,headers:array<string,string>})|null $handler */
    public static function fake(?callable $handler): void
    {
        self::$fake = $handler;
    }

    /**
     * @param array<string,string> $headers
     * @return array{status:int,body:string,headers:array<string,string>}
     * @throws NetworkError bei Verbindungsfehlern und Zeitüberschreitung
     */
    public static function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 30, bool $follow = true, int $maxBytes = 0): array
    {
        if (self::$fake !== null) {
            return (self::$fake)($method, $url, $headers, $body, $timeout);
        }
        $ch = curl_init($url);
        $out = [];
        foreach ($headers as $k => $v) {
            $out[] = $k . ': ' . $v;
        }
        $respHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => $follow,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_MAXFILESIZE => $maxBytes,
            CURLOPT_HTTPHEADER => $out,
            CURLOPT_USERAGENT => 'CommunityHangar/1.0 (+https://github.com/mackedanz/community-hangar-1)',
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$respHeaders): int {
                $len = strlen($line);
                if (str_contains($line, ':')) {
                    [$k, $v] = explode(':', $line, 2);
                    $respHeaders[strtolower(trim($k))] = trim($v);
                }
                return $len;
            },
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $result = curl_exec($ch);
        if ($result === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new NetworkError($err ?: 'Netzwerkfehler');
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ['status' => $status, 'body' => (string) $result, 'headers' => $respHeaders];
    }

    /** @return array{status:int,body:string,headers:array<string,string>} */
    public static function get(string $url, array $headers = [], int $timeout = 30, bool $follow = true, int $maxBytes = 0): array
    {
        return self::request('GET', $url, $headers, null, $timeout, $follow, $maxBytes);
    }
}
