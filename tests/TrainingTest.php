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
        Db::insert('organizations', ['id' => $this->orgId, 'slug' => 'exp', 'name' => 'Exp', 'discord_guild_id' => '9', 'created_by_id' => 'x']);
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

    public function testAreasAreFixedAndCleaned(): void
    {
        $this->assertSame(['mining', 'engineering', 'transport', 'ground', 'space', 'science', 'exploration'], array_keys(Training::AREAS));
        $this->assertSame('mining', Training::cleanArea('mining'));
        $this->assertNull(Training::cleanArea('kochen'));
        $this->assertNull(Training::cleanArea(null));
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
        $counts = Training::countsByArea($this->orgId);
        $this->assertSame(3, $counts['mining']);
        $this->assertSame(1, $counts['space']);
        $this->assertSame(0, $counts['ground']);
    }

    public function testContentLinksAreEscapedAndClickable(): void
    {
        $html = Training::renderContent("<script>x</script>\nSiehe https://example.org/a?b=1&c=2.");
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('href="https://example.org/a?b=1&amp;c=2"', $html);
        $this->assertStringContainsString('rel="noopener noreferrer"', $html);
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

    public function testCoursesOfOtherOrgsAreNotTouched(): void
    {
        $other = new_id();
        Db::insert('organizations', ['id' => $other, 'slug' => 'zz', 'name' => 'Zz', 'discord_guild_id' => '8', 'created_by_id' => 'x']);
        $foreign = Training::save($other, $this->course());
        [$admin, $csrf] = $this->login('ADMIN');
        $this->call('POST', '/o/exp/training/mining/delete', $admin, $csrf, ['id' => $foreign]);
        $this->assertSame(1, (int) Db::val('SELECT COUNT(*) FROM training_courses WHERE id = ?', [$foreign]));
        $this->call('POST', '/o/exp/training/mining/save', $admin, $csrf, $this->course(['id' => $foreign, 'title' => 'Fremd']));
        $this->assertSame('Grundlagen', Db::val('SELECT title FROM training_courses WHERE id = ?', [$foreign]));
    }
}
