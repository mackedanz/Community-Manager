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
use Hangar\Http\Flash;
use Hangar\Http\Request;
use Hangar\Http\Response;
use Hangar\Time;

/** Vorhandene Rollen, Kanäle und Kategorien ändern und löschen: nur Geändertes senden, Grenzen des Bots beachten. */
final class DiscordEditTest extends DbTestCase
{
    private string $orgId;
    /** @var array<string,array{token:string,csrf:string}> */
    private array $u = [];
    /** @var list<array{0:string,1:string,2:?array<string,mixed>}> schreibende Aufrufe an Discord */
    private array $calls = [];

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
        $botPerms = $this->bit(10, 11, 13, 4);
        Client::fake(function (string $method, string $url, array $h, ?string $body) use ($botPerms): array {
            $json = fn (mixed $b, int $s = 200): array => ['status' => $s, 'body' => json_encode($b), 'headers' => []];
            $path = (string) preg_replace('#^https://discord.com/api/v10#', '', $url);
            if ($method !== 'GET') {
                $this->calls[] = [$method, $path, $body === null ? null : json_decode($body, true)];
                return ['status' => $method === 'DELETE' ? 204 : 200, 'body' => $method === 'DELETE' ? '' : '{}', 'headers' => []];
            }
            if ($path === '/users/@me') {
                return $json(['id' => '555']);
            }
            if ($path === '/guilds/900/members/555') {
                return $json(['roles' => ['20']]);
            }
            if ($path === '/guilds/900/channels') {
                return $json([
                    ['id' => '10', 'type' => 4, 'name' => 'Info', 'position' => 1, 'permission_overwrites' => [
                        ['id' => '900', 'type' => 0, 'allow' => '0', 'deny' => '1024'],
                        ['id' => '20', 'type' => 0, 'allow' => '66560', 'deny' => '0'],
                    ]],
                    ['id' => '11', 'type' => 0, 'name' => '┋regeln', 'topic' => 'Bitte lesen', 'position' => 2, 'parent_id' => '10'],
                    ['id' => '12', 'type' => 0, 'name' => '┋news', 'position' => 3, 'parent_id' => '10', 'permission_overwrites' => [
                        ['id' => '900', 'type' => 0, 'allow' => '0', 'deny' => '2048'],
                        ['id' => '21', 'type' => 0, 'allow' => '2048', 'deny' => '0'],
                    ]],
                    ['id' => '13', 'type' => 2, 'name' => 'Lounge', 'position' => 4],
                    ['id' => '30', 'type' => 4, 'name' => 'Events', 'position' => 5],
                ]);
            }
            if ($path === '/guilds/900/roles') {
                return $json([
                    ['id' => '900', 'name' => '@everyone', 'position' => 0, 'permissions' => (string) $this->bit(10)],
                    ['id' => '20', 'name' => 'Community-Manager', 'position' => 5, 'managed' => true, 'permissions' => (string) $botPerms],
                    ['id' => '21', 'name' => 'Pilot', 'position' => 2, 'color' => 255, 'permissions' => (string) $this->bit(10, 11, 1)],
                    ['id' => '22', 'name' => 'Chef', 'position' => 7, 'permissions' => (string) $this->bit(10)],
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

    public function testRoleUpdateSendsOnlyWhatChanged(): void
    {
        // Kicken (Bit 1) hat die Rolle, der Bot nicht: bleibt, auch wenn das Kästchen gesperrt (nicht gesendet) ist
        $m = $this->post('role/update', ['id' => '21', 'name' => 'Pilot', 'usecolor' => '1', 'color' => '#0000ff', 'hoist' => '1', 'perms' => ['10', '11']]);
        $this->assertStringContainsString('„Pilot“ geändert', $m);
        $this->assertSame([['PATCH', '/guilds/900/roles/21', ['hoist' => true]]], $this->calls);
    }

    public function testRoleUpdateChangesRightsNameAndColorButKeepsRightsTheBotLacks(): void
    {
        $this->post('role/update', ['id' => '21', 'name' => 'Navigator', 'perms' => ['10', '13']]);
        $this->assertSame('PATCH', $this->calls[0][0]);
        $this->assertSame([
            'name' => 'Navigator',
            'color' => 0,
            'permissions' => (string) $this->bit(10, 13, 1),
        ], $this->calls[0][2]);
    }

    public function testRoleUpdateAndDeleteRefuseWhatTheBotCannotTouch(): void
    {
        foreach (['22' => 'nicht ändern', '20' => 'nicht ändern', '900' => 'nicht ändern', '99' => 'nicht mehr'] as $key => $msg) {
            $id = (string) $key; // Zahlen als Schlüssel macht PHP zu int
            $this->assertStringContainsString($msg, $this->post('role/update', ['id' => $id, 'name' => 'X']), "update $id");
            $this->assertStringContainsString($msg, $this->post('role/delete', ['id' => $id]), "delete $id");
        }
        $this->assertSame([], $this->calls);
    }

    public function testRoleUpdateChecksNameAndChange(): void
    {
        $this->assertStringContainsString('gibt es schon', $this->post('role/update', ['id' => '21', 'name' => 'chef']));
        $this->assertStringContainsString('gibt es schon', $this->post('role/update', ['id' => '21', 'name' => 'everyone']));
        $this->assertStringContainsString('1 bis', $this->post('role/update', ['id' => '21', 'name' => ' ']));
        $this->assertStringContainsString('Unbekanntes Recht', $this->post('role/update', ['id' => '21', 'name' => 'Pilot', 'perms' => ['3']]));
        $this->assertStringContainsString('Nichts geändert', $this->post('role/update', ['id' => '21', 'name' => 'Pilot', 'usecolor' => '1', 'color' => '#0000ff', 'perms' => ['10', '11']]));
        $this->assertSame([], $this->calls);
    }

    public function testRoleDelete(): void
    {
        $this->assertStringContainsString('„Pilot“ gelöscht', $this->post('role/delete', ['id' => '21']));
        $this->assertSame([['DELETE', '/guilds/900/roles/21', null]], $this->calls);
    }

    public function testChannelRenameTopicAndMove(): void
    {
        $m = $this->post('channel/update', ['id' => '11', 'name' => '┋hausordnung', 'topic' => 'Neu', 'parent' => '30']);
        $this->assertStringContainsString('geändert', $m);
        $this->assertEquals([['PATCH', '/channels/11', ['name' => '┋hausordnung', 'topic' => 'Neu', 'parent_id' => '30']]], $this->calls);

        $this->calls = [];
        $this->post('channel/update', ['id' => '11', 'name' => '┋regeln', 'topic' => 'Bitte lesen', 'parent' => '-']);
        $this->assertSame([['PATCH', '/channels/11', ['parent_id' => null]]], $this->calls);
    }

    public function testChannelChecksNamesAndNothingChanged(): void
    {
        $this->assertStringContainsString('gibt es in dieser Kategorie schon', $this->post('channel/update', ['id' => '11', 'name' => 'news']));
        $this->assertStringContainsString('Nichts geändert', $this->post('channel/update', ['id' => '11', 'name' => '┋regeln', 'topic' => 'Bitte lesen']));
        $this->assertStringContainsString('nicht mehr', $this->post('channel/update', ['id' => '99', 'name' => 'x']));
        $this->assertStringContainsString('keine Rolle', $this->post('channel/update', ['id' => '11', 'name' => '┋regeln', 'access' => 'roles']));
        $this->assertStringContainsString('Kategorie gibt es', $this->post('channel/update', ['id' => '11', 'name' => '┋regeln', 'parent' => '77']));
        $this->assertSame([], $this->calls);
    }

    public function testChannelVisibilityReplacesRightsAndKeepsBotAccess(): void
    {
        $this->post('channel/update', ['id' => '11', 'name' => '┋regeln', 'access' => 'roles', 'roles' => ['21']]);
        $ow = array_column($this->calls[0][2]['permission_overwrites'], null, 'id');
        $this->assertSame((string) DiscordSetup::VIEW, $ow['900']['deny']);
        $this->assertSame((string) (DiscordSetup::VIEW | DiscordSetup::HISTORY), $ow['21']['allow']);
        $this->assertSame(1, $ow['555']['type']);
    }

    public function testReadOnlyCanBeSwitchedOnAndOffWithoutLosingOtherRights(): void
    {
        // „news“ ist im Aufbau schon nur zum Lesen; das Ausschalten ändert, das erneute Einschalten kommt vom Server nicht neu an
        $this->post('channel/update', ['id' => '12', 'name' => '┋news', 'readonly' => '0']);
        $off = array_column($this->calls[0][2]['permission_overwrites'], null, 'id');
        $this->assertSame(0, (int) ($off['900']['deny'] ?? 0) & DiscordSetup::SEND);
        $this->assertSame(DiscordSetup::SEND, (int) $off['21']['allow']);

        $on = array_column(DiscordSetup::readonlyOverwrites($this->calls[0][2]['permission_overwrites'], true, '900', '555'), null, 'id');
        $this->assertSame(DiscordSetup::SEND, (int) $on['900']['deny'] & DiscordSetup::SEND);
        $this->assertSame(0, (int) $on['21']['allow'] & DiscordSetup::SEND);
    }

    public function testFormShowsTheCurrentStateAndSendingItChangesNoRights(): void
    {
        $page = $this->call('GET', '/o/alpha/bot', 'admin', [], ['m' => 'setup'])->body;
        // Kategorie „Info“: nur bestimmte Rollen; Kanal „news“: für alle, nur lesen; Kanal „regeln“: in Kategorie Info
        $this->assertStringContainsString('<option value="roles" selected>nur bestimmte Rollen</option>', $page);
        $this->assertStringContainsString('<option value="1" selected>ja (nur Bot und Admins schreiben)</option>', $page);
        $this->assertStringContainsString('<option value="10" selected>Info</option>', $page);
        $this->assertStringContainsString('<option value="-" selected>Ohne Kategorie</option>', $page);

        // Das Formular schickt den Stand mit; nur der neue Name ändert etwas, die Rechte bleiben unberührt.
        $this->post('channel/update', ['id' => '12', 'name' => '┋neuigkeiten', 'parent' => '10', 'access' => 'all', 'readonly' => '1']);
        $this->assertSame([['PATCH', '/channels/12', ['name' => '┋neuigkeiten']]], $this->calls);

        // Kategorie, deren Rollen nur die Rolle des Bots sind: Umbenennen darf nicht an „keine Rolle angekreuzt“ scheitern.
        $this->calls = [];
        $this->post('channel/update', ['id' => '10', 'name' => 'Information', 'access' => 'roles']);
        $this->assertSame([['PATCH', '/channels/10', ['name' => 'Information']]], $this->calls);

        // Eine echte Änderung ersetzt die Rechte weiterhin.
        $this->calls = [];
        $this->post('channel/update', ['id' => '12', 'name' => '┋news', 'parent' => '10', 'access' => 'roles', 'roles' => ['21'], 'readonly' => '1']);
        $this->assertArrayHasKey('permission_overwrites', $this->calls[0][2]);
    }

    public function testCategoryRenameVisibilityAndDuplicates(): void
    {
        $this->assertStringContainsString('gibt es schon', $this->post('channel/update', ['id' => '10', 'name' => 'events']));
        $this->post('channel/update', ['id' => '10', 'name' => 'Information', 'access' => 'all']);
        $this->assertSame([['PATCH', '/channels/10', ['name' => 'Information', 'permission_overwrites' => []]]], $this->calls);
    }

    public function testChannelDeleteClearsTheBotsSettingAndNamesIt(): void
    {
        BotSettings::set($this->orgId, BotSettings::RULES_CHANNEL, '11');
        BotSettings::set($this->orgId, BotSettings::WELCOME_CHANNEL, '12');
        $setup = $this->call('GET', '/o/alpha/bot', 'admin', [], ['m' => 'setup'])->body;
        $this->assertStringContainsString('Bot: Regeln', $setup);

        $m = $this->post('channel/delete', ['id' => '11']);
        $this->assertStringContainsString('Kanal „┋regeln“ gelöscht', $m);
        $this->assertStringContainsString('Der Bot nutzt ihn nicht mehr', $m);
        $this->assertSame([['DELETE', '/channels/11', null]], $this->calls);
        $this->assertSame(['welcome_channel_id' => '12'], array_intersect_key(BotSettings::all($this->orgId), ['rules_channel_id' => 1, 'welcome_channel_id' => 1]));
    }

    public function testCategoryDeleteAndUnknown(): void
    {
        $this->assertStringContainsString('Kategorie „Events“ gelöscht', $this->post('channel/delete', ['id' => '30']));
        $this->assertStringContainsString('nicht mehr', $this->post('channel/delete', ['id' => '99']));
        $this->assertSame([['DELETE', '/channels/30', null]], $this->calls);
    }

    public function testOnlyAdminsMayChangeOrDelete(): void
    {
        foreach (['role/update', 'role/delete', 'channel/update', 'channel/delete'] as $what) {
            $this->assertSame(404, $this->call('POST', "/o/alpha/bot/$what", 'member', ['id' => '21', 'name' => 'X'])->status, $what);
        }
        $this->assertSame([], $this->calls);
    }

    public function testPagesOfferEditingOnlyWhereTheBotMay(): void
    {
        $roles = $this->call('GET', '/o/alpha/bot', 'admin', [], ['m' => 'rollen'])->body;
        $this->assertStringContainsString('action="/o/alpha/bot/role/update"', $roles);
        $this->assertSame(1, substr_count($roles, 'action="/o/alpha/bot/role/update"'), 'nur Pilot ist änderbar');
        $this->assertStringContainsString('Steht über der Rolle des Bots', $roles);
        $this->assertStringContainsString('Gehört einem Bot oder einer Integration', $roles);

        $setup = $this->call('GET', '/o/alpha/bot', 'admin', [], ['m' => 'setup'])->body;
        $this->assertSame(5, substr_count($setup, 'action="/o/alpha/bot/channel/update"'));
        $this->assertSame(5, substr_count($setup, 'action="/o/alpha/bot/channel/delete"'));
        $this->assertStringContainsString('data-edit-access', $setup);
    }

    public function testRolesRightsPageShowsTheCurrentStateAndLocksWhatTheBotLacks(): void
    {
        $page = $this->call('GET', '/o/alpha/bot', 'admin', [], ['m' => 'rechte', 'c' => '12', 'r' => '21'])->body;
        $this->assertSame(200, $this->call('GET', '/o/alpha/bot', 'admin', [], ['m' => 'rechte', 'c' => '12', 'r' => '21'])->status);
        // „news“: Pilot darf senden (Bit 11); der Bot darf senden, aber nicht einbetten (Bit 14)
        $this->assertMatchesRegularExpression('/name="perm\[11\]"[^>]*>\s*<option value="">[^<]*<\/option>\s*<option value="allow" selected>/', $page);
        $this->assertMatchesRegularExpression('/name="perm\[14\]"[^>]*disabled/', $page);
        $this->assertDoesNotMatchRegularExpression('/name="perm\[11\]"[^>]*disabled/', $page);
        $this->assertStringContainsString('@Pilot', $page);
        $this->assertStringNotContainsString('Community-Manager', $page); // Bot-Rolle nicht änderbar
        // Sprachkanal: Sprach-Rechte, keine Text-Rechte
        $voice = $this->call('GET', '/o/alpha/bot', 'admin', [], ['m' => 'rechte', 'c' => '13'])->body;
        $this->assertStringContainsString('name="perm[20]"', $voice);
        $this->assertStringNotContainsString('name="perm[13]"', $voice);
        // Aus dem Bearbeiten-Formular verlinkt
        $this->assertStringContainsString('?m=rechte&amp;c=12', $this->call('GET', '/o/alpha/bot', 'admin', [], ['m' => 'setup'])->body);
        // Unbekannter Kanal: freundliche Seite
        $this->assertStringContainsString('gibt es nicht', $this->call('GET', '/o/alpha/bot', 'admin', [], ['m' => 'rechte', 'c' => '999'])->body);
    }

    public function testRolesRightsOnlyTouchesThatRolesEntryAndWhatTheBotMaySet(): void
    {
        // Pilot in „regeln“ (hat dort noch keinen Eintrag): senden erlauben, Nachrichten verwalten verbieten, Einbetten (Bot hat es nicht) wird ignoriert
        $m = $this->post('channel/rights', ['id' => '11', 'role' => '21', 'perm' => ['11' => 'allow', '13' => 'deny', '14' => 'allow']]);
        $this->assertStringContainsString('gespeichert', $m);
        $this->assertSame([['PUT', '/channels/11/permissions/21', ['allow' => (string) (1 << 11), 'deny' => (string) (1 << 13), 'type' => 0]]], $this->calls);

        // Alles auf „nicht festgelegt“ entfernt den Eintrag von Pilot in „news“ (dort steht nur Senden erlauben)
        $this->calls = [];
        $this->post('channel/rights', ['id' => '12', 'role' => '21', 'perm' => ['11' => '']]);
        $this->assertSame([['DELETE', '/channels/12/permissions/21', null]], $this->calls);
    }

    public function testRolesRightsKeepsUnknownBitsAndChecksInput(): void
    {
        // Bit 3 (Administrator) und Bit 14 stehen im Eintrag; nur Bit 11 darf der Bot ändern, der Rest bleibt
        $this->assertSame(
            ['allow' => (1 << 3) | (1 << 14) | (1 << 11), 'deny' => 0],
            (function (): array {
                $structure = ['categories' => [], 'loose' => [['id' => '5', 'name' => 'x', 'type' => 0, 'overwrites' => [['id' => '21', 'type' => 0, 'allow' => (string) ((1 << 3) | (1 << 14)), 'deny' => '0']]]]];
                $roles = [['id' => '21', 'name' => 'Pilot', 'managed' => false, 'position' => 2]];
                $p = \Hangar\DiscordRights::plan(['id' => '5', 'role' => '21', 'perm' => ['11' => 'allow', '14' => 'deny']], $structure, $roles, ['permissions' => $this->bit(10, 11), 'top' => 5]);
                return ['allow' => $p['allow'], 'deny' => $p['deny']];
            })(),
        );

        // Ohne Änderung, mit unbekannter Rolle, unbekanntem Kanal, Bot-Rolle und als Mitglied
        $this->assertStringContainsString('Nichts geändert', $this->post('channel/rights', ['id' => '12', 'role' => '21', 'perm' => ['11' => 'allow']]));
        $this->assertStringContainsString('Rolle gibt es', $this->post('channel/rights', ['id' => '12', 'role' => '77', 'perm' => ['11' => 'allow']]));
        $this->assertStringContainsString('nicht mehr', $this->post('channel/rights', ['id' => '99', 'role' => '21', 'perm' => ['11' => 'allow']]));
        $this->assertStringContainsString('Bots und Integrationen', $this->post('channel/rights', ['id' => '12', 'role' => '20', 'perm' => ['11' => 'allow']]));
        $this->assertSame([], $this->calls);
        $this->assertSame(404, $this->call('POST', '/o/alpha/bot/channel/rights', 'member', ['id' => '12', 'role' => '21', 'perm' => ['11' => '']])->status);
        $this->assertSame([], $this->calls);
    }
}
