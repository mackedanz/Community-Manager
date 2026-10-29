<?php

declare(strict_types=1);

namespace Hangar\Tests;

use Hangar\App;
use Hangar\Auth;
use Hangar\Db;
use Hangar\Http\Request;
use Hangar\Training;

/** Bereiche der Ausbildung über die Web-Oberfläche: anlegen mit Bild, nur Admins und Ausbilder, Bild nur für Mitglieder der Orga. */
final class TrainingAreasWebTest extends DbTestCase
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
        Db::insert('organizations', ['id' => $this->orgId, 'slug' => 'exp', 'name' => 'Exp', 'discord_guild_id' => '9', 'created_by_id' => 'x', 'instructor_role_ids' => 'role-i']);
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
    private function login(string $role, bool $instruct = false, ?string $orgId = null): array
    {
        $u = $this->mkUser(['membership_checked_at' => \Hangar\Time::nowDb(), 'membership_status' => 'OK']);
        Db::insert('org_memberships', ['user_id' => $u['id'], 'org_id' => $orgId ?? $this->orgId, 'role' => $role, 'can_plan' => 0, 'can_instruct' => (int) $instruct]);
        $s = Auth::createSession($u['id']);
        return [$s['token'], (string) Db::val('SELECT csrf_token FROM sessions WHERE token_hash = ?', [hash('sha256', $s['token'])])];
    }

    /** @param array<string,mixed> $post @param array<string,mixed> $files */
    private function call(string $method, string $path, string $token, string $csrf, array $post = [], array $files = []): \Hangar\Http\Response
    {
        Auth::reset();
        return App::handle(new Request($method, $path, [], $post + ($method === 'POST' ? ['_csrf' => $csrf] : []), [], [Auth::COOKIE => $token], '', '0.0.0.0', $files));
    }

    /** @param array<string,mixed> $post */
    private function saveArea(string $token, string $csrf, array $post, ?string $bytes = null): \Hangar\Http\Response
    {
        $files = [];
        $tmp = null;
        if ($bytes !== null) {
            $tmp = tempnam(sys_get_temp_dir(), 'up');
            file_put_contents($tmp, $bytes);
            $files['image'] = ['error' => UPLOAD_ERR_OK, 'tmp_name' => $tmp];
        }
        $res = $this->call('POST', '/o/exp/training/areas/save', $token, $csrf, $post, $files);
        if ($tmp !== null) {
            unlink($tmp);
        }
        return $res;
    }

    public function testOnlyAdminsAndInstructorsManageAreas(): void
    {
        [$member, $mCsrf] = $this->login('MEMBER');
        $this->saveArea($member, $mCsrf, ['name' => 'Mining']);
        $this->assertSame(0, (int) Db::val('SELECT COUNT(*) FROM training_areas'), 'Mitglieder dürfen nichts anlegen');
        $this->assertStringNotContainsString('Neuer Bereich', $this->call('GET', '/o/exp/training', $member, $mCsrf)->body);

        [$teacher, $tCsrf] = $this->login('MEMBER', true);
        $this->assertSame(303, $this->saveArea($teacher, $tCsrf, ['name' => 'Mining und Salvage', 'description' => 'Erz abbauen'])->status);
        $id = (string) Db::val('SELECT id FROM training_areas');
        $page = $this->call('GET', '/o/exp/training', $teacher, $tCsrf)->body;
        $this->assertStringContainsString('Neuer Bereich', $page);
        $this->assertStringContainsString('Mining und Salvage', $page);
        $this->assertStringContainsString('Erz abbauen', $page);
        // Auf der Bereichsseite lässt sich danach eine Ausbildung anlegen
        $this->assertStringContainsString('Neue Ausbildung', $this->call('GET', '/o/exp/training/' . $id, $teacher, $tCsrf)->body);

        $this->call('POST', '/o/exp/training/areas/delete', $member, $mCsrf, ['id' => $id]);
        $this->assertSame(1, (int) Db::val('SELECT COUNT(*) FROM training_areas'), 'Mitglieder dürfen nichts löschen');
        $this->call('POST', '/o/exp/training/areas/delete', $teacher, $tCsrf, ['id' => $id]);
        $this->assertSame(0, (int) Db::val('SELECT COUNT(*) FROM training_areas'));
    }

    public function testImageIsStoredReplacedRemovedAndOnlyShownToMembers(): void
    {
        [$admin, $aCsrf] = $this->login('ADMIN');
        $this->saveArea($admin, $aCsrf, ['name' => 'Raumkampf'], $this->png());
        $id = (string) Db::val('SELECT id FROM training_areas');
        $url = (string) Training::findArea($this->orgId, $id)['image'];
        $this->assertMatchesRegularExpression('#^/img/area/area-[a-f0-9]{16}\.png$#', $url);
        $this->assertCount(1, $this->files());
        $size = getimagesize($this->dir . '/training/' . basename($url));
        $this->assertSame(256, max($size[0], $size[1]), 'Das Bild wird auf höchstens 256 Pixel verkleinert');

        [$member, $mCsrf] = $this->login('MEMBER');
        $res = $this->call('GET', $url, $member, $mCsrf);
        $this->assertSame(200, $res->status);
        $this->assertSame('image/png', $res->headers['Content-Type']);
        $this->assertStringContainsString('<img src="' . $url . '"', $this->call('GET', '/o/exp/training', $member, $mCsrf)->body);
        $this->assertStringContainsString('<img src="' . $url . '"', $this->call('GET', '/o/exp/training/' . $id, $member, $mCsrf)->body);

        $other = new_id();
        Db::insert('organizations', ['id' => $other, 'slug' => 'zz', 'name' => 'Zz', 'discord_guild_id' => '8', 'created_by_id' => 'x']);
        [$stranger, $sCsrf] = $this->login('MEMBER', false, $other);
        $this->assertSame(404, $this->call('GET', $url, $stranger, $sCsrf)->status);
        $this->assertNotSame(200, $this->call('GET', $url, 'kein-token', 'x')->status);
        $this->assertSame(404, $this->call('GET', '/img/area/area-0000000000000000.png', $member, $mCsrf)->status);

        // Ein neues Bild ersetzt das alte; ohne neue Datei bleibt es; "entfernen" löscht es samt Datei
        $this->saveArea($admin, $aCsrf, ['id' => $id, 'name' => 'Raumkampf'], $this->png(300, 200));
        $this->assertCount(1, $this->files());
        $this->assertNotSame($url, Training::findArea($this->orgId, $id)['image']);
        $this->saveArea($admin, $aCsrf, ['id' => $id, 'name' => 'Raumkampf 2']);
        $this->assertNotNull(Training::findArea($this->orgId, $id)['image']);
        $this->saveArea($admin, $aCsrf, ['id' => $id, 'name' => 'Raumkampf 2', 'removeImage' => '1']);
        $this->assertNull(Training::findArea($this->orgId, $id)['image']);
        $this->assertSame([], $this->files());
    }

    public function testBadImageIsRejectedAndNothingIsSaved(): void
    {
        [$admin, $aCsrf] = $this->login('ADMIN');
        $this->saveArea($admin, $aCsrf, ['name' => 'Kaputt'], 'kein Bild');
        $this->assertSame(0, (int) Db::val('SELECT COUNT(*) FROM training_areas'));
        $this->assertSame([], $this->files());
    }

    public function testDeletingAnAreaRemovesTheImageFile(): void
    {
        [$admin, $aCsrf] = $this->login('ADMIN');
        $this->saveArea($admin, $aCsrf, ['name' => 'Bodenkampf'], $this->png());
        $id = (string) Db::val('SELECT id FROM training_areas');
        $this->assertCount(1, $this->files());
        $this->call('POST', '/o/exp/training/areas/delete', $admin, $aCsrf, ['id' => $id]);
        $this->assertSame([], $this->files());
    }
}
