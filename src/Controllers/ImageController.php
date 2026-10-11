<?php

declare(strict_types=1);

namespace Hangar\Controllers;

use Hangar\Auth;
use Hangar\Http\HttpException;
use Hangar\Http\Request;
use Hangar\Http\Response;
use Hangar\Images\ItemImages;
use Hangar\Images\ShipImages;

final class ImageController extends Controller
{
    /** GET /img/ship/{slug}: gespeichertes Bild, sonst Download beim ersten Aufruf, sonst Platzhalter. */
    public static function ship(Request $req, array $p): Response
    {
        Auth::requireViewer($req);
        $slug = (string) ($p['slug'] ?? '');
        if (!ShipImages::validSlug($slug)) {
            throw HttpException::notFound();
        }
        return self::serve($req, ShipImages::ensure($slug));
    }

    /** GET /img/extra/{key}: Bild aus der Ausnahmeliste (Einträge, zu denen RSI kein Bild hat). */
    public static function extra(Request $req, array $p): Response
    {
        Auth::requireViewer($req);
        return self::serve($req, ShipImages::ensureFallback((string) ($p['key'] ?? '')));
    }

    /** GET /brand/{file}: Logo und Hintergrund der Installation (ohne Anmeldung, die Anmeldeseite zeigt sie auch). */
    public static function brand(Request $req, array $p): Response
    {
        $file = (string) ($p['file'] ?? '');
        $path = \Hangar\Branding::path($file);
        if ($path === null) {
            throw HttpException::notFound();
        }
        $etag = '"' . pathinfo($file, PATHINFO_FILENAME) . '"';   // der Dateiname enthält den Inhalts-Hash
        $headers = [
            'Content-Type' => str_ends_with($file, '.png') ? 'image/png' : 'image/jpeg',
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'ETag' => $etag,
        ];
        if ($req->header('if-none-match') === $etag) {
            return new Response(304, '', $headers);
        }
        return new Response(200, (string) file_get_contents($path), $headers);
    }

    /** GET /img/armor/{slug}: Rüstungen aus dem Katalog (Quelle: Star Citizen Wiki). */
    public static function armor(Request $req, array $p): Response
    {
        return self::item($req, 'armor', (string) ($p['slug'] ?? ''));
    }

    /** GET /img/info/{key}: Ausrüstung und Loot aus dem Hangar (Quelle: Wiki, über item_info). */
    public static function info(Request $req, array $p): Response
    {
        return self::item($req, 'info', (string) ($p['key'] ?? ''));
    }

    private static function item(Request $req, string $scope, string $key): Response
    {
        Auth::requireViewer($req);
        if (!ItemImages::validKey($key)) {
            throw HttpException::notFound();
        }
        return self::serve($req, ItemImages::ensure($scope, $key));
    }

    /** @param array{path:string,mime:string}|null $img null = Platzhalter */
    private static function serve(Request $req, ?array $img): Response
    {
        if ($img === null) {
            // Kurz cachen, damit ein später erfolgreicher Download bald sichtbar wird
            return Response::text(ShipImages::placeholderSvg(), 200, 'image/svg+xml')
                ->withHeader('Cache-Control', 'private, max-age=300');
        }
        $mtime = (int) filemtime($img['path']);
        $etag = '"' . dechex($mtime) . '-' . dechex((int) filesize($img['path'])) . '"';
        $headers = [
            'Content-Type' => $img['mime'],
            'Cache-Control' => 'private, max-age=604800',
            'ETag' => $etag,
            'Last-Modified' => gmdate('D, d M Y H:i:s', $mtime) . ' GMT',
        ];
        if ($req->header('if-none-match') === $etag) {
            return new Response(304, '', $headers);
        }
        return new Response(200, (string) file_get_contents($img['path']), $headers);
    }
}
