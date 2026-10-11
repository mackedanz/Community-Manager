<?php

declare(strict_types=1);

namespace Hangar\Tests;

use DateTimeImmutable;
use Hangar\Db;
use Hangar\Discord;
use Hangar\OrgError;
use Hangar\Orgs;
use Hangar\Secrets;
use Hangar\Text;
use Hangar\Time;

final class OrgsTest extends DbTestCase
{
    private FakeDiscord $discord;

    protected function setUp(): void
    {
        parent::setUp();
        $this->discord = new FakeDiscord();
        $this->discord->install();
    }

    private static int $accSeq = 0;

    /** @return array<string,mixed> */
    private function mkDiscordUser(string $scope = FakeDiscord::SCOPES, int $expiresInSec = 3600): array
    {
        $u = $this->mkUser();
        Db::insert('accounts', [
            'id' => new_id(),
            'user_id' => $u['id'],
            'provider' => 'discord',
            'provider_account_id' => 'acc-' . (++self::$accSeq) . '-' . uniqid(),
            'access_token' => 'alt',
            'refresh_token' => 'ref',
            'expires_at' => time() + $expiresInSec,
            'scope' => $scope,
        ]);
        return $u;
    }

    /** @return array<string,mixed> */
    private function mkOrg(string $guildId, ?string $memberRoleIds = 'role-member', ?string $planner = null): array
    {
        $row = [
            'id' => new_id(), 'slug' => "o-$guildId", 'name' => "Orga $guildId", 'discord_guild_id' => $guildId,
            'member_role_ids' => $memberRoleIds, 'planner_role_ids' => $planner, 'created_by_id' => 'x',
        ];
        Db::insert('organizations', $row);
        return $row;
    }

    /** @return list<string> */
    private function orgsOf(string $userId): array
    {
        $rows = Db::all(
            'SELECT o.discord_guild_id AS g, m.role FROM org_memberships m JOIN organizations o ON o.id = m.org_id WHERE m.user_id = ?',
            [$userId],
        );
        $out = array_map(fn ($r) => "{$r['g']}:{$r['role']}", $rows);
        sort($out);
        return $out;
    }

    private function canPlan(string $userId, string $guildId): bool
    {
        return (bool) Db::val(
            'SELECT m.can_plan FROM org_memberships m JOIN organizations o ON o.id = m.org_id WHERE m.user_id = ? AND o.discord_guild_id = ?',
            [$userId, $guildId],
        );
    }

    // -------------------------------------------------------------------------------------

    public function testIsGuildAdmin(): void
    {
        $g = fn (array $x) => FakeDiscord::guild('1', $x);
        $this->assertTrue(Discord::isGuildAdmin($g(['owner' => true])));
        $this->assertTrue(Discord::isGuildAdmin($g(['permissions' => '8'])));
        $this->assertTrue(Discord::isGuildAdmin($g(['permissions' => '32'])));
        $this->assertTrue(Discord::isGuildAdmin($g(['permissions' => '2147483647'])));
        $this->assertFalse(Discord::isGuildAdmin($g(['permissions' => '1024'])));
        $this->assertFalse(Discord::isGuildAdmin($g(['permissions' => 'kaputt'])));
        // Größer als ein 64-Bit-Integer: die unteren 6 Bits entscheiden (10^6 ist durch 64 teilbar)
        $this->assertTrue(Discord::isGuildAdmin($g(['permissions' => '99999999999999999999999999000008'])));
        $this->assertTrue(Discord::isGuildAdmin($g(['permissions' => '99999999999999999999999999000032'])));
        $this->assertFalse(Discord::isGuildAdmin($g(['permissions' => '99999999999999999999999999000004'])));
    }

    public function testSlugify(): void
    {
        $this->assertSame('explorer-germany', Text::slugify('Explorer Germany'));
        $this->assertSame('groesste-flotte', Text::slugify('Größte Flotte!'));
        $this->assertSame('orga', Text::slugify('!!!'));
    }

