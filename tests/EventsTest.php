<?php

declare(strict_types=1);

namespace Hangar\Tests;

use DateTimeImmutable;
use Hangar\Db;
use Hangar\EventBriefing;
use Hangar\EventError;
use Hangar\Events;
use Hangar\EventTime;

final class EventsTest extends DbTestCase
{
    private string $orgA;
    private string $orgB;
    private string $shipId;
    /** @var array<string,string> */
    private array $u = [];

    protected function setUp(): void
    {
        parent::setUp();
        $mkOrg = function (string $name): string {
            $id = new_id();
            Db::insert('organizations', ['id' => $id, 'slug' => "ev-$name", 'name' => $name, 'discord_guild_id' => "ev-guild-$name", 'created_by_id' => 'x']);
            return $id;
        };
        $this->orgA = $mkOrg('A');
        $this->orgB = $mkOrg('B');
        $this->shipId = new_id();
        Db::insert('catalog_items', ['id' => $this->shipId, 'kind' => 'SHIP', 'slug' => 'ev-ship', 'name' => 'Ev Ship', 'match_key' => 'evship', 'data' => '{}']);
        $mk = function (string $name, string $orgId, string $role = 'MEMBER', bool $canPlan = false): void {
            $user = $this->mkUser(['name' => $name]);
            Db::insert('org_memberships', ['user_id' => $user['id'], 'org_id' => $orgId, 'role' => $role, 'can_plan' => (int) $canPlan]);
            $this->u[$name] = $user['id'];
        };
        $mk('Admin', $this->orgA, 'ADMIN');
        $mk('Planer', $this->orgA, 'MEMBER', true);
        $mk('Member', $this->orgA);
        $mk('Fremd', $this->orgB, 'ADMIN');
    }

    private function viewer(string $name, array $orgIds): \Hangar\Viewer
    {
        return VisibilityFilterTest::viewer($this->u[$name], $orgIds);
    }

    private function input(array $extra = []): array
    {
        return $extra + [
            'title' => 'Mining-Abend',
            'startsAt' => '2026-07-15T20:00',
            'endsAt' => '2026-07-15T23:00',
            'ships' => [[
                'catalogItemId' => $this->shipId, 'task' => 'Erz',
                'slots' => [['label' => 'Pilot', 'userId' => null], ['label' => 'Copilot', 'userId' => null]],
            ]],
        ];
    }

    private function fails(callable $fn, string $msg = 'Aktion war erlaubt'): void
    {
        try {
            $fn();
            $this->fail($msg);
        } catch (EventError) {
            $this->addToAssertionCount(1);
        }
    }

    // --- Rechte ------------------------------------------------------------------------------

    public function testPlannersAndAdminsMayCreateMembersAndStrangersMayNot(): void
    {
        $this->assertNotEmpty(Events::save($this->u['Admin'], $this->orgA, $this->input()));
        $this->assertNotEmpty(Events::save($this->u['Planer'], $this->orgA, $this->input()));
        $this->fails(fn () => Events::save($this->u['Member'], $this->orgA, $this->input()));
        $this->fails(fn () => Events::save($this->u['Fremd'], $this->orgA, $this->input()));
    }

    public function testAdminOfAnotherOrgCannotChangeOrDeleteEvents(): void
    {
        $e = Events::save($this->u['Admin'], $this->orgA, $this->input());
        $this->fails(fn () => Events::save($this->u['Fremd'], $this->orgB, $this->input(), $e['id']));
        $this->fails(fn () => Events::delete($this->u['Fremd'], $this->orgB, $e['id']));
        $this->fails(fn () => Events::setCancelled($this->u['Fremd'], $this->orgB, $e['id'], true));
        $this->assertNotNull(Db::val('SELECT id FROM events WHERE id = ?', [$e['id']]));
    }

    // --- Speichern ---------------------------------------------------------------------------

