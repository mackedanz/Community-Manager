<?php

declare(strict_types=1);

namespace Hangar\Tests;

use Hangar\App;
use Hangar\Auth;
use Hangar\Db;
use Hangar\Env;
use Hangar\Http\Client;
use Hangar\Http\Request;
use Hangar\Http\Response;
use Hangar\Onboarding;
use Hangar\Time;
use Hangar\OrgError;

/** Onboarding-Bot: Signaturprüfung, /einrichten, Rollenauswahl, Zugangsliste und Anmelde-Sperre. */
final class OnboardingTest extends DbTestCase
{
    private const GUILD = '900000000000000001';
    private const ROLE_USE = '800000000000000001';
    private const ROLE_PLAN = '800000000000000002';
    private const ADMIN = '700000000000000001';

    private string $secretKey;
    /** @var list<array{0:string,1:string,2:?string}> */
    private array $calls = [];
    /** @var list<array<string,mixed>> Mitglieder, die der nachgebaute Server zurückgibt */
    private array $members = [];

    protected function setUp(): void
    {
        parent::setUp();
        Auth::reset();
        $pair = sodium_crypto_sign_keypair();
        $this->secretKey = sodium_crypto_sign_secretkey($pair);
        Env::set('DISCORD_PUBLIC_KEY', bin2hex(sodium_crypto_sign_publickey($pair)));
        Env::set('DISCORD_BOT_TOKEN', 'bot-token');
        Env::set('AUTH_DISCORD_ID', '600000000000000001');
        $this->calls = [];
        $this->members = [
            $this->member('700000000000000001', 'Chef', [self::ROLE_USE]),
            $this->member('700000000000000002', 'Pilot', [self::ROLE_USE]),
            $this->member('700000000000000003', 'Planer', [self::ROLE_PLAN]),
            $this->member('700000000000000004', 'Gast', []),
            ['user' => ['id' => '700000000000000005', 'username' => 'Bot', 'bot' => true], 'roles' => [self::ROLE_USE]],
        ];
        Client::fake(function (string $method, string $url, array $h, ?string $body): array {
            $this->calls[] = [$method, $url, $body];
            $json = fn (mixed $b, int $s = 200): array => ['status' => $s, 'body' => json_encode($b), 'headers' => []];
            if (str_contains($url, '/members?')) {
                return $json($this->members);
            }
            if (preg_match('#/guilds/\d+$#', $url)) {
                return $json(['id' => self::GUILD, 'name' => 'Mac Dance', 'icon' => 'ic']);
            }
            if (str_contains($url, '/webhooks/')) {
                return $json([]);
            }
            if (str_contains($url, '/api/orgs/getOrgMembers')) {
                $html = '<li class="member-item js-member-item org-main org-visibility-V" data-org-name="Explorer Germany"><a href="/citizens/Pilot_1"></a></li>';
                return $json(['success' => 1, 'data' => ['totalrows' => 1, 'html' => $html]]);
            }
            return $json([], 500);
        });
    }

    /** @param list<string> $roles @return array<string,mixed> */
    private function member(string $id, string $name, array $roles): array
    {
        return ['user' => ['id' => $id, 'username' => strtolower($name), 'global_name' => $name, 'avatar' => 'av' . $id], 'roles' => $roles];
    }

    /** @param array<string,mixed> $interaction */
    private function post(array $interaction, ?string $forgedSig = null): Response
    {
        $body = json_encode($interaction);
        $ts = (string) time();
        $sig = $forgedSig ?? bin2hex(sodium_crypto_sign_detached($ts . $body, $this->secretKey));
        return App::handle(new Request('POST', '/discord/interactions', [], [], [
            'x-signature-ed25519' => $sig, 'x-signature-timestamp' => $ts, 'content-type' => 'application/json',
        ], [], $body));
    }

    /** @param array<string,mixed> $extra @return array<string,mixed> */
    private function command(string $perms = '32', array $extra = []): array
    {
        return $extra + [
            'type' => 2, 'token' => 'tok', 'guild_id' => self::GUILD, 'data' => ['name' => 'einrichten'],
            'member' => ['permissions' => $perms, 'user' => ['id' => self::ADMIN, 'username' => 'chef', 'global_name' => 'Chef']],
        ];
    }

    /** @return array<string,mixed> */
    private function json(Response $r): array
    {
        return json_decode($r->body, true);
    }

    // --- Signatur ----------------------------------------------------------------------------

