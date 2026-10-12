<?php

declare(strict_types=1);

namespace Hangar\Tests;

use Hangar\Db;
use Hangar\EventError;
use Hangar\Events;
use Hangar\Training;

/** Ausbildung im Kalender: Termin mit Ausbildung, Anmeldung nur mit bestandenen Vorstufen. */
final class TrainingEventsTest extends DbTestCase
{
    private string $orgId;
    private string $otherOrg;
    /** @var array<string,string> */
    private array $u = [];
    private string $level1;
    private string $level2;
    private string $level3;

    protected function setUp(): void
    {
        parent::setUp();
        $this->orgId = new_id();
        $this->otherOrg = new_id();
        Db::insert('organizations', ['id' => $this->orgId, 'slug' => 'tr', 'name' => 'Tr', 'discord_guild_id' => 'tr-guild', 'created_by_id' => 'x']);
        Db::insert('organizations', ['id' => $this->otherOrg, 'slug' => 'zz', 'name' => 'Zz', 'discord_guild_id' => 'zz-guild', 'created_by_id' => 'x']);
        $mk = function (string $name, string $role = 'MEMBER', bool $planner = false, bool $instruct = false): void {
            $user = $this->mkUser(['name' => $name]);
            Db::insert('org_memberships', ['user_id' => $user['id'], 'org_id' => $this->orgId, 'role' => $role, 'can_plan' => (int) $planner, 'can_instruct' => (int) $instruct]);
            $this->u[$name] = $user['id'];
        };
        $mk('Admin', 'ADMIN');
        $mk('Planer', 'MEMBER', true);
        $mk('Ausbilder', 'MEMBER', false, true);
        $mk('Pilot');
        $course = fn (int $level, string $title, string $topic = 'Prospector', string $area = 'mining'): string => Training::save($this->orgId, ['area' => $area, 'topic' => $topic, 'level' => (string) $level, 'title' => $title, 'content' => '']);
        $this->level1 = $course(1, 'Grundlagen');
        $this->level2 = $course(2, 'Fortgeschritten');
        $this->level3 = $course(3, 'Experte');
    }

    private function done(string $user, string $courseId): void
    {
        $c = Training::find($this->orgId, $courseId);
        Db::insert('training_completions', ['user_id' => $this->u[$user], 'course_id' => $courseId, 'org_id' => $this->orgId, 'area' => $c['area'], 'topic' => $c['topic'], 'level' => $c['level'], 'title' => $c['title']]);
    }

    private function event(?string $courseId, string $title = 'Mining-Kurs'): string
    {
        return (string) Events::save($this->u['Planer'], $this->orgId, [
            'title' => $title, 'startsAt' => '2030-01-10T20:00', 'courseId' => $courseId, 'ships' => [],
        ])['id'];
    }

    private function viewer(string $name): \Hangar\Viewer
    {
        $row = Db::one('SELECT role, can_plan, can_instruct FROM org_memberships WHERE user_id = ? AND org_id = ?', [$this->u[$name], $this->orgId]);
        return new \Hangar\Viewer($this->u[$name], $name, null, null, [[
            'id' => $this->orgId, 'slug' => 'tr', 'name' => 'Tr', 'iconUrl' => null, 'role' => $row['role'],
            'canPlan' => $row['role'] === 'ADMIN' || (bool) $row['can_plan'], 'canInstruct' => $row['role'] === 'ADMIN' || (bool) $row['can_instruct'],
        ]], 'OK', 'csrf');
    }

