<?php

declare(strict_types=1);

namespace Hangar\Tests;

use Hangar\App;
use Hangar\Auth;
use Hangar\Db;
use Hangar\Env;
use Hangar\Http\Request;
use Hangar\Http\Response;
use Hangar\Secrets;
use Hangar\Time;

/** Ende-zu-Ende durch den Router: Rechte, CSRF, 404-Verhalten, API, Anmeldung. */
final class WebTest extends DbTestCase
{
    private string $orgA;
    private string $orgB;
    /** @var array<string,array{id:string,token:string,csrf:string}> */
    private array $u = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->orgA = new_id();
        $this->orgB = new_id();
        Db::insert('organizations', ['id' => $this->orgA, 'slug' => 'alpha', 'name' => 'Alpha', 'discord_guild_id' => 'g-a', 'created_by_id' => 'x']);
        Db::insert('organizations', ['id' => $this->orgB, 'slug' => 'beta', 'name' => 'Beta', 'discord_guild_id' => 'g-b', 'created_by_id' => 'x']);
        $this->member('admin', $this->orgA, 'ADMIN', 'Admin <b>Fett</b>');
        $this->member('member', $this->orgA, 'MEMBER', 'Mitglied');
        $this->member('fremd', $this->orgB, 'MEMBER', 'Fremd');
        $this->member('loner', null, 'MEMBER', 'Einzelgänger');
    }

    private function member(string $key, ?string $orgId, string $role, string $name): void
    {
        $user = $this->mkUser(['name' => $name, 'membership_checked_at' => Time::nowDb(), 'membership_status' => 'OK']);
        if ($orgId !== null) {
            Db::insert('org_memberships', ['user_id' => $user['id'], 'org_id' => $orgId, 'role' => $role, 'can_plan' => $role === 'ADMIN' ? 1 : 0]);
        }
        $s = Auth::createSession($user['id']);
        $csrf = (string) Db::val('SELECT csrf_token FROM sessions WHERE user_id = ?', [$user['id']]);
        $this->u[$key] = ['id' => $user['id'], 'token' => $s['token'], 'csrf' => $csrf];
    }

    /** @param array<string,mixed> $o */
    private function call(string $method, string $path, ?string $as = null, array $o = []): Response
    {
        $cookies = $as !== null ? [Auth::COOKIE => $this->u[$as]['token']] : [];
        $post = $o['post'] ?? [];
        if ($as !== null && $method === 'POST' && ($o['csrf'] ?? true)) {
            $post += ['_csrf' => $this->u[$as]['csrf']];
        }
        return App::handle(new Request($method, $path, $o['query'] ?? [], $post, $o['headers'] ?? [], $cookies + ($o['cookies'] ?? []), $o['body'] ?? ''));
    }

    // --- Zugriff -----------------------------------------------------------------------------

    public function testGuestsAreSentToLoginForPrivatePages(): void
    {
        foreach (['/hangar', '/settings', '/sync', '/o/alpha', '/o/alpha/events', '/catalog', '/catalog/ship/x'] as $path) {
            $r = $this->call('GET', $path);
            $this->assertSame(303, $r->status, $path);
            $this->assertSame('/login', $r->headers['Location'], $path);
        }
    }

    public function testPublicPagesWorkForGuests(): void
    {
        foreach (['/', '/login', '/datenschutz', '/healthz'] as $path) {
            $this->assertSame(200, $this->call('GET', $path)->status, $path);
        }
        $home = $this->call('GET', '/')->body;
        $this->assertStringNotContainsString('/catalog', $home, 'kein Katalog-Link für Gäste');
    }

    public function testCatalogIsForLoggedInUsersAndHugePageNumbersAreCapped(): void
    {
        $res = $this->call('GET', '/catalog', 'loner');
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('href="/catalog"', $res->body);
        foreach (['99999999999999999999', '9223372036854775807', '-5', 'abc'] as $page) {
            $this->assertSame(200, $this->call('GET', '/catalog', 'loner', ['query' => ['page' => $page]])->status, $page);
        }
    }

    public function testSortOptionsAreParsedStrictlyAndSortGermanNames(): void
    {
        $this->assertSame('name', \Hangar\FleetFilter::parseSort([]));
        $this->assertSame('name', \Hangar\FleetFilter::parseSort(['sort' => 'preis']));
        $this->assertSame('name', \Hangar\FleetFilter::parseSort(['sort' => ['name']]));
        $this->assertSame('name-desc', \Hangar\FleetFilter::parseSort(['sort' => 'name-desc']));

        $list = [['n' => 'Zeta'], ['n' => 'Ärger'], ['n' => 'anna'], ['n' => 'Berta']];
        $names = fn (string $s) => array_column(\Hangar\FleetFilter::sortByName($list, fn ($e) => $e['n'], $s), 'n');
                $this->assertSame(['anna', 'Ärger', 'Berta', 'Zeta'], $names('name'));
        $this->assertSame(['Zeta', 'Berta', 'Ärger', 'anna'], $names('name-desc'));
    }

    public function testCatalogSortsAscendingAndDescendingAndKeepsTheChoiceWhilePaging(): void
    {
        foreach (['Alpha', 'Charlie', 'Bravo'] as $i => $n) {
            Db::insert('catalog_items', ['id' => "s$i", 'kind' => 'SHIP', 'slug' => strtolower($n), 'name' => $n, 'match_key' => strtolower($n), 'data' => '{}']);
        }
        $order = function (string $sort): array {
            $body = $this->call('GET', '/catalog', 'loner', ['query' => ['sort' => $sort]])->body;
            preg_match_all('#/catalog/ship/(alpha|bravo|charlie)"#', $body, $m);
            return $m[1];
        };
        $this->assertSame(['alpha', 'bravo', 'charlie'], $order(''));
        $this->assertSame(['alpha', 'bravo', 'charlie'], $order('name'));
        $this->assertSame(['charlie', 'bravo', 'alpha'], $order('name-desc'));
        $this->assertSame(['alpha', 'bravo', 'charlie'], $order('unsinn'));
        $this->assertStringContainsString('<option value="name-desc" selected>', $this->call('GET', '/catalog', 'loner', ['query' => ['sort' => 'name-desc']])->body);
    }
    public function testLastVisitedOrgStaysOnPagesWithoutOrgInTheAddress(): void
    {
        Db::insert('org_memberships', ['user_id' => $this->u['member']['id'], 'org_id' => $this->orgB, 'role' => 'MEMBER', 'can_plan' => 0]);
        // Wer eine Orga besucht, bekommt sie als Cookie gemerkt; eine fremde (kein Mitglied) wird nicht gemerkt
        $visit = $this->call('GET', '/o/beta/hangar', 'member');
        $this->assertContains(Auth::ORG_COOKIE, array_column($visit->cookies, 0));
        $this->assertNotContains(Auth::ORG_COOKIE, array_column($this->call('GET', '/o/beta', 'admin')->cookies, 0));
        // Mein Hangar zeigt dann Kopf und Reiter dieser Orga, nicht die erste
        $page = $this->call('GET', '/hangar', 'member', ['cookies' => [Auth::ORG_COOKIE => 'beta']])->body;
        $this->assertStringContainsString('href="/o/beta/hangar"', $page);
        $this->assertStringContainsString('title="Zur Startseite von Beta"', $page);
        // Ein Cookie für eine Orga, in der man nicht Mitglied ist, wird ignoriert
        $other = $this->call('GET', '/hangar', 'admin', ['cookies' => [Auth::ORG_COOKIE => 'beta']])->body;
        $this->assertStringContainsString('title="Zur Startseite von Alpha"', $other);
        $this->assertStringNotContainsString('/o/beta/hangar', $other);
    }

    public function testSecurityHeadersAndNoStoreForLoggedInPages(): void
    {
        $guest = $this->call('GET', '/login');
        $this->assertArrayNotHasKey('Cache-Control', $guest->headers);
        $in = $this->call('GET', '/hangar', 'member');
        $this->assertSame('private, no-store', $in->headers['Cache-Control']);
        foreach ([$guest, $in] as $r) {
            $this->assertStringContainsString('camera=()', $r->headers['Permissions-Policy']);
            $this->assertSame('DENY', $r->headers['X-Frame-Options']);
        }
    }

    public function testAddReturnTargetIsLimitedToCatalogPages(): void
    {
        $item = new_id();
        Db::insert('catalog_items', ['id' => $item, 'kind' => 'SHIP', 'slug' => 'abc', 'name' => 'Abc', 'match_key' => 'abc', 'source' => 'RSI_MATRIX']);
        $to = function (string $return) use ($item): string {
            $r = $this->call('POST', '/hangar/add', 'member', ['post' => ['catalogItemId' => $item, 'return' => $return]]);
            return (string) ($r->headers['Location'] ?? '');
        };
        $this->assertSame('/catalog/ship/abc', $to('/catalog/ship/abc'));
        foreach (['//evil.example/x', 'https://evil.example', "/catalog/ship/abc\r\nSet-Cookie: x=1", '\\\\evil.example', '/other'] as $bad) {
            $this->assertSame('/hangar', $to($bad), $bad);
        }
    }

    public function testOrgPagesAre404ForNonMembersAndWorkForMembers(): void
    {
        foreach (['', '/members', '/hangar', '/stats', '/events', '/events/new'] as $suffix) {
            $this->assertSame(404, $this->call('GET', "/o/alpha$suffix", 'fremd')->status, "fremd $suffix");
            $this->assertSame(404, $this->call('GET', "/o/alpha$suffix", 'loner')->status, "loner $suffix");
        }
        foreach (['', '/members', '/hangar', '/stats', '/events'] as $suffix) {
            $this->assertSame(200, $this->call('GET', "/o/alpha$suffix", 'member')->status, "member $suffix");
        }
        $this->assertSame(404, $this->call('GET', '/o/gibt-es-nicht', 'admin')->status);
    }

    public function testOrgSettingsAndEventCreationOnlyForAdminsAndPlanners(): void
    {
        $this->assertSame(404, $this->call('GET', '/o/alpha/settings', 'member')->status);
        $this->assertSame(200, $this->call('GET', '/o/alpha/settings', 'admin')->status);
        $this->assertSame(404, $this->call('GET', '/o/alpha/events/new', 'member')->status);
        $this->assertSame(200, $this->call('GET', '/o/alpha/events/new', 'admin')->status);
    }

    public function testAdminAreaIs404ForEveryoneExceptServerAdmin(): void
    {
        $adminDiscord = (string) Db::val('SELECT discord_id FROM users WHERE id = ?', [$this->u['admin']['id']]);
        Env::set('SERVER_ADMIN_DISCORD_ID', $adminDiscord);
        $this->assertSame(404, $this->call('GET', '/admin')->status);
        $this->assertSame(404, $this->call('GET', '/admin', 'member')->status);
        $this->assertSame(200, $this->call('GET', '/admin', 'admin')->status);
        // Aktionen: für Nicht-Admins ebenfalls 404 und ohne Wirkung
        $r = $this->call('POST', '/admin/ban', 'member', ['post' => ['guildId' => 'g-a12345']]);
        $this->assertSame(404, $r->status);
        $this->assertSame(0, (int) Db::val('SELECT COUNT(*) FROM banned_guilds'));
    }

    public function testNoAdminAreaWithoutConfiguredId(): void
    {
        $this->assertSame(404, $this->call('GET', '/admin', 'admin')->status);
    }

    // --- CSRF --------------------------------------------------------------------------------

    public function testPostWithoutOrWithWrongCsrfTokenIsRejectedAndHasNoEffect(): void
    {
        $this->assertSame(403, $this->call('POST', '/settings', 'member', ['csrf' => false, 'post' => ['hangarVisibility' => 'PRIVATE', 'achievementsVisibility' => 'PRIVATE']])->status);
        $this->assertSame(403, $this->call('POST', '/settings', 'member', ['csrf' => false, 'post' => ['_csrf' => 'falsch', 'hangarVisibility' => 'PRIVATE', 'achievementsVisibility' => 'PRIVATE']])->status);
        // Token einer anderen Sitzung
        $this->assertSame(403, $this->call('POST', '/settings', 'member', ['csrf' => false, 'post' => ['_csrf' => $this->u['admin']['csrf'], 'hangarVisibility' => 'PRIVATE', 'achievementsVisibility' => 'PRIVATE']])->status);
        $this->assertSame('MEMBERS', Db::val('SELECT hangar_visibility FROM users WHERE id = ?', [$this->u['member']['id']]));
    }

    public function testPostWithValidCsrfTokenWorks(): void
    {
        $r = $this->call('POST', '/settings', 'member', ['post' => ['hangarVisibility' => 'PRIVATE', 'achievementsVisibility' => 'PRIVATE']]);
        $this->assertSame(303, $r->status);
        $this->assertSame('PRIVATE', Db::val('SELECT hangar_visibility FROM users WHERE id = ?', [$this->u['member']['id']]));
    }

    public function testGuestPostsAreRejected(): void
    {
        $this->assertSame(403, $this->call('POST', '/logout')->status);
        $this->assertSame(403, $this->call('POST', '/hangar/remove-all', null, ['post' => ['kind' => 'SHIP']])->status);
    }

    public function testInvalidVisibilityValuesAreIgnored(): void
    {
        $this->call('POST', '/settings', 'member', ['post' => ['hangarVisibility' => 'PUBLIC', 'achievementsVisibility' => 'MEMBERS']]);
        $this->assertSame('MEMBERS', Db::val('SELECT hangar_visibility FROM users WHERE id = ?', [$this->u['member']['id']]));
    }

    // --- Ausgabe -----------------------------------------------------------------------------

    public function testUserContentIsEscaped(): void
    {
        $html = $this->call('GET', '/o/alpha/members', 'member')->body;
        $this->assertStringNotContainsString('<b>Fett</b>', $html);
        $this->assertStringContainsString('Admin &lt;b&gt;Fett&lt;/b&gt;', $html);
    }

    public function testOrgFleetPageShowsNoPersonalData(): void
    {
        $shipId = new_id();
        Db::insert('catalog_items', ['id' => $shipId, 'kind' => 'SHIP', 'slug' => 'web-ship', 'name' => 'Web Ship', 'match_key' => 'webship', 'data' => '{}']);
        Db::insert('owned_items', ['id' => new_id(), 'user_id' => $this->u['admin']['id'], 'catalog_item_id' => $shipId, 'kind' => 'SHIP', 'source' => 'IMPORT']);
        Db::run("UPDATE users SET hangar_visibility = 'PRIVATE' WHERE id = ?", [$this->u['admin']['id']]);

        $html = $this->call('GET', '/o/alpha/hangar', 'member')->body;
        $this->assertStringContainsString('Web Ship', $html);
        $this->assertStringNotContainsString('Admin', str_replace('Orga verwalten', '', preg_replace('#<header.*?</header>#s', '', $html)));
    }

    public function testNotFoundPageForUnknownRoutesAndInvalidImageSlugs(): void
    {
        $this->assertSame(404, $this->call('GET', '/gibt/es/nicht')->status);
        $this->assertSame(404, $this->call('GET', '/img/ship/..%2F..%2Fetc', 'member')->status);
        $this->assertSame(405, $this->call('GET', '/logout')->status);
    }

    public function testProfileOfAnotherOrgIs404(): void
    {
        $this->assertSame(404, $this->call('GET', '/members/' . $this->u['fremd']['id'], 'member')->status);
        $this->assertSame(200, $this->call('GET', '/members/' . $this->u['admin']['id'], 'member')->status);
    }

    // --- Export und API ----------------------------------------------------------------------

    public function testExportNeedsLoginAndDeliversCsv(): void
    {
        $this->assertSame(401, $this->call('GET', '/hangar/export')->status);
        Db::insert('owned_items', ['id' => new_id(), 'user_id' => $this->u['member']['id'], 'kind' => 'SHIP', 'custom_name' => '=EVIL()', 'source' => 'MANUAL']);
        $r = $this->call('GET', '/hangar/export', 'member', ['query' => ['format' => 'csv', 'kind' => 'SHIP']]);
        $this->assertSame(200, $r->status);
        $this->assertStringContainsString('text/csv', $r->headers['Content-Type']);
        $this->assertStringContainsString('hangar-ship.csv', $r->headers['Content-Disposition']);
        $this->assertStringContainsString("'=EVIL()", $r->body);
        $json = $this->call('GET', '/hangar/export', 'member', ['query' => ['format' => 'json']]);
        $this->assertSame('=EVIL()', json_decode($json->body, true)[0]['name']);
    }

    private function importCall(?string $as, array $headers, string $body, string $query = ''): Response
    {
        return $this->call('POST', '/api/v1/import/hangar', $as, ['csrf' => false, 'headers' => $headers, 'body' => $body, 'query' => $query === '' ? [] : ['dryRun' => $query]]);
    }

    public function testImportApiRequiresAuthJsonContentTypeAndValidBody(): void
    {
        $body = (string) json_encode([['name' => '100i']]);
        $this->assertSame(401, $this->importCall(null, ['content-type' => 'application/json'], $body)->status);
        $this->assertSame(415, $this->importCall('member', ['content-type' => 'application/x-www-form-urlencoded'], $body)->status);
        $this->assertSame(400, $this->importCall('member', ['content-type' => 'application/json'], '{kaputt')->status);
        $this->assertSame(422, $this->importCall('member', ['content-type' => 'application/json'], '{"hello":"world"}')->status);
    }

    public function testImportApiDryRunWritesNothingAndRealRunDoes(): void
    {
        $body = (string) json_encode([['name' => 'Freies Schiff']]);
        $h = ['content-type' => 'application/json'];
        $r = $this->importCall('member', $h, $body, '1');
        $this->assertSame(200, $r->status);
        $this->assertTrue(json_decode($r->body, true)['dryRun']);
        $this->assertSame(0, (int) Db::val('SELECT COUNT(*) FROM owned_items'));

        $r = $this->importCall('member', $h, $body);
        $this->assertSame(200, $r->status);
        $this->assertSame(1, (int) Db::val('SELECT COUNT(*) FROM owned_items'));
        $this->assertCount(1, $r->after);   // Hintergrundabgleich vorgemerkt
    }

    public function testImportApiWorksWithBearerTokenAndRejectsBadTokens(): void
    {
        ['token' => $token] = \Hangar\ApiToken::create($this->u['member']['id'], 'Skript');
        $body = (string) json_encode([['name' => 'Per Token']]);
        $ok = $this->importCall(null, ['content-type' => 'application/json', 'authorization' => "Bearer $token"], $body, '1');
        $this->assertSame(200, $ok->status);
        $bad = $this->importCall('member', ['content-type' => 'application/json', 'authorization' => 'Bearer sch_falsch'], $body);
        $this->assertSame(401, $bad->status, 'ein ungültiger Bearer-Token darf nicht auf die Sitzung zurückfallen');
    }

    public function testImportApiIsRateLimited(): void
    {
        $h = ['content-type' => 'application/json'];
        $body = (string) json_encode([['name' => 'X']]);
        for ($i = 0; $i < 10; $i++) {
            $this->assertSame(200, $this->importCall('member', $h, $body, '1')->status);
        }
        $r = $this->importCall('member', $h, $body, '1');
        $this->assertSame(429, $r->status);
        $this->assertArrayHasKey('Retry-After', $r->headers);
    }

    public function testImportApiRejectsTooLargeBodies(): void
    {
        $r = $this->importCall('member', ['content-type' => 'application/json', 'content-length' => (string) (6 * 1024 * 1024)], '[]');
        $this->assertSame(413, $r->status);
    }

    public function testMyItemsApi(): void
    {
        $this->assertSame(401, $this->call('GET', '/api/v1/me/items')->status);
        Db::insert('owned_items', ['id' => new_id(), 'user_id' => $this->u['member']['id'], 'kind' => 'SHIP', 'custom_name' => 'Mein Schiff', 'source' => 'MANUAL']);
        $r = $this->call('GET', '/api/v1/me/items', 'member');
        $this->assertSame('Mein Schiff', json_decode($r->body, true)['items'][0]['name']);
    }

    // --- Hangar-Aktionen ---------------------------------------------------------------------

    public function testRemovingAnotherUsersItemDoesNothing(): void
    {
        $id = new_id();
        Db::insert('owned_items', ['id' => $id, 'user_id' => $this->u['admin']['id'], 'kind' => 'SHIP', 'custom_name' => 'Admin-Schiff']);
        $this->call('POST', '/hangar/remove', 'member', ['post' => ['itemId' => $id]]);
        $this->assertNotNull(Db::val('SELECT id FROM owned_items WHERE id = ?', [$id]));
    }

    public function testDeleteAccountRequiresConfirmationAndRemovesEverything(): void
    {
        $this->call('POST', '/settings/delete-account', 'loner', ['post' => ['confirm' => 'nein']]);
        $this->assertNotNull(Db::val('SELECT id FROM users WHERE id = ?', [$this->u['loner']['id']]));
        $r = $this->call('POST', '/settings/delete-account', 'loner', ['post' => ['confirm' => 'LÖSCHEN']]);
        $this->assertSame(303, $r->status);
        $this->assertNull(Db::val('SELECT id FROM users WHERE id = ?', [$this->u['loner']['id']]));
        $this->assertSame(0, (int) Db::val('SELECT COUNT(*) FROM sessions WHERE user_id = ?', [$this->u['loner']['id']]));
    }

    public function testEventsFlowThroughTheRouter(): void
    {
        $payload = json_encode(['title' => 'Test-Event', 'startsAt' => '2026-12-01T20:00', 'ships' => [['customName' => 'Eigenbau', 'slots' => [['label' => 'Pilot']]]]]);
        $this->call('POST', '/o/alpha/events/save', 'member', ['post' => ['payload' => $payload]]);
        $this->assertSame(0, (int) Db::val('SELECT COUNT(*) FROM events'), 'Planer-Recht wird in Events::save geprüft');
        $r = $this->call('POST', '/o/alpha/events/save', 'admin', ['post' => ['payload' => $payload]]);
        $this->assertSame(303, $r->status);
        $eventId = (string) Db::val('SELECT id FROM events');
        $this->assertStringContainsString("/o/alpha/events/$eventId", $r->headers['Location']);
        $this->assertSame(200, $this->call('GET', "/o/alpha/events/$eventId", 'member')->status);
        $this->assertSame(404, $this->call('GET', "/o/alpha/events/$eventId", 'fremd')->status);
        $this->call('POST', "/o/alpha/events/$eventId/rsvp", 'member', ['post' => ['status' => 'YES']]);
        $this->assertSame('YES', Db::val('SELECT status FROM event_rsvps WHERE event_id = ?', [$eventId]));
        // Fremde Orga kann nicht antworten
        $this->call('POST', "/o/alpha/events/$eventId/rsvp", 'fremd', ['post' => ['status' => 'NO']]);
        $this->assertSame(1, (int) Db::val('SELECT COUNT(*) FROM event_rsvps'));
    }

    // --- Anmeldung ---------------------------------------------------------------------------

    public function testLoginStartSetsStateCookieAndRedirectsToDiscord(): void
    {
        Env::set('AUTH_DISCORD_ID', 'client-1');
        Env::set('AUTH_URL', 'https://hangar.example.test');
        $r = $this->call('GET', '/auth/discord');
        $this->assertSame(302, $r->status);
        $this->assertStringStartsWith('https://discord.com/api/oauth2/authorize?', $r->headers['Location']);
        parse_str((string) parse_url($r->headers['Location'], PHP_URL_QUERY), $q);
        $this->assertSame('client-1', $q['client_id']);
        $this->assertSame('https://hangar.example.test/api/auth/callback/discord', $q['redirect_uri']);
        $this->assertStringContainsString('guilds.members.read', $q['scope']);
        $cookie = array_values(array_filter($r->cookies, fn ($c) => $c[0] === Auth::STATE_COOKIE))[0];
        $this->assertSame($q['state'], $cookie[1]);
        $this->assertTrue($cookie[2]['httponly']);
        $this->assertTrue($cookie[2]['secure']);
    }

    public function testCallbackRejectsMissingOrWrongState(): void
    {
        $r = $this->call('GET', '/api/auth/callback/discord', null, ['query' => ['code' => 'x', 'state' => 'a'], 'cookies' => [Auth::STATE_COOKIE => 'b']]);
        $this->assertSame('/login?error=state', $r->headers['Location']);
        $r = $this->call('GET', '/api/auth/callback/discord', null, ['query' => ['code' => 'x', 'state' => 'a']]);
        $this->assertSame('/login?error=state', $r->headers['Location']);
        $this->assertSame(0, (int) Db::val('SELECT COUNT(*) FROM sessions WHERE user_id NOT IN (?,?,?,?)', array_column($this->u, 'id')));
    }

    public function testCallbackCreatesUserSyncsMembershipsAndStartsSession(): void
    {
        $fake = new FakeDiscord();
        $fake->guilds = [FakeDiscord::guild('g-a', ['permissions' => '32'])];
        $fake->install();
        $r = $this->call('GET', '/api/auth/callback/discord', null, ['query' => ['code' => 'abc', 'state' => 's1'], 'cookies' => [Auth::STATE_COOKIE => 's1']]);
        $this->assertSame('/', $r->headers['Location']);

        $user = Db::one('SELECT * FROM users WHERE discord_id = ?', ['123456789012345678']);
        $this->assertSame('Tester', $user['name']);
        $this->assertSame('https://cdn.discordapp.com/avatars/123456789012345678/abc.png', $user['image']);
        $this->assertSame('OK', $user['membership_status']);
        $this->assertSame('ADMIN', Db::val('SELECT role FROM org_memberships WHERE user_id = ? AND org_id = ?', [$user['id'], $this->orgA]));
        $acc = Db::one('SELECT * FROM accounts WHERE user_id = ?', [$user['id']]);
        $this->assertSame('neu', Secrets::decrypt($acc['access_token']));
        $this->assertStringStartsWith('enc:v1:', $acc['access_token']);

        $session = array_values(array_filter($r->cookies, fn ($c) => $c[0] === Auth::COOKIE))[0];
        $this->assertTrue($session[2]['httponly']);
        $this->assertSame('Lax', $session[2]['samesite']);
        // Cookie enthält den Klartext, die Datenbank nur den Hash
        $this->assertNull(Db::val('SELECT id FROM sessions WHERE token_hash = ?', [$session[1]]));
        $this->assertNotNull(Db::val('SELECT id FROM sessions WHERE token_hash = ?', [hash('sha256', $session[1])]));

        // Zweite Anmeldung desselben Nutzers legt keinen zweiten Nutzer an
        $this->call('GET', '/api/auth/callback/discord', null, ['query' => ['code' => 'abc', 'state' => 's1'], 'cookies' => [Auth::STATE_COOKIE => 's1']]);
        $this->assertSame(1, (int) Db::val('SELECT COUNT(*) FROM users WHERE discord_id = ?', ['123456789012345678']));
        $this->assertSame(1, (int) Db::val('SELECT COUNT(*) FROM accounts WHERE user_id = ?', [$user['id']]));
    }

    public function testFailedDiscordExchangeRedirectsToLoginError(): void
    {
        $fake = new FakeDiscord();
        $fake->tokenStatus = 400;
        $fake->install();
        $r = $this->call('GET', '/api/auth/callback/discord', null, ['query' => ['code' => 'abc', 'state' => 's1'], 'cookies' => [Auth::STATE_COOKIE => 's1']]);
        $this->assertSame('/login?error=discord', $r->headers['Location']);
        $this->assertSame(0, (int) Db::val('SELECT COUNT(*) FROM users WHERE discord_id = ?', ['123456789012345678']));
    }

    public function testLogoutDestroysSession(): void
    {
        $r = $this->call('POST', '/logout', 'member');
        $this->assertSame(303, $r->status);
        $this->assertSame(0, (int) Db::val('SELECT COUNT(*) FROM sessions WHERE user_id = ?', [$this->u['member']['id']]));
        $this->assertSame(303, $this->call('GET', '/hangar', 'member')->status);
    }

    public function testExpiredSessionsAreIgnored(): void
    {
        Db::run('UPDATE sessions SET expires_at = ? WHERE user_id = ?', [gmdate('Y-m-d H:i:s', time() - 10), $this->u['member']['id']]);
        $this->assertSame(303, $this->call('GET', '/hangar', 'member')->status);
    }

    public function testStaleMembershipsAreRecheckedOnRequest(): void
    {
        $fake = new FakeDiscord();
        $fake->guilds = [];            // inzwischen vom Server entfernt
        $fake->install();
        Db::insert('accounts', ['id' => new_id(), 'user_id' => $this->u['member']['id'], 'provider' => 'discord', 'provider_account_id' => 'pa', 'access_token' => 'tok', 'refresh_token' => 'r', 'expires_at' => time() + 3600, 'scope' => FakeDiscord::SCOPES]);
        Db::run('UPDATE users SET membership_checked_at = ? WHERE id = ?', [gmdate('Y-m-d H:i:s', time() - 90000), $this->u['member']['id']]);
        $this->assertSame(404, $this->call('GET', '/o/alpha', 'member')->status);
    }

    public function testDevLoginIsNotAvailableInProduction(): void
    {
        Env::set('APP_ENV', 'production');
        $this->assertSame(404, $this->call('GET', '/dev/login')->status);
    }
}