    public function testNeedsCheckAfter24Hours(): void
    {
        $now = new DateTimeImmutable('2026-01-02T12:00:00Z');
        $this->assertTrue(Orgs::needsCheck(null, $now));
        $this->assertFalse(Orgs::needsCheck(new DateTimeImmutable('2026-01-02T00:00:00Z'), $now));
        $this->assertTrue(Orgs::needsCheck(new DateTimeImmutable('2026-01-01T12:00:00Z'), $now));
    }

    public function testSyncTakesOnlyServerMembersWithRole(): void
    {
        $this->mkOrg('g-role');
        $this->mkOrg('g-norole');
        $this->mkOrg('g-open', null);
        $this->mkOrg('g-fremd');
        $user = $this->mkDiscordUser();
        $this->discord->guilds = [FakeDiscord::guild('g-role'), FakeDiscord::guild('g-norole'), FakeDiscord::guild('g-open'), FakeDiscord::guild('g-unregistriert')];
        $this->discord->roles = ['g-role' => ['role-member'], 'g-norole' => ['andere-rolle']];

        $this->assertSame('OK', Orgs::syncMemberships($user['id']));
        $this->assertSame(['g-open:MEMBER', 'g-role:MEMBER'], $this->orgsOf($user['id']));
        // Rollen nur für registrierte Orgas mit Rolle, nie für fremde Server
        $calls = implode("\n", $this->discord->calls);
        $this->assertStringNotContainsString('g-unregistriert', $calls);
        // Auch bei offenen Orgas wird der Nickname geholt; ohne Mitgliedsdaten bleibt die Person trotzdem Mitglied.

        $u = Db::one('SELECT * FROM users WHERE id = ?', [$user['id']]);
        $this->assertSame('OK', $u['membership_status']);
        $this->assertNotNull($u['membership_checked_at']);
    }

    public function testStoresServerNicknamePerOrgAndUpdatesIt(): void
    {
        $a = $this->mkOrg('g-a');
        $b = $this->mkOrg('g-b');
        $user = $this->mkDiscordUser();
        $this->discord->guilds = [FakeDiscord::guild('g-a'), FakeDiscord::guild('g-b')];
        $this->discord->roles = ['g-a' => ['role-member'], 'g-b' => ['role-member']];
        $this->discord->nicks = ['g-a' => '  Maverick  '];

        $this->assertSame('OK', Orgs::syncMemberships($user['id']));
        $this->assertSame('Maverick', Db::val('SELECT nick FROM org_memberships WHERE user_id = ? AND org_id = ?', [$user['id'], $a['id']]));
        $this->assertNull(Db::val('SELECT nick FROM org_memberships WHERE user_id = ? AND org_id = ?', [$user['id'], $b['id']]));

        $this->discord->nicks = ['g-a' => 'Goose'];
        Orgs::syncMemberships($user['id']);
        $this->assertSame('Goose', Db::val('SELECT nick FROM org_memberships WHERE user_id = ? AND org_id = ?', [$user['id'], $a['id']]));
        $this->discord->nicks = [];
        Orgs::syncMemberships($user['id']);
        $this->assertNull(Db::val('SELECT nick FROM org_memberships WHERE user_id = ? AND org_id = ?', [$user['id'], $a['id']]));
    }

    public function testAdminStaysAdminWhenNicknameLookupFails(): void
    {
        $this->mkOrg('g-adm');
        $user = $this->mkDiscordUser();
        $this->discord->guilds = [FakeDiscord::guild('g-adm', ['permissions' => '8'])];
        // kein Eintrag in roles: das nachgebaute Discord antwortet mit 404 auf die Mitgliedsabfrage
        $this->assertSame('OK', Orgs::syncMemberships($user['id']));
        $this->assertSame(['g-adm:ADMIN'], $this->orgsOf($user['id']));
    }

    public function testOneOfSeveralRolesIsEnough(): void
    {
        $this->mkOrg('g-multi', 'rolle-a,rolle-b,rolle-c');
        $withB = $this->mkDiscordUser();
        $withNone = $this->mkDiscordUser();
        $this->discord->guilds = [FakeDiscord::guild('g-multi')];

        $this->discord->roles = ['g-multi' => ['andere', 'rolle-b']];
        Orgs::syncMemberships($withB['id']);
        $this->assertSame(['g-multi:MEMBER'], $this->orgsOf($withB['id']));

        $this->discord->roles = ['g-multi' => ['andere']];
        Orgs::syncMemberships($withNone['id']);
        $this->assertSame([], $this->orgsOf($withNone['id']));
    }