    public function testMissingPrerequisitesAreTheLowerLevelsOfTheSameTopicInOrder(): void
    {
        $titles = fn (string $course): array => array_column(Training::missingPrerequisites($this->orgId, $this->u['Pilot'], $course), 'title');
        $this->assertSame([], $titles($this->level1), 'Stufe 1 hat keine Voraussetzung');
        $this->assertSame(['Grundlagen'], $titles($this->level2));
        $this->assertSame(['Grundlagen', 'Fortgeschritten'], $titles($this->level3));
        $this->done('Pilot', $this->level1);
        $this->assertSame([], $titles($this->level2));
        $this->assertSame(['Fortgeschritten'], $titles($this->level3));

        // Andere Themen und Bereiche zählen nicht
        $other = Training::save($this->orgId, ['area' => 'mining', 'topic' => 'Salvage', 'level' => '1', 'title' => 'Wracks']);
        $this->assertSame([], $titles($other));
        $this->assertSame([], Training::missingPrerequisites($this->orgId, $this->u['Pilot'], 'gibt-es-nicht'));
    }

    public function testEventStoresCourseAndRejectsCoursesOfOtherOrgs(): void
    {
        $id = $this->event($this->level2);
        $e = Events::getEvent($this->orgId, $this->viewer('Planer'), $id);
        $this->assertSame('Prospector', $e['course']['topic']);
        $this->assertSame(2, $e['course']['level']);
        $this->assertSame('Mining und Salvage', $e['course']['areaName']);

        $plain = $this->event(null, 'Ohne');
        $this->assertNull(Events::getEvent($this->orgId, $this->viewer('Planer'), $plain)['course']);

        $foreign = Training::save($this->otherOrg, ['area' => 'mining', 'topic' => 'Prospector', 'level' => '1', 'title' => 'Fremd']);
        $this->expectException(EventError::class);
        $this->expectExceptionMessage('gibt es in dieser Orga nicht');
        $this->event($foreign);
    }

    public function testRsvpNeedsEarlierLevelsAndStillAllowsDecliningAndWithdrawing(): void
    {
        $id = $this->event($this->level2);
        $pilot = $this->u['Pilot'];

        foreach (['YES', 'MAYBE'] as $status) {
            try {
                Events::setRsvp($pilot, $this->orgId, $id, $status);
                $this->fail("$status hätte abgelehnt werden müssen");
            } catch (EventError $e) {
                $this->assertStringContainsString('Stufe 1', $e->getMessage());
                $this->assertStringContainsString('Grundlagen', $e->getMessage());
            }
        }
        $this->assertSame(0, (int) Db::val('SELECT COUNT(*) FROM event_rsvps WHERE user_id = ?', [$pilot]));

        // Absagen und Zurücknehmen bleiben möglich
        Events::setRsvp($pilot, $this->orgId, $id, 'NO');
        $this->assertSame('NO', Db::val('SELECT status FROM event_rsvps WHERE user_id = ? AND event_id = ?', [$pilot, $id]));
        Events::setRsvp($pilot, $this->orgId, $id, 'NONE');

        // Nach bestandener Stufe 1 geht es
        $this->done('Pilot', $this->level1);
        Events::setRsvp($pilot, $this->orgId, $id, 'YES');
        $this->assertSame('YES', Db::val('SELECT status FROM event_rsvps WHERE user_id = ? AND event_id = ?', [$pilot, $id]));

        // Stufe 3 braucht auch Stufe 2
        $id3 = $this->event($this->level3, 'Experten-Kurs');
        $this->expectException(EventError::class);
        $this->expectExceptionMessage('Stufe 2');
        Events::setRsvp($pilot, $this->orgId, $id3, 'YES');
    }

    public function testStageOneAndNormalEventsAreOpenAndAdminsAndInstructorsAreExempt(): void
    {
        $first = $this->event($this->level1);
        $plain = $this->event(null, 'Ohne');
        Events::setRsvp($this->u['Pilot'], $this->orgId, $first, 'YES');
        Events::setRsvp($this->u['Pilot'], $this->orgId, $plain, 'YES');

        $second = $this->event($this->level2, 'Stufe 2');
        Events::setRsvp($this->u['Admin'], $this->orgId, $second, 'YES');
        Events::setRsvp($this->u['Ausbilder'], $this->orgId, $second, 'YES');
        $this->assertSame(2, (int) Db::val("SELECT COUNT(*) FROM event_rsvps WHERE event_id = ? AND status = 'YES'", [$second]));
    }

