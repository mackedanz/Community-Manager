<?php

declare(strict_types=1);

namespace Hangar\Tests;

use Hangar\Community;
use Hangar\Db;
use Hangar\Http\Client;
use Hangar\OrgError;
use Hangar\RsiOrg;

/** RSI-Orga: Handle aus dem Nickname, Abgleich der öffentlichen Mitgliederliste, Rahmenfarbe in der Mitgliederliste. */
final class RsiOrgTest extends DbTestCase
{
    private string $orgId;
    /** @var list<array{0:?string,1:bool}> Mitglieder der nachgebauten RSI-Orga: [Handle (null = verborgen), Hauptorga] */
    private array $roster = [];
    private bool $rsiDown = false;
    private int $requests = 0;
    /** @var array<string,int> Handle (klein) => UEE Citizen Record auf den nachgebauten Dossier-Seiten; fehlt er, antwortet RSI mit 404 */
    private array $dossiers = [];

    protected function setUp(): void
    {
        parent::setUp();
        RsiOrg::pause(0);
        \Hangar\RsiHandleCheck::pause(0);
        $this->dossiers = ['expg_mcdance' => 1638516, 'pilot_1' => 5, 'helfer' => 7, 'pilot_neu' => 5];
        $this->orgId = new_id();
        Db::insert('organizations', ['id' => $this->orgId, 'slug' => 'exp', 'name' => 'Exp', 'discord_guild_id' => '900000000000000009', 'created_by_id' => 'x']);
        $this->roster = [['eXpG_McDance', true], ['Pilot_1', true], ['Helfer', false]];
        $this->rsiDown = false;
        $this->requests = 0;
        Client::fake(function (string $method, string $url, array $h, ?string $body): array {
            $this->requests++;
            if (preg_match('#/en/citizens/([^/?]+)$#', $url, $dm) === 1) {
                $rec = $this->rsiDown ? null : ($this->dossiers[strtolower(urldecode($dm[1]))] ?? null);
                return $this->rsiDown ? ['status' => 503, 'body' => '', 'headers' => []]
                    : ($rec === null ? ['status' => 404, 'body' => 'nicht da', 'headers' => []]
                        : ['status' => 200, 'body' => "<span>UEE Citizen Record</span>
<strong> #{$rec} </strong>", 'headers' => []]);
            }
            if ($this->rsiDown || !str_contains($url, '/api/orgs/getOrgMembers')) {
                return ['status' => 503, 'body' => '', 'headers' => []];
            }
            $q = json_decode((string) $body, true);
            if (($q['symbol'] ?? '') !== 'EXPG') {
                return ['status' => 200, 'body' => json_encode(['success' => 0, 'code' => 'ErrNoOrg', 'msg' => 'x']), 'headers' => []];
            }
            $slice = array_slice($this->roster, ($q['page'] - 1) * $q['pagesize'], $q['pagesize']);
            $html = '';
            foreach ($slice as [$handle, $main]) {
                $cls = 'member-item js-member-item ' . ($main ? 'org-main' : 'org-affiliate') . ($handle === null ? ' org-visibility-R' : ' org-visibility-V');
                $link = $handle === null ? '<a class="membercard js-edit-member" >' : '<a class="membercard js-edit-member"  href="/citizens/' . $handle . '">';
                $html .= "\t<li class=\"$cls\" data-org-sid=\"EXPG\" data-org-name=\"&#x2b50;Explorer-Germany&amp;Co\">\n\t\t$link</a></li>\n";
            }
            return ['status' => 200, 'body' => json_encode(['success' => 1, 'code' => 'OK', 'data' => ['totalrows' => count($this->roster), 'html' => $html]]), 'headers' => []];
        });
    }

    public function testHandleFromNameFollowsTheStyleOfTheOrga(): void
    {
        $this->assertSame('mimack76', RsiOrg::handleFromName('(Vorname) mimack76 [Discordname]'));
        $this->assertSame('mimack76', RsiOrg::handleFromName('(Vorname) [Tag] mimack76'), 'mehrere Klammern am Rand');
        $this->assertSame('mimack76', RsiOrg::handleFromName('<x> mimack76 <Micha>'));
        $this->assertSame('Micha', RsiOrg::handleFromName('Micha (mimack76)'), 'plain nimmt keinen Handle aus der Klammer');
        $this->assertSame('mimack76', RsiOrg::handleFromName('Micha (mimack76)', 'round'));
        $this->assertSame('mimack76', RsiOrg::handleFromName('Micha der Große [mimack76]', 'square'));
        $this->assertSame('mimack76', RsiOrg::handleFromName('Micha {mimack76}', 'curly'));
        $this->assertSame('mimack76', RsiOrg::handleFromName('Micha <mimack76>', 'angle'));
        $this->assertSame('Vorname', RsiOrg::handleFromName('Micha (Vorname) (mimack76)', 'round'), 'erste gültige Klammer gewinnt');
        $this->assertNull(RsiOrg::handleFromName('Micha [mimack76]', 'round'), 'andere Klammerart zählt nicht');
        $this->assertNull(RsiOrg::handleFromName('mimack76', 'round'), 'ohne Klammer kein Handle');
        $this->assertNull(RsiOrg::handleFromName('Micha (der Große)', 'round'), 'Leerzeichen im Inhalt: kein Handle');
        $this->assertSame('mimack76', RsiOrg::handleFromName('  mimack76 ', 'unbekannt'), 'unbekannte Auswahl wie plain');
        $this->assertSame('plain', RsiOrg::cleanHandleStyle('quatsch'));
        $this->assertSame('angle', RsiOrg::cleanHandleStyle('angle'));
    }

    public function testMemberListUsesTheHandleStyleOfTheOrga(): void
    {
        $u = $this->mkUser(['name' => 'Anna']);
        Db::insert('org_memberships', ['user_id' => $u['id'], 'org_id' => $this->orgId, 'role' => 'MEMBER', 'can_plan' => 0, 'nick' => 'Anna K. (Pilot_1)']);
        $viewer = $this->recruiter($u['id']);
        RsiOrg::connect($this->orgId, 'EXPG');
        RsiOrg::sync($this->orgId);
        $this->assertSame('out', Community::listMembers($this->orgId, $viewer)[0]['rsiStatus'], 'Handle steht in der Klammer, aber Stil ist plain');
        \Hangar\Onboarding::setHandleStyle($this->orgId, 'round');
        $this->assertSame('main', Community::listMembers($this->orgId, $viewer)[0]['rsiStatus']);
        $this->assertSame('https://robertsspaceindustries.com/en/citizens/Pilot_1', Community::getProfile($u['id'], $viewer)['user']['rsiUrl']);
        $this->expectException(OrgError::class);
        \Hangar\Onboarding::setHandleStyle($this->orgId, 'quatsch');
    }

    private function recruiter(string $userId): \Hangar\Viewer
    {
        return new \Hangar\Viewer($userId, 'R', null, null, [['id' => $this->orgId, 'slug' => 'exp', 'name' => 'Exp', 'iconUrl' => null, 'role' => 'MEMBER', 'canPlan' => false, 'canRecruit' => true]], 'OK', 'csrf');
    }

    public function testHandleFromName(): void
    {
        $this->assertSame('eXpG_McDance', RsiOrg::handleFromName('eXpG_McDance (Micha)'));
        $this->assertSame('eXpG_McDance', RsiOrg::handleFromName('  eXpG_McDance  '));
        $this->assertSame('Pilot-2', RsiOrg::handleFromName('Pilot-2 [Anna]'));
        $this->assertSame('mimack76', RsiOrg::handleFromName('(spacetrotter) mimack76'));
        $this->assertSame('Pilot-2', RsiOrg::handleFromName('[SpaceTrotter] Pilot-2 (Anna)'));
        $this->assertNull(RsiOrg::handleFromName('Micha der Große'));
        $this->assertNull(RsiOrg::handleFromName('(nur Klammer)'));
        $this->assertNull(RsiOrg::handleFromName(null));
    }

    public function testCleanSid(): void
    {
        $this->assertSame('EXPG', RsiOrg::cleanSid(' expg '));
        $this->assertNull(RsiOrg::cleanSid('E'));
        $this->assertNull(RsiOrg::cleanSid('EX PG'));
        $this->assertNull(RsiOrg::cleanSid('../x'));
    }

    public function testConnectChecksTheOrgAndStoresNameAndSid(): void
    {
        $name = RsiOrg::connect($this->orgId, 'expg');
        $this->assertSame('⭐Explorer-Germany&Co', $name);
        $o = Db::one('SELECT rsi_sid, rsi_org_name, rsi_synced_at FROM organizations WHERE id = ?', [$this->orgId]);
        $this->assertSame('EXPG', $o['rsi_sid']);
        $this->assertSame('⭐Explorer-Germany&Co', $o['rsi_org_name']);
        $this->assertNull($o['rsi_synced_at'], 'verbinden allein gleicht noch nicht ab');
    }

    public function testUnknownOrgIsRejectedAndNothingStored(): void
    {
        $this->expectException(OrgError::class);
        try {
            RsiOrg::connect($this->orgId, 'NOPE');
        } finally {
            $this->assertNull(Db::val('SELECT rsi_sid FROM organizations WHERE id = ?', [$this->orgId]));
        }
    }

    public function testInvalidSidIsRejectedWithoutRequest(): void
    {
        try {
            RsiOrg::connect($this->orgId, 'a b!');
            $this->fail('OrgError erwartet');
        } catch (OrgError) {
            $this->assertSame(0, $this->requests);
        }
    }

    public function testSyncReadsAllPagesAndCountsHiddenMembers(): void
    {
        $this->roster = [];
        for ($i = 1; $i <= 40; $i++) {
            $this->roster[] = ["Pilot_$i", $i !== 40];
        }
        $this->roster[] = [null, true];
        RsiOrg::connect($this->orgId, 'EXPG');
        $r = RsiOrg::sync($this->orgId);
        $this->assertSame(['members' => 40, 'redacted' => 1], $r);
        $this->assertSame(0, (int) Db::val('SELECT main FROM org_rsi_members WHERE org_id = ? AND handle = ?', [$this->orgId, 'pilot_40']));
        $this->assertSame(1, (int) Db::val('SELECT rsi_redacted FROM organizations WHERE id = ?', [$this->orgId]));
        $this->assertNotNull(Db::val('SELECT rsi_synced_at FROM organizations WHERE id = ?', [$this->orgId]));
    }

    public function testFailedSyncKeepsOldRoster(): void
    {
        RsiOrg::connect($this->orgId, 'EXPG');
        RsiOrg::sync($this->orgId);
        $this->rsiDown = true;
        try {
            RsiOrg::sync($this->orgId);
            $this->fail('OrgError erwartet');
        } catch (OrgError) {
        }
        $this->assertSame(3, (int) Db::val('SELECT COUNT(*) FROM org_rsi_members WHERE org_id = ?', [$this->orgId]));
    }

    public function testChangingSidDropsOldRosterAndEmptyDisconnects(): void
    {
        RsiOrg::connect($this->orgId, 'EXPG');
        RsiOrg::sync($this->orgId);
        $this->assertNull(RsiOrg::connect($this->orgId, ''));
        $this->assertSame(0, (int) Db::val('SELECT COUNT(*) FROM org_rsi_members WHERE org_id = ?', [$this->orgId]));
        $this->assertNull(Db::val('SELECT rsi_sid FROM organizations WHERE id = ?', [$this->orgId]));
    }

    public function testSyncAllSkipsOrgsWithoutSidAndReportsErrors(): void
    {
        $this->assertSame([], RsiOrg::syncAll());
        RsiOrg::connect($this->orgId, 'EXPG');
        $this->assertStringContainsString('3 Mitglieder', RsiOrg::syncAll()['exp']);
        $this->rsiDown = true;
        $this->assertStringStartsWith('Fehler:', RsiOrg::syncAll()['exp']);
    }

    public function testStatusMatrix(): void
    {
        $roster = ['expg_mcdance' => true, 'helfer' => false];
        $this->assertSame('main', RsiOrg::status(['eXpG_McDance'], $roster, 0));
        $this->assertSame('affiliate', RsiOrg::status([null, 'HELFER'], $roster, 0));
        $this->assertSame('out', RsiOrg::status(['Fremder'], $roster, 0));
        $this->assertSame('unknown', RsiOrg::status(['Fremder'], $roster, 2), 'verborgene Mitglieder: nicht als „nicht drin“ behaupten');
        $this->assertSame('unknown', RsiOrg::status([null, null], $roster, 0), 'ohne Handle nichts behaupten');
    }

    public function testMemberListShowsStatusPerMemberOnlyAfterSync(): void
    {
        $mk = function (string $name, ?string $nick, ?string $handle = null): string {
            $u = $this->mkUser(['name' => $name, 'rsi_handle' => $handle]);
            Db::insert('org_memberships', ['user_id' => $u['id'], 'org_id' => $this->orgId, 'role' => 'MEMBER', 'can_plan' => 0, 'nick' => $nick]);
            return $u['id'];
        };
        $mc = $mk('Micha', 'eXpG_McDance (Micha)');
        $mk('Anna', 'Pilot_1');
        $mk('Ben', null, 'helfer');
        $mk('Cem Müller', 'Cem der Große');
        $viewer = $this->recruiter($mc);

        $before = Community::listMembers($this->orgId, $viewer);
        $this->assertSame([null], array_values(array_unique(array_column($before, 'rsiStatus'))));

        RsiOrg::connect($this->orgId, 'EXPG');
        $this->assertSame([null], array_values(array_unique(array_column(Community::listMembers($this->orgId, $viewer), 'rsiStatus'))), 'vor dem ersten Abgleich keine Farben');

        RsiOrg::sync($this->orgId);
        $by = array_column(Community::listMembers($this->orgId, $viewer), 'rsiStatus', 'name');
        $this->assertEquals(['Cem der Große' => 'unknown', 'eXpG_McDance (Micha)' => 'main', 'Pilot_1' => 'main', 'Ben' => 'affiliate'], $by);
    }

    public function testChangedHandleIsSuggestedByCitizenRecordForAdminsOnlyAndClearsAfterNicknameFix(): void
    {
        $mk = function (string $name, ?string $nick): string {
            $u = $this->mkUser(['name' => $name]);
            Db::insert('org_memberships', ['user_id' => $u['id'], 'org_id' => $this->orgId, 'role' => 'MEMBER', 'can_plan' => 0, 'nick' => $nick]);
            return $u['id'];
        };
        $mc = $mk('Micha', 'eXpG_McDance (Micha)');
        $anna = $mk('Anna', 'Pilot_1');
        $admin = new \Hangar\Viewer($mc, 'Micha', null, null, [['id' => $this->orgId, 'slug' => 'exp', 'name' => 'Exp', 'iconUrl' => null, 'role' => 'ADMIN', 'canPlan' => true]], 'OK', 'csrf');
        $member = VisibilityFilterTest::viewer($mc, [$this->orgId]);
        $recruiter = $this->recruiter($mc);
        $state = fn (\Hangar\Viewer $v): array => array_column(Community::listMembers($this->orgId, $v), null, 'id')[$anna];

        RsiOrg::connect($this->orgId, 'EXPG');
        RsiOrg::sync($this->orgId);
        $r = \Hangar\RsiHandleCheck::run($this->orgId);
        $this->assertSame(['bound' => 3 - 1, 'conflicts' => 0, 'suggested' => 0], $r);
        $this->assertSame(5, (int) Db::val('SELECT record_no FROM rsi_checks WHERE user_id = ?', [$anna]));
        $this->assertNull($state($admin)['rsiHint']);

        // Anna heißt auf RSI jetzt anders; im Discord steht noch der alte Nickname
        $this->roster = [['eXpG_McDance', true], ['Pilot_Neu', true], ['Helfer', false]];
        $this->dossiers = ['expg_mcdance' => 1638516, 'pilot_neu' => 5, 'helfer' => 7];
        RsiOrg::sync($this->orgId);
        $r = \Hangar\RsiHandleCheck::run($this->orgId);
        $this->assertSame(1, $r['suggested']);
        $this->assertSame('out', $state($admin)['rsiStatus']);
        $this->assertSame(['record' => 5, 'suggested' => 'Pilot_Neu'], $state($admin)['rsiHint']);
        $this->assertSame(['record' => 5, 'suggested' => 'Pilot_Neu'], $state($recruiter)['rsiHint'], 'Recruiter sehen den Hinweis auch');
        $this->assertNull($state($member)['rsiHint'], 'normale Mitglieder sehen keinen Hinweis');
        $this->assertNull($state($member)['rsiStatus'], 'und keinen Rahmen');
        $this->assertSame('Pilot_1', Db::val('SELECT nick FROM org_memberships WHERE user_id = ?', [$anna]), 'nichts wird automatisch geändert');

        // Der Admin passt den Nickname im Discord an: wieder grün, kein Vorschlag mehr
        Db::run('UPDATE org_memberships SET nick = ? WHERE user_id = ?', ['Pilot_Neu', $anna]);
        \Hangar\RsiHandleCheck::run($this->orgId);
        $this->assertSame('main', $state($admin)['rsiStatus']);
        $this->assertNull($state($admin)['rsiHint']);
        $this->assertSame('Pilot_Neu', Db::val('SELECT handle FROM rsi_checks WHERE user_id = ?', [$anna]));
    }

    public function testHandleOfAnotherCitizenRecordTurnsFrameYellow(): void
    {
        $u = $this->mkUser(['name' => 'Anna']);
        Db::insert('org_memberships', ['user_id' => $u['id'], 'org_id' => $this->orgId, 'role' => 'MEMBER', 'can_plan' => 0, 'nick' => 'Pilot_1']);
        $admin = new \Hangar\Viewer($u['id'], 'Anna', null, null, [['id' => $this->orgId, 'slug' => 'exp', 'name' => 'Exp', 'iconUrl' => null, 'role' => 'ADMIN', 'canPlan' => true]], 'OK', 'csrf');
        RsiOrg::connect($this->orgId, 'EXPG');
        RsiOrg::sync($this->orgId);
        \Hangar\RsiHandleCheck::run($this->orgId);

        $this->dossiers['pilot_1'] = 99;
        Db::run('UPDATE rsi_checks SET record_checked_at = ? WHERE user_id = ?', ['2000-01-01 00:00:00', $u['id']]);
        $r = \Hangar\RsiHandleCheck::run($this->orgId);
        $this->assertSame(1, $r['conflicts']);
        $m = Community::listMembers($this->orgId, $admin)[0];
        $this->assertSame('out', $m['rsiStatus']);
        $this->assertTrue($m['rsiHint']['conflict']);
        $this->assertSame(5, (int) Db::val('SELECT record_no FROM rsi_checks WHERE user_id = ?', [$u['id']]), 'die gemerkte Nummer wird nicht überschrieben');
    }

    public function testUnreachableDossierChangesNothing(): void
    {
        $u = $this->mkUser(['name' => 'Anna']);
        Db::insert('org_memberships', ['user_id' => $u['id'], 'org_id' => $this->orgId, 'role' => 'MEMBER', 'can_plan' => 0, 'nick' => 'Pilot_1']);
        RsiOrg::connect($this->orgId, 'EXPG');
        RsiOrg::sync($this->orgId);
        $this->rsiDown = true;
        $this->assertSame(['bound' => 0, 'conflicts' => 0, 'suggested' => 0], \Hangar\RsiHandleCheck::run($this->orgId));
        $this->assertSame(0, (int) Db::val('SELECT COUNT(*) FROM rsi_checks'));
    }

    public function testRsiSyncViaWebIsLimitedPerOrgButDisconnectIsFree(): void
    {
        $admin = $this->mkUser(['membership_checked_at' => \Hangar\Time::nowDb(), 'membership_status' => 'OK']);
        Db::insert('org_memberships', ['user_id' => $admin['id'], 'org_id' => $this->orgId, 'role' => 'ADMIN', 'can_plan' => 1]);
        $s = \Hangar\Auth::createSession($admin['id']);
        $csrf = (string) Db::val('SELECT csrf_token FROM sessions WHERE token_hash = ?', [hash('sha256', $s['token'])]);
        $post = function (string $sid) use ($s, $csrf) {
            \Hangar\Auth::reset();
            return \Hangar\App::handle(new \Hangar\Http\Request('POST', '/orgs/rsi', [], ['_csrf' => $csrf, 'orgId' => $this->orgId, 'rsiSid' => $sid], [], [\Hangar\Auth::COOKIE => $s['token']]));
        };
        for ($i = 0; $i < 10; $i++) {
            $post('EXPG');
        }
        $requestsBefore = $this->requests;
        $res = $post('EXPG');
        $this->assertSame(303, $res->status);
        $this->assertSame($requestsBefore, $this->requests, 'ab dem 11. Mal geht keine Anfrage mehr an RSI');
        $this->assertSame('EXPG', Db::val('SELECT rsi_sid FROM organizations WHERE id = ?', [$this->orgId]));
        $post('');
        $this->assertNull(Db::val('SELECT rsi_sid FROM organizations WHERE id = ?', [$this->orgId]), 'Trennen ist nicht begrenzt');
    }

    public function testProfileLinksToRsiProfileWhenHandleIsKnownOrVerified(): void
    {
        $mk = function (string $name, ?string $nick, ?string $handle = null): string {
            $u = $this->mkUser(['name' => $name, 'rsi_handle' => $handle]);
            Db::insert('org_memberships', ['user_id' => $u['id'], 'org_id' => $this->orgId, 'role' => 'MEMBER', 'can_plan' => 0, 'nick' => $nick]);
            return $u['id'];
        };
        $mc = $mk('Micha', 'expg_mcdance (Micha)');
        $synced = $mk('Anna', 'Anna K', 'Anna_Real');
        $stranger = $mk('Ben', 'Ben_Unbekannt');
        $viewer = VisibilityFilterTest::viewer($mc, [$this->orgId]);

        // ohne RSI-Orga nur der per Sync übernommene Handle
        $this->assertNull(Community::getProfile($mc, $viewer)['user']['rsiUrl']);
        $this->assertSame('https://robertsspaceindustries.com/en/citizens/Anna_Real', Community::getProfile($synced, $viewer)['user']['rsiUrl']);

        RsiOrg::connect($this->orgId, 'EXPG');
        RsiOrg::sync($this->orgId);
        $u = Community::getProfile($mc, $viewer)['user'];
        $this->assertSame('eXpG_McDance', $u['rsiHandle'], 'Schreibweise stammt aus der RSI-Liste');
        $this->assertSame('https://robertsspaceindustries.com/en/citizens/eXpG_McDance', $u['rsiUrl']);
        // nicht in der RSI-Liste, aber der Nickname folgt der Server-Regel (Nickname = Handle): trotzdem verlinkt
        $this->assertSame('https://robertsspaceindustries.com/en/citizens/Ben_Unbekannt', Community::getProfile($stranger, $viewer)['user']['rsiUrl']);
        // gleichnamiger fremder RSI-Account: der Kontoname darf den Nickname nicht überstimmen
        $namesake = $mk('Pilot_1', 'Echt_Handle');
        $this->assertSame('Echt_Handle', Community::getProfile($namesake, $viewer)['user']['rsiHandle']);
        // ohne Nickname wird nichts geraten
        $noNick = $mk('Cem_Handle', null);
        $this->assertNull(Community::getProfile($noNick, $viewer)['user']['rsiUrl']);
    }
}
