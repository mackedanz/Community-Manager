<?php

declare(strict_types=1);

namespace Hangar\Controllers;

use Hangar\ApiToken;
use Hangar\Hangar;
use Hangar\Http\Request;
use Hangar\Http\Response;
use Hangar\Import\ImportFormatError;
use Hangar\Import\Importer;
use Hangar\Items\Enrich;
use Hangar\RateLimit;

final class ApiController extends Controller
{
    private const MAX_BYTES = 5 * 1024 * 1024;

    /**
     * POST /api/v1/import/hangar[?dryRun=1]
     * Body: JSON-Export (Content-Type: application/json). Auth: Bearer-Token oder Browser-Sitzung.
     */
    public static function importHangar(Request $req): Response
    {
        $userId = ApiToken::userIdFromRequest($req);
        if ($userId === null) {
            return Response::json(['error' => 'Nicht angemeldet.'], 401);
        }
        // Cookie-Sitzungen dürfen nur mit JSON-Content-Type schreiben (Schutz vor Formular-Angriffen).
        if (!$req->isJson()) {
            return Response::json(['error' => 'Content-Type muss application/json sein.'], 415);
        }
        $limit = RateLimit::hit("import:$userId", 10, 60);
        if (!$limit['ok']) {
            return Response::json(['error' => 'Zu viele Anfragen. Bitte kurz warten.'], 429)->withHeader('Retry-After', (string) $limit['retryAfterSec']);
        }
        if ((int) ($req->header('content-length') ?? 0) > self::MAX_BYTES || strlen($req->body) > self::MAX_BYTES) {
            return Response::json(['error' => 'Datei zu groß (maximal 5 MB).'], 413);
        }
        $json = json_decode($req->body, true);
        if ($json === null && trim($req->body) !== 'null') {
            return Response::json(['error' => 'Ungültiges JSON.'], 400);
        }

        try {
            $plan = Importer::buildPlan($userId, $json);
        } catch (ImportFormatError $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
        $dryRun = $req->query('dryRun') === '1';
        $res = Response::json(['dryRun' => $dryRun, 'plan' => $plan]);
        if (!$dryRun) {
            Importer::applyPlan($userId, $plan, $req->header('authorization') !== null ? 'api' : 'upload');
            // Beschreibungen und Bilder zu Loot und Ausrüstung im Hintergrund nachladen.
            // Fanden Schiffe keinen Katalogeintrag (neue oder umbenannte Schiffe), den Katalog gleich aktualisieren und neu verknüpfen.
            $refresh = \Hangar\Catalog\Refresh::afterImport(count($plan['unmatched'] ?? []));
            $res->afterSend(static function () use ($userId, $refresh): void {
                if ($refresh !== null) {
                    $refresh();
                }
                Enrich::enrichPending($userId);
            });
        }
        return $res;
    }

    /** GET /api/v1/me/items: der eigene Hangar als JSON. */
    public static function myItems(Request $req): Response
    {
        $userId = ApiToken::userIdFromRequest($req);
        if ($userId === null) {
            return Response::json(['error' => 'Nicht angemeldet.'], 401);
        }
        return Response::json(['items' => array_map(fn ($e) => [
            'id' => $e['id'],
            'kind' => $e['kind'],
            'name' => Hangar::entryName($e),
            'slug' => $e['catalogItem']['slug'] ?? null,
            'manufacturer' => $e['catalogItem']['manufacturer'] ?? null,
            'quantity' => $e['quantity'],
            'lti' => $e['lti'],
            'source' => $e['source'],
            'matched' => $e['catalogItem'] !== null,
        ], Hangar::listHangar($userId))]);
    }
}
