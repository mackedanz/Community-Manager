<?php

declare(strict_types=1);

namespace Hangar\Tests;

use Hangar\App;
use Hangar\Auth;
use Hangar\Db;
use Hangar\Env;
use Hangar\Http\Client;
use Hangar\Http\Request;
use Hangar\Onboarding;
use Hangar\Time;
use Hangar\OrgError;

/**
 * Zugangsliste und Anmelde-Sperre. Orga anlegen und Rollen wählen erledigt der Discord-Bot (BOT/, eigene Tests);
 * hier geht es um den Abgleich, den die Web-App stündlich und unter "Orga verwalten" ausführt.
 */
final class OnboardingTest extends DbTestCase
{
    private const GUILD = '900000000000000001';
    private const ROLE_USE = '800000000000000001';
    private const ROLE_PLAN = '800000000000000002';
    private const ADMIN = '700000000000000001';

    /** @var list<array<string,mixed>> Mitglieder, die der nachgebaute Server zurückgibt */
    private array $members = [];

    protected function setUp(): void
    {
        parent::setUp();
        Auth::reset();
        Env::set('DISCORD_BOT_TOKEN', 'bot-token');
        Env::set('AUTH_DISCORD_ID', '600000000000000001');
        $this->members = [
            $this->member('700000000000000001', 'Chef', [self::ROLE_USE]),
            $this->member('700000000000000002', 'Pilot', [self::ROLE_USE]),
            $this->member('700000000000000003', 'Planer', [self::ROLE_PLAN]),
            $this->member('700000000000000004', 'Gast', []),
            ['user' => ['id' => '700000000000000005', 'username' => 'Bot', 'bot' => true], 'roles' => [self::ROLE_USE]],
        ];
        Client::fake(function (string $method, string $url): array {
            if (str_contains($url, '/members?')) {
                return ['status' => 200, 'body' => json_encode($this->members), 'headers' => []];
            }
            return ['status' => 500, 'body' => '[]', 'headers' => []];
        });
    }

    /** @param list<string> $roles @return array<string,mixed> */
    private function member(string $id, string $name, array $roles): array
    {
        return ['user' => ['id' => $id, 'username' => strtolower($name), 'global_name' => $name, 'avatar' => 'av' . $id], 'roles' => $roles];
    }

    /**
     * Orga wie nach /einrichten im Bot: die aufrufende Person (ADMIN) steht fest auf der Liste.
     * @param array<string,mixed> $columns
     */
    private function setUpOrg(array $columns = []): string
    {
        $id = new_id();
        Db::insert('organizations', $columns + ['id' => $id, 'slug' => 'org-' . $id, 'name' => 'Mac Dance', 'discord_guild_id' => self::GUILD, 'created_by_id' => self::ADMIN]);
        Db::insert('org_allowed_members', ['org_id' => $id, 'discord_id' => self::ADMIN, 'name' => 'Chef', 'fixed' => 1, 'synced_at' => Time::nowDb()]);
        return $id;
    }

    private function setUpOrgWithUseRole(): string
    {
        return $this->setUpOrg(['member_role_ids' => self::ROLE_USE]);
    }

    private function accountFor(string $discordId): string
    {
        $uid = new_id();
        Db::insert('users', ['id' => $uid, 'name' => 'U' . $discordId]);
        Db::insert('accounts', ['id' => new_id(), 'user_id' => $uid, 'provider' => 'discord', 'provider_account_id' => $discordId]);
        return $uid;
    }

    /** @param list<string> $ids */
    private function putOnList(string $orgId, array $ids): void
    {
        foreach ($ids as $id) {
            $this->accountFor($id);
            Db::run('INSERT INTO org_allowed_members (org_id, discord_id, name, synced_at) VALUES (?,?,?,?)', [$orgId, $id, 'P' . $id, Time::nowDb()]);
        }
    }

    // --- Abgleich ----------------------------------------------------------------------------

