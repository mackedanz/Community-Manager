<?php

declare(strict_types=1);

namespace Hangar\Tests;

use Hangar\App;
use Hangar\Auth;
use Hangar\BotSettings;
use Hangar\Db;
use Hangar\DiscordRules;
use Hangar\Env;
use Hangar\Http\Client;
use Hangar\Http\Request;
use Hangar\Http\Response;
use Hangar\Time;

/** Regeln mit „Bestätigen“-Knopf aus dem Web veröffentlichen. */
final class DiscordRulesTest extends DbTestCase
{
    private string $orgId;
    /** @var array<string,array{token:string,csrf:string}> */
    private array $u = [];
    /** @var list<array{0:string,1:string,2:?string}> */
    private array $calls = [];
    private int $messageStatus = 200;

    protected function setUp(): void
    {
        parent::setUp();
        $this->orgId = new_id();
        Db::insert('organizations', ['id' => $this->orgId, 'slug' => 'alpha', 'name' => 'Alpha', 'discord_guild_id' => '900', 'created_by_id' => 'x']);
        foreach (['admin' => 'ADMIN', 'member' => 'MEMBER'] as $key => $role) {
            $user = $this->mkUser(['membership_checked_at' => Time::nowDb(), 'membership_status' => 'OK']);
            Db::insert('org_memberships', ['user_id' => $user['id'], 'org_id' => $this->orgId, 'role' => $role, 'can_plan' => $role === 'ADMIN' ? 1 : 0]);
            $s = Auth::createSession($user['id']);
            $this->u[$key] = ['token' => $s['token'], 'csrf' => (string) Db::val('SELECT csrf_token FROM sessions WHERE user_id = ?', [$user['id']])];
        }
        BotSettings::set($this->orgId, BotSettings::RULES_CHANNEL, '11');
        BotSettings::set($this->orgId, BotSettings::VISITOR_ROLE, '21');
        Env::set('DISCORD_BOT_TOKEN', 'bot-token');
        Client::fake(function (string $method, string $url, array $headers, ?string $body): array {
            $this->calls[] = [$method, $url, $body];
            $json = static fn (mixed $b, int $status = 200): array => ['status' => $status, 'body' => json_encode($b), 'headers' => []];
            $path = (string) parse_url($url, PHP_URL_PATH);
            return match ($path) {
                '/api/v10/guilds/900/channels' => $json([['id' => '11', 'type' => 0, 'name' => 'regeln', 'position' => 1]]),
                '/api/v10/guilds/900/roles' => $json([
                    ['id' => '900', 'name' => '@everyone', 'position' => 0, 'permissions' => '0'],
                    ['id' => '21', 'name' => 'Besucher', 'position' => 1, 'permissions' => '0'],
                    ['id' => '22', 'name' => 'Chef', 'position' => 9, 'permissions' => '0'],
                    ['id' => '23', 'name' => 'Bot', 'position' => 5, 'permissions' => (string) (1 << 28)],
                    ['id' => '24', 'name' => 'Integration', 'position' => 2, 'permissions' => '0', 'managed' => true],
                ]),
                '/api/v10/users/@me' => $json(['id' => '555']),
                '/api/v10/guilds/900/members/555' => $json(['user' => ['id' => '555'], 'roles' => ['23']]),
                '/api/v10/channels/11/messages' => $this->messageStatus === 200 ? $json(['id' => '4242']) : $json(['message' => 'Missing Access'], $this->messageStatus),
                default => throw new \LogicException("Unerwarteter HTTP-Aufruf: $method $url"),
            };
        });
    }

    /** @param array<string,mixed> $post */
    private function publish(array $post, string $as = 'admin'): Response
    {
        return App::handle(new Request('POST', '/o/alpha/bot/rules/publish', [], $post + ['_csrf' => $this->u[$as]['csrf']], [], [Auth::COOKIE => $this->u[$as]['token']], ''));
    }

    /** @return list<array{0:string,1:string,2:?string}> */
    private function sent(): array
    {
        return array_values(array_filter($this->calls, static fn ($c) => $c[0] === 'POST'));
    }

    public function testMessageCarriesTheRoleInTheButton(): void
    {
        $m = DiscordRules::message('', 'Text', '21');
        $this->assertSame('Serverregeln', $m['embeds'][0]['title']);
        $this->assertSame('rules:ok:21', $m['components'][0]['components'][0]['custom_id']);
        $this->assertSame('Bestätigen', $m['components'][0]['components'][0]['label']);
    }

    public function testPagePreselectsRulesChannelAndVisitorRole(): void
    {
        $page = App::handle(new Request('GET', '/o/alpha/bot', ['m' => 'regeln'], [], [], [Auth::COOKIE => $this->u['admin']['token']], ''));
        $this->assertSame(200, $page->status);
        $this->assertStringContainsString('<option value="11" selected># regeln</option>', $page->body);
        $this->assertStringContainsString('<option value="21" selected>@Besucher</option>', $page->body);
        $this->assertStringContainsString('Regeln veröffentlichen', $page->body);
    }

    public function testPublishPostsTheMessage(): void
    {
        $res = $this->publish(['channel' => '11', 'role' => '21', 'title' => 'Regeln', 'text' => "Eins\r\nZwei"]);
        $this->assertSame(303, $res->status);
        $sent = $this->sent();
        $this->assertCount(1, $sent);
        $payload = json_decode((string) $sent[0][2], true);
        $this->assertSame("Eins\nZwei", $payload['embeds'][0]['description']);
        $this->assertSame('rules:ok:21', $payload['components'][0]['components'][0]['custom_id']);
    }

    public function testRefusesWhatTheBotCannotDo(): void
    {
        $this->publish(['channel' => '11', 'role' => '21', 'text' => '']);     // kein Text
        $this->publish(['channel' => '99', 'role' => '21', 'text' => 'x']);    // Kanal unbekannt
        $this->publish(['channel' => '11', 'role' => '900', 'text' => 'x']);   // @everyone
        $this->publish(['channel' => '11', 'role' => '24', 'text' => 'x']);    // Integration
        $this->publish(['channel' => '11', 'role' => '22', 'text' => 'x']);    // Rolle über dem Bot
        $this->publish(['channel' => '11', 'role' => '777', 'text' => 'x']);   // unbekannte Rolle
        $this->publish(['channel' => '11', 'role' => '21', 'text' => str_repeat('a', 4001)]);
        $this->assertSame([], $this->sent());

        $this->messageStatus = 403;   // Bot darf im Kanal nicht schreiben
        $this->publish(['channel' => '11', 'role' => '21', 'text' => 'x']);
        $this->assertCount(1, $this->sent());
    }

    public function testOnlyAdmins(): void
    {
        $this->assertSame(404, $this->publish(['channel' => '11', 'role' => '21', 'text' => 'x'], 'member')->status);
        $this->assertSame([], $this->sent());
    }
}