    public function testPingIsAnsweredAndBadSignatureRejected(): void
    {
        $this->assertSame(['type' => 1], $this->json($this->post(['type' => 1])));
        $this->assertSame(401, $this->post(['type' => 1], str_repeat('ab', 64))->status);
        $this->assertSame(401, $this->post(['type' => 1], 'kein-hex')->status);
    }

    public function testWithoutPublicKeyEverythingIsRejected(): void
    {
        Env::set('DISCORD_PUBLIC_KEY', null);
        $this->assertSame(401, $this->post(['type' => 1])->status);
    }

    public function testTamperedBodyIsRejected(): void
    {
        $ts = (string) time();
        $sig = bin2hex(sodium_crypto_sign_detached($ts . '{"type":1}', $this->secretKey));
        $res = App::handle(new Request('POST', '/discord/interactions', [], [], [
            'x-signature-ed25519' => $sig, 'x-signature-timestamp' => $ts,
        ], [], '{"type":2}'));
        $this->assertSame(401, $res->status);
    }

    // --- /einrichten -------------------------------------------------------------------------

    public function testNonAdminGetsRefusedAndNothingIsCreated(): void
    {
        $res = $this->json($this->post($this->command('0')));
        $this->assertStringContainsString('Server-Admins', $res['data']['content']);
        $this->assertSame(0, (int) Db::val('SELECT COUNT(*) FROM organizations'));
    }

    public function testCommandOutsideAGuildIsRefused(): void
    {
        $i = $this->command();
        unset($i['guild_id']);
        $this->assertStringContainsString('nur auf einem Discord-Server', $this->json($this->post($i))['data']['content']);
    }

    public function testCommandCreatesOrgAndListsInvokerPermanently(): void
    {
        $res = $this->json($this->post($this->command('8')));
        $org = Db::one('SELECT * FROM organizations');
        $this->assertSame('Mac Dance', $org['name']);
        $this->assertSame(self::GUILD, $org['discord_guild_id']);
        $this->assertSame(self::ADMIN, $org['created_by_id']);
        $row = Db::one('SELECT * FROM org_allowed_members WHERE org_id = ?', [$org['id']]);
        $this->assertSame(self::ADMIN, $row['discord_id']);
        $this->assertSame(1, (int) $row['fixed']);
        // Antwort: nur für die aufrufende Person sichtbar, zwei Rollenauswahlen + Schaltfläche
        $this->assertSame(64, $res['data']['flags']);
        $this->assertSame(['onb:use', 'onb:plan'], [$res['data']['components'][0]['components'][0]['custom_id'], $res['data']['components'][1]['components'][0]['custom_id']]);
        $this->assertSame(10, $res['data']['components'][0]['components'][0]['max_values']);
        $this->assertSame(6, $res['data']['components'][0]['components'][0]['type']);
    }

    public function testCommandWithRsiSidConnectsOrgAndNamesItInTheReply(): void
    {
        $res = $this->json($this->post($this->command('8', ['data' => ['name' => 'einrichten', 'options' => [['name' => 'rsi_kuerzel', 'type' => 3, 'value' => 'expg']]]])));
        $this->assertStringContainsString('RSI-Orga „Explorer Germany“ verbunden', $res['data']['content']);
        $this->assertSame('EXPG', Db::val('SELECT rsi_sid FROM organizations'));
    }

    public function testDashMeansNoRsiOrgAndConnectsNothing(): void
    {
        $res = $this->json($this->post($this->command('8', ['data' => ['name' => 'einrichten', 'options' => [['name' => 'rsi_kuerzel', 'type' => 3, 'value' => '-']]]])));
        $this->assertStringContainsString('Community-Hangar: ', $res['data']['content']);
        $this->assertNull(Db::val('SELECT rsi_sid FROM organizations'));
        $calls = array_filter($this->calls, static fn (array $c): bool => str_contains($c[1], 'getOrgMembers'));
        $this->assertSame([], array_values($calls), 'keine Anfrage an RSI');
    }

    public function testApiTokenStopsWorkingWhenPersonIsRemovedFromTheAllowlist(): void
    {
        Env::set('LOGIN_REQUIRES_ALLOWLIST', '1');
        $org = Onboarding::ensureOrg(self::GUILD, self::ADMIN, 'Chef')['id'];
        $user = $this->mkUser(['discord_id' => '700000000000000042']);
        $token = \Hangar\ApiToken::create($user['id'], 't')['token'];
        $this->assertNull(\Hangar\ApiToken::verify($token), 'nicht auf der Zugangsliste');
        Db::insert('org_allowed_members', ['org_id' => $org, 'discord_id' => '700000000000000042', 'name' => 'X', 'fixed' => 0]);
        $this->assertSame($user['id'], \Hangar\ApiToken::verify($token));
        Db::run('DELETE FROM org_allowed_members WHERE discord_id = ?', ['700000000000000042']);
        $this->assertNull(\Hangar\ApiToken::verify($token), 'nach dem Entfernen sofort ungültig');
    }

