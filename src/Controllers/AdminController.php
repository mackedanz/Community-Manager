<?php

declare(strict_types=1);

namespace Hangar\Controllers;

use Hangar\Auth;
use Hangar\Http\Flash;
use Hangar\Http\HttpException;
use Hangar\Http\Request;
use Hangar\Http\Response;
use Hangar\OrgError;
use Hangar\ServerAdmin;

/** Betreiber-Bereich: für alle anderen Nutzer (und Gäste) gibt es ihn nicht (404). */
final class AdminController extends Controller
{
    private static function adminId(Request $req): string
    {
        $viewer = Auth::viewer($req);
        if ($viewer === null || !ServerAdmin::isAdmin($viewer->id)) {
            throw HttpException::notFound();
        }
        return $viewer->id;
    }

    public static function index(Request $req): Response
    {
        $id = self::adminId($req);
        return self::page('admin', ['orgs' => ServerAdmin::listAllOrgs($id), 'bans' => ServerAdmin::listBans($id), 'wide' => false], 'Server-Admin');
    }

    /** @param callable(string):string $fn */
    private static function run(Request $req, callable $fn): Response
    {
        $id = self::adminId($req);
        try {
            return Flash::ok('/admin', $fn($id));
        } catch (OrgError $e) {
            return Flash::error('/admin', $e->getMessage());
        }
    }

    public static function deleteOrg(Request $req): Response
    {
        return self::run($req, function (string $id) use ($req): string {
            ServerAdmin::deleteOrg($id, (string) $req->input('orgId', ''));
            return 'Orga gelöscht.';
        });
    }

    public static function ban(Request $req): Response
    {
        return self::run($req, function (string $id) use ($req): string {
            ServerAdmin::banGuild($id, (string) $req->input('guildId', ''), (string) $req->input('name', ''), (string) $req->input('reason', ''));
            return 'Server gesperrt.';
        });
    }

    public static function unban(Request $req): Response
    {
        return self::run($req, function (string $id) use ($req): string {
            ServerAdmin::unbanGuild($id, (string) $req->input('guildId', ''));
            return 'Sperre aufgehoben.';
        });
    }
}
