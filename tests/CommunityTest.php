<?php

declare(strict_types=1);

namespace Hangar\Tests;

use Hangar\Community;
use Hangar\Db;
use Hangar\Viewer;

final class CommunityTest extends DbTestCase
{
    private string $orgA;
    private string $orgB;
    /** @var array<string,string> */
    private array $u = [];
    private string $shipId;
    private Viewer $a1;
    private Viewer $a2;
    private Viewer $b1;
    private Viewer $both;
    private Viewer $loner;

    protected function setUp(): void
    {
        parent::setUp();
        $mkOrg = function (string $name): string {
            $id = new_id();
            Db::insert('organizations', ['id' => $id, 'slug' => "com-$name", 'name' => $name, 'discord_guild_id' => "guild-$name", 'created_by_id' => 'x']);
            return $id;
        };
        $this->orgA = $mkOrg('A');
        $this->orgB = $mkOrg('B');
        $this->shipId = new_id();
        Db::insert('catalog_items', ['id' => $this->shipId, 'kind' => 'SHIP', 'slug' => 'com-ship', 'name' => 'Com Ship', 'match_key' => 'comship', 'data' => '{}']);
        $achievementId = new_id();
        Db::insert('achievements', ['id' => $achievementId, 'key' => 'com-test', 'title' => 'Com Test', 'description' => 'Test']);

        $mk = function (string $name, string $visibility, array $orgs) use ($achievementId): void {
            $user = $this->mkUser(['name' => $name, 'hangar_visibility' => $visibility, 'achievements_visibility' => $visibility]);
            foreach ($orgs as $o) {
                Db::insert('org_memberships', ['user_id' => $user['id'], 'org_id' => $o]);
            }
            Db::insert('owned_items', ['id' => new_id(), 'user_id' => $user['id'], 'catalog_item_id' => $this->shipId, 'kind' => 'SHIP', 'source' => 'IMPORT']);
            Db::insert('import_logs', ['id' => new_id(), 'user_id' => $user['id'], 'source' => 'api', 'created' => 1, 'updated' => 0, 'unmatched' => 0]);
            Db::insert('user_achievements', ['user_id' => $user['id'], 'achievement_id' => $achievementId]);
            $this->u[$name] = $user['id'];
        };
        $mk('A-Mem', 'MEMBERS', [$this->orgA]);
        $mk('A-Priv', 'PRIVATE', [$this->orgA]);
        $mk('B-Mem', 'MEMBERS', [$this->orgB]);
        $mk('Both', 'MEMBERS', [$this->orgA, $this->orgB]);
        $mk('Loner', 'MEMBERS', []);

        $this->a1 = VisibilityFilterTest::viewer($this->u['A-Mem'], [$this->orgA]);
        $this->a2 = VisibilityFilterTest::viewer($this->u['A-Priv'], [$this->orgA]);
        $this->b1 = VisibilityFilterTest::viewer($this->u['B-Mem'], [$this->orgB]);
        $this->both = VisibilityFilterTest::viewer($this->u['Both'], [$this->orgA, $this->orgB]);
        $this->loner = VisibilityFilterTest::viewer($this->u['Loner'], []);
    }

    private static function names(array $rows): array
    {
        $n = array_column($rows, 'name');
        sort($n);
        return $n;
    }

    private static function feedUsers(array $feed): array
    {
        $n = array_values(array_unique(array_column($feed, 'userName')));
        sort($n);
        return $n;
    }

    // --- getOwners ---------------------------------------------------------------------------

    public function testGuestsSeeNoOwners(): void
    {
        $this->assertSame([], Community::getOwners($this->shipId, null));
    }

    public function testOwnersOnlyFromSharedOrgsWithVisibleHangarPlusSelf(): void
    {
        $this->assertSame(['A-Mem', 'Both'], self::names(Community::getOwners($this->shipId, $this->a1)));
        $this->assertSame(['A-Mem', 'A-Priv', 'Both'], self::names(Community::getOwners($this->shipId, $this->a2)));
        $this->assertSame(['B-Mem', 'Both'], self::names(Community::getOwners($this->shipId, $this->b1)));
        $this->assertSame(['A-Mem', 'B-Mem', 'Both'], self::names(Community::getOwners($this->shipId, $this->both)));
    }

    public function testWithoutOrgOnlyOneself(): void
    {
        $this->assertSame(['Loner'], self::names(Community::getOwners($this->shipId, $this->loner)));
    }

    // --- listMembers -------------------------------------------------------------------------