    public function testCommandIsRepeatableAndReusesExistingOrg(): void
    {
        $this->post($this->command());
        $this->post($this->command());
        $this->assertSame(1, (int) Db::val('SELECT COUNT(*) FROM organizations'));
        $this->assertSame(1, (int) Db::val('SELECT COUNT(*) FROM org_allowed_members'));
    }

    public function testBannedGuildCannotBeSetUp(): void
    {
        Db::insert('banned_guilds', ['discord_guild_id' => self::GUILD, 'name' => 'Fremd', 'banned_by_id' => 'x']);
        $res = $this->json($this->post($this->command()));
        $this->assertStringContainsString('gesperrt', $res['data']['content']);
        $this->assertSame(0, (int) Db::val('SELECT COUNT(*) FROM organizations'));
    }

    // --- Rollenauswahl und Abgleich ----------------------------------------------------------

    /** @param list<string> $values @param array<string,string> $names */
    private function select(string $customId, array $values, array $names = []): Response
    {
        $roles = [];
        foreach ($names as $id => $n) {
            $roles[$id] = ['id' => $id, 'name' => $n];
        }
        return $this->post($this->command('32', ['type' => 3, 'data' => ['custom_id' => $customId, 'values' => $values, 'resolved' => ['roles' => $roles]]]));
    }

    public function testRecruiterRoleSelectIsOfferedAndSaved(): void
    {
        $res = $this->json($this->post($this->command()));
        $ids = [];
        foreach ($res['data']['components'] as $row) {
            $ids[] = $row['components'][0]['custom_id'] ?? '';
        }
        $this->assertContains('onb:recruit', $ids);
        $this->assertLessThanOrEqual(5, count($res['data']['components']), 'Discord erlaubt höchstens 5 Zeilen');

        $roleRec = '800000000000000009';
        $this->select('onb:recruit', [$roleRec], [$roleRec => 'Recruiter']);
        $this->assertSame($roleRec, Db::val('SELECT recruiter_role_ids FROM organizations'));
        $this->assertStringContainsString('Recruiter', (string) Db::val('SELECT role_labels FROM organizations'));
    }

    public function testHandleStyleIsSetByTheCommandOptionAndShownInThePanel(): void
    {
        $opt = fn (string $style): array => ['data' => ['name' => 'einrichten', 'options' => [
            ['name' => 'rsi_kuerzel', 'type' => 3, 'value' => '-'], ['name' => 'handle_stil', 'type' => 3, 'value' => $style],
        ]]];
        $res = $this->json($this->post($this->command('8', $opt('square'))));
        $this->assertSame('square', Db::val('SELECT handle_style FROM organizations'));
        $this->assertStringContainsString('In eckigen Klammern', $res['data']['content']);
        $this->assertLessThanOrEqual(5, count($res['data']['components']), 'Discord erlaubt höchstens 5 Zeilen');

        $res = $this->json($this->post($this->command('8', $opt('quatsch'))));
        $this->assertSame('square', Db::val('SELECT handle_style FROM organizations'), 'ungültige Auswahl wird abgewiesen');
        $this->assertStringContainsString('Ungültige Auswahl', $res['data']['content']);
    }

    public function testInstructorRoleSelectIsOfferedAndSaved(): void
    {
        $res = $this->json($this->post($this->command()));
        $ids = [];
        foreach ($res['data']['components'] as $row) {
            $ids[] = $row['components'][0]['custom_id'] ?? '';
        }
        $this->assertContains('onb:instruct', $ids);
        $role = '800000000000000011';
        $this->select('onb:instruct', [$role], [$role => 'Ausbilder']);
        $this->assertSame($role, Db::val('SELECT instructor_role_ids FROM organizations'));
    }