    public function testApiTokenStopsWorkingWhenPersonIsRemovedFromTheAllowlist(): void
    {
        Env::set('LOGIN_REQUIRES_ALLOWLIST', '1');
        $org = $this->setUpOrg();
        $user = $this->mkUser(['discord_id' => '700000000000000042']);
        $token = \Hangar\ApiToken::create($user['id'], 't')['token'];
        $this->assertNull(\Hangar\ApiToken::verify($token), 'nicht auf der Zugangsliste');
        Db::insert('org_allowed_members', ['org_id' => $org, 'discord_id' => '700000000000000042', 'name' => 'X', 'fixed' => 0]);
        $this->assertSame($user['id'], \Hangar\ApiToken::verify($token));
        Db::run('DELETE FROM org_allowed_members WHERE discord_id = ?', ['700000000000000042']);
        $this->assertNull(\Hangar\ApiToken::verify($token), 'nach dem Entfernen sofort ungültig');
    }

    public function testSyncWritesRoleHoldersWithIdNameAndAvatar(): void
    {
        $orgId = $this->setUpOrg(['member_role_ids' => self::ROLE_USE, 'planner_role_ids' => self::ROLE_PLAN]);
        $r = Onboarding::syncAllowlist($orgId);

        // Chef (fix + Rolle), Pilot, Planer; nicht: Gast ohne Rolle, Bot
        $this->assertSame(3, $r['total']);
        $this->assertSame(2, $r['added']);
        $ids = $this->ids('org_allowed_members', 'discord_id');
        sort($ids);
        $this->assertSame(['700000000000000001', '700000000000000002', '700000000000000003'], $ids);
        $pilot = Db::one("SELECT * FROM org_allowed_members WHERE discord_id = '700000000000000002'");
        $this->assertSame('Pilot', $pilot['name']);
        $this->assertSame('https://cdn.discordapp.com/avatars/700000000000000002/av700000000000000002.png', $pilot['avatar_url']);
        $this->assertNotNull(Db::val('SELECT allowlist_synced_at FROM organizations'));
    }

    public function testSyncRemovesLostMembersButKeepsFixedOnes(): void
    {
        $orgId = $this->setUpOrgWithUseRole();
        Onboarding::syncAllowlist($orgId);
        $this->members = [$this->member('700000000000000002', 'Pilot', [])]; // Pilot hat die Rolle verloren, Chef ist weg
        $r = Onboarding::syncAllowlist($orgId);
        $this->assertSame(1, $r['total']);
        $this->assertSame([self::ADMIN], $this->ids('org_allowed_members', 'discord_id'));
    }

    public function testSyncRefreshesNicknameAndAccountNameOfExistingMembers(): void
    {
        $orgId = $this->setUpOrgWithUseRole();
        $uid = new_id();
        Db::insert('users', ['id' => $uid, 'name' => 'Alt', 'discord_id' => '700000000000000002']);
        Db::insert('org_memberships', ['user_id' => $uid, 'org_id' => $orgId, 'nick' => 'Alter Nick']);

        $this->members[1]['nick'] = 'Neuer Nick';
        Onboarding::syncAllowlist($orgId);
        $this->assertSame('Neuer Nick', Db::val('SELECT nick FROM org_memberships WHERE user_id = ?', [$uid]));
        $this->assertSame('Pilot', Db::val('SELECT name FROM users WHERE id = ?', [$uid]));

        unset($this->members[1]['nick']);
        Onboarding::syncAllowlist($orgId);
        $this->assertNull(Db::val('SELECT nick FROM org_memberships WHERE user_id = ?', [$uid]));
    }

