<?php

declare(strict_types=1);

namespace Hangar\Controllers;

use Hangar\Auth;
use Hangar\Http\Request;
use Hangar\Http\Response;
use Hangar\Orgs;

final class HomeController extends Controller
{
    public static function home(Request $req): Response
    {
        $viewer = Auth::viewer($req);
        if ($viewer === null) {
            return self::page('home_guest', ['wide' => false], '');
        }
        if (count($viewer->orgs) === 1) {
            return Response::redirect('/o/' . $viewer->orgs[0]['slug'], 302);
        }
        return self::page('home', ['viewer' => $viewer], '');
    }

    public static function login(Request $req): Response
    {
        if (Auth::viewer($req) !== null) {
            return Response::redirect('/', 302);
        }
        return self::page('login', ['error' => $req->query('error'), 'wide' => false], 'Anmelden');
    }

    public static function logout(Request $req): Response
    {
        Auth::destroySession($req->cookies[Auth::COOKIE] ?? null);
        $to = $req->query('next') === 'login' ? '/login' : '/';
        return Response::redirect($to, 303)->withCookie(Auth::COOKIE, '', Auth::cookieOptions(time() - 3600));
    }

    /** Erneuert die Orga-Mitgliedschaft sofort ("Mitgliedschaft jetzt prüfen"). */
    public static function recheck(Request $req): Response
    {
        $viewer = Auth::requireViewer($req);
        Orgs::syncMemberships($viewer->id);
        return Response::redirect($req->input('back') === 'settings' ? '/settings' : '/', 303);
    }
}
