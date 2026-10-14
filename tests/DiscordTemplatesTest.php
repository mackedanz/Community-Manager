<?php

declare(strict_types=1);

namespace Hangar\Tests;

use Hangar\App;
use Hangar\Auth;
use Hangar\BotSettings;
use Hangar\Db;
use Hangar\DiscordBot;
use Hangar\DiscordSetup;
use Hangar\DiscordTemplates;
use Hangar\Env;
use Hangar\Http\Client;
use Hangar\Http\Flash;
use Hangar\Http\Request;
use Hangar\Http\Response;
use Hangar\OrgError;
use Hangar\Time;

/** Server-Vorlagen: Format, Aufnehmen vom Server, Plan (Vorschau), Anwenden und die Verwaltung der Liste. */
final class DiscordTemplatesTest extends DbTestCase
{
    private string $orgId;
    /** @var array<string,array{token:string,csrf:string}> */
    private array $u = [];
    /** @var list<array{0:string,1:string,2:?array<string,mixed>}> schreibende Aufrufe an Discord */
    private array $calls = [];
    /** @var list<array<string,mixed>> rohe Kanäle des nachgebauten Servers */
    private array $channels = [];
    /** @var list<array<string,mixed>> rohe Rollen des nachgebauten Servers */
    private array $roles = [];
    private int $failAt = 0;

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
        $this->calls = [];
        $this->failAt = 0;
        $this->channels = [];
        // Server mit @everyone und der Bot-Rolle (alle Rechte des Katalogs außer Kicken)
        $botPerms = DiscordTemplatesTest::catalogWithout(1);
        $this->roles = [
            ['id' => '900', 'name' => '@everyone', 'position' => 0, 'permissions' => (string) $this->bit(10)],
            ['id' => '20', 'name' => 'Community-Manager', 'position' => 9, 'managed' => true, 'permissions' => (string) $botPerms],
        ];
        Client::fake(function (string $method, string $url, array $h, ?string $body): array {
            $json = fn (mixed $b, int $s = 200): array => ['status' => $s, 'body' => json_encode($b), 'headers' => []];
            $path = (string) preg_replace('#^https://discord.com/api/v10#', '', $url);
            if ($method !== 'GET') {
                if ($method === 'POST' && $this->failAt > 0 && count($this->calls) + 1 >= $this->failAt) {
                    return $json(['message' => 'Missing Permissions'], 403);
                }
                $this->calls[] = [$method, $path, $body === null ? null : json_decode($body, true)];
                return $method === 'DELETE' ? ['status' => 204, 'body' => '', 'headers' => []] : $json(['id' => (string) (8000 + count($this->calls))]);
            }
            return match (true) {
                $path === '/users/@me' => $json(['id' => '555']),
                $path === '/guilds/900/members/555' => $json(['roles' => ['20']]),
                $path === '/guilds/900/channels' => $json($this->channels),
                $path === '/guilds/900/roles' => $json($this->roles),
                default => throw new \LogicException("Unerwarteter HTTP-Aufruf: $method $url"),
            };
        });
    }

    public static function catalogWithout(int ...$skip): int
    {
        $m = 0;
        foreach (array_keys(\Hangar\DiscordRoles::PERMISSIONS) as $bit) {
            if (!in_array($bit, $skip, true)) {
                $m |= 1 << $bit;
            }
        }
        return $m;
    }

    /** @param array<string,mixed> $post */
    private function call(string $method, string $path, string $as, array $post = [], array $query = []): Response
    {
        if ($method === 'POST') {
            $post += ['_csrf' => $this->u[$as]['csrf']];
        }
        return App::handle(new Request($method, $path, $query, $post, [], [Auth::COOKIE => $this->u[$as]['token']], ''));
    }

    private function post(string $what, array $post, string $as = 'admin'): string
    {
        $r = $this->call('POST', "/o/alpha/bot/$what", $as, $post);
        $this->assertSame(303, $r->status);
        foreach ($r->cookies as [$name, $value]) {
            if ($name === Flash::COOKIE) {
                return (string) (json_decode($value, true)['m'] ?? '');
            }
        }
        return '';
    }

    /** Schlüssel der vorgewählten Einträge der Vorschau. @return list<string> */
    private function checkedKeys(string $templateId): array
    {
        $html = $this->call('GET', '/o/alpha/bot', 'admin', [], ['m' => 'vorlagen', 't' => $templateId])->body;
        preg_match_all('/name="do\[\]" value="([0-9a-f]{12})" class="mt-1 accent-cyan-400" checked/', $html, $m);
        return $m[1];
    }

    private function ow(string $id, int $allow, int $deny, int $type = 0): array
    {
        return ['id' => $id, 'type' => $type, 'allow' => (string) $allow, 'deny' => (string) $deny];
    }

    /** Ein Server mit Rolle Pilot, privater Kategorie „Intern“ (nur Pilot) mit Kanal „┋team“ und einem Sprachkanal. */
    private function serverWithStructure(): void
    {
        $this->roles[] = ['id' => '21', 'name' => 'Pilot', 'position' => 2, 'color' => 255, 'hoist' => true, 'permissions' => (string) $this->bit(10, 11)];
        $view = DiscordSetup::VIEW | DiscordSetup::HISTORY;
        $private = [$this->ow('900', 0, DiscordSetup::VIEW), $this->ow('21', $view, 0), $this->ow('555', $view | DiscordSetup::SEND, 0, 1)];
        $this->channels = [
            ['id' => '10', 'type' => 4, 'name' => 'Intern', 'position' => 1, 'permission_overwrites' => $private],
            ['id' => '11', 'type' => 0, 'name' => '┋team', 'topic' => 'Alle zusammen', 'position' => 2, 'parent_id' => '10', 'permission_overwrites' => $private],
            ['id' => '12', 'type' => 0, 'name' => '┋news', 'position' => 3, 'parent_id' => '10', 'permission_overwrites' => array_merge(
                [$this->ow('900', 0, DiscordSetup::SEND), $this->ow('21', $view, DiscordSetup::SEND)],
                [$this->ow('555', $view | DiscordSetup::SEND, 0, 1)],
            )],
            ['id' => '13', 'type' => 2, 'name' => 'Lounge', 'position' => 4, 'permission_overwrites' => []],
            ['id' => '14', 'type' => 13, 'name' => 'Bühne', 'position' => 5, 'permission_overwrites' => []],
        ];
    }

    /** @return array{0:array<string,mixed>,1:array<string,mixed>,2:list<array<string,mixed>>,3:?array<string,int>} */
    private function live(): array
    {
        $structure = DiscordBot::structure('900');
        $details = DiscordBot::roleDetails('900');
        return [$structure, $details, $details, DiscordBot::botMember('900', $details)];
    }

    public function testBuiltinTemplatesAreValidAndCounted(): void
    {
        foreach (DiscordTemplates::builtin() as $key => $b) {
            $this->assertSame($b['template'], DiscordTemplates::normalize($b['template']), $key);
        }
        $c = DiscordTemplates::counts(DiscordTemplates::builtin()['standard']['template']);
        $this->assertSame(['roles' => 5, 'categories' => 9, 'channels' => 23], $c);
    }

    public function testNormalizeRejectsBrokenTemplates(): void
    {
        $ok = ['v' => 1, 'roles' => [['name' => 'A', 'perms' => [10]]], 'categories' => [['name' => 'K', 'channels' => [['name' => 'x', 'type' => 'text']]]]];
        $this->assertSame('A', DiscordTemplates::normalize($ok)['roles'][0]['name']);
        $bad = [
            'Version' => [['v' => 2] + $ok, 'gültige Vorlage'],
            'Rolle doppelt' => [['roles' => [['name' => 'A'], ['name' => 'a']]] + $ok, 'doppelt'],
            'everyone' => [['roles' => [['name' => 'everyone']]] + $ok, 'nicht erlaubt'],
            'Recht' => [['roles' => [['name' => 'A', 'perms' => [3]]]] + $ok, 'Unbekanntes Recht'],
            'Farbe' => [['roles' => [['name' => 'A', 'color' => -1]]] + $ok, 'Farbe'],
            'Art' => [['categories' => [['name' => 'K', 'channels' => [['name' => 'x', 'type' => 'forum']]]]] + $ok, 'Kanalart'],
            'Kanal doppelt' => [['categories' => [['name' => 'K', 'channels' => [['name' => 'x', 'type' => 'text'], ['name' => 'X', 'type' => 'text']]]]] + $ok, 'doppelt'],
            'Kategorie doppelt' => [['categories' => [['name' => 'K'], ['name' => 'k']]] + $ok, 'doppelt'],
            'kein Array' => [['roles' => 'x'] + $ok, 'fehlerhaft'],
            'ohne Name' => [['roles' => [['name' => ' ']]] + $ok, 'Name'],
        ];
        foreach ($bad as $label => [$tpl, $msg]) {
            try {
                DiscordTemplates::normalize($tpl);
                $this->fail("$label: keine Fehlermeldung");
            } catch (OrgError $e) {
                $this->assertStringContainsString($msg, $e->getMessage(), $label);
            }
        }
        $this->expectException(OrgError::class);
        DiscordTemplates::normalize('kein Array');
    }

    public function testCaptureReadsRolesVisibilityAndReadOnly(): void
    {
        $this->serverWithStructure();
        [$structure, $roles] = $this->live();
        $c = DiscordTemplates::capture($structure, $roles, '900');
        $t = $c['template'];
        $this->assertSame(1, $c['skipped'], 'Bühnenkanal wird nicht aufgenommen');
        $this->assertSame(['Pilot'], array_column($t['roles'], 'name'), 'ohne @everyone und Bot-Rolle');
        $this->assertSame([10, 11], $t['roles'][0]['perms']);
        $this->assertSame(255, $t['roles'][0]['color']);
        $this->assertSame(['mode' => 'roles', 'roles' => ['Pilot']], $t['categories'][0]['access']);
        [$team, $news] = $t['categories'][0]['channels'];
        $this->assertSame(['inherit', false, 'Alle zusammen'], [$team['access'], $team['readonly'], $team['topic']]);
        $this->assertSame(['all', true], [$news['access'], $news['readonly']], 'für alle sichtbar, aber nur lesen');
        $this->assertSame(['voice', 'all'], [$t['loose'][0]['type'], $t['loose'][0]['access']]);
    }

    public function testPlanOfACapturedServerIsEmpty(): void
    {
        $this->serverWithStructure();
        [$structure, $roles, , $bot] = $this->live();
        $tpl = DiscordTemplates::capture($structure, $roles, '900')['template'];
        $plan = DiscordTemplates::plan($tpl, $structure, $roles, $bot, '900');
        $this->assertSame([], array_column($plan['items'], 'label'));
        $this->assertSame([], $plan['warnings']);
    }

    public function testPlanOnAnEmptyServerCreatesEverythingAndWarnsAboutMissingBotRights(): void
    {
        [$structure, $roles, , $bot] = $this->live();
        $tpl = DiscordTemplates::builtin()['community']['template'];
        $plan = DiscordTemplates::plan($tpl, $structure, $roles, $bot, '900');
        $ops = array_count_values(array_column($plan['items'], 'op'));
        $this->assertSame(['role_create' => 2, 'cat_create' => 4, 'ch_create' => 10], $ops);
        $this->assertTrue(array_reduce($plan['items'], static fn ($c, $i) => $c && $i['default'], true), 'alles Neue ist vorgewählt');
        $this->assertCount(1, $plan['warnings'], 'der Bot hat „Mitglieder kicken“ nicht (Rolle Moderator)');
        $this->assertStringContainsString('Mitglieder kicken', $plan['warnings'][0]);
        $mod = array_values(array_filter($plan['items'], static fn ($i) => $i['op'] === 'role_create' && $i['role']['name'] === 'Moderator'))[0];
        $this->assertSame(0, $mod['role']['permissions'] & (1 << 1));
    }

    public function testPlanFindsChangesMovesExtrasAndLocksBotChannels(): void
    {
        $this->serverWithStructure();
        [$structure, $roles, , $bot] = $this->live();
        $tpl = DiscordTemplates::normalize([
            'v' => 1,
            'roles' => [['name' => 'Pilot', 'color' => 65280, 'hoist' => true, 'perms' => [10, 11]]],
            'categories' => [
                // Kategorie jetzt für alle sichtbar; Kanal „team“ darin, „lounge“ aus der Wurzel hierher verschieben
                ['name' => 'Intern', 'access' => ['mode' => 'all'], 'channels' => [['name' => '┋team', 'type' => 'text', 'topic' => 'Neu'], ['name' => 'Lounge', 'type' => 'voice']]],
            ],
        ]);
        $plan = DiscordTemplates::plan($tpl, $structure, $roles, $bot, '900', ['12' => 'Terminankündigungen']);
        $labels = array_column($plan['items'], 'label', 'op');
        $this->assertStringContainsString('Rolle „Pilot“ ändern: Farbe', $labels['role_update']);
        $this->assertStringContainsString('Sichtbarkeit', $labels['cat_update']);
        $byOp = [];
        foreach ($plan['items'] as $i) {
            $byOp[$i['op']][] = $i;
        }
        $this->assertCount(2, $byOp['ch_update'], 'team (Thema, Rechte) und Lounge (verschieben)');
        $this->assertStringContainsString('Kategorie (verschieben)', array_column($byOp['ch_update'], 'label')[1] . array_column($byOp['ch_update'], 'label')[0]);
        $del = $byOp['ch_delete'][0];
        $this->assertSame(['12', false], [$del['id'], $del['default']]);
        $this->assertSame('Der Bot nutzt ihn für: Terminankündigungen', $del['locked']);
    }

    public function testPlanSkipsWhatNeedsAMissingRole(): void
    {
        [$structure, $roles, , $bot] = $this->live();
        $tpl = DiscordTemplates::normalize(['v' => 1, 'categories' => [
            ['name' => 'Geheim', 'access' => ['mode' => 'roles', 'roles' => ['Phantom']], 'channels' => [['name' => 'x', 'type' => 'text']]],
            ['name' => 'Offen', 'access' => ['mode' => 'all'], 'channels' => [['name' => 'y', 'type' => 'text', 'access' => 'roles', 'roles' => ['Phantom']], ['name' => 'z', 'type' => 'text']]],
        ]]);
        $plan = DiscordTemplates::plan($tpl, $structure, $roles, $bot, '900');
        $this->assertCount(2, $plan['warnings']);
        $this->assertSame(['Kategorie „Offen“ anlegen (für alle sichtbar)', 'Kanal „z“ in „Offen“ anlegen (Text, für alle sichtbar)'], array_column($plan['items'], 'label'));
    }

    public function testSaveListExportImportAndDelete(): void
    {
        $this->serverWithStructure();
        $m = $this->post('template/save', ['name' => 'Unser Server', 'description' => 'Stand heute']);
        $this->assertStringContainsString('gespeichert (1 Rollen, 1 Kategorien, 3 Kanäle)', $m);
        $this->assertStringContainsString('1 Bühnen- oder Forenkanäle', $m);
        $this->assertStringContainsString('gibt es schon', $this->post('template/save', ['name' => 'unser server']));
        $this->assertStringContainsString('gibt es schon', $this->post('template/save', ['name' => 'Orga-Standard']));
        $this->assertStringContainsString('Namen', $this->post('template/save', ['name' => ' ']));

        $page = $this->call('GET', '/o/alpha/bot', 'admin', [], ['m' => 'vorlagen'])->body;
        foreach (['Unser Server', 'Stand heute', 'Orga-Standard', 'Kleine Community', 'mitgeliefert', '9 Kategorien'] as $text) {
            $this->assertStringContainsString($text, $page, $text);
        }

        $id = (string) Db::val('SELECT id FROM discord_templates WHERE org_id = ?', [$this->orgId]);
        $export = $this->call('GET', '/o/alpha/bot/template/export', 'admin', [], ['id' => $id]);
        $this->assertStringContainsString('attachment; filename="vorlage-unser-server.json"', $export->headers['Content-Disposition']);
        $json = json_decode($export->body, true);
        $this->assertSame('Pilot', $json['template']['roles'][0]['name']);

        $this->assertStringContainsString('Vorlage importiert', $this->post('template/import', ['name' => 'Kopie', 'json' => $export->body]));
        $this->assertSame(2, (int) Db::val('SELECT COUNT(*) FROM discord_templates WHERE org_id = ?', [$this->orgId]));
        $this->assertStringContainsString('kein gültiges JSON', $this->post('template/import', ['name' => 'X', 'json' => '{kaputt']));
        $this->assertStringContainsString('gültige Vorlage', $this->post('template/import', ['name' => 'Y', 'json' => '{"v":9}']));

        $this->assertStringContainsString('gelöscht', $this->post('template/delete', ['id' => $id]));
        $this->assertStringContainsString('nicht', $this->post('template/delete', ['id' => 'builtin:standard']));
        $this->assertSame(1, (int) Db::val('SELECT COUNT(*) FROM discord_templates WHERE org_id = ?', [$this->orgId]));
        $this->assertSame([], $this->calls, 'Speichern und Verwalten ändern den Server nicht');
    }

    public function testOnlyAdminsMayUseTemplates(): void
    {
        foreach (['template/save', 'template/import', 'template/delete', 'template/apply'] as $what) {
            $this->assertSame(404, $this->call('POST', "/o/alpha/bot/$what", 'member', ['name' => 'X', 'id' => 'builtin:standard'])->status, $what);
        }
        $this->assertSame(404, $this->call('GET', '/o/alpha/bot/template/export', 'member', [], ['id' => 'builtin:standard'])->status);
        $this->assertSame(404, $this->call('GET', '/o/alpha/bot', 'member', [], ['m' => 'vorlagen'])->status);
        $this->assertSame([], $this->calls);
    }

    public function testPreviewListsWhatWouldHappen(): void
    {
        $this->serverWithStructure();
        $html = $this->call('GET', '/o/alpha/bot', 'admin', [], ['m' => 'vorlagen', 't' => 'builtin:community'])->body;
        $this->assertStringContainsString('Vorlage „Kleine Community“ anwenden', $html);
        $this->assertStringContainsString('Rolle „Moderator“ anlegen', $html);
        $this->assertStringContainsString('Überflüssig (steht nicht in der Vorlage)', $html);
        $this->assertStringContainsString('Kanal „┋team“ (Kategorie Intern) löschen', $html);
        // Löschen ist nie vorgewählt
        preg_match_all('/<input type="checkbox" name="do\[\]" value="[0-9a-f]{12}" class="mt-1 accent-cyan-400" checked>\s*<span>([^<]*)/', $html, $m);
        foreach ($m[1] as $label) {
            $this->assertStringNotContainsString('löschen', $label);
        }
    }

    public function testApplyBuildsRolesCategoriesAndChannelsInOrderWithRights(): void
    {
        $keys = $this->checkedKeys('builtin:community');
        $this->assertCount(16, $keys);
        $m = $this->post('template/apply', ['id' => 'builtin:community', 'do' => $keys]);
        $this->assertStringContainsString('angewendet: Rollen 2 neu; Kategorien 4 neu; Kanäle 10 neu', $m);
        $this->assertCount(16, $this->calls);

        [$moderator, $member] = array_slice($this->calls, 0, 2);
        $this->assertSame('/guilds/900/roles', $moderator[1]);
        $this->assertSame('Moderator', $moderator[2]['name']);
        $this->assertSame(0x3498db, $moderator[2]['color']);
        $modId = '8001';

        $cats = array_values(array_filter($this->calls, static fn ($c) => $c[1] === '/guilds/900/channels' && ($c[2]['type'] ?? null) === 4));
        $this->assertSame(['Willkommen', 'Community', 'Sprache', 'Moderation'], array_column(array_column($cats, 2), 'name'));
        $modCat = array_column($cats[3][2]['permission_overwrites'], null, 'id');
        $this->assertSame((string) DiscordSetup::VIEW, $modCat['900']['deny'], 'Moderation: für @everyone unsichtbar');
        $this->assertArrayHasKey($modId, $modCat, 'sichtbar für die neu angelegte Rolle Moderator');

        $channels = array_values(array_filter($this->calls, static fn ($c) => $c[1] === '/guilds/900/channels' && ($c[2]['type'] ?? null) !== 4));
        $this->assertCount(10, $channels);
        $rules = $channels[0][2];
        $this->assertSame('┋regeln', $rules['name']);
        $this->assertSame('Bitte lesen', $rules['topic']);
        $catIds = array_map(fn ($c) => 8000 + array_search($c, $this->calls, true) + 1, $cats);
        $this->assertSame((string) $catIds[0], $rules['parent_id']);
        $this->assertNotSame(0, (int) array_column($rules['permission_overwrites'], null, 'id')['900']['deny'] & DiscordSetup::SEND, 'nur lesen');
        $lobby = array_values(array_filter($channels, static fn ($c) => $c[2]['name'] === '┋Lobby'))[0][2];
        $this->assertSame(2, $lobby['type']);
        $this->assertSame((string) $catIds[2], $lobby['parent_id']);
        // Rollen zuerst, Kategorien vor ihren Kanälen
        $this->assertSame(['/guilds/900/roles', '/guilds/900/roles'], [$this->calls[0][1], $this->calls[1][1]]);
    }

    public function testApplyChangesMovesAndDeletesOnlyWhatIsSelected(): void
    {
        $this->serverWithStructure();
        $tpl = ['v' => 1, 'roles' => [['name' => 'Pilot', 'color' => 65280, 'hoist' => true, 'perms' => [10, 11]]], 'categories' => [
            ['name' => 'Intern', 'access' => ['mode' => 'all'], 'channels' => [['name' => '┋team', 'type' => 'text', 'topic' => 'Alle zusammen'], ['name' => 'Lounge', 'type' => 'voice']]],
        ]];
        $name = 'Test';
        Db::insert('discord_templates', ['id' => 'tpl1', 'org_id' => $this->orgId, 'name' => $name, 'data' => json_encode($tpl)]);
        BotSettings::set($this->orgId, BotSettings::ANNOUNCE_CHANNEL, '12');
        $html = $this->call('GET', '/o/alpha/bot', 'admin', [], ['m' => 'vorlagen', 't' => 'tpl1'])->body;
        preg_match_all('/value="([0-9a-f]{12})" class="mt-1 accent-cyan-400"( checked)?( disabled)?>\s*<span>([^<]*)/', $html, $m, PREG_SET_ORDER);
        $this->assertNotEmpty($m);
        $delete = null;
        $locked = null;
        $all = [];
        foreach ($m as $row) {
            $all[$row[4]] = $row[1];
            if (str_contains($row[4], 'Kanal „┋news“')) {
                $locked = $row;
            }
            if (str_contains($row[4], 'Lounge') && str_contains($row[4], 'Kategorie')) {
                $delete = $row;
            }
        }
        $this->assertNotNull($locked);
        $this->assertSame(' disabled', $locked[3], 'vom Bot genutzter Kanal ist gesperrt');
        // Gewählt: alles Vorgewählte, zusätzlich der gesperrte (wird ignoriert)
        $selected = array_merge($this->checkedKeys('tpl1'), [$locked[1]]);
        $r = $this->post('template/apply', ['id' => 'tpl1', 'do' => $selected]);
        $this->assertStringContainsString('Vorlage „Test“ angewendet', $r);
        $this->assertNotContains('DELETE', array_column($this->calls, 0), 'nichts Überflüssiges gelöscht');
        $methods = array_count_values(array_column($this->calls, 0));
        $this->assertSame(['PATCH' => $methods['PATCH']], $methods);
        $byPath = array_column($this->calls, 2, 1);
        $this->assertSame(['color' => 65280], $byPath['/guilds/900/roles/21']);
        $this->assertArrayHasKey('/channels/10', $byPath, 'Kategorie wird für alle sichtbar');
        $this->assertSame([], $byPath['/channels/10']['permission_overwrites']);
        $this->assertSame('10', $byPath['/channels/13']['parent_id'], 'Lounge wird verschoben');

        // Löschen nur auf ausdrücklichen Haken
        $this->calls = [];
        $this->channels = array_merge($this->channels, [['id' => '15', 'type' => 0, 'name' => 'alt', 'position' => 6, 'parent_id' => '10', 'permission_overwrites' => []]]);
        preg_match('/value="([0-9a-f]{12})" class="mt-1 accent-cyan-400">\s*<span>Kanal „alt“/', $this->call('GET', '/o/alpha/bot', 'admin', [], ['m' => 'vorlagen', 't' => 'tpl1'])->body, $dm);
        $this->assertNotEmpty($dm);
        $this->assertStringContainsString('Kanäle 1 gelöscht', $this->post('template/apply', ['id' => 'tpl1', 'do' => [$dm[1]]]));
        $this->assertSame([['DELETE', '/channels/15', null]], $this->calls);
    }

    public function testApplyReportsWhatWasDoneWhenDiscordStops(): void
    {
        $keys = $this->checkedKeys('builtin:community');
        $this->failAt = 5;
        $m = $this->post('template/apply', ['id' => 'builtin:community', 'do' => $keys]);
        $this->assertStringContainsString('Bis dahin erledigt: Rollen 2 neu; Kategorien 2 neu', $m);
        $this->assertStringContainsString('gestoppt bei:', $m);
        $this->assertCount(4, $this->calls);
    }

    public function testApplyNeedsASelectionAndAKnownTemplate(): void
    {
        $this->assertStringContainsString('nichts ausgewählt', mb_strtolower($this->post('template/apply', ['id' => 'builtin:community'])));
        $this->assertStringContainsString('nicht mehr', $this->post('template/apply', ['id' => 'gibtsnicht', 'do' => ['abc']]));
        $this->assertSame([], $this->calls);
    }

    public function testMissingRoleOfASkippedItemStopsClearly(): void
    {
        // Rolle Moderator wird nicht mitgewählt, die Kategorie „Moderation“ braucht sie aber
        $html = $this->call('GET', '/o/alpha/bot', 'admin', [], ['m' => 'vorlagen', 't' => 'builtin:community'])->body;
        preg_match_all('/value="([0-9a-f]{12})" class="mt-1 accent-cyan-400" checked>\s*<span>([^<]*)/', $html, $m, PREG_SET_ORDER);
        $keys = [];
        foreach ($m as $row) {
            if (!str_contains($row[2], 'Rolle „Moderator“')) {
                $keys[] = $row[1];
            }
        }
        $msg = $this->post('template/apply', ['id' => 'builtin:community', 'do' => $keys]);
        $this->assertStringContainsString('Die Rolle „Moderator“ fehlt', $msg);
    }
}
