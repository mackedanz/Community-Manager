<?php

declare(strict_types=1);

namespace Hangar\Tests;

use Hangar\App;
use Hangar\Auth;
use Hangar\Db;
use Hangar\Http\Request;
use Hangar\OrgError;
use Hangar\Training;

/** Eigene Bilder der Ausbildungsbereiche: Orga-Admins laden sie unter "Orga verwalten" hoch, Mitglieder der Orga sehen sie. */
final class TrainingAreaImagesTest extends DbTestCase
{
    private string $orgId;
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/hangar-area-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0775, true);
        \Hangar\Env::set('IMAGE_DIR', $this->dir);
        $this->orgId = new_id();
        Db::insert('organizations', ['id' => $this->orgId, 'slug' => 'exp', 'name' => 'Exp', 'discord_guild_id' => '9', 'created_by_id' => 'x']);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/training/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir . '/training');
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function png(int $w = 512, int $h = 512): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, (int) imagecolorallocate($im, 200, 90, 30));
        ob_start();
        imagepng($im);
        return (string) ob_get_clean();
    }

    /** @return list<string> */
    private function files(): array
    {
        return array_map('basename', glob($this->dir . '/training/*') ?: []);
    }

    /** @return array{0:string,1:string} Sitzungs-Token und CSRF-Token */
    private function login(string $role, ?string $orgId = null): array
    {
        $u = $this->mkUser(['membership_checked_at' => \Hangar\Time::nowDb(), 'membership_status' => 'OK']);
        Db::insert('org_memberships', ['user_id' => $u['id'], 'org_id' => $orgId ?? $this->orgId, 'role' => $role, 'can_plan' => 0]);
        $s = Auth::createSession($u['id']);
        return [$s['token'], (string) Db::val('SELECT csrf_token FROM sessions WHERE token_hash = ?', [hash('sha256', $s['token'])])];
    }

    /** @param array<string,mixed> $post @param array<string,mixed> $files */
    private function call(string $method, string $path, string $token, string $csrf, array $post = [], array $files = []): \Hangar\Http\Response
    {
        Auth::reset();
        return App::handle(new Request($method, $path, [], $post + ($method === 'POST' ? ['_csrf' => $csrf] : []), [], [Auth::COOKIE => $token], '', '0.0.0.0', $files));
    }

    /** Lädt Bilder hoch: Bereich => Bildinhalt. */
    private function upload(string $token, string $csrf, array $bytesByArea, array $post = []): \Hangar\Http\Response
    {
        $files = [];
        $tmps = [];
        foreach ($bytesByArea as $area => $bytes) {
            $tmp = tempnam(sys_get_temp_dir(), 'up');
            file_put_contents($tmp, $bytes);
            $tmps[] = $tmp;
            $files['img_' . $area] = ['error' => UPLOAD_ERR_OK, 'tmp_name' => $tmp];
        }
        $res = $this->call('POST', '/orgs/training-images', $token, $csrf, ['orgId' => $this->orgId] + $post, $files);
        array_map('unlink', $tmps);
        return $res;
    }

    public function testSetReplaceAndRemoveStoreOnlyWhatIsNeeded(): void
    {
        $this->assertSame([], Training::areaImages($this->orgId));
        Training::setAreaImage($this->orgId, 'mining', $this->png());
        $url = Training::areaImages($this->orgId)['mining'];
        $this->assertMatchesRegularExpression('#^/img/area/area-[a-f0-9]{16}\.png$#', $url);
        $this->assertCount(1, $this->files());
        // Das Bild wird verkleinert (höchstens 256 Pixel)
        $size = getimagesize($this->dir . '/training/' . basename($url));
        $this->assertSame(256, max($size[0], $size[1]));

        // Ersetzen: die alte Datei verschwindet
        Training::setAreaImage($this->orgId, 'mining', $this->png(300, 200));
        $this->assertCount(1, $this->files());
        $this->assertNotSame($url, Training::areaImages($this->orgId)['mining']);

        // Zwei Bereiche mit demselben Bild teilen sich die Datei; sie bleibt, bis der letzte Bereich sie loslässt
        Training::setAreaImage($this->orgId, 'space', $this->png(300, 200));
        $this->assertCount(1, $this->files());
        Training::setAreaImage($this->orgId, 'mining', null);
        $this->assertCount(1, $this->files());
        Training::setAreaImage($this->orgId, 'space', null);
        $this->assertSame([], $this->files());
        $this->assertSame([], Training::areaImages($this->orgId));
    }

    public function testBadImagesAndAreasAreRejected(): void
    {
        foreach (['kein Bild' => 'abc', 'zu klein' => $this->png(20, 20), 'zu groß' => $this->png(4100, 100)] as $why => $bytes) {
            try {
                Training::setAreaImage($this->orgId, 'mining', $bytes);
                $this->fail("$why wurde angenommen");
            } catch (OrgError) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame([], $this->files());
        $this->assertSame([], Training::areaImages($this->orgId));
        $this->expectException(OrgError::class);
        Training::setAreaImage($this->orgId, 'unbekannt', $this->png());
    }

    public function testOnlyOrgAdminsMayChangeImages(): void
    {
        [$member, $mCsrf] = $this->login('MEMBER');
        $res = $this->upload($member, $mCsrf, ['mining' => $this->png()]);
        $this->assertSame(303, $res->status);
        $this->assertSame([], Training::areaImages($this->orgId));
        $this->assertSame([], $this->files());

        [$admin, $aCsrf] = $this->login('ADMIN');
        $this->assertSame(303, $this->upload($admin, $aCsrf, ['mining' => $this->png(), 'ground' => $this->png(400, 400)])->status);
        $keys = array_keys(Training::areaImages($this->orgId));
        sort($keys);
        $this->assertSame(['ground', 'mining'], $keys);
        // Zurücksetzen über das Formular
        $this->upload($admin, $aCsrf, [], ['reset_mining' => '1']);
        $this->assertSame(['ground'], array_keys(Training::areaImages($this->orgId)));
    }

    public function testMembersSeeTheImageOthersDoNot(): void
    {
        Db::run("UPDATE organizations SET instructor_role_ids = 'role-i' WHERE id = ?", [$this->orgId]);
        [$admin, $aCsrf] = $this->login('ADMIN');
        $this->upload($admin, $aCsrf, ['mining' => $this->png()]);
        $url = Training::areaImages($this->orgId)['mining'];

        [$member, $mCsrf] = $this->login('MEMBER');
        $res = $this->call('GET', $url, $member, $mCsrf);
        $this->assertSame(200, $res->status);
        $this->assertSame('image/png', $res->headers['Content-Type']);
        // Die Ausbildungsseite und der Bereich zeigen das eigene Bild statt des Symbols
        $this->assertStringContainsString('<img src="' . $url . '"', $this->call('GET', '/o/exp/training', $member, $mCsrf)->body);
        $this->assertStringContainsString('<img src="' . $url . '"', $this->call('GET', '/o/exp/training/mining', $member, $mCsrf)->body);
        $this->assertStringNotContainsString('<img src="/img/area/', $this->call('GET', '/o/exp/training/ground', $member, $mCsrf)->body);

        // Mitglieder anderer Orgas, Abgemeldete und erfundene Namen bekommen es nicht
        $other = new_id();
        Db::insert('organizations', ['id' => $other, 'slug' => 'zz', 'name' => 'Zz', 'discord_guild_id' => '8', 'created_by_id' => 'x']);
        [$stranger, $sCsrf] = $this->login('MEMBER', $other);
        $this->assertSame(404, $this->call('GET', $url, $stranger, $sCsrf)->status);
        $this->assertNotSame(200, $this->call('GET', $url, 'kein-token', 'x')->status, 'Abgemeldete bekommen das Bild nicht');
        $this->assertSame(404, $this->call('GET', '/img/area/area-0000000000000000.png', $member, $mCsrf)->status);
        $this->assertSame(404, $this->call('GET', '/img/area/..%2Fx.png', $member, $mCsrf)->status);
    }

    public function testSettingsPageListsAllAreasAndDeletingTheOrgKeepsNoRows(): void
    {
        [$admin, $aCsrf] = $this->login('ADMIN');
        $body = $this->call('GET', '/o/exp/settings', $admin, $aCsrf)->body;
        $this->assertStringContainsString('Bilder der Ausbildungsbereiche', $body);
        foreach (array_keys(Training::AREAS) as $area) {
            $this->assertStringContainsString('name="img_' . $area . '"', $body, $area);
        }
        Training::setAreaImage($this->orgId, 'space', $this->png());
        $this->assertStringContainsString('Standard-Symbol wiederherstellen', $this->call('GET', '/o/exp/settings', $admin, $aCsrf)->body);
        Db::run('DELETE FROM organizations WHERE id = ?', [$this->orgId]);
        $this->assertSame(0, (int) Db::val('SELECT COUNT(*) FROM training_area_images WHERE org_id = ?', [$this->orgId]));
    }
}
