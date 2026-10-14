<?php

declare(strict_types=1);

namespace Hangar;

use Hangar\Controllers\AdminController;
use Hangar\Controllers\ApiController;
use Hangar\Controllers\BotController;
use Hangar\Controllers\CatalogController;
use Hangar\Controllers\DevController;
use Hangar\Controllers\EventController;
use Hangar\Controllers\HangarController;
use Hangar\Controllers\HomeController;
use Hangar\Controllers\ImageController;
use Hangar\Controllers\OrgController;
use Hangar\Controllers\SettingsController;
use Hangar\Controllers\SyncController;
use Hangar\Controllers\DepartmentController;
use Hangar\Controllers\TrainingController;
use Hangar\Http\Flash;
use Hangar\Http\Request;
use Hangar\Http\Response;
use Hangar\Http\Router;
use Hangar\Http\View;

/** Verdrahtet Routen und liefert pro Anfrage genau eine Antwort. */
final class App
{
    public static function routes(): Router
    {
        $r = new Router();

        // Start, Anmeldung
        $r->get('/', [HomeController::class, 'home']);
        $r->get('/login', [HomeController::class, 'login']);
        $r->post('/logout', [HomeController::class, 'logout']);
        $r->post('/recheck', [HomeController::class, 'recheck']);
        $r->get('/auth/discord', fn () => Auth::startLogin());
        $r->get('/api/auth/callback/discord', fn (Request $req) => Auth::finishLogin($req));
        $r->get('/healthz', fn () => Response::text("ok
"));
        $r->get('/datenschutz', [SettingsController::class, 'privacy']);
        $r->get('/lizenz', [SettingsController::class, 'license']);

        // Katalog und Bilder
        $r->get('/catalog', [CatalogController::class, 'index']);
        $r->get('/catalog/{kind}/{slug}', [CatalogController::class, 'show']);
        $r->get('/img/ship/{slug}', [ImageController::class, 'ship']);
        $r->get('/img/extra/{key}', [ImageController::class, 'extra']);
        $r->get('/img/armor/{slug}', [ImageController::class, 'armor']);
        $r->get('/img/info/{key}', [ImageController::class, 'info']);
        $r->get('/brand/{file}', [ImageController::class, 'brand']);
        $r->get('/img/dept/{file}', [ImageController::class, 'department']);

        // Mein Hangar
        $r->get('/hangar', [HangarController::class, 'index']);
        $r->get('/hangar/export', [HangarController::class, 'export']);
        $r->post('/hangar/add', [HangarController::class, 'add']);
        $r->post('/hangar/remove', [HangarController::class, 'remove']);
        $r->post('/hangar/remove-all', [HangarController::class, 'removeAll']);

        // Profile, Einstellungen, API-Tokens
        $r->get('/members/{id}', [SettingsController::class, 'profile']);
        $r->get('/settings', [SettingsController::class, 'index']);
        $r->post('/settings', [SettingsController::class, 'save']);
        $r->post('/settings/delete-account', [SettingsController::class, 'deleteAccount']);
        $r->get('/settings/api', [SettingsController::class, 'api']);
        $r->post('/settings/api', [SettingsController::class, 'createToken']);
        $r->post('/settings/api/revoke', [SettingsController::class, 'revokeToken']);

        // RSI-Sync und API (die API prüft Bearer-Token bzw. Sitzung + JSON statt CSRF-Token)
        $r->get('/sync', [SyncController::class, 'index']);
        $r->get('/sync/receive', [SyncController::class, 'receive']);
        $r->post('/api/v1/import/hangar', [ApiController::class, 'importHangar'], false);
        $r->get('/api/v1/me/items', [ApiController::class, 'myItems']);
        // Die Slash-Befehle bedient der Discord-Bot im Ordner BOT/ (früher POST /discord/interactions)

        // Orgas
        $r->post('/orgs/update', [OrgController::class, 'update']);
        $r->post('/orgs/delete', [OrgController::class, 'delete']);
        $r->post('/orgs/sync-allowlist', [OrgController::class, 'syncAllowlist']);
        $r->post('/orgs/rsi', [OrgController::class, 'saveRsi']);
        $r->post('/orgs/handle-style', [OrgController::class, 'saveHandleStyle']);
        $r->post('/orgs/branding', [OrgController::class, 'saveBranding']);
        $r->post('/orgs/discord-events', [OrgController::class, 'saveDiscordEvents']);
        $r->get('/o/{slug}', [OrgController::class, 'home']);
        $r->get('/o/{slug}/members', [OrgController::class, 'members']);
        $r->get('/o/{slug}/hangar', [OrgController::class, 'hangar']);
        $r->get('/o/{slug}/stats', [OrgController::class, 'stats']);
        $r->get('/o/{slug}/settings', [OrgController::class, 'settings']);
        $r->get('/o/{slug}/bot', [BotController::class, 'show']);
        $r->post('/o/{slug}/bot/save', [BotController::class, 'save']);
        $r->post('/o/{slug}/bot/setup', [BotController::class, 'setup']);
        $r->post('/o/{slug}/bot/roles', [BotController::class, 'roles']);
        $r->post('/o/{slug}/bot/role/update', [BotController::class, 'roleUpdate']);
        $r->post('/o/{slug}/bot/role/delete', [BotController::class, 'roleDelete']);
        $r->post('/o/{slug}/bot/creator/add', [BotController::class, 'creatorAdd']);
        $r->post('/o/{slug}/bot/creator/update', [BotController::class, 'creatorUpdate']);
        $r->post('/o/{slug}/bot/creator/delete', [BotController::class, 'creatorDelete']);
        $r->post('/o/{slug}/bot/channel/update', [BotController::class, 'channelUpdate']);
        $r->post('/o/{slug}/bot/channel/delete', [BotController::class, 'channelDelete']);
        $r->post('/o/{slug}/bot/template/save', [BotController::class, 'templateSave']);
        $r->post('/o/{slug}/bot/template/import', [BotController::class, 'templateImport']);
        $r->post('/o/{slug}/bot/template/delete', [BotController::class, 'templateDelete']);
        $r->post('/o/{slug}/bot/template/apply', [BotController::class, 'templateApply']);
        $r->get('/o/{slug}/bot/template/export', [BotController::class, 'templateExport']);
        $r->get('/o/{slug}/departments', [DepartmentController::class, 'index']);
        $r->post('/o/{slug}/departments/save', [DepartmentController::class, 'save']);
        $r->post('/o/{slug}/departments/delete', [DepartmentController::class, 'delete']);
        $r->get('/o/{slug}/departments/{id}', [DepartmentController::class, 'show']);
        $r->get('/o/{slug}/training', [TrainingController::class, 'index']);
        $r->get('/o/{slug}/training/{area}', [TrainingController::class, 'area']);
        $r->post('/o/{slug}/training/completions/remove', [TrainingController::class, 'removeCompletion']);
        $r->post('/o/{slug}/training/{area}/save', [TrainingController::class, 'save']);
        $r->post('/o/{slug}/training/{area}/delete', [TrainingController::class, 'delete']);

        // Planung
        $r->get('/o/{slug}/events', [EventController::class, 'index']);
        $r->get('/o/{slug}/events/new', [EventController::class, 'newForm']);
        $r->post('/o/{slug}/events/save', [EventController::class, 'save']);
        $r->get('/o/{slug}/events/{id}', [EventController::class, 'show']);
        $r->get('/o/{slug}/events/{id}/edit', [EventController::class, 'editForm']);
        $r->post('/o/{slug}/events/{id}/rsvp', [EventController::class, 'rsvp']);
        $r->post('/o/{slug}/events/{id}/slot', [EventController::class, 'slot']);
        $r->post('/o/{slug}/events/{id}/complete', [EventController::class, 'complete']);
        $r->post('/o/{slug}/events/{id}/cancel', [EventController::class, 'cancel']);
        $r->post('/o/{slug}/events/{id}/delete', [EventController::class, 'delete']);

        // Betreiber
        $r->get('/admin', [AdminController::class, 'index']);
        $r->post('/admin/delete-org', [AdminController::class, 'deleteOrg']);
        $r->post('/admin/ban', [AdminController::class, 'ban']);
        $r->post('/admin/unban', [AdminController::class, 'unban']);

        if (DevController::enabled()) {
            $r->get('/dev/login', [DevController::class, 'form']);
            $r->post('/dev/login', [DevController::class, 'login'], false);
        }

        return $r;
    }

    private static function setsFlash(Response $res): bool
    {
        foreach ($res->cookies as [$name, $value]) {
            if ($name === Flash::COOKIE && $value !== '') {
                return true;
            }
        }
        return false;
    }

    /** Seiten, die es unter /pur/… ohne Logo, Navigation und Fußzeile gibt. */
    private const BARE_PATH = '#^/(catalog(/.*)?|hangar(/.*)?|o/[^/]+/hangar)$#';

    public static function handle(Request $req): Response
    {
        $bare = false;
        if (preg_match('#^/pur(/.*)$#', $req->path, $m)) {
            if (!preg_match(self::BARE_PATH, $m[1])) {
                return Response::notFound();
            }
            $bare = true;
            $req = new Request($req->method, $m[1], $req->query, $req->post, $req->headers, $req->cookies, $req->body, $req->ip);
        }
        Auth::reset();
        View::resetShared();
        try {
            $viewer = $req->path === '/healthz' ? null : Auth::viewer($req);
        } catch (\Throwable) {
            $viewer = null;
        }
        $flash = Flash::read($req);
        View::share([
            'viewer' => $viewer,
            'serverAdmin' => Auth::isServerAdmin($viewer),
            'csrf' => $viewer?->csrf ?? '',
            'flash' => $flash,
            'bare' => $bare,
        ]);
        $res = self::routes()->dispatch($req);
        if ($bare) {
            $res = self::keepBare($res);
        }
        // Meldung wurde dieser Seite angezeigt: Cookie löschen (außer die Antwort setzt gerade eine neue)
        if ($flash !== null && !self::setsFlash($res)) {
            Flash::clear($res);
        }
        if ($viewer !== null && !isset($res->headers['Cache-Control'])) {
            // Angemeldete Seiten nicht zwischenspeichern (Zurück-Taste nach dem Abmelden, geteilte Rechner).
            $res->withHeader('Cache-Control', 'private, no-store');
        }
        return $res
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Referrer-Policy', 'same-origin')
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()');
    }

    /** Links und Weiterleitungen auf die drei Seiten bleiben in der Ansicht ohne Rahmen. */
    private static function keepBare(Response $res): Response
    {
        if (isset($res->headers['Location']) && preg_match(self::BARE_PATH, (string) parse_url($res->headers['Location'], PHP_URL_PATH))) {
            $res->headers['Location'] = '/pur' . $res->headers['Location'];
        }
        if (str_contains($res->headers['Content-Type'] ?? '', 'text/html')) {
            $res->body = (string) preg_replace_callback('#(href|action)="(/[^"]*)"#', static fn (array $m): string => preg_match(self::BARE_PATH, (string) parse_url(html_entity_decode($m[2]), PHP_URL_PATH)) ? $m[1] . '="/pur' . $m[2] . '"' : $m[0], $res->body);
        }
        return $res;
    }
}
