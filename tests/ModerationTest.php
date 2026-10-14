<?php

declare(strict_types=1);

namespace Hangar\Tests;

use Hangar\App;
use Hangar\Auth;
use Hangar\BotSettings;
use Hangar\Db;
use Hangar\Env;
use Hangar\Http\Client;
use Hangar\Http\Request;
use Hangar\Http\Response;
use Hangar\Moderation;
use Hangar\OrgError;
use Hangar\Time;

/** Moderation von Hand im Web: Maßnahmen, Fälle, Schutzregeln. */
final class ModerationTest extends DbTestCase
{
    private string $orgId;
    /** @var array<string,array{token:string,csrf:string}> */
    private array $u = [];
    /** @var list<array{0:string,1:string,2:?string,3:array<string,string>}> */
    private array $calls = [];
    private bool $dmWorks = true;
    private ?string $banned = null;
    private ?string $until = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->orgId = new_id();
        Db::insert('organizations', ['id' => $this->orgId, 'slug' => 'alpha', 'name' => 'Alpha', 'discord_guild_id' => '900', 'created_by_id' => 'x']);
        foreach (['admin' => 'ADMIN', 'member' => 'MEMBER'] as $key => $role) {
            $user = $this->mkUser(['membership_checked_at' => Time::nowDb(), 'membership_status' => 'OK', 'discord_id' => $key === 'admin' ? '900000000000000001' : '900000000000000002', 'name' => ucfirst($key)]);
            Db::insert('org_memberships', ['user_id' => $user['id'], 'org_id' => $this->orgId, 'role' => $role, 'can_plan' => $role === 'ADMIN' ? 1 : 0]);
            $s = Auth::createSession($user['id']);
            $this->u[$key] = ['token' => $s['token'], 'csrf' => (string) Db::val('SELECT csrf_token FROM sessions WHERE user_id = ?', [$user['id']])];
        }
        BotSettings::set($this->orgId, BotSettings::MOD_LOG_CHANNEL, '33');
        Env::set('DISCORD_BOT_TOKEN', 'bot-token');
        Client::fake(function (string $method, string $url, array $headers, ?string $body): array {
            $this->calls[] = [$method, $url, $body, $headers];
            $json = static fn (mixed $b, int $status = 200): array => ['status' => $status, 'body' => json_encode($b), 'headers' => []];
            $user = static fn (string $id, string $name, array $roles, ?string $until = null) => ['user' => ['id' => $id, 'username' => $name], 'roles' => $roles, 'communication_disabled_until' => $until];
            $path = (string) parse_url($url, PHP_URL_PATH);
            $query = (string) parse_url($url, PHP_URL_QUERY);
            return match (true) {
                $path === '/api/v10/users/@me' => $json(['id' => '555']),
                $path === '/api/v10/guilds/900/channels' => $json([['id' => '33', 'type' => 0, 'name' => 'protokoll', 'position' => 1]]),
                $path === '/api/v10/guilds/900' => $json(['id' => '900', 'owner_id' => '700000000000000000']),
                $path === '/api/v10/guilds/900/roles' => $json([
                    ['id' => '900', 'name' => '@everyone', 'position' => 0, 'permissions' => '0'],
                    ['id' => '21', 'name' => 'Mitglied', 'position' => 1, 'permissions' => '0'],
                    ['id' => '23', 'name' => 'Bot', 'position' => 5, 'permissions' => '0'],
                ]),
                $path === '/api/v10/guilds/900/members/555' => $json($user('555', 'Bot', ['23'])),
                $path === '/api/v10/guilds/900/members/111000000000000001' && $method === 'GET' => $json($user('111000000000000001', 'Pilot', ['21'], $this->until)),
                $path === '/api/v10/guilds/900/members/222000000000000002' && $method === 'GET' => $json($user('222000000000000002', 'Boss', ['23'])),
                $path === '/api/v10/guilds/900/members/search' => $json(str_contains($query, 'query=Pilot') ? [$user('111000000000000001', 'Pilot', ['21'])]
                    : (str_contains($query, 'query=Pi') ? [$user('111000000000000001', 'Pilot', ['21']), $user('111000000000000009', 'Pirat', ['21'])] : [])),
                preg_match('~^/api/v10/guilds/900/members/\d+$~', $path) === 1 && $method === 'GET' => $json(['message' => 'Unknown Member'], 404),
                preg_match('~^/api/v10/guilds/900/bans/(\d+)$~', $path, $m) === 1 && $method === 'GET' => $this->banned === $m[1]
                    ? $json(['user' => ['id' => $m[1], 'username' => 'Gebannt']]) : $json(['message' => 'Unknown Ban'], 404),
                $path === '/api/v10/users/@me/channels' => $this->dmWorks ? $json(['id' => '77']) : $json(['message' => 'x'], 403),
                $path === '/api/v10/channels/77/messages', $path === '/api/v10/channels/33/messages' => $json(['id' => '1']),
                in_array($method, ['PATCH', 'DELETE', 'PUT'], true) && str_starts_with($path, '/api/v10/guilds/900/') => ['status' => 204, 'body' => '', 'headers' => []],
                default => throw new \LogicException("Unerwarteter HTTP-Aufruf: $method $url"),
            };
        });
    }

    /** @param array<string,mixed> $post */
    private function post(string $path, array $post, string $as = 'admin'): Response
    {
        return App::handle(new Request('POST', "/o/alpha/bot/mod/$path", [], $post + ['_csrf' => $this->u[$as]['csrf']], [], [Auth::COOKIE => $this->u[$as]['token']], ''));
    }

    private function act(array $in): Response
    {
        return $this->post('action', $in + ['do' => 'warn', 'person' => '111000000000000001', 'reason' => '', 'duration' => '', 'days' => '0']);
    }

    /** @return list<array{0:string,1:string,2:?string,3:array<string,string>}> */
    private function writes(): array
    {
        return array_values(array_filter($this->calls, static fn ($c) => $c[0] !== 'GET'));
    }

    private function cases(): array
    {
        return Db::all('SELECT * FROM bot_mod_cases WHERE org_id = ? ORDER BY id', [$this->orgId]);
    }

    public function testParsesDurationsLikeTheBot(): void
    {
        $this->assertSame(1800, Moderation::parseDuration('30m'));
        $this->assertSame(7200, Moderation::parseDuration('2 h'));
        $this->assertSame(600, Moderation::parseDuration('10'));
        $this->assertSame(7 * 86400, Moderation::parseDuration('1w'));
        $this->assertSame('1 Tag 2 Stunden', Moderation::durationText(86400 + 7200));
        foreach (['0m', 'abc', '5x', '29d'] as $bad) {
            try {
                Moderation::parseDuration($bad);
                $this->fail($bad);
            } catch (OrgError) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testOnlyAdminsMayModerate(): void
    {
        $this->assertSame(404, $this->post('action', ['do' => 'warn', 'person' => '111000000000000001', 'reason' => 'x'], 'member')->status);
        $this->assertSame(404, $this->post('unwarn', ['id' => '1'], 'member')->status);
        $this->assertSame([], $this->cases());
        $this->assertSame([], $this->writes());
    }

    public function testWarnNeedsAReasonStoresTheCaseAndLogsIt(): void
    {
        $this->act(['do' => 'warn']);
        $this->assertSame([], $this->cases());

        $this->act(['do' => 'warn', 'reason' => 'Spam']);
        $case = $this->cases()[0];
        $this->assertSame(['WARN', '111000000000000001', 'Pilot', 'Spam', '900000000000000001'], [$case['action'], $case['target_id'], $case['target_name'], $case['reason'], $case['moderator_id']]);
        $posts = array_values(array_filter($this->writes(), static fn ($c) => str_ends_with((string) parse_url($c[1], PHP_URL_PATH), '/messages')));
        $this->assertCount(2, $posts); // Direktnachricht und Protokoll
        $this->assertStringContainsString('verwarnt', (string) $posts[0][2]);
        $this->assertStringContainsString('Fall #' . $case['id'], (string) $posts[1][2]);
        $this->assertStringContainsString('Admin (Manager)', (string) json_decode((string) $posts[1][2], true)['embeds'][0]['fields'][1]['value']);
    }

    public function testNamesMustMatchExactlyOnePerson(): void
    {
        $this->act(['person' => 'Pilot', 'reason' => 'x']);
        $this->assertCount(1, $this->cases());
        $this->act(['person' => 'Pi', 'reason' => 'x']);   // zwei Treffer
        $this->act(['person' => 'Niemand', 'reason' => 'x']);
        $this->assertCount(1, $this->cases());
    }

    public function testTimeoutKickBanAndTheirReverse(): void
    {
        $this->act(['do' => 'timeout', 'duration' => '2h', 'reason' => 'ruhig']);
        $patch = array_values(array_filter($this->writes(), static fn ($c) => $c[0] === 'PATCH'))[0];
        $until = (string) json_decode((string) $patch[2], true)['communication_disabled_until'];
        $this->assertEqualsWithDelta(time() + 7200, strtotime($until), 5);
        $this->assertSame(rawurlencode('Admin: ruhig'), $patch[3]['X-Audit-Log-Reason']);
        $this->assertSame('2 Stunden', $this->cases()[0]['detail']);

        // Aufheben geht nur bei laufendem Timeout.
        $this->act(['do' => 'untimeout']);
        $this->assertCount(1, $this->cases());
        $this->until = gmdate('c', time() + 3600);
        $this->act(['do' => 'untimeout']);
        $this->assertSame('UNTIMEOUT', $this->cases()[1]['action']);

        $this->act(['do' => 'kick']);
        $this->assertSame('KICK', $this->cases()[2]['action']);
        $this->assertContains('DELETE', array_column($this->writes(), 0));

        $this->act(['do' => 'ban', 'days' => '3', 'reason' => 'Werbung']);
        $ban = array_values(array_filter($this->writes(), static fn ($c) => $c[0] === 'PUT'))[0];
        $this->assertSame(['delete_message_seconds' => 259200], json_decode((string) $ban[2], true));
        $this->assertSame('Nachrichten der letzten 3 Tage gelöscht', $this->cases()[3]['detail']);

        // Entbannen nur mit ID und nur bei gebannten Personen.
        $this->act(['do' => 'unban', 'person' => 'Pilot']);
        $this->act(['do' => 'unban', 'person' => '333000000000000003']);
        $this->assertCount(4, $this->cases());
        $this->banned = '333000000000000003';
        $this->act(['do' => 'unban', 'person' => '333000000000000003']);
        $this->assertSame(['UNBAN', 'Gebannt'], [$this->cases()[4]['action'], $this->cases()[4]['target_name']]);
    }

    public function testProtectsSelfBotOwnerAndHigherRoles(): void
    {
        foreach (['900000000000000001', '555', '700000000000000000', '222000000000000002'] as $id) {
            $this->act(['do' => 'ban', 'person' => $id, 'reason' => 'x']);
        }
        $this->assertSame([], $this->cases());
        $this->assertNotContains('PUT', array_column($this->writes(), 0));
    }

    public function testNoDirectMessageIsNoError(): void
    {
        $this->dmWorks = false;
        $this->act(['do' => 'kick']);
        $this->assertCount(1, $this->cases());
        $this->assertContains('DELETE', array_column($this->writes(), 0));
    }

    public function testCasePageFiltersAndWarningsCanBeTakenBack(): void
    {
        $this->act(['reason' => 'Spam']);
        $this->act(['do' => 'kick']);
        $id = (int) $this->cases()[0]['id'];
        $this->assertSame(1, Moderation::activeWarnings($this->orgId, '111000000000000001'));

        $page = fn (array $q) => App::handle(new Request('GET', '/o/alpha/bot', ['m' => 'faelle'] + $q, [], [], [Auth::COOKIE => $this->u['admin']['token']], ''))->body;
        $html = $page([]);
        $this->assertStringContainsString('Verwarnung', $html);
        $this->assertStringContainsString('Kick', $html);
        $this->assertStringContainsString('von Admin', $html);
        $only = $page(['a' => 'KICK']);
        $this->assertStringNotContainsString('/mod/unwarn', $only);
        $this->assertStringContainsString('Pilot', $page(['q' => 'pil']));
        $this->assertStringNotContainsString('<li class="flex', $page(['q' => 'Niemand']));

        $this->post('unwarn', ['id' => (string) $id]);
        $this->assertSame(0, Moderation::activeWarnings($this->orgId, '111000000000000001'));
        $this->assertStringContainsString('zurückgenommen', $page([]));
        $this->post('unwarn', ['id' => (string) $id]); // zweites Mal: nichts mehr offen
        $this->assertSame(1, (int) Db::val("SELECT COUNT(*) FROM bot_mod_cases WHERE removed_at IS NOT NULL"));
    }
}
