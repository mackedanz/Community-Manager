<?php

declare(strict_types=1);

namespace Hangar\Tests;

use Hangar\App;
use Hangar\Auth;
use Hangar\BotSettings;
use Hangar\Db;
use Hangar\DiscordSetup;
use Hangar\Env;
use Hangar\Http\Client;
use Hangar\Http\Request;
use Hangar\Http\Response;
use Hangar\OrgError;
use Hangar\Time;

/** Discord-Setup im Web: Eingabe prüfen, Rechte berechnen, Kategorien und Kanäle über die Discord-Schnittstelle anlegen. */
final class DiscordSetupTest extends DbTestCase
{
    private string $orgId;
    /** @var array<string,array{token:string,csrf:string}> */
    private array $u = [];
    /** @var list<array<string,mixed>> Anlegeaufrufe an Discord */
    private array $created = [];
    private int $createStatus = 200;
    private int $createFailAt = 0;

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
        $this->createStatus = 200;
        $this->createFailAt = 0;
        Client::fake(function (string $method, string $url, array $h, ?string $body): array {
            $json = fn (mixed $b, int $s = 200): array => ['status' => $s, 'body' => json_encode($b), 'headers' => []];
            if ($method === 'POST' && str_ends_with($url, '/guilds/900/channels')) {
                if ($this->createFailAt > 0 && count($this->created) + 1 >= $this->createFailAt) {
                    return $json(['message' => 'Missing Permissions'], $this->createStatus);
                }
                $this->created[] = json_decode((string) $body, true);
                return $json(['id' => (string) (7000 + count($this->created))]);
            }
            if (str_ends_with($url, '/users/@me')) {
                return $json(['id' => '555']);
            }
            if (str_ends_with($url, '/guilds/900/channels')) {
                return $json([
                    ['id' => '10', 'type' => 4, 'name' => 'Info', 'position' => 1, 'permission_overwrites' => [
                        ['id' => '900', 'type' => 0, 'allow' => '0', 'deny' => '1024'],
                        ['id' => '20', 'type' => 0, 'allow' => '66560', 'deny' => '0'],
                    ]],
                    ['id' => '11', 'type' => 0, 'name' => '┋regeln', 'position' => 2, 'parent_id' => '10'],
                    ['id' => '13', 'type' => 2, 'name' => 'Lounge', 'position' => 3],
                ]);
            }
            if (str_ends_with($url, '/guilds/900/roles')) {
                return $json([
                    ['id' => '900', 'name' => '@everyone', 'position' => 0],
                    ['id' => '20', 'name' => 'Moderator', 'position' => 3],
                    ['id' => '21', 'name' => 'Besucher', 'position' => 1],
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

    private function postSetup(array $post, string $as = 'admin'): Response
    {
        return $this->call('POST', '/o/alpha/bot/setup', $as, $post);
    }

    private function flash(Response $r): string
    {
        foreach ($r->cookies as [$name, $value]) {
            if ($name === \Hangar\Http\Flash::COOKIE) {
                return (string) (json_decode($value, true)['m'] ?? '');
            }
        }
        return '';
    }

    public function testBaseNameIgnoresDecorationAndCase(): void
    {
        $this->assertSame('server-regeln', DiscordSetup::baseName('! -Server-Regeln-!'));
        $this->assertSame(DiscordSetup::baseName('┋Regeln'), DiscordSetup::baseName('regeln'));
        $this->assertSame('tips-und-tricks', DiscordSetup::baseName('Tips und Tricks'));
    }

    public function testPlanChecksTheWholeInputBeforeAnythingIsCreated(): void
    {
        $structure = ['categories' => [['id' => '10', 'name' => 'Info', 'overwrites' => [], 'channels' => [['id' => '11', 'name' => '┋regeln', 'type' => 0]]]], 'loose' => []];
        $plan = static fn (array $blocks, array $extra = []): array => DiscordSetup::plan($extra + ['blocks' => $blocks], $structure, ['20', '21']);
        $ch = static fn (string $name, array $extra = []): array => ['0' => $extra + ['name' => $name]];

        $ok = $plan([['target' => '', 'name' => 'Events', 'channels' => $ch('Planung')]]);
        $this->assertSame(1, $ok['categories']);
        $this->assertSame('┋Planung', $ok['blocks'][0]['channels'][0]['name']);

        $bad = [
            'Kategorie existiert' => [[['target' => '', 'name' => 'info', 'channels' => $ch('x')]], 'gibt es schon'],
            'Name fehlt' => [[['target' => '', 'name' => '', 'channels' => $ch('x')]], 'braucht einen Namen'],
            'Kanal doppelt' => [[['target' => '10', 'channels' => $ch('Regeln')]], 'gibt es in dieser Kategorie schon'],
            'Kategorie unbekannt' => [[['target' => '99', 'channels' => $ch('x')]], 'nicht mehr'],
            'Rolle unbekannt' => [[['target' => '', 'name' => 'A', 'access' => 'roles', 'roles' => ['77']]], 'nicht (mehr)'],
            'Rollen leer' => [[['target' => '', 'name' => 'A', 'access' => 'roles']], 'keine Rolle'],
            'Kanal-Rollen leer' => [[['target' => '', 'name' => 'A', 'channels' => $ch('x', ['access' => 'roles'])]], 'keine Rolle'],
            'Leer' => [[['target' => '', 'name' => '', 'channels' => $ch('')]], 'nichts zum Anlegen'],
        ];
        foreach ($bad as $label => [$blocks, $msg]) {
            try {
                $plan($blocks);
                $this->fail("$label: keine Fehlermeldung");
            } catch (OrgError $e) {
                $this->assertStringContainsString($msg, $e->getMessage(), $label);
            }
        }
    }

    public function testPrefixIsAppliedOnceAndCanBeEmpty(): void
    {
        $structure = ['categories' => [], 'loose' => []];
        $p = DiscordSetup::plan(['prefix' => '┋', 'blocks' => [['target' => '-', 'channels' => [['name' => '┋schon'], ['name' => 'neu']]]]], $structure, []);
        $this->assertSame(['┋schon', '┋neu'], array_column($p['blocks'][0]['channels'], 'name'));
        $p = DiscordSetup::plan(['prefix' => '', 'blocks' => [['target' => '-', 'channels' => [['name' => 'neu']]]]], $structure, []);
        $this->assertSame('neu', $p['blocks'][0]['channels'][0]['name']);
    }

    public function testCategoryForRolesHidesItFromEveryoneAndKeepsTheBot(): void
    {
        $o = DiscordSetup::categoryOverwrites(['mode' => 'roles', 'roles' => ['20']], '900', '555');
        $by = array_column($o, null, 'id');
        $this->assertSame((string) DiscordSetup::VIEW, $by['900']['deny']);
        $this->assertSame('0', $by['900']['allow']);
        $this->assertSame(0, (int) $by['20']['allow'] & ~(DiscordSetup::VIEW | DiscordSetup::HISTORY | DiscordSetup::CONNECT));
        $this->assertSame(1, $by['555']['type']);
        $this->assertSame([], DiscordSetup::categoryOverwrites(['mode' => 'all', 'roles' => []], '900', '555'));
    }

    public function testReadOnlyChannelTakesWritingFromEveryoneButNotFromTheBot(): void
    {
        $ch = ['type' => 0, 'access' => 'inherit', 'roles' => [], 'readonly' => true];
        $o = array_column(DiscordSetup::channelOverwrites($ch, [], '900', '555'), null, 'id');
        $this->assertSame((string) DiscordSetup::WRITE, $o['900']['deny']);
        $this->assertSame('0', $o['555']['deny']);
        $this->assertNotSame(0, (int) $o['555']['allow'] & DiscordSetup::SEND);

        // In einer privaten Kategorie: Sichtbarkeit bleibt, Schreiben entfällt auch für die erlaubten Rollen
        $base = [['id' => '900', 'type' => 0, 'allow' => '0', 'deny' => '1024'], ['id' => '20', 'type' => 0, 'allow' => '66560', 'deny' => '0']];
        $o = array_column(DiscordSetup::channelOverwrites($ch, $base, '900', '555'), null, 'id');
        $this->assertSame(DiscordSetup::VIEW | DiscordSetup::WRITE, (int) $o['900']['deny']);
        $this->assertSame(0, (int) $o['20']['allow'] & DiscordSetup::SEND);
        $this->assertNotSame(0, (int) $o['20']['deny'] & DiscordSetup::SEND);
    }

    public function testInheritCopiesTheCategoryAndVoiceNeedsNoBotRights(): void
    {
        $base = [['id' => '900', 'type' => 0, 'allow' => '0', 'deny' => '1024']];
        $inherit = ['type' => 0, 'access' => 'inherit', 'roles' => [], 'readonly' => false];
        $this->assertSame($base, array_values(array_filter(
            DiscordSetup::channelOverwrites($inherit, $base, '900', '555'),
            static fn ($o) => $o['id'] === '900',
        )));
        $voice = DiscordSetup::channelOverwrites(['type' => 2, 'access' => 'roles', 'roles' => ['21'], 'readonly' => true] , [], '900', '555');
        $this->assertNotContains('555', array_column($voice, 'id'));
        $this->assertNotSame(0, (int) array_column($voice, null, 'id')['21']['allow'] & DiscordSetup::CONNECT);
        $this->assertSame('0', array_column($voice, null, 'id')['900']['allow']);
        $this->assertSame([], DiscordSetup::channelOverwrites($inherit, [], '900', null));
    }

    public function testOnlyAdminsMaySetUp(): void
    {
        $this->assertSame(404, $this->postSetup(['blocks' => [['target' => '-', 'channels' => [['name' => 'x']]]]], 'member')->status);
        $this->assertSame([], $this->created);
        $this->assertSame(404, $this->call('GET', '/o/alpha/bot', 'member', [], ['m' => 'setup'])->status);
    }

    public function testSetupPageShowsServerStructureAndBlocksForm(): void
    {
        $body = $this->call('GET', '/o/alpha/bot', 'admin', [], ['m' => 'setup'])->body;
        $this->assertStringContainsString('Discord-Setup', $body);
        $this->assertStringContainsString('data-setup', $body);
        $this->assertStringContainsString('┋regeln', $body);
        $this->assertStringContainsString('Lounge', $body);
        $this->assertStringContainsString('name="_csrf"', $body);
        $this->assertStringContainsString('@Moderator', $body);
    }

    public function testCreatesCategoriesAndChannelsWithRights(): void
    {
        $res = $this->postSetup(['prefix' => '┋', 'blocks' => [
            ['target' => '', 'name' => 'Events', 'access' => 'roles', 'roles' => ['20'], 'channels' => [
                ['type' => 'text', 'name' => 'planung', 'topic' => 'Termine', 'access' => 'inherit', 'readonly' => '1'],
                ['type' => 'voice', 'name' => 'Treffpunkt', 'access' => 'inherit'],
            ]],
            ['target' => '10', 'channels' => [['type' => 'text', 'name' => 'news', 'access' => 'all']]],
        ]]);
        $this->assertSame('/o/alpha/bot?m=setup', $res->headers['Location']);
        $this->assertStringContainsString('Angelegt: 1 Kategorien und 3 Kanäle', $this->flash($res));
        $this->assertCount(4, $this->created);

        [$cat, $text, $voice, $news] = $this->created;
        $this->assertSame(4, $cat['type']);
        $this->assertSame('Events', $cat['name']);
        $this->assertSame('7001', $text['parent_id']);
        $this->assertSame('7001', $voice['parent_id']);
        $this->assertSame('10', $news['parent_id']);
        $this->assertSame('┋planung', $text['name']);
        $this->assertSame('Termine', $text['topic']);
        $this->assertSame(2, $voice['type']);
        $this->assertArrayNotHasKey('topic', $voice);
        // Text: sichtbar nur für die Rolle (aus der Kategorie kopiert) und ohne Schreibrecht
        $ow = array_column($text['permission_overwrites'], null, 'id');
        $this->assertSame((string) DiscordSetup::VIEW, (string) ((int) $ow['900']['deny'] & DiscordSetup::VIEW));
        $this->assertNotSame(0, (int) $ow['20']['deny'] & DiscordSetup::SEND);
        $this->assertSame(1, $ow['555']['type']);
        // „alle“ in einer privaten Kategorie: ausdrücklich für @everyone sichtbar
        $this->assertSame((string) (DiscordSetup::VIEW | DiscordSetup::HISTORY), array_column($news['permission_overwrites'], null, 'id')['900']['allow']);
    }

    public function testNothingIsCreatedWhenTheInputIsWrong(): void
    {
        $res = $this->postSetup(['blocks' => [
            ['target' => '', 'name' => 'Gut', 'channels' => [['name' => 'a']]],
            ['target' => '', 'name' => 'Info', 'channels' => [['name' => 'b']]],
        ]]);
        $this->assertStringContainsString('gibt es schon', $this->flash($res));
        $this->assertSame([], $this->created);
    }

    public function testDiscordFailureReportsWhatWasCreated(): void
    {
        $this->createFailAt = 3;
        $this->createStatus = 403;
        $res = $this->postSetup(['blocks' => [['target' => '', 'name' => 'Neu', 'channels' => [['name' => 'a'], ['name' => 'b']]]]]);
        $m = $this->flash($res);
        $this->assertStringContainsString('Bis dahin angelegt: 1 Kategorien, 1 Kanäle', $m);
        $this->assertStringContainsString('Kanäle verwalten', $m);
        $this->assertCount(2, $this->created);
    }

    public function testAutomodNoticeFollowsTheBotsOwnReport(): void
    {
        $page = fn (): string => $this->call('GET', '/o/alpha/bot', 'admin', [], ['m' => 'automod'])->body;
        $this->assertStringContainsString('arbeitet nur, wenn der Bot', $page());
        BotSettings::set($this->orgId, BotSettings::MESSAGE_CONTENT_STATUS, '0');
        $this->assertStringContainsString('arbeitet nur, wenn der Bot', $page());
        BotSettings::set($this->orgId, BotSettings::MESSAGE_CONTENT_STATUS, '1');
        $this->assertStringNotContainsString('arbeitet nur, wenn der Bot', $page());
        $this->assertStringContainsString('Der Bot liest Nachrichten mit', $page());
    }
}
