<?php

declare(strict_types=1);

namespace Hangar;

/** Abgeleitete Einstellungen. */
final class Config
{
    /** Öffentliche Adresse ohne abschließenden Schrägstrich, z. B. https://hangar.example.de */
    public static function appUrl(): string
    {
        $url = Env::get('AUTH_URL') ?? Env::get('APP_URL');
        if ($url === null) {
            $domain = Env::get('DOMAIN');
            $url = $domain !== null ? 'https://' . $domain : 'http://localhost:8080';
        }
        return rtrim($url, '/');
    }

    public static function isHttps(): bool
    {
        return str_starts_with(self::appUrl(), 'https://');
    }

    public static function shipMatrixUrl(): string
    {
        return Env::get('SHIP_MATRIX_URL', Constants::DEFAULT_SHIP_MATRIX_URL) ?? Constants::DEFAULT_SHIP_MATRIX_URL;
    }

    public static function imageDir(): string
    {
        return rtrim(Env::get('IMAGE_DIR', dirname(__DIR__) . '/storage/images') ?? '', '/\\');
    }

    /** Server (Discord-IDs, kommagetrennt), für die der Bot Orgas einrichten darf. Leer = jeder Server. @return list<string> */
    public static function onboardingGuildIds(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) Env::get('ONBOARDING_GUILD_IDS', ''))), fn ($s) => $s !== ''));
    }

    /** @return list<string> */
    public static function serverAdminIds(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) Env::get('SERVER_ADMIN_DISCORD_ID', ''))), fn ($s) => $s !== ''));
    }
}
