<?php

declare(strict_types=1);

namespace Hangar\Tests;

use Hangar\App;
use Hangar\Auth;
use Hangar\Db;
use Hangar\DiscordBot;
use Hangar\DiscordRoles;
use Hangar\Env;
use Hangar\Http\Client;
use Hangar\Http\Flash;
use Hangar\Http\Request;
use Hangar\Http\Response;
use Hangar\OrgError;
use Hangar\Time;

/** Rollen und Rechte im Web: Eingabe prüfen, Rechte des Bots beachten, Rollen über die Discord-Schnittstelle anlegen. */
final class DiscordRolesTest extends DbTestCase
{
    private string $orgId;
    /** @var array<string,array{token:string,csrf:string}> */
    private array $u = [];
    /** @var list<array<string,mixed>> */
    private array $created = [];
    private int $failAt = 0;
    private int $botPerms = 0;

    private function bit(int ...$bits): int
    {
        return array_reduce($bits, static fn (int $c, int $b) => $c | (1 << $b), 0);
    }

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
        Env::set('DISCORD_BOT_TOKEN', 'bot-token');
        $this->created = [];
        $this->failAt = 0;
        // Der Bot hat Ansehen, Senden, Nachrichten verwalten und Kanäle verwalten (über seine Rolle 20)
        $this->botPerms = $this->bit(10, 11, 13, 4);
        Client::fake(function (string $method, string $url, array $h, ?string $body): array {
            $json = fn (mixed $b, int $s = 200): array => ['status' => $s, 'body' => json_encode($b), 'headers' => []];
            if ($method === 'POST' && str_ends_with($url, '/guilds/900/roles')) {
                if ($this->failAt > 0 && count($this->created) + 1 >= $this->failAt) {
                    return $json(['message' => 'Missing Permissions'], 403);
                }
                $this->created[] = json_decode((string) $body, true);
                return $json(['id' => (string) (8000 + count($this->created))]);
            }
            if (str_ends_with($url, '/users/@me')) {
                return $json(['id' => '555']);
            }
            if (str_ends_with($url, '/guilds/900/members/555')) {
                return $json(['roles' => ['20']]);
            }
            if (str_ends_with($url, '/guilds/900/channels')) {
                return $json([['id' => '10', 'type' => 4, 'name' => 'Info', 'position' => 1], ['id' => '11', 'type' => 0, 'name' => 'regeln', 'position' => 2, 'parent_id' => '10']]);
            }
            if (str_ends_with($url, '/guilds/900/roles')) {
                return $json([
                    ['id' => '900', 'name' => '@everyone', 'position' => 0, 'permissions' => (string) $this->bit(10)],
                    ['id' => '20', 'name' => 'Community-Manager', 'position' => 5, 'managed' => true, 'permissions' => (string) $this->botPerms],
                    ['id' => '21', 'name' => 'Pilot', 'position' => 2, 'color' => 255, 'permissions' => (string) $this->bit(10, 11, 1)],
                ]);
            }
            throw new \LogicException("Unerwarteter HTTP-Aufruf: $method $url");
        });
    }

    /** @param array<string,mixed> $post */
    private function call(string $method, string $path, string $as, array $post = [], array $query = []): Response
    {
        if ($method === 'POST') {
            $post += ['_csrf' => $this->u[$as]['csrf']];
        }
        return App::handle(new Request($method, $path, $query, $post, [], [Auth::COOKIE => $this->u[$as]['token']], ''));
    }

    private function create(array $roles, string $as = 'admin'): Response
    {
        return $this->call('POST', '/o/alpha/bot/roles', $as, ['roles' => $roles]);
    }

    private function flash(Response $r): string
    {
        foreach ($r->cookies as [$name, $value]) {
            if ($name === Flash::COOKIE) {
                return (string) (json_decode($value, true)['m'] ?? '');
            }
        }
        return '';
    }

    public function testColorAndLabels(): void
    {
        $this->assertSame(0, DiscordRoles::color(''));
        $this->assertSame(0x3366ff, DiscordRoles::color('#3366FF'));
        $this->expectException(OrgError::class);
        DiscordRoles::color('blau');
    }

    public function testLabelsListCatalogRightsAndAdministratorAlone(): void
    {
        $this->assertSame(['Kanäle ansehen', 'Nachrichten senden'], DiscordRoles::labels($this->bit(10, 11)));
        $this->assertSame(['Administrator'], DiscordRoles::labels($this->bit(3, 10)));
    }

    public function testPresetsOnlyUseCatalogRights(): void
    {
        foreach (DiscordRoles::PRESETS as $title => $bits) {
            foreach ($bits as $bit) {
                $this->assertArrayHasKey($bit, DiscordRoles::PERMISSIONS, $title);
            }
        }
        foreach ([3, 5, 28, 29] as $dangerous) {
            $this->assertArrayNotHasKey($dangerous, DiscordRoles::PERMISSIONS);
        }
    }

    public function testPlanValidatesEverythingBeforeAnythingIsCreated(): void
    {
        $existing = [['id' => '900', 'name' => '@everyone'], ['id' => '21', 'name' => 'Pilot']];
        $plan = static fn (array $roles, ?int $bot = -1): array => DiscordRoles::plan(['roles' => $roles], $existing, $bot);

        $ok = $plan([['name' => ' Navigator ', 'color' => '#ff0000', 'hoist' => '1', 'perms' => ['10', '11']], ['name' => '']]);
        $this->assertCount(1, $ok);
        $this->assertSame(['name' => 'Navigator', 'color' => 0xff0000, 'permissions' => (1 << 10) | (1 << 11), 'hoist' => true, 'mentionable' => false], $ok[0]);

        $bad = [
            'Name vorhanden' => [[['name' => 'pilot']], -1, 'gibt es schon'],
            'everyone' => [[['name' => 'everyone']], -1, 'gibt es schon'],
            'doppelt' => [[['name' => 'A'], ['name' => 'a']], -1, 'gibt es schon'],
            'Recht unbekannt' => [[['name' => 'A', 'perms' => ['3']]], -1, 'Unbekanntes Recht'],
            'Recht kein Zahl' => [[['name' => 'A', 'perms' => ['x']]], -1, 'Unbekanntes Recht'],
            'Bot hat Recht nicht' => [[['name' => 'A', 'perms' => ['1']]], $this->bit(10), 'Mitglieder kicken'],
            'Farbe' => [[['name' => 'A', 'color' => 'rot']], -1, 'keine Farbe'],
            'leer' => [[['name' => '']], -1, 'keine Rolle'],
        ];
        foreach ($bad as $label => [$roles, $bot, $msg]) {
            try {
                $plan($roles, $bot);
                $this->fail("$label: keine Fehlermeldung");
            } catch (OrgError $e) {
                $this->assertStringContainsString($msg, $e->getMessage(), $label);
            }
        }
        // Rechte des Bots unbekannt: Discord entscheidet
        $this->assertCount(1, $plan([['name' => 'A', 'perms' => ['1']]], null));
    }

    public function testBotPermissionsCombineItsRolesAndEveryoneAndAdminMeansAll(): void
    {
        $roles = DiscordBot::roleDetails('900');
        $this->assertSame($this->bit(10, 11, 13, 4), DiscordBot::botPermissions('900', $roles));
        $this->botPerms = $this->bit(3);
        $this->assertSame(-1, DiscordBot::botPermissions('900', DiscordBot::roleDetails('900')));
    }

    public function testOnlyAdminsMayCreateRoles(): void
    {
        $this->assertSame(404, $this->create([['name' => 'X']], 'member')->status);
        $this->assertSame(404, $this->call('GET', '/o/alpha/bot', 'member', [], ['m' => 'rollen'])->status);
        $this->assertSame([], $this->created);
    }

    public function testPageListsRolesAndLocksRightsTheBotLacks(): void
    {
        $body = $this->call('GET', '/o/alpha/bot', 'admin', [], ['m' => 'rollen'])->body;
        $this->assertStringContainsString('Rollen und Rechte', $body);
        $this->assertStringContainsString('Pilot', $body);
        $this->assertStringContainsString('Kanäle ansehen, Nachrichten senden, Mitglieder kicken', $body);
        $this->assertStringContainsString('data-roles-form', $body);
        $this->assertStringContainsString('Gast (lesen und zuhören)', $body);
        // Kicken (Bit 1) kann der Bot nicht vergeben, Senden (Bit 11) schon
        $this->assertMatchesRegularExpression('/value="1" class="accent-cyan-400" disabled/', $body);
        $this->assertDoesNotMatchRegularExpression('/value="11" class="accent-cyan-400" disabled/', $body);
    }

    public function testCreatesRolesWithRightsColorAndFlags(): void
    {
        $res = $this->create([
            ['name' => 'Navigator', 'color' => '#00ff80', 'hoist' => '1', 'mentionable' => '1', 'perms' => ['10', '11', '13']],
            ['name' => 'Gast', 'perms' => ['10']],
        ]);
        $this->assertSame('/o/alpha/bot?m=rollen', $res->headers['Location']);
        $this->assertStringContainsString('Angelegt: 2 Rollen', $this->flash($res));
        $this->assertSame([
            ['name' => 'Navigator', 'permissions' => (string) $this->bit(10, 11, 13), 'color' => 0x00ff80, 'hoist' => true, 'mentionable' => true],
            ['name' => 'Gast', 'permissions' => (string) $this->bit(10), 'color' => 0, 'hoist' => false, 'mentionable' => false],
        ], $this->created);
    }

    public function testNothingIsCreatedWhenOneRoleIsWrong(): void
    {
        $res = $this->create([['name' => 'Gut'], ['name' => 'Pilot']]);
        $this->assertStringContainsString('gibt es schon', $this->flash($res));
        $res = $this->create([['name' => 'Kicker', 'perms' => ['1']]]);
        $this->assertStringContainsString('selbst nicht', $this->flash($res));
        $this->assertSame([], $this->created);
    }

    public function testDiscordFailureReportsWhatWasCreated(): void
    {
        $this->failAt = 2;
        $m = $this->flash($this->create([['name' => 'Eins'], ['name' => 'Zwei']]));
        $this->assertStringContainsString('Bis dahin angelegt: 1 Rollen', $m);
        $this->assertStringContainsString('Rollen verwalten', $m);
        $this->assertCount(1, $this->created);
    }
}
