<?php

declare(strict_types=1);

namespace Hangar\Tests;

use Hangar\App;
use Hangar\Branding;
use Hangar\Db;
use Hangar\Env;
use Hangar\Http\Client;
use Hangar\Http\Request;
use Hangar\Http\Response;
use Hangar\OrgError;

final class BrandingTest extends DbTestCase
{
    private const GUILD = '800000000000000001';
    private string $dir;
    private string $secretKey;
    /** @var list<array{0:string,1:string,2:?string}> */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/hangar-brand-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0775, true);
        Env::set('IMAGE_DIR', $this->dir);
        Branding::forget();
        $pair = sodium_crypto_sign_keypair();
        $this->secretKey = sodium_crypto_sign_secretkey($pair);
        Env::set('DISCORD_PUBLIC_KEY', bin2hex(sodium_crypto_sign_publickey($pair)));
        Env::set('DISCORD_BOT_TOKEN', 'bot-token');
        Env::set('AUTH_DISCORD_ID', '600000000000000001');
        $this->calls = [];
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

    // --- Laden von Discord -----------------------------------------------------------------

    public function testOnlyDiscordImageServersAreAccepted(): void
    {
        $png = $this->image(300, 300);
        Client::fake(function (string $m, string $url) use ($png): array {
            $this->calls[] = [$m, $url, null];
            return ['status' => 200, 'body' => $png, 'headers' => []];
        });
        $this->assertSame($png, Branding::fetch('https://cdn.discordapp.com/attachments/1/2/logo.png?ex=abc&hm=def'));
        $this->assertSame($png, Branding::fetch('https://media.discordapp.net/attachments/1/2/logo.png'));

        $calls = count($this->calls);
        foreach (['https://evil.example/logo.png', 'http://cdn.discordapp.com/x.png', 'https://cdn.discordapp.com.evil.example/x.png', 'https://169.254.169.254/latest'] as $url) {
            try {
                Branding::fetch($url);
                $this->fail("$url wurde geladen");
            } catch (OrgError) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertCount($calls, $this->calls, 'fremde Adressen dürfen nie angefragt werden');
    }

    // --- Discord-Befehl /design ------------------------------------------------------------

    /** @param array<string,mixed> $interaction */
    private function post(array $interaction): Response
    {
        $body = json_encode($interaction);
        $ts = (string) time();
        return App::handle(new Request('POST', '/discord/interactions', [], [], [
            'x-signature-ed25519' => bin2hex(sodium_crypto_sign_detached($ts . $body, $this->secretKey)),
            'x-signature-timestamp' => $ts, 'content-type' => 'application/json',
        ], [], $body));
    }

    private function runAfter(Response $r): void
    {
        $p = new \ReflectionProperty($r, 'after');
        foreach ($p->getValue($r) as $fn) {
            $fn();
        }
    }

    /** @param list<array<string,mixed>> $options @param array<string,mixed> $attachments */
    private function design(array $options, array $attachments = [], string $perms = '32'): Response
    {
        return $this->post([
            'type' => 2, 'token' => 'tok', 'guild_id' => self::GUILD,
            'data' => ['name' => 'design', 'options' => $options, 'resolved' => ['attachments' => $attachments]],
            'member' => ['permissions' => $perms, 'user' => ['id' => '700000000000000001', 'username' => 'chef']],
        ]);
    }

    private function setupOrg(): void
    {
        $u = $this->mkUser();
        Db::insert('organizations', ['id' => new_id(), 'slug' => 'brand-org', 'name' => 'B', 'discord_guild_id' => self::GUILD, 'created_by_id' => $u['id']]);
    }

    public function testDesignCommandIsOnlyForServerAdminsOfASetUpServer(): void
    {
        $this->setupOrg();
        $r = $this->design([['name' => 'deckkraft_dunkel', 'value' => 50]], [], '0');
        $this->assertStringContainsString('Server-Admins', json_decode($r->body, true)['data']['content']);
        $this->assertSame(10, Branding::current()['opacityDark']);
    }

    public function testDesignCommandNeedsASetUpServer(): void
    {
        $r = $this->design([['name' => 'deckkraft_dunkel', 'value' => 50]]);
        $this->assertStringContainsString('/einrichten', json_decode($r->body, true)['data']['content']);
    }

    public function testDesignWithoutOptionsShowsTheCurrentState(): void
    {
        $this->setupOrg();
        $text = json_decode($this->design([])->body, true)['data']['content'];
        $this->assertStringContainsString('Deckkraft dunkel 10 %, hell 40 %', $text);
    }

    public function testDesignCommandSetsImagesAndOpacityAfterTheReply(): void
    {
        $this->setupOrg();
        $png = $this->image(600, 600);
        $jpg = $this->image(1600, 900, 'jpg');
        Client::fake(function (string $m, string $url, array $h, ?string $body) use ($png, $jpg): array {
            $this->calls[] = [$m, $url, $body];
            if (str_contains($url, '/webhooks/')) {
                return ['status' => 200, 'body' => '{}', 'headers' => []];
            }
            return ['status' => 200, 'body' => str_contains($url, 'logo.png') ? $png : $jpg, 'headers' => []];
        });
        $r = $this->design(
            [
                ['name' => 'logo', 'value' => 'a1'], ['name' => 'hintergrund', 'value' => 'a2'],
                ['name' => 'deckkraft_dunkel', 'value' => 15], ['name' => 'deckkraft_hell', 'value' => 80],
            ],
            ['a1' => ['url' => 'https://cdn.discordapp.com/attachments/1/1/logo.png?ex=1'], 'a2' => ['url' => 'https://cdn.discordapp.com/attachments/1/2/bg.jpg?ex=2']],
        );
        $this->assertSame(5, json_decode($r->body, true)['type']);   // "wird bearbeitet": Discord bekommt sofort eine Antwort
        $this->assertFalse(Branding::current()['logoCustom']);
        $this->runAfter($r);

        $b = Branding::current();
        $this->assertTrue($b['logoCustom']);
        $this->assertTrue($b['backgroundCustom']);
        $this->assertSame([15, 80], [$b['opacityDark'], $b['opacityLight']]);
        $last = end($this->calls);
        $this->assertStringContainsString('/webhooks/', $last[1]);
        $this->assertStringContainsString('Logo gesetzt', (string) $last[2]);
    }

    public function testDesignCommandReportsAProblemWithAnAttachment(): void
    {
        $this->setupOrg();
        Client::fake(function (string $m, string $url, array $h, ?string $body): array {
            $this->calls[] = [$m, $url, $body];
            return ['status' => 200, 'body' => str_contains($url, '/webhooks/') ? '{}' : 'kein bild', 'headers' => []];
        });
        $r = $this->design([['name' => 'logo', 'value' => 'a1']], ['a1' => ['url' => 'https://cdn.discordapp.com/attachments/1/1/x.png']]);
        $this->runAfter($r);
        $this->assertFalse(Branding::current()['logoCustom']);
        $this->assertStringContainsString('Nicht möglich', (string) end($this->calls)[2]);
    }

    public function testDesignCommandResetsToDefaults(): void
    {
        $this->setupOrg();
        Branding::setOpacity(70, 70);
        Client::fake(fn () => ['status' => 200, 'body' => '{}', 'headers' => []]);
        $this->runAfter($this->design([['name' => 'zuruecksetzen', 'value' => 'all']]));
        $this->assertSame([10, 40], [Branding::current()['opacityDark'], Branding::current()['opacityLight']]);
    }
}