    public function testEventPageShowsMissingLevelsOnlyToThoseWhoLackThem(): void
    {
        $id = $this->event($this->level2);
        $pilot = Events::getEvent($this->orgId, $this->viewer('Pilot'), $id);
        $this->assertSame([['level' => 1, 'title' => 'Grundlagen']], $pilot['missing']);
        $this->assertStringContainsString('Stufe 1', (string) $pilot['missingText']);
        $this->assertSame([], Events::getEvent($this->orgId, $this->viewer('Ausbilder'), $id)['missing']);
        $this->assertSame([], Events::getEvent($this->orgId, $this->viewer('Admin'), $id)['missing']);
        $this->done('Pilot', $this->level1);
        $this->assertSame([], Events::getEvent($this->orgId, $this->viewer('Pilot'), $id)['missing']);
    }

    public function testListsNameTheCourseAndDeletingTheCourseKeepsTheEvent(): void
    {
        $id = $this->event($this->level2);
        $from = new \DateTimeImmutable('2030-01-01', new \DateTimeZone('UTC'));
        $list = Events::listTimeline($this->orgId, $this->viewer('Pilot'), $from, 3);
        $this->assertSame('Prospector · Stufe 2', $list[0]['training']);

        Training::delete($this->orgId, $this->level2);
        $e = Events::getEvent($this->orgId, $this->viewer('Pilot'), $id);
        $this->assertNotNull($e, 'der Termin bleibt bestehen');
        $this->assertNull($e['course']);
        Events::setRsvp($this->u['Pilot'], $this->orgId, $id, 'YES');
        $this->assertSame('YES', Db::val('SELECT status FROM event_rsvps WHERE event_id = ?', [$id]));
    }

    public function testInstructorConfirmsParticipantsAndMayRevoke(): void
    {
        $id = $this->event($this->level1);
        Events::setRsvp($this->u['Pilot'], $this->orgId, $id, 'YES');
        Events::setRsvp($this->u['Planer'], $this->orgId, $id, 'MAYBE');

        $this->assertFalse(Events::getEvent($this->orgId, $this->viewer('Pilot'), $id)['canConfirm']);
        $this->assertFalse(Events::getEvent($this->orgId, $this->viewer('Planer'), $id)['canConfirm']);
        $e = Events::getEvent($this->orgId, $this->viewer('Ausbilder'), $id);
        $this->assertTrue($e['canConfirm']);
        $this->assertSame(['Pilot'], array_column($e['participants'], 'name'), 'nur Zusagen „Dabei“');
        $this->assertTrue(Events::getEvent($this->orgId, $this->viewer('Admin'), $id)['canConfirm']);

        Training::confirm($this->orgId, $id, $this->u['Ausbilder'], [$this->u['Pilot'], $this->u['Planer']]);
        $this->assertSame([], Training::missingPrerequisites($this->orgId, $this->u['Pilot'], $this->level2));
        $this->assertSame($this->u['Ausbilder'], Db::val('SELECT confirmed_by FROM training_completions WHERE user_id = ?', [$this->u['Pilot']]));
        $this->assertNull(Db::val('SELECT 1 FROM training_completions WHERE user_id = ?', [$this->u['Planer']]), 'ohne Zusage nicht betroffen');
        $this->assertTrue(Events::getEvent($this->orgId, $this->viewer('Ausbilder'), $id)['participants'][0]['passed']);

        Training::confirm($this->orgId, $id, $this->u['Ausbilder'], []);
        $this->assertSame(0, (int) Db::val('SELECT COUNT(*) FROM training_completions'));
    }