    public function testConvertsBerlinTimeToUtcAndStoresShipsWithSlots(): void
    {
        $e = Events::save($this->u['Admin'], $this->orgA, $this->input());
        $d = Events::getEvent($this->orgA, $this->viewer('Admin', [$this->orgA]), $e['id']);
        $this->assertSame('2026-07-15T18:00:00+00:00', $d['startsAt']->format('c'));
        $this->assertCount(1, $d['ships']);
        $this->assertSame('Ev Ship', $d['ships'][0]['name']);
        $this->assertSame(['Pilot', 'Copilot'], array_column($d['ships'][0]['slots'], 'label'));
    }

    public function testChangingReplacesShipsAndSlots(): void
    {
        $e = Events::save($this->u['Admin'], $this->orgA, $this->input());
        Events::save($this->u['Planer'], $this->orgA, $this->input([
            'title' => 'Neu',
            'ships' => [['customName' => 'Eigenbau', 'slots' => [['label' => 'Pilot', 'userId' => $this->u['Member']]]]],
        ]), $e['id']);
        $d = Events::getEvent($this->orgA, $this->viewer('Admin', [$this->orgA]), $e['id']);
        $this->assertSame('Neu', $d['title']);
        $this->assertSame(['Eigenbau'], array_column($d['ships'], 'name'));
        $this->assertSame('Member', $d['ships'][0]['slots'][0]['userName']);
        $this->assertSame(1, (int) Db::val('SELECT COUNT(*) FROM event_ships WHERE event_id = ?', [$e['id']]));
    }

    public function testRejectsInvalidInput(): void
    {
        foreach ([
            $this->input(['startsAt' => 'morgen']),
            $this->input(['endsAt' => '2026-07-15T19:00']),
            $this->input(['title' => 'x']),
            $this->input(['ships' => [['catalogItemId' => 'gibt-es-nicht', 'slots' => []]]]),
            $this->input(['ships' => [['slots' => []]]]),
            $this->input(['ships' => [['customName' => 'X', 'slots' => [['label' => '']]]]]),
        ] as $bad) {
            $this->fails(fn () => Events::save($this->u['Admin'], $this->orgA, $bad), 'Ungültige Eingabe wurde akzeptiert');
        }
    }

    public function testOnlyOrgMembersGetSlots(): void
    {
        $this->fails(fn () => Events::save($this->u['Admin'], $this->orgA, $this->input([
            'ships' => [['customName' => 'X', 'slots' => [['label' => 'Pilot', 'userId' => $this->u['Fremd']]]]],
        ])));
    }

    public function testLimitsShipsAndSlots(): void
    {
        $tooManyShips = array_fill(0, Events::MAX_SHIPS + 1, ['customName' => 'X', 'slots' => []]);
        $this->fails(fn () => Events::save($this->u['Admin'], $this->orgA, $this->input(['ships' => $tooManyShips])));
        $tooManySlots = [['customName' => 'X', 'slots' => array_fill(0, Events::MAX_SLOTS + 1, ['label' => 'P'])]];
        $this->fails(fn () => Events::save($this->u['Admin'], $this->orgA, $this->input(['ships' => $tooManySlots])));
    }

    // --- Sichtbarkeit nach Orga --------------------------------------------------------------

    public function testMembersOfAnotherOrgSeeNeitherListNorDetail(): void
    {
        $e = Events::save($this->u['Admin'], $this->orgA, $this->input());
        $fremd = $this->viewer('Fremd', [$this->orgB]);
        $epoch = new DateTimeImmutable('@0');
        $far = new DateTimeImmutable('2100-01-01');
        $this->assertNull(Events::getEvent($this->orgA, $fremd, $e['id']));
        $this->assertSame([], Events::listEvents($this->orgA, $fremd, $epoch, $far));
        $this->assertSame([], Events::listUpcoming($this->orgA, $fremd, $epoch));
        // Auch mit der eigenen Orga-ID: das Event gehört nicht dazu
        $this->assertNull(Events::getEvent($this->orgB, $fremd, $e['id']));
    }