    public function testSyncRefreshesAvatarAndServerIcon(): void
    {
        $orgId = $this->setUpOrgWithUseRole();
        Db::run("UPDATE organizations SET icon_url = 'https://cdn.discordapp.com/icons/old/alt.png' WHERE id = ?", [$orgId]);
        $uid = new_id();
        Db::insert('users', ['id' => $uid, 'name' => 'Pilot', 'discord_id' => '700000000000000002', 'image' => 'https://cdn.discordapp.com/avatars/700000000000000002/alt.png']);
        Db::insert('org_memberships', ['user_id' => $uid, 'org_id' => $orgId]);
        $guild = ['icon' => 'a_neu'];
        Client::fake(function (string $method, string $url) use (&$guild): array {
            if (str_contains($url, '/members?')) {
                return ['status' => 200, 'body' => json_encode($this->members), 'headers' => []];
            }
            return $guild === null ? ['status' => 500, 'body' => '[]', 'headers' => []] : ['status' => 200, 'body' => json_encode($guild), 'headers' => []];
        });

        Onboarding::syncAllowlist($orgId);
        $this->assertSame('https://cdn.discordapp.com/avatars/700000000000000002/av700000000000000002.png', Db::val('SELECT image FROM users WHERE id = ?', [$uid]));
        $this->assertSame('https://cdn.discordapp.com/icons/' . self::GUILD . '/a_neu.png', Db::val('SELECT icon_url FROM organizations WHERE id = ?', [$orgId]));

        // Discord nicht erreichbar: das gespeicherte Icon bleibt
        $guild = null;
        Onboarding::syncAllowlist($orgId);
        $this->assertSame('https://cdn.discordapp.com/icons/' . self::GUILD . '/a_neu.png', Db::val('SELECT icon_url FROM organizations WHERE id = ?', [$orgId]));

        // Server ohne Icon
        $guild = ['icon' => null];
        Onboarding::syncAllowlist($orgId);
        $this->assertNull(Db::val('SELECT icon_url FROM organizations WHERE id = ?', [$orgId]));
    }

    public function testFormerMemberAccountIsDeletedWhenGateIsOn(): void
    {
        Env::set('LOGIN_REQUIRES_ALLOWLIST', '1');
        $orgId = $this->setUpOrgWithUseRole();
        Onboarding::syncAllowlist($orgId);
        $pilot = $this->accountFor('700000000000000002');
        $chef = $this->accountFor(self::ADMIN);
        $this->members = [$this->member('700000000000000003', 'Planer', [self::ROLE_USE])]; // Pilot und Chef weg
        $r = Onboarding::syncAllowlist($orgId);
        $this->assertSame(1, $r['deleted']);
        $this->assertNull(Db::val('SELECT id FROM users WHERE id = ?', [$pilot]));
        $this->assertSame($chef, Db::val('SELECT id FROM users WHERE id = ?', [$chef]), 'fest eingetragene Personen bleiben');
        $this->assertNull(Db::val('SELECT 1 FROM accounts WHERE user_id = ?', [$pilot]));
    }

    public function testNothingIsDeletedWithoutTheGate(): void
    {
        $orgId = $this->setUpOrgWithUseRole();
        Onboarding::syncAllowlist($orgId);
        $pilot = $this->accountFor('700000000000000002');
        $this->members = [$this->member('700000000000000003', 'Planer', [self::ROLE_USE])];
        $this->assertSame(0, Onboarding::syncAllowlist($orgId)['deleted']);
        $this->assertSame($pilot, Db::val('SELECT id FROM users WHERE id = ?', [$pilot]));
    }

    public function testNothingIsDeletedWhenDiscordReturnsNoMembers(): void
    {
        Env::set('LOGIN_REQUIRES_ALLOWLIST', '1');
        $orgId = $this->setUpOrgWithUseRole();
        Onboarding::syncAllowlist($orgId);
        $pilot = $this->accountFor('700000000000000002');
        $this->members = [];
        $this->assertSame(0, Onboarding::syncAllowlist($orgId)['deleted']);
        $this->assertSame($pilot, Db::val('SELECT id FROM users WHERE id = ?', [$pilot]));
    }

