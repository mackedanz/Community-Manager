<?php

declare(strict_types=1);

namespace Hangar\Tests;

use Hangar\App;
use Hangar\Auth;
use Hangar\Db;
use Hangar\Http\Request;
use Hangar\OrgError;
use Hangar\Training;

/** Ausbildung: Bereiche, Ausbildungen nach Thema und Stufe, Pflege nur durch Admins und Ausbilder. */
final class TrainingTest extends DbTestCase
{
    private string $orgId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->orgId = new_id();
        Db::insert('organizations', ['id' => $this->orgId, 'slug' => 'exp', 'name' => 'Exp', 'discord_guild_id' => '9', 'created_by_id' => 'x', 'instructor_role_ids' => 'role-ins']);
        Db::insert('training_areas', ['id' => 'mining', 'org_id' => $this->orgId, 'name' => 'Mining und Salvage']);
        Db::insert('training_areas', ['id' => 'space', 'org_id' => $this->orgId, 'name' => 'Raumkampf']);
    }

    /** @return array{0:string,1:string} Sitzungs-Token und CSRF-Token */
    private function login(string $role, bool $instruct = false): array
    {
        $u = $this->mkUser(['membership_checked_at' => \Hangar\Time::nowDb(), 'membership_status' => 'OK']);
        Db::insert('org_memberships', ['user_id' => $u['id'], 'org_id' => $this->orgId, 'role' => $role, 'can_plan' => 0, 'can_instruct' => (int) $instruct]);
        $s = Auth::createSession($u['id']);
        return [$s['token'], (string) Db::val('SELECT csrf_token FROM sessions WHERE token_hash = ?', [hash('sha256', $s['token'])])];
    }

    /** @param array<string,mixed> $post */
    private function call(string $method, string $path, string $token, string $csrf, array $post = []): \Hangar\Http\Response
    {
        Auth::reset();
        return App::handle(new Request($method, $path, [], $post + ($method === 'POST' ? ['_csrf' => $csrf] : []), [], [Auth::COOKIE => $token]));
    }

    private function course(array $over = []): array
    {
        return $over + ['area' => 'mining', 'topic' => 'Prospector', 'level' => '1', 'title' => 'Grundlagen', 'content' => 'Erst lesen: https://example.org/mining.'];
    }

    public function testAreasAreCreatedPerOrgWithUniqueNames(): void
    {
        $id = Training::saveArea($this->orgId, ['name' => '  Handel   und Fracht ', 'description' => "Waren
bewegen"]);
        $a = Training::findArea($this->orgId, $id);
        $this->assertSame('Handel und Fracht', $a['name']);
        $this->assertSame("Waren
bewegen", $a['description']);
        $this->assertNull($a['image']);
        $this->assertNull(Training::findArea($this->orgId, 'gibt-es-nicht'));

        foreach ([['name' => 'x'], ['name' => ''], ['name' => 'Mining und Salvage'], ['name' => 'Gut', 'description' => str_repeat('a', 8001)]] as $bad) {
            try {
                Training::saveArea($this->orgId, $bad);
                $this->fail('Eingabe hätte abgelehnt werden müssen: ' . json_encode($bad));
            } catch (OrgError) {
                $this->addToAssertionCount(1);
            }
        }
        // Umbenennen ist erlaubt, auch auf den eigenen Namen
        Training::saveArea($this->orgId, ['name' => 'Handel'], $id);
        Training::saveArea($this->orgId, ['name' => 'Handel', 'description' => 'neu'], $id);
        $this->assertSame('neu', Training::findArea($this->orgId, $id)['description']);

        // Derselbe Name in einer anderen Orga ist kein Problem; fremde Bereiche lassen sich nicht ändern
        $other = new_id();
        Db::insert('organizations', ['id' => $other, 'slug' => 'zz', 'name' => 'Zz', 'discord_guild_id' => '8', 'created_by_id' => 'x']);
        $foreign = Training::saveArea($other, ['name' => 'Handel']);
        $this->assertSame(['Handel', 'Mining und Salvage', 'Raumkampf'], array_column(Training::areas($this->orgId, new \DateTimeImmutable('now', new \DateTimeZone('UTC'))), 'name'));
        $this->expectException(OrgError::class);
        Training::saveArea($this->orgId, ['name' => 'Fremd'], $foreign);
    }

    public function testDeletingAnAreaRemovesItsCoursesButKeepsCompletions(): void
    {
        $course = Training::save($this->orgId, $this->course());
        $user = $this->mkUser();
        Db::insert('training_completions', ['user_id' => $user['id'], 'course_id' => $course, 'org_id' => $this->orgId, 'area_name' => 'Mining und Salvage', 'topic' => 'Prospector', 'level' => 1, 'title' => 'Grundlagen']);
        Training::deleteArea($this->orgId, 'mining');
        $this->assertNull(Training::findArea($this->orgId, 'mining'));
        $this->assertSame(0, (int) Db::val('SELECT COUNT(*) FROM training_courses'));
        $q = Training::qualifications($this->orgId, $user['id']);
        $this->assertSame(['Mining und Salvage'], array_column($q, 'areaName'));
        $this->assertSame([true], array_column($q, 'retired'));
        // Ohne Bereich gibt es keine Ausbildung
        $this->expectException(OrgError::class);
        Training::save($this->orgId, $this->course());
    }

    public function testSaveValidatesAndKeepsLevelsUniquePerTopic(): void
    {
        $id = Training::save($this->orgId, $this->course());
        $this->assertSame(['Prospector' => 1], array_map('count', Training::listByArea($this->orgId, 'mining')));

        foreach ([
            ['topic' => 'x'], ['title' => ''], ['level' => '0'], ['level' => 'abc'], ['level' => '99'], ['area' => 'kochen'],
        ] as $bad) {
            try {
                Training::save($this->orgId, $this->course($bad));
                $this->fail('Eingabe hätte abgelehnt werden müssen: ' . json_encode($bad));
            } catch (OrgError) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(OrgError::class);
        $this->expectExceptionMessage('schon eine Stufe 1');
        try {
            Training::save($this->orgId, $this->course(['title' => 'Doppelt']));
        } finally {
            // Die Stufe lässt sich aber umbenennen und umnummerieren.
            Training::save($this->orgId, $this->course(['title' => 'Neu', 'level' => '2']), $id);
            $this->assertSame([2], array_column(Training::listByArea($this->orgId, 'mining')['Prospector'], 'level'));
        }
    }

    public function testListIsOrderedByTopicAndLevelAndCounted(): void
    {
        Training::save($this->orgId, $this->course(['level' => '2', 'title' => 'Zwei']));
        Training::save($this->orgId, $this->course(['level' => '1', 'title' => 'Eins']));
        Training::save($this->orgId, $this->course(['topic' => 'Salvage', 'title' => 'Wracks']));
        Training::save($this->orgId, $this->course(['area' => 'space', 'topic' => 'Jäger']));
        $list = Training::listByArea($this->orgId, 'mining');
        $this->assertSame(['Prospector', 'Salvage'], array_keys($list));
        $this->assertSame(['Eins', 'Zwei'], array_column($list['Prospector'], 'title'));
        $counts = array_column(Training::areas($this->orgId, new \DateTimeImmutable('now', new \DateTimeZone('UTC'))), 'courses', 'id');
        $this->assertSame(3, $counts['mining']);
        $this->assertSame(1, $counts['space']);
        $this->assertSame(4, Training::courseCount($this->orgId));
    }

    public function testContentLinksAreEscapedAndClickable(): void
    {
        $html = Training::renderContent("<script>x</script>\nSiehe https://example.org/a?b=1&c=2.");
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('href="https://example.org/a?b=1&amp;c=2"', $html);
        $this->assertStringContainsString('rel="noopener noreferrer"', $html);
    }

    public function testHomeTileIsDisabledWithoutInstructorRole(): void
    {
        Db::run('UPDATE organizations SET instructor_role_ids = NULL WHERE id = ?', [$this->orgId]);
        [$member, $csrf] = $this->login('MEMBER');
        $home = $this->call('GET', '/o/exp', $member, $csrf)->body;
        $this->assertStringContainsString('Ausbildung', $home);
        $this->assertSame(404, $this->call('GET', '/o/exp/training', $member, $csrf)->status, 'ohne Ausbilder-Rolle auch nicht per Adresse');
        $this->assertSame(404, $this->call('GET', '/o/exp/training/mining', $member, $csrf)->status);
        $this->assertStringNotContainsString('href="/o/exp/training"', $home, 'ohne Ausbilder-Rolle kein Link');
        $this->assertSame(2, substr_count($home, 'opacity-5'), 'Ausbildung und Abteilungen sind ausgegraut');

        Db::run('UPDATE organizations SET instructor_role_ids = ? WHERE id = ?', ['role-ins', $this->orgId]);
        $home = $this->call('GET', '/o/exp', $member, $csrf)->body;
        $this->assertStringContainsString('href="/o/exp/training"', $home);
        $this->assertSame(1, substr_count($home, 'opacity-5'), 'nur noch die Abteilungen sind ausgegraut');
    }

    public function testMembersSeePagesButOnlyAdminsAndInstructorsMayChange(): void
    {
        [$member, $mCsrf] = $this->login('MEMBER');
        [$teacher, $tCsrf] = $this->login('MEMBER', true);
        [$admin, $aCsrf] = $this->login('ADMIN');

        $this->assertStringContainsString('Mining und Salvage', $this->call('GET', '/o/exp/training', $member, $mCsrf)->body);
        $this->assertSame(404, $this->call('GET', '/o/exp/training/kochen', $member, $mCsrf)->status);
        $page = $this->call('GET', '/o/exp/training/mining', $member, $mCsrf);
        $this->assertSame(200, $page->status);
        $this->assertStringNotContainsString('Neue Ausbildung', $page->body, 'Mitglieder sehen kein Formular');

        $this->call('POST', '/o/exp/training/mining/save', $member, $mCsrf, $this->course());
        $this->assertSame(0, (int) Db::val('SELECT COUNT(*) FROM training_courses'), 'Mitglieder dürfen nichts anlegen');

        $this->call('POST', '/o/exp/training/mining/save', $teacher, $tCsrf, $this->course());
        $this->assertSame(1, (int) Db::val('SELECT COUNT(*) FROM training_courses'), 'Ausbilder dürfen anlegen');
        $this->assertStringContainsString('Neue Ausbildung', $this->call('GET', '/o/exp/training/mining', $teacher, $tCsrf)->body);
        $id = (string) Db::val('SELECT id FROM training_courses');

        $this->call('POST', '/o/exp/training/mining/delete', $member, $mCsrf, ['id' => $id]);
        $this->assertSame(1, (int) Db::val('SELECT COUNT(*) FROM training_courses'), 'Mitglieder dürfen nichts löschen');
        $this->call('POST', '/o/exp/training/mining/save', $admin, $aCsrf, $this->course(['id' => $id, 'title' => 'Geändert']));
        $this->assertSame('Geändert', Db::val('SELECT title FROM training_courses WHERE id = ?', [$id]));
        $this->call('POST', '/o/exp/training/mining/delete', $admin, $aCsrf, ['id' => $id]);
        $this->assertSame(0, (int) Db::val('SELECT COUNT(*) FROM training_courses'), 'Admins dürfen löschen');
    }

    public function testConfirmationFormPostsCheckedParticipants(): void
    {
        [$teacher, $tCsrf] = $this->login('MEMBER', true);
        [$member, $mCsrf] = $this->login('MEMBER');
        $memberId = (string) Db::val('SELECT user_id FROM sessions WHERE token_hash = ?', [hash('sha256', $member)]);
        $course = Training::save($this->orgId, $this->course());
        $teacherId = (string) Db::val('SELECT user_id FROM sessions WHERE token_hash = ?', [hash('sha256', $teacher)]);
        Db::run('UPDATE org_memberships SET can_plan = 1 WHERE user_id = ?', [$teacherId]);
        $ev = \Hangar\Events::save($teacherId, $this->orgId, ['title' => 'Kurs', 'startsAt' => '2030-01-10T20:00', 'courseId' => $course, 'ships' => []]);
        Db::insert('event_rsvps', ['event_id' => $ev['id'], 'user_id' => $memberId, 'status' => 'YES']);

        $this->call('POST', '/o/exp/events/' . $ev['id'] . '/complete', $member, $mCsrf, ['passed' => [$memberId]]);
        $this->assertSame(0, (int) Db::val('SELECT COUNT(*) FROM training_completions'), 'Mitglieder dürfen nicht bestätigen');

        $r = $this->call('POST', '/o/exp/events/' . $ev['id'] . '/complete', $teacher, $tCsrf, ['passed' => [$memberId]]);
        $this->assertSame(303, $r->status);
        $this->assertSame(1, (int) Db::val('SELECT COUNT(*) FROM training_completions'));
        $this->call('POST', '/o/exp/events/' . $ev['id'] . '/complete', $teacher, $tCsrf);
        $this->assertSame(0, (int) Db::val('SELECT COUNT(*) FROM training_completions'), 'ohne Haken wird zurückgenommen');
    }

    public function testCoursesOfOtherOrgsAreNotTouched(): void
    {
        $other = new_id();
        Db::insert('organizations', ['id' => $other, 'slug' => 'zz', 'name' => 'Zz', 'discord_guild_id' => '8', 'created_by_id' => 'x']);
        Db::insert('training_areas', ['id' => 'mining-zz', 'org_id' => $other, 'name' => 'Mining und Salvage']);
        $foreign = Training::save($other, $this->course(['area' => 'mining-zz']));
        [$admin, $csrf] = $this->login('ADMIN');
        $this->call('POST', '/o/exp/training/mining/delete', $admin, $csrf, ['id' => $foreign]);
        $this->assertSame(1, (int) Db::val('SELECT COUNT(*) FROM training_courses WHERE id = ?', [$foreign]));
        $this->call('POST', '/o/exp/training/mining/save', $admin, $csrf, $this->course(['id' => $foreign, 'title' => 'Fremd']));
        $this->assertSame('Grundlagen', Db::val('SELECT title FROM training_courses WHERE id = ?', [$foreign]));
    }
}
