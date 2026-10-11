<?php

declare(strict_types=1);

namespace Hangar;

/**
 * Verschlüsselt Geheimnisse in der Datenbank (Discord-Tokens der Nutzer) mit dem Schlüssel APP_KEY aus der Umgebung.
 * Verfahren: libsodium secretbox (XSalsa20-Poly1305), je Wert ein neuer zufälliger Nonce. Gespeichert wird "enc:v1:" + Base64.
 * Werte ohne diese Kennung gelten als noch unverschlüsselt (Altbestand) und werden unverändert geliefert, bis
 * bin/encrypt-tokens.php sie umschreibt. Mit falschem Schlüssel oder veränderten Daten liefert decrypt() null.
 */
final class Secrets
{
    public const PREFIX = 'enc:v1:';
    public const MIN_KEY_LENGTH = 32;

    private static function key(): string
    {
        $k = (string) Env::get('APP_KEY', '');
        if (strlen($k) < self::MIN_KEY_LENGTH) {
            throw new \RuntimeException('APP_KEY fehlt oder ist zu kurz (mindestens ' . self::MIN_KEY_LENGTH . ' Zeichen, z. B. mit "openssl rand -base64 32" erzeugen).');
        }
        return sodium_crypto_generichash("community-hangar:secrets:v1\0" . $k, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    public static function isEncrypted(?string $value): bool
    {
        return $value !== null && str_starts_with($value, self::PREFIX);
    }

    /** Leere Werte und bereits verschlüsselte bleiben unverändert. */
    public static function encrypt(?string $plain): ?string
    {
        if ($plain === null || $plain === '' || self::isEncrypted($plain)) {
            return $plain;
        }
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return self::PREFIX . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, self::key()));
    }

    /** Klartext, oder null, wenn der Wert fehlt bzw. sich nicht entschlüsseln lässt (falscher Schlüssel, beschädigt). */
    public static function decrypt(?string $stored): ?string
    {
        if ($stored === null || $stored === '') {
            return null;
        }
        if (!self::isEncrypted($stored)) {
            return $stored;   // Altbestand im Klartext
        }
        $raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }
        $plain = sodium_crypto_secretbox_open(
            substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            self::key(),
        );
        return $plain === false ? null : $plain;
    }

    /** Schreibt alle noch unverschlüsselten Tokens um. @return int Anzahl der Konten */
    public static function encryptStoredTokens(): int
    {
        $n = 0;
        foreach (Db::all(
            "SELECT id, access_token, refresh_token FROM accounts
              WHERE (access_token IS NOT NULL AND access_token <> '' AND access_token NOT LIKE 'enc:v1:%')
                 OR (refresh_token IS NOT NULL AND refresh_token <> '' AND refresh_token NOT LIKE 'enc:v1:%')",
        ) as $a) {
            Db::run('UPDATE accounts SET access_token = ?, refresh_token = ? WHERE id = ?', [self::encrypt($a['access_token']), self::encrypt($a['refresh_token']), $a['id']]);
            $n++;
        }
        return $n;
    }
}