    public function testServerAdminsCountWithoutRole(): void
    {
        $this->mkOrg('g-admin');
        $user = $this->mkDiscordUser();
        $this->discord->guilds = [FakeDiscord::guild('g-admin', ['permissions' => '32'])];
        Orgs::syncMemberships($user['id']);
        $this->assertSame(['g-admin:ADMIN'], $this->orgsOf($user['id']));
    }

    public function testMembershipRemovedWhenServerLeftOrRoleRevoked(): void
    {
        $this->mkOrg('g-leave');
        $this->mkOrg('g-demote');
        $user = $this->mkDiscordUser();
        $this->discord->guilds = [FakeDiscord::guild('g-leave'), FakeDiscord::guild('g-demote')];
        $this->discord->roles = ['g-leave' => ['role-member'], 'g-demote' => ['role-member']];
        Orgs::syncMemberships($user['id']);
        $this->assertSame(['g-demote:MEMBER', 'g-leave:MEMBER'], $this->orgsOf($user['id']));

        $this->discord->guilds = [FakeDiscord::guild('g-demote')];
        $this->discord->roles = ['g-demote' => []];
        Orgs::syncMemberships($user['id']);
        $this->assertSame([], $this->orgsOf($user['id']));
    }

    public function testFailClosedOnInvalidDiscordAccess(): void
    {
        $this->mkOrg('g-401', null);
        $user = $this->mkDiscordUser();
        $this->discord->guilds = [FakeDiscord::guild('g-401')];
        Orgs::syncMemberships($user['id']);
        $this->assertCount(1, $this->orgsOf($user['id']));

        $this->discord->guildsStatus = 401;
        $this->assertSame('REAUTH', Orgs::syncMemberships($user['id']));
        $this->assertSame([], $this->orgsOf($user['id']));
        $this->assertSame('REAUTH', Db::val('SELECT membership_status FROM users WHERE id = ?', [$user['id']]));
    }

    public function testNewLoginRequiredWhenScopesMissing(): void
    {
        $user = $this->mkDiscordUser('identify');
        $this->assertSame('REAUTH', Orgs::syncMemberships($user['id']));
        $this->assertSame([], $this->discord->calls);
    }

    public function testKeepsMembershipsDuringOutageUpTo72HoursAndRetriesSoon(): void
    {
        $this->mkOrg('g-500a', null);
        $b = $this->mkOrg('g-500b', null);
        $user = $this->mkDiscordUser();
        $this->discord->guilds = [FakeDiscord::guild('g-500a'), FakeDiscord::guild('g-500b')];
        Orgs::syncMemberships($user['id']);
        // g-500b wurde zuletzt vor mehr als 72 Stunden bestätigt
        Db::run('UPDATE org_memberships SET verified_at = ? WHERE user_id = ? AND org_id = ?', [
            Time::db(Time::now()->modify('-' . (Orgs::GRACE + 1) . ' seconds')), $user['id'], $b['id'],
        ]);

        $this->discord->guildsStatus = 502;
        $now = Time::now();
        $this->assertSame('UNAVAILABLE', Orgs::syncMemberships($user['id'], $now));
        $this->assertSame(['g-500a:MEMBER'], $this->orgsOf($user['id']));

        $checkedAt = Time::parse(Db::val('SELECT membership_checked_at FROM users WHERE id = ?', [$user['id']]));
        $this->assertFalse(Orgs::needsCheck($checkedAt, $now->modify('+5 minutes')));
        $this->assertTrue(Orgs::needsCheck($checkedAt, $now->modify('+16 minutes')));
    }

    public function testRefreshesExpiredTokenAndStoresIt(): void
    {
        $user = $this->mkDiscordUser(FakeDiscord::SCOPES, -10);
        Orgs::syncMemberships($user['id']);
        $this->assertMatchesRegularExpression('#oauth2/token$#', $this->discord->calls[0]);
        $acc = Db::one('SELECT * FROM accounts WHERE user_id = ?', [$user['id']]);
        $this->assertSame('neu', Secrets::decrypt($acc['access_token']));
        $this->assertSame('ref2', Secrets::decrypt($acc['refresh_token']));
        $this->assertStringStartsWith('enc:v1:', $acc['access_token']);
        $this->assertGreaterThan(time(), (int) $acc['expires_at']);
    }