    public function testListsOnlyOrgMembersAndHidesPrivateNumbers(): void
    {
        $list = Community::listMembers($this->orgA, $this->a1);
        $this->assertSame(['A-Mem', 'A-Priv', 'Both'], self::names($list));
        $by = array_column($list, null, 'name');
        $this->assertSame(1, $by['A-Mem']['shipCount']);
        $this->assertNull($by['A-Priv']['shipCount']);
        $this->assertNull($by['A-Priv']['achievementCount']);
    }

    public function testServerNicknameReplacesNameOnlyInThatOrg(): void
    {
        Db::run('UPDATE org_memberships SET nick = ? WHERE user_id = ? AND org_id = ?', ['Maverick', $this->u['Both'], $this->orgA]);
        $this->assertSame(['A-Mem', 'A-Priv', 'Maverick'], self::names(Community::listMembers($this->orgA, $this->a1)));
        $this->assertSame(['B-Mem', 'Both'], self::names(Community::listMembers($this->orgB, $this->b1)));
        // auch in den Aktivitäten der Orga
        $this->assertContains('Maverick', self::feedUsers(Community::getFeed($this->orgA, $this->a1)));
        $this->assertNotContains('Both', self::feedUsers(Community::getFeed($this->orgA, $this->a1)));
        $this->assertContains('Both', self::feedUsers(Community::getFeed($this->orgB, $this->b1)));
    }

    public function testNonMembersGetNothingEvenWithKnownOrgId(): void
    {
        $this->assertSame([], Community::listMembers($this->orgA, $this->b1));
        $this->assertSame([], Community::listMembers($this->orgA, $this->loner));
        $this->assertSame([], Community::listMembers($this->orgA, null));
    }

    // --- getProfile --------------------------------------------------------------------------

    public function testProfileOfOtherOrgIsNotEvenKnownToExist(): void
    {
        $this->assertNull(Community::getProfile($this->u['A-Mem'], $this->b1));
        $this->assertNull(Community::getProfile($this->u['B-Mem'], $this->a1));
        $this->assertNull(Community::getProfile($this->u['A-Mem'], null));
    }

    public function testSameOrgSeesHangarAchievementsAndSyncDate(): void
    {
        $p = Community::getProfile($this->u['A-Mem'], $this->a1);
        $this->assertCount(1, $p['items']);
        $this->assertCount(1, $p['achievements']);
        $this->assertInstanceOf(\DateTimeImmutable::class, $p['lastSync']);
    }

    public function testProfileShowsOnlyShipsNotGearOrPaints(): void
    {
        $uid = $this->u['A-Mem'];
        Db::insert('owned_items', ['id' => new_id(), 'user_id' => $uid, 'kind' => 'ARMOR', 'custom_name' => 'Helm']);
        Db::insert('owned_items', ['id' => new_id(), 'user_id' => $uid, 'kind' => 'PAINT', 'custom_name' => 'Lack']);
        Db::insert('owned_items', ['id' => new_id(), 'user_id' => $uid, 'kind' => 'ITEM', 'custom_name' => 'Kram']);
        $p = Community::getProfile($uid, $this->both);
        $this->assertSame(['SHIP'], array_values(array_unique(array_column($p['items'], 'kind'))));
        $this->assertCount(1, $p['items']);
        $own = Community::getProfile($uid, $this->a1);
        $this->assertSame(['SHIP'], array_values(array_unique(array_column($own['items'], 'kind'))), 'auch im eigenen Profil nur Schiffe');
    }

    public function testSameOrgSeesNoContentOfPrivateProfile(): void
    {
        $p = Community::getProfile($this->u['A-Priv'], $this->a1);
        $this->assertSame('A-Priv', $p['user']['name']);
        $this->assertNull($p['items']);
        $this->assertNull($p['achievements']);
        $this->assertNull($p['lastSync']);
    }

    public function testUserInBothOrgsIsVisibleInBoth(): void
    {
        $this->assertCount(1, Community::getProfile($this->u['Both'], $this->a1)['items']);
        $this->assertCount(1, Community::getProfile($this->u['Both'], $this->b1)['items']);
    }

    public function testOwnerSeesOwnProfileEvenWithoutOrg(): void
    {
        $this->assertCount(1, Community::getProfile($this->u['Loner'], $this->loner)['items']);
        $this->assertCount(1, Community::getProfile($this->u['A-Priv'], $this->a2)['items']);
    }

    public function testUnknownIdGivesNull(): void
    {
        $this->assertNull(Community::getProfile('gibt-es-nicht', $this->a1));
    }

    // --- getOrgFleet -------------------------------------------------------------------------

    private static function entry(array $fleet, string $name): ?array
    {
        foreach ($fleet['entries'] as $e) {
            if ($e['name'] === $name) {
                return $e;
            }
        }
        return null;
    }