    public function testServerAdminAndPeopleOnAnotherListAreKept(): void
    {
        Env::set('LOGIN_REQUIRES_ALLOWLIST', '1');
        Env::set('SERVER_ADMIN_DISCORD_ID', '700000000000000002');
        $orgId = $this->setUpOrgWithUseRole();
        Onboarding::syncAllowlist($orgId);
        $admin = $this->accountFor('700000000000000002');
        $other = $this->accountFor('700000000000000003');
        Db::run("INSERT INTO organizations (id, slug, name, discord_guild_id, created_by_id) VALUES ('o2','zwei','Zwei','900000000000000002','x')");
        Db::run("INSERT INTO org_allowed_members (org_id, discord_id, name, synced_at) VALUES ('o2','700000000000000003','Planer',?)", [Time::nowDb()]);
        Db::run("INSERT INTO org_allowed_members (org_id, discord_id, name, synced_at) VALUES (?, '700000000000000003','Planer',?)", [$orgId, Time::nowDb()]);
        $this->members = [$this->member('700000000000000004', 'Gast', [self::ROLE_USE])];
        $r = Onboarding::syncAllowlist($orgId);
        $this->assertSame(0, $r['deleted']);
        $this->assertSame($admin, Db::val('SELECT id FROM users WHERE id = ?', [$admin]));
        $this->assertSame($other, Db::val('SELECT id FROM users WHERE id = ?', [$other]));
    }

    public function testBrakeSkipsDeletionWhenMostOfTheListLeavesAtOnce(): void
    {
        Env::set('LOGIN_REQUIRES_ALLOWLIST', '1');
        $orgId = $this->setUpOrgWithUseRole();
        $ids = ['710000000000000001', '710000000000000002', '710000000000000003', '710000000000000004', '710000000000000005', '710000000000000006'];
        $this->putOnList($orgId, $ids);
        $this->members = [$this->member('700000000000000003', 'Planer', [self::ROLE_USE])]; // alle sechs weg
        $r = Onboarding::syncAllowlist($orgId);
        $this->assertSame(0, $r['deleted']);
        $this->assertSame(6, $r['skipped']);
        $this->assertSame(6, (int) Db::val("SELECT COUNT(*) FROM accounts WHERE provider_account_id LIKE '71%'"));
        $this->assertStringContainsString('NICHT gelöscht', Onboarding::deletionNote($r));
        // von der Liste sind sie trotzdem gestrichen
        $this->assertSame(0, (int) Db::val("SELECT COUNT(*) FROM org_allowed_members WHERE discord_id LIKE '71%'"));
    }

    public function testBrakeDoesNotApplyToSmallDepartures(): void
    {
        Env::set('LOGIN_REQUIRES_ALLOWLIST', '1');
        $orgId = $this->setUpOrgWithUseRole();
        $this->putOnList($orgId, ['710000000000000001', '710000000000000002', '710000000000000003', '710000000000000004']);
        $this->members = [$this->member('700000000000000003', 'Planer', [self::ROLE_USE])];
        $r = Onboarding::syncAllowlist($orgId);
        $this->assertSame(4, $r['deleted']);
        $this->assertSame(0, $r['skipped']);
    }

    public function testPurgeDeletesAccountsWithoutListAfterTheBrake(): void
    {
        Env::set('LOGIN_REQUIRES_ALLOWLIST', '1');
        Env::set('SERVER_ADMIN_DISCORD_ID', '710000000000000009');
        $orgId = $this->setUpOrgWithUseRole();
        $this->accountFor('710000000000000001');
        $this->accountFor('710000000000000009'); // Server-Admin
        $this->putOnList($orgId, ['710000000000000002']);
        $dry = Onboarding::purgeFormerMembers(true);
        $this->assertCount(1, $dry);
        $this->assertSame(2, (int) Db::val("SELECT COUNT(*) FROM accounts WHERE provider_account_id IN ('710000000000000001','710000000000000002')"));
        $this->assertCount(1, Onboarding::purgeFormerMembers(false));
        $this->assertNull(Db::val("SELECT 1 FROM accounts WHERE provider_account_id = '710000000000000001'"));
        $this->assertNotNull(Db::val("SELECT 1 FROM accounts WHERE provider_account_id = '710000000000000009'"));
        $this->assertNotNull(Db::val("SELECT 1 FROM accounts WHERE provider_account_id = '710000000000000002'"));
    }

