<?php

declare(strict_types=1);

namespace Hangar\Controllers;

use Hangar\Auth;
use Hangar\Config;
use Hangar\Db;
use Hangar\Env;
use Hangar\Http\HttpException;
use Hangar\Http\Request;
use Hangar\Http\Response;

/**
 * Anmeldung ohne Discord, nur für die lokale Entwicklung (APP_ENV=local und Zugriff über localhost).
 * Legt Testnutzer samt Testorga an. In Produktion ist diese Route nicht registriert.
 */
final class DevController extends Controller
{
    /**
     * Nur mit APP_ENV=local und nur, wenn die App nicht über HTTPS erreichbar sein soll. Ein öffentlicher Server hat immer eine
     * https-Adresse (APP_URL, AUTH_URL oder DOMAIN); steht APP_ENV=local dort versehentlich, bleibt der Dev-Login trotzdem aus,
     * denn er legt Sitzungen für beliebige Nutzer an.
     */
    public static function enabled(): bool
    {
        return Env::get('APP_ENV') === 'local' && !Config::isHttps();
    }

    private static function guard(Request $req): void
    {
        $host = strtolower((string) ($req->header('host') ?? ''));
        $host = preg_replace('/:\d+$/', '', $host);
        if (!self::enabled() || !in_array($host, ['localhost', '127.0.0.1'], true)) {
            throw HttpException::notFound();
        }
    }

    public static function form(Request $req): Response
    {
        self::guard($req);
        $users = Db::all('SELECT id, name, discord_id FROM users ORDER BY created_at DESC LIMIT 20');
        return self::page('dev_login', ['users' => $users, 'wide' => false], 'Dev-Login');
    }

    public static function login(Request $req): Response
    {
        self::guard($req);
        $name = trim($req->input('name', '')) ?: 'Testpilot';
        $existing = $req->input('user_id');
        if ($existing !== null && $existing !== '') {
            $userId = (string) Db::val('SELECT id FROM users WHERE id = ?', [$existing]);
            if ($userId === '') {
                throw HttpException::notFound();
            }
        } else {
            $userId = new_id();
            $discordId = $req->input('discord_id') ?: (string) random_int(100000000000000000, 999999999999999999);
            Db::insert('users', [
                'id' => $userId, 'name' => $name, 'discord_id' => $discordId,
                'membership_checked_at' => '2099-01-01 00:00:00', 'membership_status' => 'OK',
            ]);
        }
        // Testorga "Dev-Orga" mit diesem Nutzer (Admin oder Mitglied)
        $org = Db::one("SELECT id FROM organizations WHERE slug = 'dev-orga'");
        if ($org === null) {
            $org = ['id' => new_id()];
            Db::insert('organizations', [
                'id' => $org['id'], 'slug' => 'dev-orga', 'name' => 'Dev-Orga',
                'discord_guild_id' => 'dev-guild', 'created_by_id' => $userId,
            ]);
        }
        $role = $req->input('role') === 'MEMBER' ? 'MEMBER' : 'ADMIN';
        Db::run(
            'INSERT INTO org_memberships (user_id, org_id, role, can_plan) VALUES (?,?,?,?)
             ON DUPLICATE KEY UPDATE role = VALUES(role), can_plan = VALUES(can_plan)',
            [$userId, $org['id'], $role, $role === 'ADMIN' ? 1 : (int) ($req->input('can_plan') === '1')],
        );
        $s = Auth::createSession($userId);
        return Response::redirect('/', 303)->withCookie(...Auth::sessionCookie($s['token'], $s['expires']));
    }
}
