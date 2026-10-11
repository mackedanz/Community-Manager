<?php

declare(strict_types=1);

namespace Hangar;

use Hangar\Http\Client;
use Hangar\Http\NetworkError;

/**
 * Zugriff auf die Discord-API mit dem OAuth-Token des Nutzers (kein Bot).
 * Scopes: guilds (Serverliste mit Rechten) und guilds.members.read (eigene Rollen auf einem Server).
 */
final class Discord
{
    public const API = 'https://discord.com/api/v10';
    public const TOKEN_URL = 'https://discord.com/api/oauth2/token';
    public const AUTHORIZE_URL = 'https://discord.com/api/oauth2/authorize';
    public const SCOPES = 'identify guilds guilds.members.read';
    private const REQUIRED_SCOPES = ['guilds', 'guilds.members.read'];

    /**
     * Owner, Administrator (0x8) oder "Server verwalten" (0x20). Die Rechte kommen als Dezimalstring,
     * der größer als ein 64-Bit-Integer sein kann; beide Bits liegen in den unteren 6 Bits, deshalb
     * genügt der Rest modulo 64, berechnet ziffernweise.
     *
     * @param array{owner?:bool,permissions?:mixed} $guild
     */
    public static function isGuildAdmin(array $guild): bool
    {
        if (!empty($guild['owner'])) {
            return true;
        }
        $perms = $guild['permissions'] ?? null;
        if (!is_string($perms) && !is_int($perms)) {
            return false;
        }
        $perms = (string) $perms;
        if (!preg_match('/^\d+$/', $perms)) {
            return false;
        }
        $r = 0;
        foreach (str_split($perms) as $d) {
            $r = ($r * 10 + (int) $d) % 64;
        }
        return ($r & 0x8) !== 0 || ($r & 0x20) !== 0;
    }

    /** @param array{id:string,icon?:?string} $guild */
    public static function guildIconUrl(array $guild): ?string
    {
        return !empty($guild['icon']) ? "https://cdn.discordapp.com/icons/{$guild['id']}/{$guild['icon']}.png" : null;
    }

    public static function hasRequiredScopes(?string $scope): bool
    {
        $granted = preg_split('/\s+/', trim((string) $scope)) ?: [];
        foreach (self::REQUIRED_SCOPES as $s) {
            if (!in_array($s, $granted, true)) {
                return false;
            }
        }
        return true;
    }

    /** Gültiges Access-Token des Nutzers, bei Bedarf erneuert. */
    public static function accessToken(string $userId): string
    {
        $acc = Db::one("SELECT * FROM accounts WHERE user_id = ? AND provider = 'discord' LIMIT 1", [$userId]);
        if ($acc === null || empty($acc['access_token'])) {
            throw new DiscordAuthError('Kein Discord-Konto verknüpft.');
        }
        // Konten aus der Zeit vor der Orga-Prüfung haben die Server-Rechte noch nicht.
        if (!self::hasRequiredScopes($acc['scope'] ?? null)) {
            throw new DiscordAuthError('Neue Discord-Berechtigungen nötig.');
        }
        // Die Tokens liegen verschlüsselt in der Datenbank; mit falschem APP_KEY sind sie unlesbar: dann neu anmelden.
        $access = Secrets::decrypt($acc['access_token']);
        $refresh = Secrets::decrypt($acc['refresh_token'] ?? null);
        $expires = (int) ($acc['expires_at'] ?? 0);
        if ($access !== null && $expires > Time::now()->getTimestamp() + 60) {
            return $access;
        }
        if ($refresh === null) {
            throw new DiscordAuthError('Discord-Anmeldung abgelaufen.');
        }
        return self::refresh((string) $acc['id'], $refresh);
    }

    private static function refresh(string $accountId, string $refresh): string
    {
        $res = self::send('POST', self::TOKEN_URL, ['Content-Type' => 'application/x-www-form-urlencoded'], http_build_query([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refresh,
            'client_id' => Env::get('AUTH_DISCORD_ID', ''),
            'client_secret' => Env::get('AUTH_DISCORD_SECRET', ''),
        ]));
        if ($res['status'] === 400 || $res['status'] === 401) {
            throw new DiscordAuthError('Discord-Anmeldung abgelaufen.');
        }
        if ($res['status'] < 200 || $res['status'] >= 300) {
            throw new DiscordUnavailableError("Discord antwortet mit Status {$res['status']}.");
        }
        $body = json_decode($res['body'], true);
        if (!is_array($body) || empty($body['access_token'])) {
            throw new DiscordUnavailableError('Unerwartete Antwort von Discord.');
        }
        Db::run('UPDATE accounts SET access_token = ?, refresh_token = ?, expires_at = ?, scope = COALESCE(?, scope) WHERE id = ?', [
            Secrets::encrypt((string) $body['access_token']),
            Secrets::encrypt((string) ($body['refresh_token'] ?? $refresh)),
            Time::now()->getTimestamp() + (int) ($body['expires_in'] ?? 0),
            $body['scope'] ?? null,
            $accountId,
        ]);
        return (string) $body['access_token'];
    }