    public function testPurgeNeedsTheGate(): void
    {
        $this->expectException(OrgError::class);
        Onboarding::purgeFormerMembers(true);
    }

    public function testSyncNeedsAtLeastOneUseRole(): void
    {
        $orgId = $this->setUpOrg();
        $this->expectException(OrgError::class);
        Onboarding::syncAllowlist($orgId);
    }

    public function testSyncAllContinuesAfterOneOrgFails(): void
    {
        $a = new_id();
        $b = new_id();
        Db::insert('organizations', ['id' => $a, 'slug' => 'a', 'name' => 'A', 'discord_guild_id' => self::GUILD, 'member_role_ids' => self::ROLE_USE, 'created_by_id' => 'x']);
        Db::insert('organizations', ['id' => $b, 'slug' => 'b', 'name' => 'B', 'discord_guild_id' => '900000000000000002', 'member_role_ids' => self::ROLE_USE, 'created_by_id' => 'x']);
        $calls = 0;
        Client::fake(function (string $m, string $url) use (&$calls): array {
            $calls++;
            return $calls === 1
                ? ['status' => 403, 'body' => '{}', 'headers' => []]
                : ['status' => 200, 'body' => json_encode($this->members), 'headers' => []];
        });
        $out = Onboarding::syncAll();
        $this->assertStringStartsWith('Fehler', $out['a']);
        $this->assertStringContainsString('auf der Liste', $out['b']);
    }

    public function testHandleStyleAcceptsOnlyKnownValues(): void
    {
        $orgId = $this->setUpOrg();
        Onboarding::setHandleStyle($orgId, 'square');
        $this->assertSame('square', Db::val('SELECT handle_style FROM organizations WHERE id = ?', [$orgId]));
        $this->expectException(OrgError::class);
        Onboarding::setHandleStyle($orgId, 'quatsch');
    }

    public function testTheOldInteractionsEndpointIsGone(): void
    {
        $this->assertSame(404, App::handle(new Request('POST', '/discord/interactions', [], [], [], [], '{"type":1}'))->status);
    }

    // --- Anmelde-Sperre ----------------------------------------------------------------------

    public function testGateIsOffByDefault(): void
    {
        $this->assertTrue(Onboarding::mayLogin('irgendwer'));
        $this->assertTrue(Onboarding::mayLogin(null));
    }

    public function testGateAllowsOnlyListedMembersAndServerAdmins(): void
    {
        Env::set('LOGIN_REQUIRES_ALLOWLIST', '1');
        Env::set('SERVER_ADMIN_DISCORD_ID', '111');
        $org = new_id();
        Db::insert('organizations', ['id' => $org, 'slug' => 'a', 'name' => 'A', 'discord_guild_id' => 'g', 'created_by_id' => 'x']);
        Db::insert('org_allowed_members', ['org_id' => $org, 'discord_id' => '222']);
        $this->assertTrue(Onboarding::mayLogin('222'));
        $this->assertTrue(Onboarding::mayLogin('111'));
        $this->assertFalse(Onboarding::mayLogin('333'));
        $this->assertFalse(Onboarding::mayLogin(null));
    }

    public function testRunningSessionEndsWhenRemovedFromList(): void
    {
        Env::set('LOGIN_REQUIRES_ALLOWLIST', '1');
        $org = new_id();
        Db::insert('organizations', ['id' => $org, 'slug' => 'a', 'name' => 'A', 'discord_guild_id' => 'g', 'created_by_id' => 'x']);
        $user = $this->mkUser(['membership_checked_at' => \Hangar\Time::nowDb(), 'membership_status' => 'OK']);
        Db::insert('org_allowed_members', ['org_id' => $org, 'discord_id' => $user['discord_id']]);
        $s = Auth::createSession($user['id']);
        $req = new Request('GET', '/hangar', [], [], [], [Auth::COOKIE => $s['token']]);
        $this->assertSame(200, App::handle($req)->status);

        Db::run('DELETE FROM org_allowed_members');
        Auth::reset();
        $res = App::handle($req);
        $this->assertSame(303, $res->status);
        $this->assertSame('/login', $res->headers['Location']);
    }

