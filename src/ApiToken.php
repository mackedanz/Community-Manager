<?php

declare(strict_types=1);

namespace Hangar;

use Hangar\Http\Request;

/** API-Token für Skripte und Erweiterungen. Gespeichert wird nur der SHA-256-Hash. */
final class ApiToken
{
    public const PREFIX = 'sch_';
    public const MAX_PER_USER = 10;

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /** Erzeugt ein Token. Der Klartext wird nur hier zurückgegeben und nirgends gespeichert. @return array{token:string,id:string} */
    public static function create(string $userId, string $name): array
    {
        $token = self::PREFIX . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $id = new_id();
        $name = trim(mb_substr(trim($name), 0, 60));
        Db::insert('api_tokens', ['id' => $id, 'user_id' => $userId, 'name' => $name !== '' ? $name : 'Token', 'token_hash' => self::hash($token)]);
        return ['token' => $token, 'id' => $id];
    }

    /** Gibt die User-ID zum Token zurück oder null. Aktualisiert last_used_at. */
    public static function verify(string $token): ?string
    {
        if (!str_starts_with($token, self::PREFIX)) {
            return null;
        }
        $row = Db::one(
            'SELECT t.id, t.user_id, u.discord_id FROM api_tokens t JOIN users u ON u.id = t.user_id WHERE t.token_hash = ?',
            [self::hash($token)],
        );
        // Wer von der Zugangsliste gestrichen wurde, darf auch mit einem Token nichts mehr (wie bei der Sitzung).
        if ($row === null || !Onboarding::mayLogin($row['discord_id'])) {
            return null;
        }
        Db::run('UPDATE api_tokens SET last_used_at = ? WHERE id = ?', [Time::nowDb(), $row['id']]);
        return $row['user_id'];
    }

    public static function revoke(string $userId, string $tokenId): int
    {
        return Db::exec('DELETE FROM api_tokens WHERE id = ? AND user_id = ?', [$tokenId, $userId]);
    }

    /** @return list<array<string,mixed>> */
    public static function list(string $userId): array
    {
        return Db::all('SELECT id, name, last_used_at, created_at FROM api_tokens WHERE user_id = ? ORDER BY created_at DESC', [$userId]);
    }

    /**
     * Ermittelt den Nutzer einer API-Anfrage: entweder per "Authorization: Bearer sch_…" (Skripte,
     * Erweiterungen) oder über die Sitzung im Browser. Ein vorhandener Authorization-Header gilt
     * ausschließlich; ein ungültiger fällt nie auf die Sitzung zurück.
     */
    public static function userIdFromRequest(Request $req): ?string
    {
        $bearer = $req->bearerToken();
        if ($bearer !== null) {
            return $bearer === '' ? null : self::verify($bearer);
        }
        return Auth::viewer($req)?->id;
    }
}