    public function testNewLoginRequiredWhenRefreshIsRejected(): void
    {
        $user = $this->mkDiscordUser(FakeDiscord::SCOPES, -10);
        $this->discord->tokenStatus = 400;
        $this->assertSame('REAUTH', Orgs::syncMemberships($user['id']));
    }

    // --- Orga anlegen und verwalten ----------------------------------------------------------

    public function testStoresUpToTenRolesIgnoringEmptyAndDuplicates(): void
    {
        $a = '111111111111111111';
        $b = '222222222222222222';
        $this->assertSame("$a,$b", Orgs::cleanRoleIds([$a, '', $b, $a, '']));

        $eleven = array_map(fn ($d) => str_repeat((string) ($d % 10), 18), range(1, 11));
        $eleven[9] = '1010101010101010101';
        $eleven[10] = '2020202020202020202';
        $this->expectException(OrgError::class);
        Orgs::cleanRoleIds($eleven);
    }

    public function testRoleIdFormatIsChecked(): void
    {
        $this->expectException(OrgError::class);
        Orgs::cleanRoleIds(['Mitglied']);
    }

    public function testOnlyAdminsChangeOrDeleteAndNewRoleForcesRecheck(): void
    {
        $org = $this->mkOrg('g-manage');
        $admin = $this->mkDiscordUser();
        $member = $this->mkDiscordUser();
        Db::insert('org_memberships', ['user_id' => $admin['id'], 'org_id' => $org['id'], 'role' => 'ADMIN']);
        Db::insert('org_memberships', ['user_id' => $member['id'], 'org_id' => $org['id'], 'role' => 'MEMBER']);
        Db::run('UPDATE users SET membership_checked_at = NOW() WHERE id = ?', [$member['id']]);

        $input = ['name' => 'Neu', 'memberRoleIds' => ['999999999999999999']];
        try {
            Orgs::update($member['id'], $org['id'], $input);
            $this->fail('Mitglied durfte ändern');
        } catch (OrgError) {
        }
        try {
            Orgs::delete($member['id'], $org['id']);
            $this->fail('Mitglied durfte löschen');
        } catch (OrgError) {
        }

        Orgs::update($admin['id'], $org['id'], $input);
        $this->assertSame('999999999999999999', Db::val('SELECT member_role_ids FROM organizations WHERE id = ?', [$org['id']]));
        $this->assertNull(Db::val('SELECT membership_checked_at FROM users WHERE id = ?', [$member['id']]));

        Orgs::delete($admin['id'], $org['id']);
        $this->assertNull(Db::val('SELECT id FROM organizations WHERE id = ?', [$org['id']]));
        $this->assertSame(0, (int) Db::val('SELECT COUNT(*) FROM org_memberships WHERE org_id = ?', [$org['id']]));
        $this->assertNotNull(Db::val('SELECT id FROM users WHERE id = ?', [$member['id']]));
    }

    // --- Planer-Rolle ------------------------------------------------------------------------

    public function testCanPlanForAdminsAndPlannerRoleOnly(): void
    {
        $this->mkOrg('g-plan', 'role-member', 'role-plan,role-plan2');
        $planer = $this->mkDiscordUser();
        $normal = $this->mkDiscordUser();
        $admin = $this->mkDiscordUser();

        $this->discord->guilds = [FakeDiscord::guild('g-plan')];
        $this->discord->roles = ['g-plan' => ['role-member', 'role-plan2']];
        Orgs::syncMemberships($planer['id']);
        $this->discord->roles = ['g-plan' => ['role-member']];
        Orgs::syncMemberships($normal['id']);
        $this->discord->guilds = [FakeDiscord::guild('g-plan', ['permissions' => '8'])];
        $this->discord->roles = ['g-plan' => []];
        Orgs::syncMemberships($admin['id']);

        $this->assertTrue($this->canPlan($planer['id'], 'g-plan'));
        $this->assertFalse($this->canPlan($normal['id'], 'g-plan'));
        $this->assertTrue($this->canPlan($admin['id'], 'g-plan'));
    }