    /** @param array<string,string> $headers @return array{status:int,body:string,headers:array<string,string>} */
    private static function send(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        try {
            return Client::request($method, $url, $headers, $body, 20);
        } catch (NetworkError) {
            throw new DiscordUnavailableError('Discord ist nicht erreichbar.');
        }
    }

    /** @return array{status:int,body:string,headers:array<string,string>} */
    private static function get(string $path, string $token): array
    {
        return self::send('GET', self::API . $path, ['Authorization' => 'Bearer ' . $token]);
    }

    /** @return list<array{id:string,name:string,icon:?string,owner:bool,permissions:string}> */
    public static function userGuilds(string $token): array
    {
        $res = self::get('/users/@me/guilds', $token);
        if ($res['status'] === 401 || $res['status'] === 403) {
            throw new DiscordAuthError('Discord verweigert den Zugriff auf die Serverliste.');
        }
        if ($res['status'] < 200 || $res['status'] >= 300) {
            throw new DiscordUnavailableError("Discord antwortet mit Status {$res['status']}.");
        }
        $data = json_decode($res['body'], true);
        return is_array($data) ? array_values($data) : [];
    }

    /**
     * Mitgliedsdaten des Nutzers auf dem Server; null, wenn er dort nicht (mehr) Mitglied ist.
     * @return array{roles:list<string>,nick:?string}|null
     */
    public static function member(string $token, string $guildId): ?array
    {
        $res = self::get('/users/@me/guilds/' . rawurlencode($guildId) . '/member', $token);
        if ($res['status'] === 401) {
            throw new DiscordAuthError('Discord-Anmeldung ungültig.');
        }
        if ($res['status'] === 403 || $res['status'] === 404) {
            return null;
        }
        if ($res['status'] < 200 || $res['status'] >= 300) {
            throw new DiscordUnavailableError("Discord antwortet mit Status {$res['status']}.");
        }
        $body = json_decode($res['body'], true);
        $roles = is_array($body) ? ($body['roles'] ?? []) : [];
        $nick = is_array($body) && is_string($body['nick'] ?? null) && trim($body['nick']) !== '' ? mb_substr(trim($body['nick']), 0, 190) : null;
        return ['roles' => array_values(array_map('strval', is_array($roles) ? $roles : [])), 'nick' => $nick];
    }

    // --- OAuth-Anmeldung (Authorization-Code-Flow) -------------------------------------------

    public static function authorizeUrl(string $redirectUri, string $state): string
    {
        return self::AUTHORIZE_URL . '?' . http_build_query([
            'client_id' => Env::get('AUTH_DISCORD_ID', ''),
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => self::SCOPES,
            'state' => $state,
        ]);
    }

    /**
     * Tauscht den Code gegen Token und lädt das Discord-Profil.
     * @return array{token:array<string,mixed>,profile:array<string,mixed>}
     */
    public static function exchangeCode(string $code, string $redirectUri): array
    {
        $res = self::send('POST', self::TOKEN_URL, ['Content-Type' => 'application/x-www-form-urlencoded'], http_build_query([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'client_id' => Env::get('AUTH_DISCORD_ID', ''),
            'client_secret' => Env::get('AUTH_DISCORD_SECRET', ''),
        ]));
        $token = json_decode($res['body'], true);
        if ($res['status'] !== 200 || !is_array($token) || empty($token['access_token'])) {
            throw new DiscordAuthError('Anmeldung bei Discord fehlgeschlagen.');
        }
        $me = self::get('/users/@me', (string) $token['access_token']);
        $profile = json_decode($me['body'], true);
        if ($me['status'] !== 200 || !is_array($profile) || empty($profile['id'])) {
            throw new DiscordAuthError('Discord-Profil konnte nicht gelesen werden.');
        }
        return ['token' => $token, 'profile' => $profile];
    }
}