    public function testLoginCallbackRejectsUnlistedAccount(): void
    {
        Env::set('LOGIN_REQUIRES_ALLOWLIST', '1');
        $fake = new FakeDiscord();
        $fake->install();
        $req = new Request('GET', '/api/auth/callback/discord', ['state' => 's', 'code' => 'c'], [], [], [Auth::STATE_COOKIE => 's']);
        $res = App::handle($req);
        $this->assertSame('/login?error=allowlist', $res->headers['Location']);
        $this->assertSame(0, (int) Db::val('SELECT COUNT(*) FROM users'));
    }

    // --- Verwaltung im Web -------------------------------------------------------------------

    public function testOrgAdminSeesListAndCanSyncButMembersCannot(): void
    {
        $org = new_id();
        Db::insert('organizations', ['id' => $org, 'slug' => 'zeta', 'name' => 'Zeta', 'discord_guild_id' => self::GUILD, 'member_role_ids' => self::ROLE_USE, 'created_by_id' => 'x']);
        $admin = $this->mkUser(['membership_checked_at' => \Hangar\Time::nowDb(), 'membership_status' => 'OK']);
        $member = $this->mkUser(['membership_checked_at' => \Hangar\Time::nowDb(), 'membership_status' => 'OK']);
        Db::insert('org_memberships', ['user_id' => $admin['id'], 'org_id' => $org, 'role' => 'ADMIN', 'can_plan' => 1]);
        Db::insert('org_memberships', ['user_id' => $member['id'], 'org_id' => $org, 'role' => 'MEMBER', 'can_plan' => 0]);
        $as = function (array $u) {
            $s = Auth::createSession($u['id']);
            $csrf = (string) Db::val('SELECT csrf_token FROM sessions WHERE token_hash = ?', [hash('sha256', $s['token'])]);
            return [[Auth::COOKIE => $s['token']], $csrf];
        };

        [$cookies, $csrf] = $as($admin);
        Auth::reset();
        $page = App::handle(new Request('GET', '/o/zeta/settings', [], [], [], $cookies));
        $this->assertSame(200, $page->status);
        $this->assertStringContainsString('Zugangsliste', $page->body);
        $this->assertStringContainsString('Mitglieder jetzt abgleichen', $page->body);

        Auth::reset();
        $res = App::handle(new Request('POST', '/orgs/sync-allowlist', [], ['_csrf' => $csrf, 'orgId' => $org], [], $cookies));
        $this->assertSame(303, $res->status);
        $this->assertSame(2, (int) Db::val('SELECT COUNT(*) FROM org_allowed_members WHERE org_id = ?', [$org]));
        Auth::reset();
        $after = App::handle(new Request('GET', '/o/zeta/settings', [], [], [], $cookies));
        $this->assertSame(200, $after->status);
        $this->assertStringContainsString('Letzter Abgleich', $after->body);
        $this->assertStringContainsString('2 Personen auf der Liste', $after->body);

        [$mCookies, $mCsrf] = $as($member);
        Auth::reset();
        App::handle(new Request('POST', '/orgs/sync-allowlist', [], ['_csrf' => $mCsrf, 'orgId' => $org], [], $mCookies));
        $this->assertSame(2, (int) Db::val('SELECT COUNT(*) FROM org_allowed_members WHERE org_id = ?', [$org]));
        Auth::reset();
        $this->assertSame(404, App::handle(new Request('GET', '/o/zeta/settings', [], [], [], $mCookies))->status);
    }
}