    public function testCanRecruitForAdminsAndRecruiterRoleOnly(): void
    {
        $org = $this->mkOrg('g-rec', 'role-member');
        Db::run('UPDATE organizations SET recruiter_role_ids = ? WHERE id = ?', ['role-rec,role-rec2', $org['id']]);
        $recruiter = $this->mkDiscordUser();
        $normal = $this->mkDiscordUser();
        $admin = $this->mkDiscordUser();
        $canRecruit = fn (string $uid): bool => (bool) Db::val('SELECT can_recruit FROM org_memberships WHERE user_id = ? AND org_id = ?', [$uid, $org['id']]);

        $this->discord->guilds = [FakeDiscord::guild('g-rec')];
        $this->discord->roles = ['g-rec' => ['role-member', 'role-rec2']];
        Orgs::syncMemberships($recruiter['id']);
        $this->discord->roles = ['g-rec' => ['role-member']];
        Orgs::syncMemberships($normal['id']);
        $this->discord->guilds = [FakeDiscord::guild('g-rec', ['permissions' => '8'])];
        $this->discord->roles = ['g-rec' => []];
        Orgs::syncMemberships($admin['id']);

        $this->assertTrue($canRecruit($recruiter['id']));
        $this->assertFalse($canRecruit($normal['id']));
        $this->assertTrue($canRecruit($admin['id']));
        $byId = fn (string $uid): array => array_column(Orgs::listMyOrgs($uid), null, 'id')[$org['id']];
        $this->assertTrue($byId($recruiter['id'])['canRecruit']);
        $this->assertFalse($byId($recruiter['id'])['canPlan'], 'Recruiter dürfen deshalb nicht planen');
        $this->assertFalse($byId($normal['id'])['canRecruit']);
        $this->assertTrue($byId($admin['id'])['canRecruit']);
    }

    public function testRecruiterRolesAreStoredKeptWithoutInputAndForceRecheck(): void
    {
        $admin = $this->mkDiscordUser();
        $member = $this->mkDiscordUser();
        $org = $this->mkOrg('g-rec-edit', null);
        Db::insert('org_memberships', ['user_id' => $admin['id'], 'org_id' => $org['id'], 'role' => 'ADMIN']);
        Db::insert('org_memberships', ['user_id' => $member['id'], 'org_id' => $org['id']]);
        Db::run('UPDATE users SET membership_checked_at = NOW() WHERE id = ?', [$member['id']]);

        Orgs::update($admin['id'], $org['id'], ['name' => 'Rec', 'memberRoleIds' => [], 'recruiterRoleIds' => ['222222222222222222']]);
        $this->assertSame('222222222222222222', Db::val('SELECT recruiter_role_ids FROM organizations WHERE id = ?', [$org['id']]));
        $this->assertNull(Db::val('SELECT membership_checked_at FROM users WHERE id = ?', [$member['id']]));

        Orgs::update($admin['id'], $org['id'], ['name' => 'Rec 2', 'memberRoleIds' => []]);
        $this->assertSame('222222222222222222', Db::val('SELECT recruiter_role_ids FROM organizations WHERE id = ?', [$org['id']]), 'ohne Angabe unverändert');
    }

    public function testCanInstructForAdminsAndInstructorRoleOnly(): void
    {
        $org = $this->mkOrg('g-ins', 'role-member');
        Db::run('UPDATE organizations SET instructor_role_ids = ? WHERE id = ?', ['role-ins', $org['id']]);
        $teacher = $this->mkDiscordUser();
        $normal = $this->mkDiscordUser();
        $admin = $this->mkDiscordUser();

        $this->discord->guilds = [FakeDiscord::guild('g-ins')];
        $this->discord->roles = ['g-ins' => ['role-member', 'role-ins']];
        Orgs::syncMemberships($teacher['id']);
        $this->discord->roles = ['g-ins' => ['role-member']];
        Orgs::syncMemberships($normal['id']);
        $this->discord->guilds = [FakeDiscord::guild('g-ins', ['permissions' => '8'])];
        $this->discord->roles = ['g-ins' => []];
        Orgs::syncMemberships($admin['id']);

        $byId = fn (string $uid): array => array_column(Orgs::listMyOrgs($uid), null, 'id')[$org['id']];
        $this->assertTrue($byId($teacher['id'])['canInstruct']);
        $this->assertFalse($byId($teacher['id'])['canPlan']);
        $this->assertFalse($byId($normal['id'])['canInstruct']);
        $this->assertTrue($byId($admin['id'])['canInstruct']);
    }

