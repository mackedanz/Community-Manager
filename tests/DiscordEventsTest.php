<?php

declare(strict_types=1);

namespace Hangar\Tests;

use DateTimeImmutable;
use Hangar\Db;
use Hangar\DiscordEvents;
use Hangar\Events;
use Hangar\Http\Client;

final class DiscordEventsTest extends DbTestCase
{
    private string $orgId;
    private string $adminId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->orgId = new_id();
        $admin = $this->mkUser(['name' => 'Admin']);
        $this->adminId = $admin['id'];
        Db::insert('organizations', ['id' => $this->orgId, 'slug' => 'de-org', 'name' => 'DE', 'discord_guild_id' => 'de-guild', 'created_by_id' => $this->adminId, 'discord_events_mode' => 'DRAFT']);
        Db::insert('org_memberships', ['user_id' => $this->adminId, 'org_id' => $this->orgId, 'role' => 'ADMIN', 'can_plan' => 1]);
    }

    private function org(string $mode = 'DRAFT'): array
    {
        Db::run('UPDATE organizations SET discord_events_mode = ? WHERE id = ?', [$mode, $this->orgId]);
        return Db::one('SELECT * FROM organizations WHERE id = ?', [$this->orgId]);
    }

    private function d(string $id, array $extra = []): array
    {
        return $extra + [
            'id' => $id, 'name' => 'Raid', 'description' => 'Treffen im Hangar', 'location' => 'Port Olisar',
            'startsAt' => new DateTimeImmutable('2026-10-20T18:00:00Z'), 'endsAt' => new DateTimeImmutable('2026-10-20T21:00:00Z'),
            'status' => 1, 'creatorId' => null,
        ];
    }

    private function row(string $discordId): ?array
    {
        return Db::one('SELECT * FROM events WHERE org_id = ? AND discord_event_id = ?', [$this->orgId, $discordId]);
    }

    private const NOW = '2026-10-10T00:00:00Z';

    public function testNewDiscordEventBecomesDraftOrPublishedAccordingToMode(): void
    {
        $r = DiscordEvents::apply($this->org('DRAFT'), [$this->d('e1')], new DateTimeImmutable(self::NOW));
        $this->assertSame(1, $r['created']);
        $row = $this->row('e1');
        $this->assertSame('DRAFT', $row['status']);
        $this->assertSame('Raid', $row['title']);
        $this->assertSame('2026-10-20 18:00:00', $row['starts_at']);
        $this->assertSame('Port Olisar', $row['location']);
        $this->assertSame($this->adminId, $row['created_by_id']);

        DiscordEvents::apply($this->org('PUBLISHED'), [$this->d('e2')], new DateTimeImmutable(self::NOW));
        $this->assertSame('PLANNED', $this->row('e2')['status']);
    }

    public function testSecondRunIsIdempotentAndFollowsDiscordChanges(): void
    {
        $org = $this->org();
        $now = new DateTimeImmutable(self::NOW);
        DiscordEvents::apply($org, [$this->d('e1')], $now);
        $again = DiscordEvents::apply($org, [$this->d('e1')], $now);
        $this->assertSame(['created' => 0, 'updated' => 0, 'cancelled' => 0], $again);
        $this->assertSame(1, (int) Db::val('SELECT COUNT(*) FROM events WHERE org_id = ?', [$this->orgId]));

        $r = DiscordEvents::apply($org, [$this->d('e1', ['name' => 'Raid verschoben', 'startsAt' => new DateTimeImmutable('2026-10-21T19:00:00Z'), 'endsAt' => null])], $now);
        $this->assertSame(1, $r['updated']);
        $row = $this->row('e1');
        $this->assertSame('Raid verschoben', $row['title']);
        $this->assertSame('2026-10-21 19:00:00', $row['starts_at']);
        $this->assertNull($row['ends_at']);
    }

    public function testShipsAndRsvpsSurviveTheSync(): void
    {
        $org = $this->org('PUBLISHED');
        $now = new DateTimeImmutable(self::NOW);
        DiscordEvents::apply($org, [$this->d('e1')], $now);
        $id = $this->row('e1')['id'];
        $shipId = new_id();
        Db::insert('event_ships', ['id' => $shipId, 'event_id' => $id, 'custom_name' => 'Cutlass', 'sort' => 0]);
        Db::insert('event_rsvps', ['event_id' => $id, 'user_id' => $this->adminId, 'status' => 'YES']);

        DiscordEvents::apply($org, [$this->d('e1', ['name' => 'Neuer Titel'])], $now);
        $this->assertSame(1, (int) Db::val('SELECT COUNT(*) FROM event_ships WHERE event_id = ?', [$id]));
        $this->assertSame(1, (int) Db::val('SELECT COUNT(*) FROM event_rsvps WHERE event_id = ?', [$id]));
    }

    public function testPlannerEditsAreNotOverwritten(): void
    {
        $org = $this->org();
        $now = new DateTimeImmutable(self::NOW);
        DiscordEvents::apply($org, [$this->d('e1')], $now);
        $row = $this->row('e1');

        // Planer ändert den Titel im Hangar
        Events::save($this->adminId, $this->orgId, [
            'title' => 'Unser Raid', 'startsAt' => '2026-10-20T20:00', 'endsAt' => '2026-10-20T23:00', 'draft' => true,
            'description' => 'Treffen im Hangar', 'location' => 'Port Olisar', 'ships' => [],
        ], $row['id']);
        $this->assertSame(1, (int) $this->row('e1')['discord_edited']);

        DiscordEvents::apply($org, [$this->d('e1', ['name' => 'Discord-Titel'])], $now);
        $this->assertSame('Unser Raid', $this->row('e1')['title']);
    }

    public function testPublishingWithoutChangingTheDataKeepsFollowingDiscord(): void
    {
        $org = $this->org();
        $now = new DateTimeImmutable(self::NOW);
        DiscordEvents::apply($org, [$this->d('e1')], $now);
        $row = $this->row('e1');
        // Planer veröffentlicht nur (Entwurf aus), Daten bleiben gleich (Berliner Zeit = UTC+2)
        Events::save($this->adminId, $this->orgId, [
            'title' => 'Raid', 'startsAt' => '2026-10-20T20:00', 'endsAt' => '2026-10-20T23:00',
            'description' => 'Treffen im Hangar', 'location' => 'Port Olisar', 'ships' => [],
        ], $row['id']);
        $this->assertSame(0, (int) $this->row('e1')['discord_edited']);
        $this->assertSame('PLANNED', $this->row('e1')['status']);

        DiscordEvents::apply($org, [$this->d('e1', ['name' => 'Raid 2'])], $now);
        $this->assertSame('Raid 2', $this->row('e1')['title']);
        $this->assertSame('PLANNED', $this->row('e1')['status']);
    }

    public function testDecisionsOfPlannersSurviveTheNextRuns(): void
    {
        $org = $this->org();
        $now = new DateTimeImmutable(self::NOW);
        DiscordEvents::apply($org, [$this->d('e1'), $this->d('e2', ['status' => 1])], $now);

        // e1 veröffentlicht: bleibt es bei jedem weiteren Durchlauf
        $id = $this->row('e1')['id'];
        Db::run("UPDATE events SET status = 'PLANNED' WHERE id = ?", [$id]);
        for ($i = 0; $i < 3; $i++) {
            DiscordEvents::apply($org, [$this->d('e1'), $this->d('e2')], $now);
        }
        $this->assertSame('PLANNED', $this->row('e1')['status']);

        // In Discord abgesagt, im Hangar wieder aktiviert: bleibt aktiv, solange Discord es weiter als abgesagt liefert
        DiscordEvents::apply($org, [$this->d('e1', ['status' => 4]), $this->d('e2')], $now);
        $this->assertSame('CANCELLED', $this->row('e1')['status']);
        Db::run("UPDATE events SET status = 'PLANNED' WHERE id = ?", [$id]);
        for ($i = 0; $i < 3; $i++) {
            DiscordEvents::apply($org, [$this->d('e1', ['status' => 4]), $this->d('e2')], $now);
        }
        $this->assertSame('PLANNED', $this->row('e1')['status']);

        // Wieder aktiv in Discord nach Absage: der Termin wird wieder übernommen (Entwurf je nach Einstellung)
        DiscordEvents::apply($org, [$this->d('e1', ['status' => 4]), $this->d('e2')], $now);
        Db::run("UPDATE events SET status = 'CANCELLED' WHERE id = ?", [$id]);
        DiscordEvents::apply($org, [$this->d('e1'), $this->d('e2')], $now);
        $this->assertSame('DRAFT', $this->row('e1')['status']);
    }
    public function testCancelledOrDeletedInDiscordCancelsTheAppointment(): void
    {
        $org = $this->org('PUBLISHED');
        $now = new DateTimeImmutable(self::NOW);
        DiscordEvents::apply($org, [$this->d('e1'), $this->d('e2')], $now);

        // e1 in Discord abgesagt, e2 gelöscht (fehlt in der Liste)
        $r = DiscordEvents::apply($org, [$this->d('e1', ['status' => 4])], $now);
        $this->assertSame(2, $r['cancelled']);
        $this->assertSame('CANCELLED', $this->row('e1')['status']);
        $this->assertSame('CANCELLED', $this->row('e2')['status']);
    }

    public function testEventsThatAlreadyStartedAreNotCancelledWhenTheyDisappear(): void
    {
        $org = $this->org('PUBLISHED');
        DiscordEvents::apply($org, [$this->d('e1')], new DateTimeImmutable(self::NOW));
        // Zwei Tage nach Beginn ist das Event aus Discords Liste verschwunden (beendet)
        DiscordEvents::apply($org, [], new DateTimeImmutable('2026-10-22T00:00:00Z'));
        $this->assertSame('PLANNED', $this->row('e1')['status']);
    }

    public function testCanceledOrFinishedEventsAreNeverImportedNew(): void
    {
        $org = $this->org();
        $r = DiscordEvents::apply($org, [$this->d('e1', ['status' => 4]), $this->d('e2', ['status' => 3])], new DateTimeImmutable(self::NOW));
        $this->assertSame(0, $r['created']);
        $this->assertNull($this->row('e1'));
    }

    public function testEventsOfAnotherOrgAreNotTouched(): void
    {
        $other = new_id();
        Db::insert('organizations', ['id' => $other, 'slug' => 'de-other', 'name' => 'Other', 'discord_guild_id' => 'other-guild', 'created_by_id' => $this->adminId]);
        Db::insert('events', ['id' => new_id(), 'org_id' => $other, 'title' => 'Fremd', 'starts_at' => '2026-10-20 18:00:00', 'created_by_id' => $this->adminId, 'discord_event_id' => 'e1']);
        DiscordEvents::apply($this->org(), [$this->d('e1')], new DateTimeImmutable(self::NOW));
        $this->assertSame('Fremd', Db::val('SELECT title FROM events WHERE org_id = ?', [$other]));
        $this->assertSame('Raid', $this->row('e1')['title']);
    }

    public function testShortOrLongTextsAreCutToTheLimits(): void
    {
        DiscordEvents::apply($this->org(), [$this->d('e1', ['name' => 'X', 'description' => str_repeat('a', 4000), 'location' => str_repeat('b', 300)])], new DateTimeImmutable(self::NOW));
        $row = $this->row('e1');
        $this->assertSame('Discord-Event', $row['title']);
        $this->assertSame(3000, mb_strlen($row['description']));
        $this->assertSame(100, mb_strlen($row['location']));
    }

    // --- Hangar → Discord ----------------------------------------------------------------

    /** @var list<array{0:string,1:string,2:?array}> */
    private array $calls = [];

    /** Discord-Nachbau: merkt sich die Aufrufe; $status/$body für POST und PATCH einstellbar. */
    private function fakeDiscord(int $status = 200, string $body = '{"id":"555"}'): void
    {
        $this->calls = [];
        Client::fake(function (string $m, string $url, array $h, ?string $payload) use ($status, $body): array {
            $this->calls[] = [$m, substr($url, strpos($url, '/guilds')), $payload !== null ? json_decode($payload, true) : null];
            return ['status' => $m === 'DELETE' ? ($status === 200 ? 204 : $status) : $status, 'body' => $m === 'POST' ? $body : '{}', 'headers' => []];
        });
    }

    private function save(array $extra = [], ?string $id = null): array
    {
        return Events::save($this->adminId, $this->orgId, $extra + [
            'title' => 'Mining-Abend', 'startsAt' => '2026-10-20T20:00', 'endsAt' => null,
            'description' => 'Erz abbauen', 'location' => 'Port Olisar', 'ships' => [], 'discord' => true,
        ], $id);
    }

    private function push(): string
    {
        return DiscordEvents::pushOrg($this->org(), new DateTimeImmutable(self::NOW));
    }

    public function testPublishedEventWithTickIsCreatedAsExternalDiscordEvent(): void
    {
        $this->fakeDiscord();
        $e = $this->save();
        $this->assertSame(1, (int) Db::val('SELECT discord_dirty FROM events WHERE id = ?', [$e['id']]));
        $this->push();

        $this->assertCount(1, $this->calls);
        [$m, $url, $p] = $this->calls[0];
        $this->assertSame('POST', $m);
        $this->assertSame('/guilds/de-guild/scheduled-events', $url);
        $this->assertSame(3, $p['entity_type']);
        $this->assertSame('Port Olisar', $p['entity_metadata']['location']);
        $this->assertSame('2026-10-20T18:00:00Z', $p['scheduled_start_time']);
        $this->assertSame('2026-10-20T21:00:00Z', $p['scheduled_end_time']);   // ohne Ende: drei Stunden
        $this->assertStringContainsString('/o/de-org/events/' . $e['id'], $p['description']);
        $row = Db::one('SELECT * FROM events WHERE id = ?', [$e['id']]);
        $this->assertSame('555', $row['discord_event_id']);
        $this->assertSame('PUSH', $row['discord_origin']);
        $this->assertSame(0, (int) $row['discord_dirty']);

        // nichts mehr zu tun
        $this->fakeDiscord();
        $this->push();
        $this->assertSame([], $this->calls);
    }

    public function testEventWithoutTickAndDraftsAreNotSentButPublishingSendsThem(): void
    {
        $this->fakeDiscord();
        $this->save(['discord' => false]);
        $draft = $this->save(['draft' => true, 'title' => 'Entwurf']);
        $this->push();
        $this->assertSame([], $this->calls);

        $this->save(['title' => 'Entwurf'], $draft['id']);   // veröffentlicht, Haken bleibt
        $this->push();
        $this->assertSame('POST', $this->calls[0][0]);
    }

    public function testChangesCancellationAndDeletionAreWrittenToDiscord(): void
    {
        $this->fakeDiscord();
        $e = $this->save();
        $this->push();

        $this->fakeDiscord();
        $this->save(['title' => 'Neuer Titel'], $e['id']);
        $this->push();
        $this->assertSame('PATCH', $this->calls[0][0]);
        $this->assertSame('/guilds/de-guild/scheduled-events/555', $this->calls[0][1]);
        $this->assertSame('Neuer Titel', $this->calls[0][2]['name']);

        $this->fakeDiscord();
        Events::setCancelled($this->adminId, $this->orgId, $e['id'], true);
        $this->push();
        $this->assertSame(['status' => 4], $this->calls[0][2]);

        // Wieder aktiviert: ein abgesagtes Discord-Event lässt sich nicht reaktivieren, es entsteht ein neues
        $this->fakeDiscord(200, '{"id":"777"}');
        Events::setCancelled($this->adminId, $this->orgId, $e['id'], false);
        $this->push();
        $this->assertSame('POST', $this->calls[0][0]);
        $this->assertSame('777', Db::val('SELECT discord_event_id FROM events WHERE id = ?', [$e['id']]));

        $this->fakeDiscord();
        Events::delete($this->adminId, $this->orgId, $e['id']);
        $this->push();
        $this->assertSame(['DELETE', '/guilds/de-guild/scheduled-events/777', null], $this->calls[0]);
        $this->assertSame(0, (int) Db::val('SELECT COUNT(*) FROM discord_event_deletions'));
    }

    public function testRemovingTheTickOrMakingADraftDeletesTheDiscordEvent(): void
    {
        $this->fakeDiscord();
        $e = $this->save();
        $this->push();

        $this->fakeDiscord();
        $this->save(['discord' => false], $e['id']);
        $this->push();
        $this->assertSame('DELETE', $this->calls[0][0]);
        $row = Db::one('SELECT * FROM events WHERE id = ?', [$e['id']]);
        $this->assertNull($row['discord_event_id']);
        $this->assertSame(0, (int) $row['discord_push']);
    }

    public function testFailuresAreRememberedAndRetried(): void
    {
        $this->fakeDiscord(403);
        $e = $this->save();
        $this->assertStringContainsString('1 fehlgeschlagen', $this->push());
        $row = Db::one('SELECT * FROM events WHERE id = ?', [$e['id']]);
        $this->assertSame(1, (int) $row['discord_dirty']);
        $this->assertStringContainsString('Events erstellen', $row['discord_error']);

        $this->fakeDiscord();
        $this->push();
        $row = Db::one('SELECT * FROM events WHERE id = ?', [$e['id']]);
        $this->assertSame(0, (int) $row['discord_dirty']);
        $this->assertNull($row['discord_error']);
        $this->assertSame('555', $row['discord_event_id']);
    }

    public function testPastEventsAreNotSent(): void
    {
        $this->fakeDiscord();
        $this->save(['startsAt' => '2026-09-01T20:00']);
        $this->push();
        $this->assertSame([], $this->calls);
        $this->assertSame(0, (int) Db::val('SELECT COUNT(*) FROM events WHERE discord_dirty = 1'));
    }

    public function testImportNeitherDuplicatesNorOverwritesEventsCreatedByTheHangar(): void
    {
        $this->fakeDiscord();
        $e = $this->save();
        $this->push();

        $org = $this->org();
        $now = new DateTimeImmutable(self::NOW);
        $r = DiscordEvents::apply($org, [$this->d('555', ['name' => 'In Discord umbenannt'])], $now);
        $this->assertSame(['created' => 0, 'updated' => 0, 'cancelled' => 0], $r);
        $this->assertSame('Mining-Abend', Db::val('SELECT title FROM events WHERE id = ?', [$e['id']]));
        $this->assertSame(1, (int) Db::val('SELECT COUNT(*) FROM events WHERE org_id = ?', [$this->orgId]));

        // In Discord abgesagt: das wird übernommen
        $r = DiscordEvents::apply($org, [$this->d('555', ['status' => 4])], $now);
        $this->assertSame(1, $r['cancelled']);
    }

    public function testImportedEventsIgnoreTheTick(): void
    {
        $this->fakeDiscord();
        DiscordEvents::apply($this->org(), [$this->d('e1')], new DateTimeImmutable(self::NOW));
        $row = $this->row('e1');
        $this->save(['title' => 'Raid', 'discord' => true], $row['id']);
        $this->assertSame(0, (int) $this->row('e1')['discord_push']);
        $this->push();
        $this->assertSame([], $this->calls);
    }
}