    public function testConfirmNeedsACourseAndQualificationsListThePassedOnes(): void
    {
        $plain = $this->event(null, 'Ohne');
        try {
            Training::confirm($this->orgId, $plain, $this->u['Ausbilder'], []);
            $this->fail('Ohne Ausbildung gibt es nichts zu bestätigen');
        } catch (\Hangar\OrgError) {
            $this->addToAssertionCount(1);
        }
        $this->done('Pilot', $this->level2);
        $this->done('Pilot', $this->level1);
        $q = Training::qualifications($this->orgId, $this->u['Pilot']);
        $this->assertSame([1, 2], array_column($q, 'level'));
        $this->assertSame('Mining und Salvage', $q[0]['areaName']);
        $this->assertSame([], Training::qualifications($this->otherOrg, $this->u['Pilot']));
        $this->assertSame([], Training::qualifications($this->orgId, $this->u['Admin']));
    }

    public function testPassedCourseStaysAfterTheCourseIsDeleted(): void
    {
        $this->done('Pilot', $this->level1);
        $id = $this->event($this->level2);
        Events::setRsvp($this->u['Pilot'], $this->orgId, $id, 'YES');
        Training::confirm($this->orgId, $id, $this->u['Ausbilder'], [$this->u['Pilot']]);

        Training::delete($this->orgId, $this->level1);
        Training::delete($this->orgId, $this->level2);
        $q = Training::qualifications($this->orgId, $this->u['Pilot']);
        $this->assertSame(['Grundlagen', 'Fortgeschritten'], array_column($q, 'title'), 'Nachweis bleibt');
        $this->assertSame([true, true], array_column($q, 'retired'));
        $this->assertSame(2, (int) Db::val('SELECT COUNT(*) FROM training_completions'));

        // Admins können einen Nachweis entfernen, nur in der eigenen Orga.
        Training::removeCompletion($this->otherOrg, $q[0]['id']);
        $this->assertSame(2, (int) Db::val('SELECT COUNT(*) FROM training_completions'));
        Training::removeCompletion($this->orgId, $q[0]['id']);
        $this->assertSame(['Fortgeschritten'], array_column(Training::qualifications($this->orgId, $this->u['Pilot']), 'title'));
    }

    public function testAreaListsUpcomingEventsOfEachCourse(): void
    {
        $id = $this->event($this->level2);
        $now = new \DateTimeImmutable('2029-12-01', new \DateTimeZone('UTC'));
        $list = Training::upcomingEventsByCourse($this->orgId, 'mining', $now);
        $this->assertSame([$this->level2], array_keys($list));
        $this->assertSame($id, $list[$this->level2][0]['id']);
        $this->assertSame([], Training::upcomingEventsByCourse($this->orgId, 'space', $now));
        $this->assertSame([], Training::upcomingEventsByCourse($this->orgId, 'mining', new \DateTimeImmutable('2031-01-01', new \DateTimeZone('UTC'))), 'vergangene nicht');
        Events::setCancelled($this->u['Planer'], $this->orgId, $id, true);
        $this->assertSame([], Training::upcomingEventsByCourse($this->orgId, 'mining', $now), 'abgesagte nicht');
    }

    public function testEventCanBeEditedToAnotherCourseOrNone(): void
    {
        $id = $this->event($this->level1);
        Events::save($this->u['Planer'], $this->orgId, ['title' => 'Mining-Kurs', 'startsAt' => '2030-01-10T20:00', 'courseId' => $this->level3, 'ships' => []], $id);
        $this->assertSame($this->level3, Db::val('SELECT course_id FROM events WHERE id = ?', [$id]));
        Events::save($this->u['Planer'], $this->orgId, ['title' => 'Mining-Kurs', 'startsAt' => '2030-01-10T20:00', 'courseId' => '', 'ships' => []], $id);
        $this->assertNull(Db::val('SELECT course_id FROM events WHERE id = ?', [$id]));
    }
}