    public function testCountsShipsOfOrgMembersIncludingPrivate(): void
    {
        $fleetA = Community::getOrgFleet($this->orgA, $this->a1);
        $this->assertSame(3, self::entry($fleetA, 'Com Ship')['count']);
        $this->assertSame(3, $fleetA['memberCount']);
        $this->assertSame(2, self::entry(Community::getOrgFleet($this->orgB, $this->b1), 'Com Ship')['count']);
    }

    public function testNonMembersGetEmptyFleet(): void
    {
        $this->assertSame([], Community::getOrgFleet($this->orgA, $this->b1)['entries']);
        $this->assertSame(0, Community::getOrgFleet($this->orgA, null)['totalShips']);
    }

    public function testFleetContainsNoPersonalData(): void
    {
        $fleet = Community::getOrgFleet($this->orgA, $this->a1);
        $json = json_encode($fleet);
        foreach (['A-Mem', 'A-Priv', 'Both'] as $name) {
            $this->assertStringNotContainsString($name, $json);
        }
        $keys = array_keys($fleet['entries'][0]);
        sort($keys);
        $this->assertSame(['catalogItemId', 'count', 'href', 'imageUrl', 'manufacturer', 'name', 'specs'], $keys);
    }

    public function testShipImagesComeFromLocalCache(): void
    {
        $fleet = Community::getOrgFleet($this->orgA, $this->a1);
        $this->assertSame('/img/ship/com-ship', $fleet['entries'][0]['imageUrl']);
    }

    public function testCountsShipsWithoutCatalogByNameAndIgnoresArmor(): void
    {
        Db::insert('owned_items', ['id' => new_id(), 'user_id' => $this->u['A-Mem'], 'kind' => 'SHIP', 'custom_name' => 'Frei Schiff', 'quantity' => 2]);
        Db::insert('owned_items', ['id' => new_id(), 'user_id' => $this->u['B-Mem'], 'kind' => 'SHIP', 'custom_name' => 'Frei Schiff']);
        $armor = new_id();
        Db::insert('catalog_items', ['id' => $armor, 'kind' => 'ARMOR', 'slug' => 'org-armor', 'name' => 'Org Armor', 'match_key' => 'orgarmor', 'data' => '{}']);
        Db::insert('owned_items', ['id' => new_id(), 'user_id' => $this->u['A-Mem'], 'kind' => 'ARMOR', 'catalog_item_id' => $armor]);

        $fleet = Community::getOrgFleet($this->orgA, $this->a1);
        $free = self::entry($fleet, 'Frei Schiff');
        $this->assertSame(2, $free['count']);
        $this->assertNull($free['href']);
        $this->assertNull(self::entry($fleet, 'Org Armor'));
        $counts = array_column($fleet['entries'], 'count');
        $sorted = $counts;
        rsort($sorted);
        $this->assertSame($sorted, $counts);
    }

    // --- getFeed -----------------------------------------------------------------------------

    public function testFeedOnlyOrgMembersWithVisibleData(): void
    {
        $this->assertSame(['A-Mem', 'Both'], self::feedUsers(Community::getFeed($this->orgA, $this->a1)));
        $this->assertSame(['B-Mem', 'Both'], self::feedUsers(Community::getFeed($this->orgB, $this->b1)));
    }

    public function testOwnPrivateEventsOnlyForYourself(): void
    {
        $this->assertSame(['A-Mem', 'A-Priv', 'Both'], self::feedUsers(Community::getFeed($this->orgA, $this->a2)));
    }

    public function testGroupsSeveralAchievementsOfTheSameDay(): void
    {
        for ($n = 1; $n <= 5; $n++) {
            $id = new_id();
            Db::insert('achievements', ['id' => $id, 'key' => "com-extra-$n", 'title' => "Extra $n", 'description' => 'Test']);
            Db::insert('user_achievements', ['user_id' => $this->u['A-Mem'], 'achievement_id' => $id]);
        }
        $own = array_values(array_filter(Community::getFeed($this->orgA, $this->a1), fn ($e) => $e['userName'] === 'A-Mem' && str_contains($e['text'], 'Errungenschaft')));
        $this->assertCount(1, $own);
        $this->assertSame('hat 6 Errungenschaften erreicht (Com Test, Extra 1, Extra 2, Extra 3 und 2 weitere)', $own[0]['text']);
    }

    public function testFeedForNonMembersIsEmpty(): void
    {
        $this->assertSame([], Community::getFeed($this->orgA, $this->b1));
        $this->assertSame([], Community::getFeed($this->orgA, null));
    }
}
