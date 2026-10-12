<?php

declare(strict_types=1);

namespace Hangar\Tests;

use DateTimeImmutable;
use DateTimeZone;
use Hangar\App;
use Hangar\Auth;
use Hangar\Db;
use Hangar\Departments;
use Hangar\EventError;
use Hangar\Events;
use Hangar\Http\Request;
use Hangar\OrgError;

/** Abteilungen: Pflege nur durch Admins und Abteilungsleiter, ohne Leiter-Rolle nicht eingerichtet, Trainings und Übungen im Kalender. */
final class DepartmentsTest extends DbTestCase
{
    private string $orgId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->orgId = new_id();
        Db::insert('organizations', ['id' => $this->orgId, 'slug' => 'exp', 'name' => 'Exp', 'discord_guild_id' => '9', 'created_by_id' => 'x']);
    }

    /** @return array{0:string,1:string,2:string} Sitzungs-Token, CSRF-Token und Nutzer-ID */
    private function login(string $role, bool $lead = false, bool $plan = false): array
    {
        $u = $this->mkUser(['membership_checked_at' => \Hangar\Time::nowDb(), 'membership_status' => 'OK']);
        Db::insert('org_memberships', ['user_id' => $u['id'], 'org_id' => $this->orgId, 'role' => $role, 'can_plan' => (int) $plan, 'can_lead' => (int) $lead]);
        $s = Auth::createSession($u['id']);
        return [$s['token'], (string) Db::val('SELECT csrf_token FROM sessions WHERE token_hash = ?', [hash('sha256', $s['token'])]), $u['id']];
    }

    /** @param array<string,mixed> $post */
    private function call(string $method, string $path, string $token, string $csrf, array $post = []): \Hangar\Http\Response
    {
        Auth::reset();
        return App::handle(new Request($method, $path, [], $post + ($method === 'POST' ? ['_csrf' => $csrf] : []), [], [Auth::COOKIE => $token]));
    }

    private function enable(): void
    {
        Db::run('UPDATE organizations SET lead_role_ids = ? WHERE id = ?', ['role-lead', $this->orgId]);
    }

    public function testEnabledOnlyWithLeaderRole(): void
    {
        $this->assertFalse(Departments::enabled($this->orgId));
        $this->enable();
        $this->assertTrue(Departments::enabled($this->orgId));
    }

    public function testSaveValidatesAndKeepsNamesUnique(): void
    {
        $id = Departments::save($this->orgId, ['name' => 'Marine', 'description' => "Zeile 1\r\nZeile 2"]);
        $this->assertSame("Zeile 1\nZeile 2", Departments::find($this->orgId, $id)['description']);
        foreach ([['name' => 'x'], ['name' => ''], ['name' => str_repeat('a', 81)], ['name' => 'Gut', 'description' => str_repeat('a', 8001)], ['name' => 'Marine']] as $bad) {
            try {
                Departments::save($this->orgId, $bad);
                $this->fail('Eingabe hätte abgelehnt werden müssen: ' . json_encode($bad));
            } catch (OrgError) {
                $this->addToAssertionCount(1);
            }
        }
        Departments::save($this->orgId, ['name' => 'Marine Korps', 'description' => ''], $id);
        $this->assertSame('Marine Korps', Departments::find($this->orgId, $id)['name']);
        $this->assertSame(1, Departments::count($this->orgId));
    }

    public function testHomeTileIsDisabledWithoutLeaderRole(): void
    {
        [$member, $csrf] = $this->login('MEMBER');
        $home = $this->call('GET', '/o/exp', $member, $csrf)->body;
        $this->assertStringContainsString('Abteilungen', $home);
        $this->assertStringNotContainsString('href="/o/exp/departments"', $home);
        $this->assertSame(404, $this->call('GET', '/o/exp/departments', $member, $csrf)->status, 'ohne Leiter-Rolle gibt es die Seite nicht');

        $this->enable();
        $home = $this->call('GET', '/o/exp', $member, $csrf)->body;
        $this->assertStringContainsString('href="/o/exp/departments"', $home);
        $this->assertSame(200, $this->call('GET', '/o/exp/departments', $member, $csrf)->status);
    }

    public function testMembersSeePagesButOnlyAdminsAndLeadersMayChange(): void
    {
        $this->enable();
        [$member, $mCsrf] = $this->login('MEMBER');
        [$leader, $lCsrf] = $this->login('MEMBER', true);
        [$admin, $aCsrf] = $this->login('ADMIN');

        $this->call('POST', '/o/exp/departments/save', $member, $mCsrf, ['name' => 'Marine']);
        $this->assertSame(0, Departments::count($this->orgId), 'Mitglieder dürfen nichts anlegen');
        $this->call('POST', '/o/exp/departments/save', $leader, $lCsrf, ['name' => 'Marine', 'description' => 'Siehe https://example.org']);
        $this->assertSame(1, Departments::count($this->orgId), 'Abteilungsleiter dürfen anlegen');
        $this->call('POST', '/o/exp/departments/save', $admin, $aCsrf, ['name' => 'Logistik']);
        $this->assertSame(2, Departments::count($this->orgId), 'Admins dürfen anlegen');

        $id = (string) Db::val("SELECT id FROM departments WHERE name = 'Marine'");
        $page = $this->call('GET', '/o/exp/departments/' . $id, $member, $mCsrf);
        $this->assertSame(200, $page->status);
        $this->assertStringContainsString('href="https://example.org"', $page->body);
        $this->assertStringNotContainsString('Bearbeiten', $page->body, 'Mitglieder sehen keine Pflege');
        $this->assertStringContainsString('Bearbeiten', $this->call('GET', '/o/exp/departments/' . $id, $leader, $lCsrf)->body);
        $this->assertSame(404, $this->call('GET', '/o/exp/departments/gibtsnicht', $member, $mCsrf)->status);

        $this->call('POST', '/o/exp/departments/delete', $member, $mCsrf, ['id' => $id]);
        $this->assertSame(2, Departments::count($this->orgId), 'Mitglieder dürfen nichts löschen');
        $this->call('POST', '/o/exp/departments/delete', $leader, $lCsrf, ['id' => $id]);
        $this->assertSame(1, Departments::count($this->orgId));
    }

    public function testEventsCarryDepartmentAndKind(): void
    {
        [, , $planner] = $this->login('MEMBER', false, true);
        $dept = Departments::save($this->orgId, ['name' => 'Marine']);
        $other = new_id();
        Db::insert('organizations', ['id' => $other, 'slug' => 'zz', 'name' => 'Zz', 'discord_guild_id' => '8', 'created_by_id' => 'x']);
        $foreign = Departments::save($other, ['name' => 'Fremd']);

        $future = (new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin')))->modify('+10 days')->format('Y-m-d\T20:00');
        $later = (new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin')))->modify('+10 days')->format('Y-m-d\T21:00');
        $e = Events::save($planner, $this->orgId, ['title' => 'Schießübung', 'startsAt' => $future, 'departmentId' => $dept, 'departmentKind' => 'EXERCISE', 'ships' => []]);
        $this->assertSame('EXERCISE', Db::val('SELECT department_kind FROM events WHERE id = ?', [$e['id']]));

        $plain = Events::save($planner, $this->orgId, ['title' => 'Ohne', 'startsAt' => $future, 'departmentKind' => 'EXERCISE', 'ships' => []]);
        $this->assertNull(Db::val('SELECT department_kind FROM events WHERE id = ?', [$plain['id']]), 'ohne Abteilung keine Art');
        $default = Events::save($planner, $this->orgId, ['title' => 'Standard', 'startsAt' => $later, 'departmentId' => $dept, 'departmentKind' => 'quatsch', 'ships' => []]);
        $this->assertSame('TRAINING', Db::val('SELECT department_kind FROM events WHERE id = ?', [$default['id']]), 'ungültige Art wird Training');

        try {
            Events::save($planner, $this->orgId, ['title' => 'Fremd', 'startsAt' => $future, 'departmentId' => $foreign, 'ships' => []]);
            $this->fail('Abteilung einer anderen Orga hätte abgelehnt werden müssen');
        } catch (EventError) {
            $this->addToAssertionCount(1);
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $list = Departments::upcomingEvents($this->orgId, $dept, $now);
        $this->assertSame(['Schießübung', 'Standard'], array_column($list, 'title'));
        $this->assertSame(['Übung', 'Training'], array_column($list, 'kindName'));
        $this->assertSame(2, Departments::list($this->orgId, $now)[0]['upcoming']);

        // Löschen der Abteilung lässt den Termin als normalen Termin bestehen.
        Departments::delete($this->orgId, $dept);
        $this->assertNull(Db::val('SELECT department_id FROM events WHERE id = ?', [$e['id']]));
        $this->assertSame(1, (int) Db::val('SELECT COUNT(*) FROM events WHERE id = ?', [$e['id']]));
    }

    public function testLeaderRoleIsStoredFromTheBot(): void
    {
        \Hangar\Onboarding::setRoles($this->orgId, 'lead', ['11111111111111111', '22222222222222222'], ['11111111111111111' => 'Leiter', '22222222222222222' => 'Chef']);
        $this->assertSame('11111111111111111,22222222222222222', Db::val('SELECT lead_role_ids FROM organizations WHERE id = ?', [$this->orgId]));
        $this->assertTrue(Departments::enabled($this->orgId));
        \Hangar\Onboarding::setRoles($this->orgId, 'lead', [], []);
        $this->assertFalse(Departments::enabled($this->orgId));
    }
}
