<?php

declare(strict_types=1);

namespace Hangar;

use Hangar\Http\HttpException;
use Hangar\Http\Request;
use Hangar\Http\Response;

/** Sitzungen (Cookie + Tabelle sessions), Anmeldung per Discord und Zugriffsprüfungen für Handler. */
final class Auth
{
    public const COOKIE = 'ch_session';
    public const STATE_COOKIE = 'ch_oauth_state';
    private const LIFETIME = 30 * 86400;

    private static ?Viewer $viewer = null;
    private static bool $loaded = false;

    public static function reset(): void
    {
        self::$viewer = null;
        self::$loaded = false;
    }

    // --- Sitzung -----------------------------------------------------------------------------

    /** Legt eine Sitzung an. @return array{token:string,expires:int} */
    public static function createSession(string $userId): array
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $expires = Time::now()->getTimestamp() + self::LIFETIME;
        Db::insert('sessions', [
            'id' => new_id(),
            'token_hash' => hash('sha256', $token),
            'user_id' => $userId,
            'csrf_token' => bin2hex(random_bytes(16)),
            'expires_at' => gmdate('Y-m-d H:i:s', $expires),
        ]);
        return ['token' => $token, 'expires' => $expires];
    }

    public static function destroySession(?string $token): void
    {
        if ($token !== null && $token !== '') {
            Db::run('DELETE FROM sessions WHERE token_hash = ?', [hash('sha256', $token)]);
        }
    }

    /** @return array{user_id:string,csrf_token:string}|null */
    public static function sessionRow(?string $token): ?array
    {
        if ($token === null || $token === '') {
            return null;
        }
        $row = Db::one(
            'SELECT user_id, csrf_token FROM sessions WHERE token_hash = ? AND expires_at > ?',
            [hash('sha256', $token), Time::nowDb()],
        );
        return $row;
    }

    public static function sessionCookie(string $token, int $expires): array
    {
        return [self::COOKIE, $token, self::cookieOptions($expires)];
    }

    /** @return array<string,mixed> */
    public static function cookieOptions(int $expires): array
    {
        return ['expires' => $expires, 'path' => '/', 'secure' => Config::isHttps(), 'httponly' => true, 'samesite' => 'Lax'];
    }

    // --- Viewer ------------------------------------------------------------------------------

    /**
     * Der angemeldete Nutzer, einmal pro Anfrage. Ist die letzte Mitgliedschaftsprüfung älter als
     * 24 Stunden, wird sie vorher bei Discord wiederholt.
     */
    public static function viewer(Request $req): ?Viewer
    {
        if (self::$loaded) {
            return self::$viewer;
        }
        self::$loaded = true;
        $session = self::sessionRow($req->cookies[self::COOKIE] ?? null);
        if ($session === null) {
            return self::$viewer = null;
        }
        $id = $session['user_id'];
        $user = Db::one('SELECT * FROM users WHERE id = ?', [$id]);
        if ($user === null) {
            return self::$viewer = null;
        }
        // Wer von der Zugangsliste gestrichen wurde, ist sofort abgemeldet (auch mit laufender Sitzung).
        if (!Onboarding::mayLogin($user['discord_id'])) {
            return self::$viewer = null;
        }
        if (Orgs::needsCheck(Time::parse($user['membership_checked_at']))) {
            Orgs::syncMemberships($id);
            $user = Db::one('SELECT * FROM users WHERE id = ?', [$id]) ?? $user;
        }
        return self::$viewer = new Viewer(
            $id,
            $user['name'],
            $user['image'],
            $user['discord_id'],
            Orgs::listMyOrgs($id),
            $user['membership_status'],
            $session['csrf_token'],
        );
    }

    public static function requireViewer(Request $req): Viewer
    {
        $v = self::viewer($req);
        if ($v === null) {
            throw HttpException::redirect('/login');
        }
        return $v;
    }

    /**
     * Orga aus der URL; 404 für Nicht-Mitglieder, damit fremde Orgas nicht erkennbar sind.
     * @return array{0:Viewer,1:array{id:string,slug:string,name:string,iconUrl:?string,role:string,canPlan:bool}}
     */
    public static function requireOrgMember(Request $req, string $slug): array
    {
        $viewer = self::requireViewer($req);
        $org = $viewer->org($slug);
        if ($org === null) {
            throw HttpException::notFound();
        }
        return [$viewer, $org];
    }

    public static function isServerAdmin(?Viewer $v): bool
    {
        if ($v === null || $v->discordId === null) {
            return false;
        }
        return in_array($v->discordId, Config::serverAdminIds(), true);
    }

    // --- CSRF --------------------------------------------------------------------------------

    /** Prüft das Formular-Token einer Anfrage mit Sitzung (zeitkonstant). */
    public static function csrfValid(Request $req): bool
    {
        $session = self::sessionRow($req->cookies[self::COOKIE] ?? null);
        if ($session === null) {
            return false;
        }
        $sent = $req->input('_csrf') ?? $req->header('x-csrf-token') ?? '';
        return hash_equals($session['csrf_token'], $sent);
    }

    // --- Anmeldung per Discord ---------------------------------------------------------------

    public static function redirectUri(): string
    {
        return Config::appUrl() . '/api/auth/callback/discord';
    }

    /** Startet die Anmeldung: Zufalls-State im Cookie, Weiterleitung zu Discord. */
    public static function startLogin(): Response
    {
        $state = bin2hex(random_bytes(16));
        return Response::redirect(Discord::authorizeUrl(self::redirectUri(), $state), 302)
            ->withCookie(self::STATE_COOKIE, $state, self::cookieOptions(time() + 600));
    }

    /**
     * Schließt die Anmeldung ab. Legt Nutzer und Discord-Konto an oder aktualisiert sie, prüft die
     * Orga-Mitgliedschaften und startet eine Sitzung.
     */
    public static function finishLogin(Request $req): Response
    {
        $state = (string) $req->query('state', '');
        $expected = (string) ($req->cookies[self::STATE_COOKIE] ?? '');
        $clear = fn (Response $r): Response => $r->withCookie(self::STATE_COOKIE, '', self::cookieOptions(time() - 3600));
        if ($state === '' || !hash_equals($expected, $state) || $req->query('code') === null) {
            return $clear(Response::redirect('/login?error=state'));
        }
        try {
            $res = Discord::exchangeCode((string) $req->query('code'), self::redirectUri());
        } catch (DiscordAuthError | DiscordUnavailableError) {
            return $clear(Response::redirect('/login?error=discord'));
        }
        if (!Onboarding::mayLogin((string) ($res['profile']['id'] ?? ''))) {
            return $clear(Response::redirect('/login?error=allowlist'));
        }
        $userId = self::upsertUser($res['profile'], $res['token']);
        Orgs::syncMemberships($userId);
        $s = self::createSession($userId);
        return $clear(Response::redirect('/'))->withCookie(...self::sessionCookie($s['token'], $s['expires']));
    }

    /**
     * @param array<string,mixed> $profile Discord-Profil (/users/@me)
     * @param array<string,mixed> $token   Antwort des Token-Endpunkts
     */
    public static function upsertUser(array $profile, array $token): string
    {
        $discordId = (string) $profile['id'];
        $name = (string) ($profile['global_name'] ?? $profile['username'] ?? 'Discord-Nutzer');
        $image = !empty($profile['avatar']) ? "https://cdn.discordapp.com/avatars/{$discordId}/{$profile['avatar']}.png" : null;

        return Db::transaction(function () use ($discordId, $name, $image, $token): string {
            $acc = Db::one("SELECT * FROM accounts WHERE provider = 'discord' AND provider_account_id = ?", [$discordId]);
            $userId = $acc['user_id'] ?? Db::val('SELECT id FROM users WHERE discord_id = ?', [$discordId]);
            if ($userId === null) {
                $userId = new_id();
                Db::insert('users', [
                    'id' => $userId, 'name' => $name,
                    'image' => $image, 'discord_id' => $discordId,
                ]);
            } else {
                Db::run('UPDATE users SET name = ?, image = ?, discord_id = ? WHERE id = ?', [$name, $image, $discordId, $userId]);
            }
            $data = [
                'access_token' => Secrets::encrypt($token['access_token'] ?? null),
                'refresh_token' => Secrets::encrypt($token['refresh_token'] ?? null),
                'expires_at' => time() + (int) ($token['expires_in'] ?? 0),
                'token_type' => $token['token_type'] ?? null,
                'scope' => $token['scope'] ?? null,
            ];
            if ($acc === null) {
                Db::insert('accounts', ['id' => new_id(), 'user_id' => $userId, 'provider' => 'discord', 'provider_account_id' => $discordId] + $data);
            } else {
                Db::run(
                    'UPDATE accounts SET access_token = ?, refresh_token = COALESCE(?, refresh_token), expires_at = ?, token_type = ?, scope = ? WHERE id = ?',
                    [$data['access_token'], $data['refresh_token'], $data['expires_at'], $data['token_type'], $data['scope'], $acc['id']],
                );
            }
            return (string) $userId;
        });
    }
}