    public function testRoleSelectionSavesRolesAndNames(): void
    {
        $this->post($this->command());
        $res = $this->select('onb:use', [self::ROLE_USE], [self::ROLE_USE => 'Mitglied']);
        $org = Db::one('SELECT * FROM organizations');
        $this->assertSame(self::ROLE_USE, $org['member_role_ids']);
        $this->assertSame(['Mitglied'], array_values(json_decode($org['role_labels'], true)));
        $this->assertSame(7, $this->json($res)['type']);
        // gewählte Rolle ist in der Auswahl vorbelegt
        $this->assertSame(self::ROLE_USE, $this->json($res)['data']['components'][0]['components'][0]['default_values'][0]['id']);

        $this->select('onb:plan', [self::ROLE_PLAN], [self::ROLE_PLAN => 'Planer']);
        $this->assertSame(self::ROLE_PLAN, Db::val('SELECT planner_role_ids FROM organizations'));
        $this->assertSame(self::ROLE_USE, Db::val('SELECT member_role_ids FROM organizations'));
    }

    public function testSelectionIsDeniedForNonAdmins(): void
    {
        $this->post($this->command());
        $i = $this->command('0', ['type' => 3, 'data' => ['custom_id' => 'onb:use', 'values' => [self::ROLE_USE]]]);
        $this->post($i);
        $this->assertNull(Db::val('SELECT member_role_ids FROM organizations'));
    }

    public function testInvalidRoleIdIsRejected(): void
    {
        $this->post($this->command());
        $res = $this->json($this->select('onb:use', ['abc']));
        $this->assertStringContainsString('17 bis 20 Ziffern', $res['data']['content']);
        $this->assertNull(Db::val('SELECT member_role_ids FROM organizations'));
    }

