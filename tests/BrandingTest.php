<?php

declare(strict_types=1);

namespace Hangar\Tests;

use Hangar\App;
use Hangar\Branding;
use Hangar\Db;
use Hangar\Env;
use Hangar\Http\Request;
use Hangar\OrgError;

/** Logo, Hintergrund und Deckkraft. Den Discord-Befehl /design testet der Bot (BOT/test). */
final class BrandingTest extends DbTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/hangar-brand-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0775, true);
        Env::set('IMAGE_DIR', $this->dir);
        Branding::forget();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/brand/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir . '/brand');
        @rmdir($this->dir);
        Branding::forget();
        parent::tearDown();
    }

    private function image(int $w, int $h, string $type = 'png'): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, (int) imagecolorallocate($im, 30, 120, 200));
        ob_start();
        match ($type) {
            'jpg' => imagejpeg($im),
            'webp' => imagewebp($im),
            default => imagepng($im),
        };
        return (string) ob_get_clean();
    }

    private function files(): array
    {
        return glob($this->dir . '/brand/*') ?: [];
    }

    public function testDefaultsAndCss(): void
    {
        $b = Branding::current();
        $this->assertSame('/logo.png', $b['logoUrl']);
        $this->assertSame('/img/background.jpg', $b['backgroundUrl']);
        $this->assertSame([10, 40], [$b['opacityDark'], $b['opacityLight']]);
        // Überblendung = 1 - Deckkraft: dunkel 90 %, hell 60 %
        $this->assertSame(':root{--bg-overlay:rgb(10 10 10 / 0.90)}:root[data-theme="light"]{--bg-overlay:rgb(250 250 250 / 0.60)}', Branding::css());
    }

    public function testOpacityIsSeparateForDarkAndLightAndValidated(): void
    {
        Branding::setOpacity(25, null);
        $this->assertSame([25, 40], [Branding::current()['opacityDark'], Branding::current()['opacityLight']]);
        Branding::setOpacity(null, 70);
        $this->assertSame([25, 70], [Branding::current()['opacityDark'], Branding::current()['opacityLight']]);
        $this->assertStringContainsString('rgb(10 10 10 / 0.75)', Branding::css());
        $this->assertStringContainsString('rgb(250 250 250 / 0.30)', Branding::css());

        foreach ([[101, null], [-1, null], [null, 150]] as [$d, $l]) {
            try {
                Branding::setOpacity($d, $l);
                $this->fail('Ungültige Deckkraft wurde akzeptiert');
            } catch (OrgError) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame([25, 70], [Branding::current()['opacityDark'], Branding::current()['opacityLight']]);
        Branding::setOpacity(0, 100);   // Grenzen sind erlaubt
        $this->assertSame([0, 100], [Branding::current()['opacityDark'], Branding::current()['opacityLight']]);
    }

    public function testLogoIsShrunkReencodedAndStoredUnderItsHash(): void
    {
        Branding::setImage('logo', $this->image(1024, 1024));
        $b = Branding::current();
        $this->assertTrue($b['logoCustom']);
        $this->assertMatchesRegularExpression('#^/brand/logo-[a-f0-9]{16}\.png$#', $b['logoUrl']);
        $path = Branding::path(basename($b['logoUrl']));
        $this->assertNotNull($path);
        $info = getimagesize($path);
        $this->assertSame([256, 256], [$info[0], $info[1]]);
        $this->assertSame(IMAGETYPE_PNG, $info[2]);
    }

    public function testBackgroundIsShrunkToJpegAndReplacingDeletesTheOldFile(): void
    {
        Branding::setImage('background', $this->image(3000, 1500, 'webp'));
        $first = Branding::current()['backgroundUrl'];
        $info = getimagesize((string) Branding::path(basename($first)));
        $this->assertSame([2000, 1000, IMAGETYPE_JPEG], [$info[0], $info[1], $info[2]]);

        Branding::setImage('background', $this->image(800, 600, 'jpg'));
        $second = Branding::current()['backgroundUrl'];
        $this->assertNotSame($first, $second);
        $this->assertNull(Branding::path(basename($first)));
        $this->assertCount(1, $this->files());
    }

    public function testRejectsUnsuitableFiles(): void
    {
        $bad = [
            'Text' => 'das ist kein Bild',
            'SVG' => '<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100"><script>alert(1)</script></svg>',
            'leer' => '',
            'zu klein' => $this->image(20, 20),
            'zu groß (Pixel)' => $this->image(4100, 100),
        ];
        foreach ($bad as $name => $bytes) {
            try {
                Branding::setImage('logo', $bytes);
                $this->fail("$name wurde akzeptiert");
            } catch (OrgError) {
                $this->addToAssertionCount(1);
            }
        }
        try {
            Branding::setImage('logo', str_repeat('x', Branding::MAX_BYTES + 1));
            $this->fail('Übergroße Datei akzeptiert');
        } catch (OrgError $e) {
            $this->assertStringContainsString('5 MB', $e->getMessage());
        }
        $this->assertSame([], $this->files());
        $this->assertFalse(Branding::current()['logoCustom']);
    }

    public function testResetRestoresDefaultsAndRemovesFiles(): void
    {
        Branding::setImage('logo', $this->image(300, 300));
        Branding::setImage('background', $this->image(1000, 600, 'jpg'));
        Branding::setOpacity(33, 66);

        Branding::reset('logo');
        $this->assertFalse(Branding::current()['logoCustom']);
        $this->assertTrue(Branding::current()['backgroundCustom']);
        Branding::reset('all');
        $b = Branding::current();
        $this->assertSame([false, false, 10, 40], [$b['logoCustom'], $b['backgroundCustom'], $b['opacityDark'], $b['opacityLight']]);
        $this->assertSame([], $this->files());
    }

    public function testCssUsesTheCustomBackgroundImage(): void
    {
        Branding::setImage('background', $this->image(800, 600, 'jpg'));
        $this->assertMatchesRegularExpression('#^:root\{--bg-image:url\("/brand/bg-[a-f0-9]{16}\.jpg"\);--bg-overlay:#', Branding::css());
    }

    public function testBrandFilesAreServedCachedAndOnlyWithValidNames(): void
    {
        Branding::setImage('logo', $this->image(300, 300));
        $url = Branding::current()['logoUrl'];
        $res = App::handle(new Request('GET', $url));
        $this->assertSame(200, $res->status);
        $this->assertSame('image/png', $res->headers['Content-Type']);
        $this->assertStringContainsString('immutable', $res->headers['Cache-Control']);
        $etag = $res->headers['ETag'];
        $this->assertSame(304, App::handle(new Request('GET', $url, [], [], ['if-none-match' => $etag]))->status);

        foreach (['/brand/logo-0000000000000000.png', '/brand/..%2Fbrand%2Fx.png', '/brand/etc-passwd', '/brand/logo-zzzzzzzzzzzzzzzz.png'] as $bad) {
            $this->assertSame(404, App::handle(new Request('GET', $bad))->status, $bad);
        }
    }

    public function testPagesUseTheConfiguredLogoAndOverlay(): void
    {
        Branding::setImage('logo', $this->image(300, 300));
        Branding::setOpacity(20, 55);
        $res = App::handle(new Request('GET', '/login'));
        $this->assertStringContainsString(Branding::current()['logoUrl'], $res->body);
        $this->assertStringContainsString('rgb(10 10 10 / 0.80)', $res->body);
        $this->assertStringContainsString('rgb(250 250 250 / 0.45)', $res->body);
    }
}
