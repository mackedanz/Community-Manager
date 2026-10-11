<?php

declare(strict_types=1);

namespace Hangar\Tests;

use Hangar\App;
use Hangar\Auth;
use Hangar\Community;
use Hangar\Db;
use Hangar\Env;
use Hangar\FleetFilter;
use Hangar\Http\Client;
use Hangar\Http\NetworkError;
use Hangar\Http\Request;
use Hangar\Images\ItemImages;
use Hangar\Images\ShipImages;

/** Lokaler Bild-Cache für Rüstungen und Ausrüstung, sowie das Ausgrauen von Schiffen, die nicht flight ready sind. */
final class ItemImagesTest extends DbTestCase
{
    private const URL = 'https://media.starcitizen.tools/thumb/a/ab/Arden.png/600px-Arden.png.webp';

    private string $dir;
    /** @var list<string> */
    private array $urls = [];
    private string $png;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/hangar-items-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0775, true);
        Env::set('IMAGE_DIR', $this->dir);
        $this->png = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        Db::insert('catalog_items', ['id' => 'a1', 'kind' => 'ARMOR', 'slug' => 'arden-sl-core', 'name' => 'ADP Core', 'match_key' => 'adpcore', 'source' => 'WIKI', 'image_url' => self::URL, 'data' => '{}']);
        Db::insert('item_info', ['match_key' => 'carrackshirt', 'found' => 1, 'name' => 'Carrack T-Shirt', 'image_url' => 'https://cstone.space/uifimages/x.png']);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/items/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir . '/items');
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function fakeHost(?string $body = null, int $status = 200): void
    {
        $body ??= $this->png;
        Client::fake(function (string $m, string $url) use ($body, $status) {
            $this->urls[] = $url;
            return ['status' => $status, 'headers' => ['content-type' => 'image/png'], 'body' => $body];
        });
    }

    public function testDownloadsArmorImageOnFirstCallAndStoresIt(): void
    {
        $this->fakeHost();
        $img = ItemImages::ensure('armor', 'arden-sl-core');
        $this->assertNotNull($img);
        $this->assertSame('image/png', $img['mime']);
        $this->assertSame($this->png, file_get_contents($img['path']));
        $this->assertSame([self::URL], $this->urls);
        $this->assertNull(Db::val("SELECT image_checked_at FROM catalog_items WHERE id = 'a1'"));
        // Dateiname enthält keinen Teil der Eingabe
        $this->assertStringNotContainsString('arden', basename($img['path']));
    }

    public function testSecondCallUsesStoredFileWithoutHttp(): void
    {
        $this->fakeHost();
        ItemImages::ensure('armor', 'arden-sl-core');
        Client::fake(function (): never {
            throw new \LogicException('Kein HTTP-Aufruf erwartet');
        });
        $this->assertNotNull(ItemImages::ensure('armor', 'arden-sl-core'));
    }

    public function testInfoImageForGearAndLoot(): void
    {
        $this->fakeHost();
        $this->assertNotNull(ItemImages::ensure('info', 'carrackshirt'));
        $this->assertSame(['https://cstone.space/uifimages/x.png'], $this->urls);
    }

    public function testRejectsForeignHostsAndStoresNothing(): void
    {
        $this->fakeHost();
        Db::run("UPDATE catalog_items SET image_url = 'https://evil.example/a.png' WHERE id = 'a1'");
        $this->assertNull(ItemImages::ensure('armor', 'arden-sl-core'));
        $this->assertSame([], $this->urls);
        $this->assertSame([], glob($this->dir . '/items/*.png') ?: []);
        $this->assertNotNull(Db::val("SELECT image_checked_at FROM catalog_items WHERE id = 'a1'"));
    }

    public function testRejectsHttpAndNonImages(): void
    {
        Db::run("UPDATE catalog_items SET image_url = 'http://media.starcitizen.tools/a.png' WHERE id = 'a1'");
        $this->fakeHost();
        $this->assertNull(ItemImages::ensure('armor', 'arden-sl-core'));
        $this->assertSame([], $this->urls);

        Db::run('UPDATE catalog_items SET image_url = ?, image_checked_at = NULL WHERE id = ?', [self::URL, 'a1']);
        $this->fakeHost('<html>kein Bild</html>');
        $this->assertNull(ItemImages::ensure('armor', 'arden-sl-core'));
    }

    public function testNoRetryWithin24HoursAfterFailure(): void
    {
        Client::fake(function () {
            throw new NetworkError('offline');
        });
        $this->assertNull(ItemImages::ensure('armor', 'arden-sl-core'));
        $this->assertNotNull(Db::val("SELECT image_checked_at FROM catalog_items WHERE id = 'a1'"));
        $this->fakeHost();
        $this->assertNull(ItemImages::ensure('armor', 'arden-sl-core'));
        $this->assertSame([], $this->urls);

        Db::run('UPDATE catalog_items SET image_checked_at = ? WHERE id = ?', [gmdate('Y-m-d H:i:s', time() - ShipImages::RETRY_SECONDS - 60), 'a1']);
        $this->assertNotNull(ItemImages::ensure('armor', 'arden-sl-core'));

        // Bei Ausrüstung gilt dieselbe Pause (eigene Spalte in item_info)
        Client::fake(function () {
            throw new NetworkError('offline');
        });
        $this->assertNull(ItemImages::ensure('info', 'carrackshirt'));
        $this->assertNotNull(Db::val("SELECT image_checked_at FROM item_info WHERE match_key = 'carrackshirt'"));
    }

    public function testInvalidKeysScopesAndUnknownItemsTouchNothing(): void
    {
        $this->fakeHost();
        foreach (['../etc/passwd', 'a/b', '..', '', "x\0y", str_repeat('a', 200)] as $bad) {
            $this->assertFalse(ItemImages::validKey($bad), $bad);
            $this->assertNull(ItemImages::ensure('armor', $bad));
        }
        $this->assertNull(ItemImages::ensure('ship', 'arden-sl-core'));
        $this->assertNull(ItemImages::ensure('armor', 'gibt-es-nicht'));
        $this->assertNull(ItemImages::ensure('info', 'gibtesnicht'));
        $this->assertSame([], $this->urls);
    }

    public function testPagesPointToTheLocalCacheNeverToForeignServers(): void
    {
        $this->assertSame('/img/armor/arden-sl-core', Community::imageSrc('ARMOR', 'arden-sl-core', self::URL));
        $this->assertNull(Community::imageSrc('ARMOR', 'arden-sl-core', null));
        $this->assertSame('/img/ship/retaliator', Community::imageSrc('SHIP', 'retaliator', null));
    }

    /** @return array<string,string> Cookie einer angemeldeten Testperson */
    private function login(): array
    {
        $u = $this->mkUser(['membership_checked_at' => \Hangar\Time::nowDb(), 'membership_status' => 'OK']);
        return [Auth::COOKIE => Auth::createSession($u['id'])['token']];
    }

    public function testImageRoutesNeedLoginAndDoNotDownloadForGuests(): void
    {
        $this->fakeHost();
        foreach (['/img/armor/arden-sl-core', '/img/info/irgendwas', '/img/ship/retaliator'] as $path) {
            $res = App::handle(new Request('GET', $path));
            $this->assertSame(303, $res->status, $path);
            $this->assertSame('/login', $res->headers['Location'], $path);
        }
        $this->assertSame([], $this->urls, 'ohne Anmeldung wird nichts heruntergeladen');
    }

    public function testRoutesServeStoredImageAndPlaceholder(): void
    {
        $this->fakeHost();
        $c = $this->login();
        $res = App::handle(new Request('GET', '/img/armor/arden-sl-core', [], [], [], $c));
        $this->assertSame(200, $res->status);
        $this->assertSame('image/png', $res->headers['Content-Type']);
        $this->assertSame($this->png, $res->body);

        $etag = $res->headers['ETag'];
        $this->assertStringContainsString('private', $res->headers['Cache-Control']);
        $this->assertSame(304, App::handle(new Request('GET', '/img/armor/arden-sl-core', [], [], ['if-none-match' => $etag], $c))->status);

        $ph = App::handle(new Request('GET', '/img/info/gibtesnicht', [], [], [], $c));
        $this->assertSame(200, $ph->status);
        $this->assertStringContainsString('image/svg+xml', $ph->headers['Content-Type']);

        $this->assertSame(404, App::handle(new Request('GET', '/img/armor/' . rawurlencode('a b'), [], [], [], $c))->status);
    }

    // --- Ausgrauen --------------------------------------------------------------------------

    public function testOnlyKnownNonFlightReadyShipsAreDimmed(): void
    {
        $this->assertNull(FleetFilter::notReadyLabel('flight-ready'));
        $this->assertNull(FleetFilter::notReadyLabel('Flight Ready'));
        $this->assertNull(FleetFilter::notReadyLabel(null));
        $this->assertNull(FleetFilter::notReadyLabel(''));
        $this->assertSame('In Konzept', FleetFilter::notReadyLabel('in-concept'));
        $this->assertSame('In Produktion', FleetFilter::notReadyLabel('in-production'));
        $this->assertSame('Sonderfall', FleetFilter::notReadyLabel('Sonderfall'));
        $this->assertTrue(FleetFilter::isFlightReady(null));
    }

    public function testCatalogMarksConceptShipsAsNotReady(): void
    {
        Db::insert('catalog_items', ['id' => 's1', 'kind' => 'SHIP', 'slug' => 'fertig', 'name' => 'Fertig', 'match_key' => 'fertig', 'source' => 'RSI_MATRIX', 'data' => json_encode(['status' => 'flight-ready'])]);
        Db::insert('catalog_items', ['id' => 's2', 'kind' => 'SHIP', 'slug' => 'konzept', 'name' => 'Konzept', 'match_key' => 'konzept', 'source' => 'RSI_MATRIX', 'data' => json_encode(['status' => 'in-concept'])]);
        $page = App::handle(new Request('GET', '/catalog', ['kind' => 'SHIP'], [], [], $this->login()))->body;
        $this->assertSame(1, substr_count($page, 'nicht flight ready (In Konzept)'));
        $this->assertStringContainsString('grayscale', $page);
    }
}
