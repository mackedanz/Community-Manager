<?php

declare(strict_types=1);

namespace Hangar\Controllers;

use Hangar\ApiToken;
use Hangar\Auth;
use Hangar\Community;
use Hangar\Config;
use Hangar\Constants;
use Hangar\Db;
use Hangar\Http\Flash;
use Hangar\Http\HttpException;
use Hangar\Http\Request;
use Hangar\Http\Response;
use Hangar\Orgs;
use Hangar\Time;

final class SettingsController extends Controller
{
    public static function index(Request $req): Response
    {
        $viewer = Auth::requireViewer($req);
        $user = Db::one('SELECT rsi_handle, hangar_visibility, achievements_visibility, membership_checked_at FROM users WHERE id = ?', [$viewer->id]);
        return self::page('settings', ['viewer' => $viewer, 'user' => $user, 'checkedAt' => Time::parse($user['membership_checked_at'] ?? null), 'wide' => false], 'Einstellungen');
    }

    public static function save(Request $req): Response
    {
        $viewer = Auth::requireViewer($req);
        $h = (string) $req->input('hangarVisibility');
        $a = (string) $req->input('achievementsVisibility');
        if (in_array($h, Constants::VISIBILITIES, true) && in_array($a, Constants::VISIBILITIES, true)) {
            Db::run('UPDATE users SET hangar_visibility = ?, achievements_visibility = ? WHERE id = ?', [$h, $a, $viewer->id]);
            return Flash::ok('/settings', 'Gespeichert.');
        }
        return Response::redirect('/settings');
    }

    public static function deleteAccount(Request $req): Response
    {
        $viewer = Auth::requireViewer($req);
        if ($req->input('confirm') !== 'LÖSCHEN') {
            return Flash::error('/settings', 'Zur Bestätigung bitte LÖSCHEN eintippen.');
        }
        // Erst abmelden, dann alle Daten löschen (Cascade).
        Auth::destroySession($req->cookies[Auth::COOKIE] ?? null);
        Db::run('DELETE FROM users WHERE id = ?', [$viewer->id]);
        return Response::redirect('/')->withCookie(Auth::COOKIE, '', Auth::cookieOptions(time() - 3600));
    }

    // --- API-Tokens --------------------------------------------------------------------------

    private static function tokenPage(Request $req, ?string $token = null, ?string $error = null): Response
    {
        $viewer = Auth::requireViewer($req);
        return self::page('settings_api', [
            'wide' => false,
            'tokens' => ApiToken::list($viewer->id), 'token' => $token, 'error' => $error, 'appUrl' => Config::appUrl(),
        ], 'API-Zugang');
    }

    public static function api(Request $req): Response
    {
        return self::tokenPage($req);
    }

    public static function createToken(Request $req): Response
    {
        $viewer = Auth::requireViewer($req);
        if (count(ApiToken::list($viewer->id)) >= ApiToken::MAX_PER_USER) {
            return self::tokenPage($req, null, 'Maximal ' . ApiToken::MAX_PER_USER . ' Tokens. Widerrufe zuerst ein altes.');
        }
        $t = ApiToken::create($viewer->id, (string) $req->input('name', ''));
        return self::tokenPage($req, $t['token']);
    }

    public static function revokeToken(Request $req): Response
    {
        $viewer = Auth::requireViewer($req);
        ApiToken::revoke($viewer->id, (string) $req->input('id', ''));
        return Response::redirect('/settings/api');
    }

    // --- Profil ------------------------------------------------------------------------------

    public static function profile(Request $req, array $p): Response
    {
        $viewer = Auth::requireViewer($req);
        $profile = Community::getProfile((string) $p['id'], $viewer);
        if ($profile === null) {
            throw HttpException::notFound();
        }
        return self::page('profile', ['profile' => $profile, 'wide' => true], (string) ($profile['user']['name'] ?? 'Profil'));
    }

    /** GET /lizenz: Nutzungsbedingungen und Lizenzvereinbarung (Text aus LIZENZ.md, ohne Anmeldung lesbar). */
    public static function license(Request $req): Response
    {
        $file = dirname(__DIR__, 2) . '/LIZENZ.md';
        $md = is_file($file) ? (string) file_get_contents($file) : "# Lizenz\n\nDie Lizenzdatei fehlt in dieser Installation.";
        return self::page('license', ['html' => \Hangar\SimpleMarkdown::render($md), 'wide' => false], 'Lizenz');
    }

    public static function privacy(Request $req): Response
    {
        return self::page('privacy', ['wide' => false], 'Datenschutz');
    }
}