    public function testFetchesRolesEvenWhenOnlyPlannerRoleIsSet(): void
    {
        $this->mkOrg('g-plan-open', null, 'role-plan');
        $planer = $this->mkDiscordUser();
        $normal = $this->mkDiscordUser();
        $this->discord->guilds = [FakeDiscord::guild('g-plan-open')];
        $this->discord->roles = ['g-plan-open' => ['role-plan']];
        Orgs::syncMemberships($planer['id']);
        $this->discord->roles = ['g-plan-open' => []];
        Orgs::syncMemberships($normal['id']);

        $this->assertSame(['g-plan-open:MEMBER'], $this->orgsOf($planer['id']));
        $this->assertTrue($this->canPlan($planer['id'], 'g-plan-open'));
        $this->assertSame(['g-plan-open:MEMBER'], $this->orgsOf($normal['id']));
        $this->assertFalse($this->canPlan($normal['id'], 'g-plan-open'));
    }

    public function testPlannerRolesAreStoredAndForceRecheck(): void
    {
        $admin = $this->mkDiscordUser();
        $member = $this->mkDiscordUser();
        $org = $this->mkOrg('g-plan-edit', null);
        Db::insert('org_memberships', ['user_id' => $admin['id'], 'org_id' => $org['id'], 'role' => 'ADMIN']);
        Db::insert('org_memberships', ['user_id' => $member['id'], 'org_id' => $org['id']]);
        Db::run('UPDATE users SET membership_checked_at = NOW() WHERE id = ?', [$member['id']]);

        Orgs::update($admin['id'], $org['id'], ['name' => 'Edit', 'memberRoleIds' => [], 'plannerRoleIds' => ['111111111111111111']]);
        $this->assertSame('111111111111111111', Db::val('SELECT planner_role_ids FROM organizations WHERE id = ?', [$org['id']]));
        $this->assertNull(Db::val('SELECT membership_checked_at FROM users WHERE id = ?', [$member['id']]));

        // Ohne Angabe bleiben die Planer-Rollen unverändert
        Orgs::update($admin['id'], $org['id'], ['name' => 'Edit 2', 'memberRoleIds' => []]);
        $this->assertSame('111111111111111111', Db::val('SELECT planner_role_ids FROM organizations WHERE id = ?', [$org['id']]));
    }

    public function testRoleLabelsOnlyForEnteredRolesAndKeptWithoutInput(): void
    {
        $admin = $this->mkDiscordUser();
        $org = $this->mkOrg('g-labels', null);
        Db::insert('org_memberships', ['user_id' => $admin['id'], 'org_id' => $org['id'], 'role' => 'ADMIN']);
        $a = '111111111111111111';
        $b = '222222222222222222';
        $read = fn () => Orgs::parseRoleLabels(Db::val('SELECT role_labels FROM organizations WHERE id = ?', [$org['id']]));

        Orgs::update($admin['id'], $org['id'], [
            'name' => 'Labels', 'memberRoleIds' => [$a], 'plannerRoleIds' => [$b],
            'roleNames' => [$a => ' Mitglied ', $b => 'Planer', '333333333333333333' => 'Fremd'],
        ]);
        $this->assertSame([$a => 'Mitglied', $b => 'Planer'], $read());

        // Ohne roleNames bleiben die Namen; entfernte Rollen verlieren ihren Namen
        Orgs::update($admin['id'], $org['id'], ['name' => 'Labels', 'memberRoleIds' => [$a], 'plannerRoleIds' => []]);
        $this->assertSame([$a => 'Mitglied'], $read());
    }
}