    public function testGuestsSeeNothingMembersSeeTheirOrgsEvents(): void
    {
        $e = Events::save($this->u['Admin'], $this->orgA, $this->input());
        $this->assertNull(Events::getEvent($this->orgA, null, $e['id']));
        $member = $this->viewer('Member', [$this->orgA]);
        $this->assertNotNull(Events::getEvent($this->orgA, $member, $e['id']));
        $list = Events::listEvents($this->orgA, $member, new DateTimeImmutable('2026-07-01'), new DateTimeImmutable('2026-08-01'));
        $this->assertContains($e['id'], array_column($list, 'id'));
    }

    // --- Zusagen -----------------------------------------------------------------------------

    public function testRsvpFlow(): void
    {
        $e = Events::save($this->u['Admin'], $this->orgA, $this->input());
        Events::setRsvp($this->u['Member'], $this->orgA, $e['id'], 'YES');
        Events::setRsvp($this->u['Member'], $this->orgA, $e['id'], 'MAYBE');
        Events::setRsvp($this->u['Planer'], $this->orgA, $e['id'], 'YES');
        $this->fails(fn () => Events::setRsvp($this->u['Fremd'], $this->orgA, $e['id'], 'YES'));
        $this->fails(fn () => Events::setRsvp($this->u['Fremd'], $this->orgB, $e['id'], 'YES'));
        $this->fails(fn () => Events::setRsvp($this->u['Member'], $this->orgA, $e['id'], 'VIELLEICHT'));

        $d = Events::getEvent($this->orgA, $this->viewer('Member', [$this->orgA]), $e['id']);
        $this->assertSame('MAYBE', $d['myRsvp']);
        $brief = Events::toBriefing($d);
        $this->assertSame(['Planer'], $brief['yes']);
        $this->assertSame(['Member'], $brief['maybe']);

        Events::setRsvp($this->u['Member'], $this->orgA, $e['id'], 'NONE');
        $this->assertNull(Events::getEvent($this->orgA, $this->viewer('Member', [$this->orgA]), $e['id'])['myRsvp']);
    }

    public function testMemberClaimsAndReleasesSlots(): void
    {
        $e = Events::save($this->u['Admin'], $this->orgA, $this->input([
            'ships' => [['customName' => 'Kraken', 'slots' => [['label' => 'Pilot'], ['label' => 'Copilot']]]],
        ]));
        $view = fn (string $n) => Events::getEvent($this->orgA, $this->viewer($n, [$this->orgA]), $e['id']);
        $slots = $view('Member')['ships'][0]['slots'];

        $this->fails(fn () => Events::claimSlot($this->u['Member'], $this->orgA, $e['id'], $slots[0]['id']), 'Eintragen ohne Zusage');
        Events::setRsvp($this->u['Member'], $this->orgA, $e['id'], 'YES');
        Events::setRsvp($this->u['Planer'], $this->orgA, $e['id'], 'YES');
        Events::claimSlot($this->u['Member'], $this->orgA, $e['id'], $slots[0]['id']);
        $this->fails(fn () => Events::claimSlot($this->u['Planer'], $this->orgA, $e['id'], $slots[0]['id']), 'Doppelt vergebener Platz');
        $this->fails(fn () => Events::claimSlot($this->u['Fremd'], $this->orgA, $e['id'], $slots[1]['id']), 'Fremder trägt sich ein');

        Events::claimSlot($this->u['Member'], $this->orgA, $e['id'], $slots[1]['id']);   // wechselt den Platz
        $now = $view('Member')['ships'][0]['slots'];
        $this->assertNull($now[0]['userId']);
        $this->assertSame($this->u['Member'], $now[1]['userId']);

        Events::setRsvp($this->u['Member'], $this->orgA, $e['id'], 'MAYBE');            // Absage räumt den Platz
        $this->assertNull($view('Member')['ships'][0]['slots'][1]['userId']);
    }

