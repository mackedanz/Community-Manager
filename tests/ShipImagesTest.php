<?php

declare(strict_types=1);

namespace Hangar\Tests;

use Hangar\Config;
use Hangar\Db;
use Hangar\Env;
use Hangar\Http\Client;
use Hangar\Http\NetworkError;
use Hangar\Images\ShipImages;

final class ShipImagesTest extends DbTestCase
{
    private string $dir;
    /** @var list<string> */
    private array $urls = [];
    /** @var list<array<string,mixed>> */
    private array $queries = [];
    private string $png;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/hangar-img-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0775, true);
        Env::set('IMAGE_DIR', $this->dir);
        // kleines gültiges PNG (1x1)
        $this->png = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        Db::insert('catalog_items', ['id' => 'c1', 'kind' => 'SHIP', 'slug' => 'retaliator', 'name' => 'Retaliator', 'match_key' => 'retaliator', 'source' => 'RSI_MATRIX', 'data' => json_encode(['webUrl' => 'https://robertsspaceindustries.com/pledge/ships/aegis-retaliator/Retaliator-Bomber'])]);
    }

    protected function tearDown(): void
    {
        foreach (array_merge(glob($this->dir . '/ships/*') ?: [], glob($this->dir . '/ships/.*') ?: []) as $f) {
            if (is_dir($f)) {
                continue;
            }
            @unlink($f);
        }
        @rmdir($this->dir . '/ships');
        @rmdir($this->dir);
        parent::tearDown();
    }

    /** Fake für die RSI-GraphQL-Schnittstelle und den RSI-Bildspeicher mit einer Weiterleitung wie im echten Betrieb. */
    private function installRsi(?string $imageBody = null, int $imageStatus = 200, string $mediaHost = 'media.robertsspaceindustries.com', ?string $resourceUrl = '/pledge/ships/aegis-retaliator/Retaliator-Bomber'): void
    {
        $imageBody ??= $this->png;
        Client::fake(function (string $m, string $url, array $h, ?string $body) use ($imageBody, $imageStatus, $mediaHost, $resourceUrl) {
            $this->urls[] = $url;
            if ($url === 'https://robertsspaceindustries.com/graphql') {
                $this->queries[] = json_decode((string) $body, true);
                $resources = $resourceUrl === null ? [] : [['url' => $resourceUrl, 'media' => ['thumbnail' => ['slideshow' => "https://$mediaHost/abc/slideshow.jpg"]]]];
                return ['status' => 200, 'headers' => [], 'body' => json_encode(['data' => ['store' => ['search' => ['resources' => $resources]]]])];
            }
            if (str_contains($url, '/abc/slideshow.jpg')) {
                return ['status' => 302, 'headers' => ['location' => "https://$mediaHost/xyz"], 'body' => ''];
            }
            if (str_contains($url, '/xyz')) {
                return ['status' => $imageStatus, 'headers' => ['content-type' => 'image/png'], 'body' => $imageBody];
            }
            return ['status' => 404, 'headers' => [], 'body' => ''];
        });
    }

    public function testDownloadsOnFirstCallAndStoresTheFile(): void
    {
        $this->installRsi();
        $img = ShipImages::ensure('retaliator');
        $this->assertNotNull($img);
        $this->assertSame('image/png', $img['mime']);
        $this->assertFileExists($this->dir . '/ships/retaliator.png');
        $this->assertSame($this->png, file_get_contents($img['path']));
        $this->assertNull(Db::val('SELECT image_checked_at FROM catalog_items WHERE id = ?', ['c1']));
    }

    public function testUsesStoredFileWithoutAnyHttpCall(): void
    {
        $this->installRsi();
        ShipImages::ensure('retaliator');
        $this->urls = [];
        Client::fake(function (): never {
            throw new \LogicException('Es darf kein HTTP-Aufruf stattfinden');
        });
        $this->assertNotNull(ShipImages::ensure('retaliator'));
        $this->assertSame([], $this->urls);
    }

    public function testFallbackListIsMatchedByNormalizedName(): void
    {
        $this->assertSame('dragonfly-star-kitten-edition', ShipImages::fallbackKey('Dragonfly Star Kitten Edition'));
        $this->assertSame('dragonfly-star-kitten-edition', ShipImages::fallbackKey("  dragonfly – STAR kitten edition "));
        $this->assertNull(ShipImages::fallbackKey('Dragonfly Black'));
        $this->assertNull(ShipImages::fallbackKey(null));
        $this->assertSame('/img/extra/dragonfly-star-kitten-edition', ShipImages::fallbackSrc('Dragonfly Star Kitten Edition'));
        $this->assertNull(ShipImages::fallbackSrc('Unbekannt'));
    }

    public function testFallbackImageIsDownloadedOnceFromItsFixedHostAndStored(): void
    {
        $key = 'dragonfly-star-kitten-edition';
        Client::fake(function (string $m, string $url) {
            $this->urls[] = $url;
            return str_starts_with($url, 'https://storage.fltyrd.net/')
                ? ['status' => 200, 'headers' => ['content-type' => 'image/png'], 'body' => $this->png]
                : ['status' => 404, 'headers' => [], 'body' => ''];
        });
        $img = ShipImages::ensureFallback($key);
        $this->assertNotNull($img);
        $this->assertFileExists($this->dir . "/ships/extra-$key.png");
        $this->assertSame(['https://storage.fltyrd.net/iq6atqxvsaqzbx56e49paaq36yfj'], $this->urls);

        $this->urls = [];
        $this->assertNotNull(ShipImages::ensureFallback($key));
        $this->assertSame([], $this->urls, 'Zweiter Aufruf darf nicht erneut laden');
        $this->assertNull(ShipImages::ensureFallback('gibt-es-nicht'));
    }

    public function testFallbackFailureIsNotRetriedImmediately(): void
    {
        $key = 'dragonfly-star-kitten-edition';
        Client::fake(function (string $m, string $url) {
            $this->urls[] = $url;
            return ['status' => 500, 'headers' => [], 'body' => ''];
        });
        $this->assertNull(ShipImages::ensureFallback($key));
        $this->assertNull(ShipImages::ensureFallback($key));
        $this->assertCount(1, $this->urls);
    }

    public function testRejectsDisallowedHostsAndStoresNothing(): void
    {
        $this->installRsi(null, 200, 'evil.example');
        $this->assertNull(ShipImages::ensure('retaliator'));
        $this->assertSame([], glob($this->dir . '/ships/*.png'));
        $this->assertNotNull(Db::val('SELECT image_checked_at FROM catalog_items WHERE id = ?', ['c1']));
        foreach ($this->urls as $u) {
            $this->assertStringNotContainsString('evil.example', $u);
        }
    }

    public function testRejectsNonImagesAndHttpErrors(): void
    {
        $this->installRsi('<html>kein Bild</html>');
        $this->assertNull(ShipImages::ensure('retaliator'));
        Db::run('UPDATE catalog_items SET image_checked_at = NULL');
        $this->installRsi($this->png, 404);
        $this->assertNull(ShipImages::ensure('retaliator'));
        $this->assertSame([], glob($this->dir . '/ships/*') ?: []);
    }

    public function testDoesNotRetryWithin24HoursAfterFailure(): void
    {
        Client::fake(function () {
            throw new NetworkError('offline');
        });
        $this->assertNull(ShipImages::ensure('retaliator'));
        $this->assertNotNull(Db::val('SELECT image_checked_at FROM catalog_items WHERE id = ?', ['c1']));

        $this->urls = [];
        $this->installRsi();
        $this->assertNull(ShipImages::ensure('retaliator'));
        $this->assertSame([], $this->urls);

        // Nach mehr als 24 Stunden wird erneut versucht
        Db::run('UPDATE catalog_items SET image_checked_at = ? WHERE id = ?', [gmdate('Y-m-d H:i:s', time() - ShipImages::RETRY_SECONDS - 60), 'c1']);
        $this->assertNotNull(ShipImages::ensure('retaliator'));
    }

    public function testInvalidSlugsNeverTouchTheFilesystemOrNetwork(): void
    {
        $this->installRsi();
        foreach (['../etc/passwd', 'a/b', '..', '', 'UPPER', str_repeat('a', 130), "x\0y"] as $bad) {
            $this->assertFalse(ShipImages::validSlug($bad), $bad);
            $this->assertNull(ShipImages::ensure($bad));
        }
        $this->assertSame([], $this->urls);
    }

    public function testUnknownCatalogSlugGivesNoImage(): void
    {
        $this->installRsi();
        $this->assertNull(ShipImages::ensure('gibt-es-nicht'));
        $this->assertSame([], $this->urls);
    }

    public function testAsksRsiForTheShipPageByItsMatrixUrl(): void
    {
        $this->installRsi();
        $this->assertNotNull(ShipImages::ensure('retaliator'));
        $this->assertSame(['/pledge/ships/aegis-retaliator/Retaliator-Bomber'], $this->queries[0]['variables']['query']['ships']['urls']);
    }

    public function testNoImageWhenRsiKnowsNoMatchingShip(): void
    {
        $this->installRsi(null, 200, 'media.robertsspaceindustries.com', '/pledge/ships/anderes/Schiff');
        $this->assertNull(ShipImages::ensure('retaliator'));
        $this->installRsi(null, 200, 'media.robertsspaceindustries.com', null);
        Db::run('UPDATE catalog_items SET image_checked_at = NULL');
        $this->assertNull(ShipImages::ensure('retaliator'));
        $this->assertSame([], glob($this->dir . '/ships/*') ?: []);
    }

    public function testShipWithoutStoreLinkMakesNoHttpCall(): void
    {
        Db::run("UPDATE catalog_items SET data = '{}'");
        $this->installRsi();
        $this->assertNull(ShipImages::ensure('retaliator'));
        $this->assertSame([], $this->urls);
    }

    public function testHostAllowlist(): void
    {
        $this->assertTrue(ShipImages::hostAllowed('https://media.robertsspaceindustries.com/x/slideshow.jpg'));
        $this->assertFalse(ShipImages::hostAllowed('http://media.robertsspaceindustries.com/x'));
        $this->assertFalse(ShipImages::hostAllowed('https://media.robertsspaceindustries.com.evil.test/x'));
        $this->assertFalse(ShipImages::hostAllowed('https://evil.test/media.robertsspaceindustries.com'));
        $this->assertFalse(ShipImages::hostAllowed('https://storage.fltyrd.net/x'));
        $this->assertSame($this->dir, Config::imageDir());
    }
}