    public function testSyncWritesRoleHoldersWithIdNameAndAvatar(): void
    {
        $this->post($this->command());
        $orgId = (string) Db::val('SELECT id FROM organizations');
        Onboarding::setRoles($orgId, 'use', [self::ROLE_USE], []);
        Onboarding::setRoles($orgId, 'plan', [self::ROLE_PLAN], []);
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
        $this->post($this->command());
        $orgId = (string) Db::val('SELECT id FROM organizations');
        Onboarding::setRoles($orgId, 'use', [self::ROLE_USE], []);
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

    private function accountFor(string $discordId): string
    {
        $uid = new_id();
        Db::insert('users', ['id' => $uid, 'name' => 'U' . $discordId]);
        Db::insert('accounts', ['id' => new_id(), 'user_id' => $uid, 'provider' => 'discord', 'provider_account_id' => $discordId]);
        return $uid;
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

    public function testStaleSignedRequestIsRejected(): void
    {
        $body = '{"type":1}';
        $old = (string) (time() - 600);
        $sig = bin2hex(sodium_crypto_sign_detached($old . $body, $this->secretKey));
        $res = App::handle(new Request('POST', '/discord/interactions', [], [], [
            'x-signature-ed25519' => $sig, 'x-signature-timestamp' => $old,
        ], [], $body));
        $this->assertSame(401, $res->status);
        $future = (string) (time() + 600);
        $sig = bin2hex(sodium_crypto_sign_detached($future . $body, $this->secretKey));
        $res = App::handle(new Request('POST', '/discord/interactions', [], [], [
            'x-signature-ed25519' => $sig, 'x-signature-timestamp' => $future,
        ], [], $body));
        $this->assertSame(401, $res->status);
    }

    /** @param list<string> $ids */
    private function putOnList(string $orgId, array $ids): void
    {
        foreach ($ids as $id) {
            $this->accountFor($id);
            Db::run('INSERT INTO org_allowed_members (org_id, discord_id, name, synced_at) VALUES (?,?,?,?)', [$orgId, $id, 'P' . $id, Time::nowDb()]);
        }
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
        $this->post($this->command());
        $this->expectException(OrgError::class);
        Onboarding::syncAllowlist((string) Db::val('SELECT id FROM organizations'));
    }

    /** @param list<string> $roles @return array<string,mixed> */
    private function syncCommand(string $userId, array $roles, string $perms = '0'): array
    {
        return [
            'type' => 2, 'token' => 'tok', 'guild_id' => self::GUILD, 'data' => ['name' => 'abgleichen'],
            'member' => ['permissions' => $perms, 'roles' => $roles, 'user' => ['id' => $userId, 'username' => 'x', 'global_name' => 'X']],
        ];
    }

    private function setUpOrgWithUseRole(): string
    {
        $this->post($this->command());
        $orgId = (string) Db::val('SELECT id FROM organizations');
        Onboarding::setRoles($orgId, 'use', [self::ROLE_USE], []);
        return $orgId;
    }

    public function testMemberWithUseRoleMaySyncWithoutBeingAdmin(): void
    {
        $orgId = $this->setUpOrgWithUseRole();
        $res = $this->post($this->syncCommand('700000000000000002', [self::ROLE_USE]));
        $this->assertSame(5, $this->json($res)['type']);
        $this->assertCount(1, $res->after);
        foreach ($res->after as $fn) {
            $fn();
        }
        $this->assertNotNull(Db::val('SELECT allowlist_synced_at FROM organizations WHERE id = ?', [$orgId]));
        $this->assertContains('700000000000000002', $this->ids('org_allowed_members', 'discord_id'));
        $edit = array_values(array_filter($this->calls, static fn (array $c): bool => str_contains($c[1], '/webhooks/')));
        $this->assertStringContainsString('Fertig', (string) $edit[0][2]);
    }

    public function testPersonWithoutUseRoleMayNotSync(): void
    {
        $this->setUpOrgWithUseRole();
        $res = $this->post($this->syncCommand('700000000000000004', []));
        $this->assertStringContainsString('Nutzungs-Rolle', $this->json($res)['data']['content']);
        $this->assertCount(0, $res->after);
        // Auch die Planer-Rolle allein reicht nicht
        $res = $this->post($this->syncCommand('700000000000000003', [self::ROLE_PLAN]));
        $this->assertStringContainsString('Nutzungs-Rolle', $this->json($res)['data']['content']);
    }

    public function testAdminMaySyncWithoutRole(): void
    {
        $this->setUpOrgWithUseRole();
        $res = $this->post($this->syncCommand('700000000000000004', [], '32'));
        $this->assertSame(5, $this->json($res)['type']);
    }

    public function testSyncCommandNeverCreatesAnOrg(): void
    {
        $res = $this->post($this->syncCommand('700000000000000002', [self::ROLE_USE], '8'));
        $this->assertStringContainsString('noch keine Orga', $this->json($res)['data']['content']);
        $this->assertSame(0, (int) Db::val('SELECT COUNT(*) FROM organizations'));
    }

    public function testSyncCommandHasACooldown(): void
    {
        $orgId = $this->setUpOrgWithUseRole();
        Onboarding::syncAllowlist($orgId);
        $res = $this->post($this->syncCommand('700000000000000002', [self::ROLE_USE]));
        $this->assertStringContainsString('gerade erst', $this->json($res)['data']['content']);
        $this->assertCount(0, $res->after);
    }

    public function testSyncCommandRespectsTheServerAllowlist(): void
    {
        $this->setUpOrgWithUseRole();
        Env::set('ONBOARDING_GUILD_IDS', '123456789012345678');
        $res = $this->post($this->syncCommand('700000000000000002', [self::ROLE_USE]));
        $this->assertStringContainsString('nicht freigeschaltet', $this->json($res)['data']['content']);
    }

    public function testSelectionTriggersBackgroundSyncAndEditsTheMessage(): void
    {
        $this->post($this->command());
        $res = $this->select('onb:use', [self::ROLE_USE], [self::ROLE_USE => 'Mitglied']);
        $this->assertCount(1, $res->after);
        $this->assertStringContainsString('abgeglichen', $this->json($res)['data']['content']);
        foreach ($res->after as $fn) {
            $fn();
        }
        $this->assertSame(2, (int) Db::val('SELECT COUNT(*) FROM org_allowed_members'));
        $edit = array_values(array_filter($this->calls, fn ($c) => $c[0] === 'PATCH'));
        $this->assertCount(1, $edit);
        $this->assertStringContainsString('/webhooks/600000000000000001/tok/messages/@original', $edit[0][1]);
        $this->assertStringContainsString('2 Mitglieder', $edit[0][2]);
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

    public function testOnlyListedGuildsMayBeSetUpWhenRestricted(): void
    {
        Env::set('ONBOARDING_GUILD_IDS', '900000000000000009, 900000000000000010');
        $res = $this->json($this->post($this->command()));
        $this->assertStringContainsString('nicht freigeschaltet', $res['data']['content']);
        $this->assertStringContainsString(self::GUILD, $res['data']['content']);
        $this->assertSame(0, (int) Db::val('SELECT COUNT(*) FROM organizations'));
        $this->assertSame(0, (int) Db::val('SELECT COUNT(*) FROM org_allowed_members'));

        Env::set('ONBOARDING_GUILD_IDS', '900000000000000009,' . self::GUILD);
        $this->post($this->command());
        $this->assertSame(1, (int) Db::val('SELECT COUNT(*) FROM organizations'));
    }

    public function testNoRestrictionMeansEveryGuildMaySetUp(): void
    {
        $this->post($this->command());
        $this->assertSame(1, (int) Db::val('SELECT COUNT(*) FROM organizations'));
    }
}