    public function testPlanningShowsServerNicknames(): void
    {
        Db::run('UPDATE org_memberships SET nick = ? WHERE user_id = ? AND org_id = ?', ['Maverick', $this->u['Member'], $this->orgA]);
        $e = Events::save($this->u['Admin'], $this->orgA, $this->input());
        Events::setRsvp($this->u['Member'], $this->orgA, $e['id'], 'YES');
        $viewer = $this->viewer('Admin', [$this->orgA]);
        $d = Events::getEvent($this->orgA, $viewer, $e['id']);
        $this->assertSame(['Maverick'], array_column($d['rsvps'] ?? [], 'name'));
        $names = array_column(Events::listAssignableMembers($this->orgA, $viewer), 'name');
        $this->assertContains('Maverick', $names);
        $this->assertNotContains('Member', $names);
        $slot = $d['ships'][0]['slots'][0]['id'];
        Db::run('UPDATE event_slots SET user_id = ? WHERE id = ?', [$this->u['Member'], $slot]);
        $d = Events::getEvent($this->orgA, $viewer, $e['id']);
        $this->assertSame('Maverick', $d['ships'][0]['slots'][0]['userName']);
    }

    public function testCancelAndDeleteWorkForPlanners(): void
    {
        $e = Events::save($this->u['Admin'], $this->orgA, $this->input());
        Events::setCancelled($this->u['Planer'], $this->orgA, $e['id'], true);
        $this->assertTrue(Events::getEvent($this->orgA, $this->viewer('Admin', [$this->orgA]), $e['id'])['cancelled']);
        $this->fails(fn () => Events::setCancelled($this->u['Member'], $this->orgA, $e['id'], false));
        Events::delete($this->u['Planer'], $this->orgA, $e['id']);
        $this->assertNull(Db::val('SELECT id FROM events WHERE id = ?', [$e['id']]));
    }

    public function testDraftsAreOnlyVisibleAndEditableForPlanners(): void
    {
        $draft = Events::save($this->u['Admin'], $this->orgA, $this->input(['draft' => true]));
        $this->assertSame('DRAFT', $draft['status']);
        $planner = new \Hangar\Viewer($this->u['Planer'], 'Planer', null, null, [['id' => $this->orgA, 'slug' => 'a', 'name' => 'A', 'iconUrl' => null, 'role' => 'MEMBER', 'canPlan' => true]], 'OK', 'csrf');
        $member = $this->viewer('Member', [$this->orgA]);
        $from = new DateTimeImmutable('2026-07-01T00:00:00Z');

        $this->assertCount(1, Events::listTimeline($this->orgA, $planner, $from, 1));
        $this->assertTrue(Events::getEvent($this->orgA, $planner, $draft['id'])['draft']);
        $this->assertSame([], Events::listTimeline($this->orgA, $member, $from, 1));
        $this->assertSame([], Events::listEvents($this->orgA, $member, $from, $from->modify('+1 month')));
        $this->assertNull(Events::getEvent($this->orgA, $member, $draft['id']));
        $this->fails(fn () => Events::setRsvp($this->u['Member'], $this->orgA, $draft['id'], 'YES'));
        $this->fails(fn () => Events::claimSlot($this->u['Member'], $this->orgA, $draft['id'], null));
        $this->assertSame([], Events::listUpcoming($this->orgA, $member, new DateTimeImmutable('2026-07-01T00:00:00Z')));

        // Entwurf aufheben: ab jetzt für alle sichtbar
        Events::save($this->u['Admin'], $this->orgA, $this->input(), $draft['id']);
        $this->assertFalse(Events::getEvent($this->orgA, $member, $draft['id'])['draft']);
        $this->assertCount(1, Events::listTimeline($this->orgA, $member, $from, 1));
    }
    public function testUpcomingExcludesCancelledAndPast(): void
    {
        $now = new DateTimeImmutable('2026-07-10T00:00:00Z');
        $future = Events::save($this->u['Admin'], $this->orgA, $this->input());
        $cancelled = Events::save($this->u['Admin'], $this->orgA, $this->input(['title' => 'Abgesagt']));
        Events::setCancelled($this->u['Admin'], $this->orgA, $cancelled['id'], true);
        Events::save($this->u['Admin'], $this->orgA, $this->input(['title' => 'Alt', 'startsAt' => '2026-06-01T20:00', 'endsAt' => '2026-06-01T22:00']));

        $ids = array_column(Events::listUpcoming($this->orgA, $this->viewer('Member', [$this->orgA]), $now), 'id');
        $this->assertSame([$future['id']], $ids);
    }

