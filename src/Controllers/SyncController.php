<?php

declare(strict_types=1);

namespace Hangar\Controllers;

use Hangar\Auth;
use Hangar\Bookmarklet;
use Hangar\Community;
use Hangar\Config;
use Hangar\Constants;
use Hangar\Http\Request;
use Hangar\Http\Response;

final class SyncController extends Controller
{
    public static function index(Request $req): Response
    {
        $viewer = Auth::requireViewer($req);
        return self::page('sync', [
            'lastSync' => Community::getLastSync($viewer->id),
            'bookmarklet' => Bookmarklet::build(Config::appUrl()),
        ], 'RSI-Sync');
    }

    /** Wird vom Lesezeichen als Fenster geöffnet; die Daten kommen per postMessage von der RSI-Seite. */
    public static function receive(Request $req): Response
    {
        $viewer = Auth::viewer($req);
        return self::page('sync_receive', [
            'loggedIn' => $viewer !== null,
            'kindLabels' => Constants::KIND_LABELS,
        ], 'RSI-Sync');
    }
}