    // --- Zeit --------------------------------------------------------------------------------

    public function testBerlinTimeParsingHandlesDstAndInvalidInput(): void
    {
        $this->assertSame('2026-07-15T18:00', EventTime::parseBerlinLocal('2026-07-15T20:00')->format('Y-m-d\TH:i'));   // Sommerzeit UTC+2
        $this->assertSame('2026-01-15T19:00', EventTime::parseBerlinLocal('2026-01-15 20:00')->format('Y-m-d\TH:i'));   // Winterzeit UTC+1
        $this->assertNull(EventTime::parseBerlinLocal('2026-02-31T20:00'));
        $this->assertNull(EventTime::parseBerlinLocal('2026-03-29T02:30'));   // ausgefallene Stunde
        $this->assertNull(EventTime::parseBerlinLocal('morgen'));
        $this->assertNull(EventTime::parseBerlinLocal('2026-07-15T24:00'));
    }

    public function testBerlinTimeRoundTripAndFormatting(): void
    {
        $d = EventTime::parseBerlinLocal('2026-03-28T20:00');
        $this->assertSame('2026-03-28T20:00', EventTime::toInput($d));
        $this->assertSame('2026-03-28', EventTime::dayKey($d));
        $this->assertSame('Sa., 28.03.2026, 20:00 Uhr', EventTime::format($d));
        // Kurz nach Mitternacht UTC ist in Berlin schon der nächste Tag
        $late = new DateTimeImmutable('2026-07-15T22:30:00Z');
        $this->assertSame('2026-07-16', EventTime::dayKey($late));
    }

    // --- Briefing ----------------------------------------------------------------------------

    private static function base(): array
    {
        return [
            'title' => 'Mining-Abend', 'description' => 'Bringt Snacks mit.', 'location' => 'Stanton, Lorville',
            'startsAt' => new DateTimeImmutable('2026-07-15T18:00:00Z'), 'endsAt' => new DateTimeImmutable('2026-07-15T21:00:00Z'),
            'cancelled' => false,
            'ships' => [['name' => 'Prospector', 'task' => 'Erz abbauen', 'slots' => [['label' => 'Pilot', 'userName' => 'Alex'], ['label' => 'Copilot', 'userName' => null]]]],
            'yes' => ['Alex', 'Sam'], 'maybe' => ['Kim'],
        ];
    }

    public function testBriefingContainsTimestampsShipsOpenSlotsAndRsvps(): void
    {
        $text = implode("\n", EventBriefing::format(self::base()));
        foreach (['# Mining-Abend', '<t:1784138400:F>', 'bis <t:1784149200:t>', '**Prospector** – Erz abbauen', '- Pilot: Alex', '- Copilot: _offen_', 'Dabei (2): Alex, Sam', 'Vielleicht (1): Kim'] as $needle) {
            $this->assertStringContainsString($needle, $text);
        }
    }

    public function testBriefingMarksCancelledEvents(): void
    {
        $this->assertStringContainsString('ABGESAGT: Mining-Abend', EventBriefing::format(['cancelled' => true] + self::base())[0]);
    }

    public function testBriefingSplitsLongTextsUnderTheLimit(): void
    {
        $ships = array_map(fn ($i) => ['name' => "Schiff $i", 'task' => 'Aufgabe', 'slots' => [['label' => 'Pilot', 'userName' => 'Jemand']]], range(0, 39));
        $chunks = EventBriefing::format(['ships' => $ships] + self::base(), 300);
        $this->assertGreaterThan(1, count($chunks));
        foreach ($chunks as $c) {
            $this->assertLessThanOrEqual(300, mb_strlen($c));
        }
        foreach (EventBriefing::format(['description' => str_repeat('x', 700)] + self::base(), 300) as $c) {
            $this->assertLessThanOrEqual(300, mb_strlen($c));
        }
    }
}
