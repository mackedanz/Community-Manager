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
use Hangar\OrgError;
use Hangar\Time;

/** Einstellungen des Discord-Bots im Web: nur Orga-Admins, Kanäle und Rollen aus Discord, gleiche Regeln wie der Bot. */
final class BotSettingsTest extends DbTestCase
{
    private string $orgId;
    /** @var array<string,array{token:string,csrf:string}> */
    private array $u = [];
    private int $discordStatus = 200;

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
        Client::fake(function (string $method, string $url): array {
            $json = fn (mixed $b): array => ['status' => $this->discordStatus, 'body' => json_encode($b), 'headers' => []];
            if (str_ends_with($url, '/guilds/900/channels')) {
                return $json([
                    ['id' => '10', 'type' => 4, 'name' => 'Info', 'position' => 1],
                    ['id' => '11', 'type' => 0, 'name' => 'regeln', 'position' => 2, 'parent_id' => '10'],
                    ['id' => '12', 'type' => 0, 'name' => 'willkommen', 'position' => 1, 'parent_id' => '10'],
                    ['id' => '13', 'type' => 2, 'name' => 'Lounge', 'position' => 3],
                    ['id' => '14', 'type' => 5, 'name' => 'news', 'position' => 0],
                ]);
            }
            if (str_ends_with($url, '/users/@me')) {
                return $json(['id' => '555']);
            }
            if (str_ends_with($url, '/guilds/900/members/555')) {
                return $json(['roles' => ['20']]);
            }
            if (str_ends_with($url, '/guilds/900/roles')) {
                return $json([
                    ['id' => '900', 'name' => '@everyone', 'position' => 0],
                    ['id' => '20', 'name' => 'Moderator', 'position' => 3],
                    ['id' => '21', 'name' => 'Besucher', 'position' => 1],
                    ['id' => '22', 'name' => 'Bot', 'position' => 5, 'managed' => true],
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

    private function save(string $section, array $post): Response
    {
        return $this->call('POST', '/o/alpha/bot/save', 'admin', ['section' => $section] + $post);
    }

    public function testOnlyAdminsSeeThePageAndTheTile(): void
    {
        $this->assertSame(404, $this->call('GET', '/o/alpha/bot', 'member')->status);
        $this->assertSame(404, $this->call('POST', '/o/alpha/bot/save', 'member', ['section' => 'termine', 'announceChannel' => '11'])->status);
        $this->assertSame([], BotSettings::all($this->orgId));
        $this->assertStringNotContainsString('/o/alpha/bot', $this->call('GET', '/o/alpha', 'member')->body);

        $this->assertStringContainsString('href="/o/alpha/bot"', $this->call('GET', '/o/alpha', 'admin')->body);
        foreach (array_keys(BotSettings::SECTIONS) as $m) {
            $this->assertSame(200, $this->call('GET', '/o/alpha/bot', 'admin', [], ['m' => $m])->status, $m);
        }
    }

    public function testModeratorsSeeModerationAndAutomodReadOnly(): void
    {
        Db::run("UPDATE org_memberships SET can_moderate = 1 WHERE org_id = ? AND role = 'MEMBER'", [$this->orgId]);
        // Die Bot-Seite öffnet sich auf der Moderation; Menü und Kachel zeigen nur diese Bereiche
        $page = $this->call('GET', '/o/alpha/bot', 'member');
        $this->assertSame(200, $page->status);
        $this->assertStringContainsString('als Moderator', $page->body);
        $this->assertStringContainsString('m=automod', $page->body);
        $this->assertStringNotContainsString('m=termine', $page->body);
        $this->assertStringNotContainsString('m=setup', $page->body);
        $this->assertStringContainsString('<fieldset class="min-w-0" disabled>', $page->body);
        $this->assertStringNotContainsString('>Speichern</button>', $page->body);
        $this->assertStringContainsString('href="/o/alpha/bot"', $this->call('GET', '/o/alpha', 'member')->body);
        // Andere Bereiche: es erscheint wieder die Moderation
        $other = $this->call('GET', '/o/alpha/bot', 'member', [], ['m' => 'termine'])->body;
        $this->assertStringContainsString('als Moderator', $other);
        $this->assertStringNotContainsString('name="announceChannel"', $other);
        foreach (['moderation', 'faelle', 'automod'] as $m) {
            $this->assertSame(200, $this->call('GET', '/o/alpha/bot', 'member', [], ['m' => $m])->status, $m);
        }
        // Ändern dürfen sie nichts: Speichern und alle anderen Aktionen bleiben bei den Admins
        $this->assertSame(404, $this->call('POST', '/o/alpha/bot/save', 'member', ['section' => 'automod', 'spam' => '1'])->status);
        $this->assertSame(404, $this->call('POST', '/o/alpha/bot/save', 'member', ['section' => 'moderation', 'modRoles' => ['20']])->status);
        $this->assertSame(404, $this->call('POST', '/o/alpha/bot/setup', 'member', [])->status);
        $this->assertSame([], BotSettings::all($this->orgId));
        // Ohne Moderator-Recht bleibt es bei 404
        Db::run("UPDATE org_memberships SET can_moderate = 0 WHERE org_id = ? AND role = 'MEMBER'", [$this->orgId]);
        $this->assertSame(404, $this->call('GET', '/o/alpha/bot', 'member')->status);
    }

    public function testModeratorsRankMattersForMeasures(): void
    {
        $c = ['actorId' => '1', 'targetId' => '2', 'botId' => '9', 'ownerId' => '8', 'botTop' => 10, 'targetTop' => 3];
        $this->assertNull(\Hangar\Moderation::targetProblem($c), 'Admins: keine Rangprüfung');
        $this->assertNull(\Hangar\Moderation::targetProblem($c + ['actorTop' => 5]));
        $this->assertStringContainsString('Rangfolge', (string) \Hangar\Moderation::targetProblem($c + ['actorTop' => 3]));
        $this->assertStringContainsString('Rangfolge', (string) \Hangar\Moderation::targetProblem($c + ['actorTop' => 2]));
    }

    public function testGeneralPageOnlyWarnsAndHasNothingToChoose(): void
    {
        $body = $this->call('GET', '/o/alpha/bot', 'admin')->body;
        $this->assertStringContainsString('Änderungen auf eigene Gefahr', $body);
        $this->assertStringContainsString('sofort umgesetzt', $body);
        $this->assertStringNotContainsString('<select name="adminChannel"', $body);
        $this->assertStringNotContainsString('<select name="visitorRole"', $body);
        $this->assertStringContainsString('m=zuordnung', $body);
        // Das Formular gibt es nicht mehr; ein alter Aufruf ändert nichts.
        $this->save('allgemein', ['adminChannel' => '11', 'visitorRole' => '21']);
        $this->assertSame([], BotSettings::all($this->orgId));
    }

    public function testSuggestionsComeFromNamesAndOnlyForEmptySettings(): void
    {
        $channels = \Hangar\DiscordBot::textChannels('900');
        $roles = \Hangar\DiscordBot::roles('900');
        $this->assertSame(
            ['welcome_channel_id' => '12', 'rules_channel_id' => '11', 'visitor_role_id' => '21', 'mod_role_ids' => '20'],
            BotSettings::suggest([], $channels, $roles),
        );
        $this->assertSame(
            ['welcome_channel_id' => '12', 'visitor_role_id' => '21'],
            BotSettings::suggest(['rules_channel_id' => '14', 'mod_role_ids' => '21'], $channels, $roles),
        );
        $page = $this->call('GET', '/o/alpha/bot', 'admin', [], ['m' => 'zuordnung']);
        $this->assertSame(200, $page->status);
        $this->assertStringContainsString('<option value="12" selected># willkommen</option>', $page->body);
        $this->assertStringContainsString('Vorschlag', $page->body);
        $this->assertStringContainsString('/o/alpha/settings', $page->body);
    }

    public function testAssignmentSavesOnlyWhatWasChosen(): void
    {
        BotSettings::set($this->orgId, BotSettings::ANNOUNCE_CHANNEL, '14');
        $this->save('zuordnung', [
            'welcomeChannel' => '12', 'rulesChannel' => '11', 'announceChannel' => '', 'visitorRole' => '21', 'modRoles' => ['20'],
        ]);
        $this->assertSame([
            'announce_channel_id' => '14', 'mod_role_ids' => '20', 'rules_channel_id' => '11', 'visitor_role_id' => '21', 'welcome_channel_id' => '12',
        ], $this->sorted());

        // Ohne Auswahl bleibt alles; unbekannte Kanäle werden abgelehnt.
        $this->save('zuordnung', ['modRoles' => []]);
        $this->assertSame('20', BotSettings::all($this->orgId)['mod_role_ids']);
        $this->save('zuordnung', ['rulesChannel' => '999']);
        $this->assertSame('11', BotSettings::all($this->orgId)['rules_channel_id']);
    }

    public function testChannelsAreGroupedAndRolesWithoutEveryoneAndBots(): void
    {
        $this->assertSame([
            ['id' => '14', 'name' => 'news', 'category' => null],
            ['id' => '12', 'name' => 'willkommen', 'category' => 'Info'],
            ['id' => '11', 'name' => 'regeln', 'category' => 'Info'],
        ], \Hangar\DiscordBot::textChannels('900'));
        $this->assertSame(['20', '21'], array_column(\Hangar\DiscordBot::roles('900'), 'id'));
        $body = $this->call('GET', '/o/alpha/bot', 'admin', [], ['m' => 'moderation'])->body;
        $this->assertStringContainsString('@Moderator', $body);
        $this->assertStringNotContainsString('@everyone', $body);
    }

    public function testSavingWritesTheKeysTheBotReads(): void
    {
        $this->save('termine', ['announceChannel' => '14']);
        $this->save('zuordnung', ['adminChannel' => '11', 'visitorRole' => '21']);
        $this->save('willkommen', ['welcomeChannel' => '12', 'rulesChannel' => '11', 'welcomeText' => BotSettings::DEFAULT_WELCOME]);
        $this->save('moderation', ['modRoles' => ['20', '20', '21'], 'modLogChannel' => '']);
        $this->assertSame([
            'admin_channel_id' => '11', 'announce_channel_id' => '14', 'mod_role_ids' => '20,21', 'rules_channel_id' => '11',
            'visitor_role_id' => '21', 'welcome_channel_id' => '12',
        ], $this->sorted());
        $this->assertSame(['termine' => true, 'willkommen' => true, 'sprache' => false, 'moderation' => true, 'automod' => false, 'creator' => false, 'recruiting' => false], BotSettings::status(BotSettings::all($this->orgId), $this->orgId));

        // Leeren entfernt die Einstellung, ein eigener Text wird gespeichert.
        $this->save('termine', ['announceChannel' => '']);
        $this->save('willkommen', ['welcomeChannel' => '12', 'welcomeText' => "Hallo {user}\r\nauf {server}"]);
        $all = BotSettings::all($this->orgId);
        $this->assertArrayNotHasKey('announce_channel_id', $all);
        $this->assertArrayNotHasKey('rules_channel_id', $all);
        $this->assertSame("Hallo {user}\nauf {server}", $all['welcome_text']);
    }

    public function testUnknownChannelsAndRolesAreRejectedButStoredOnesStayValid(): void
    {
        $r = $this->save('termine', ['announceChannel' => '13']); // Sprachkanal
        $this->assertSame(303, $r->status);
        $this->assertSame([], BotSettings::all($this->orgId));
        $this->save('moderation', ['modRoles' => ['22']]); // Bot-Rolle
        $this->assertSame([], BotSettings::all($this->orgId));

        // Ein gespeicherter Kanal, den Discord nicht mehr liefert, verhindert das Speichern nicht.
        BotSettings::set($this->orgId, 'mod_log_channel_id', '777');
        $this->save('moderation', ['modRoles' => ['20'], 'modLogChannel' => '777']);
        $this->assertSame('20', BotSettings::all($this->orgId)['mod_role_ids']);
        $this->assertStringContainsString('Kanal nicht mehr verfügbar', $this->call('GET', '/o/alpha/bot', 'admin', [], ['m' => 'moderation'])->body);
    }

    public function testTempVoiceTriggerMustBeAVoiceChannelAndUsesTheBotsKey(): void
    {
        $this->save('sprache', ['tempvoiceChannel' => '11']); // Textkanal
        $this->assertSame([], BotSettings::all($this->orgId));
        $this->save('sprache', ['tempvoiceChannel' => '13']);
        $this->assertSame(['tempvoice_trigger' => '13'], BotSettings::all($this->orgId));
        $this->assertTrue(BotSettings::status(BotSettings::all($this->orgId), $this->orgId)['sprache']);
        $body = $this->call('GET', '/o/alpha/bot', 'admin', [], ['m' => 'sprache'])->body;
        $this->assertStringContainsString('Lounge', $body);
        $this->assertStringNotContainsString('>#  regeln', $body);
        $this->save('sprache', ['tempvoiceChannel' => '']);
        $this->assertSame([], BotSettings::all($this->orgId));
    }

    public function testWithoutDiscordOnlyTheIdFormatIsChecked(): void
    {
        $this->discordStatus = 503;
        $this->assertStringContainsString('Discord antwortet mit Status 503', $this->call('GET', '/o/alpha/bot', 'admin')->body);
        $this->save('termine', ['announceChannel' => '123456']);
        $this->save('zuordnung', ['adminChannel' => 'kein-kanal']);
        $this->assertSame(['announce_channel_id' => '123456'], BotSettings::all($this->orgId));
    }

    public function testAutomodIsStoredAsTheBotExpects(): void
    {
        $this->save('automod', [
            'spam' => '1', 'links' => '1', 'mentions' => '5',
            'words' => "Scam\n scam \n\nbetrug", 'domains' => "https://www.YouTube.com/watch?v=1\nrobertsspaceindustries.com",
            'exemptChannels' => ['14'],
        ]);
        $cfg = json_decode(BotSettings::all($this->orgId)['automod'], true);
        $this->assertSame([
            'spam' => true, 'invites' => false, 'links' => true, 'mentions' => 5,
            'words' => ['scam', 'betrug'], 'domains' => ['youtube.com', 'robertsspaceindustries.com'], 'exemptChannels' => ['14'],
        ], $cfg);
        $this->assertTrue(BotSettings::status(BotSettings::all($this->orgId))['automod']);

        foreach ([['mentions' => '2'], ['mentions' => '51'], ['words' => 'x'], ['domains' => 'keine domain'], ['exemptChannels' => ['13']]] as $bad) {
            $this->save('automod', $bad + ['mentions' => '0']);
            $this->assertSame($cfg, json_decode(BotSettings::all($this->orgId)['automod'], true), json_encode($bad));
        }
        $this->save('automod', ['words' => implode("\n", array_map(fn ($i) => "wort$i", range(1, 51)))]);
        $this->assertSame($cfg, json_decode(BotSettings::all($this->orgId)['automod'], true));
    }

    public function testBrokenAutomodJsonFallsBackToOff(): void
    {
        $this->assertFalse(BotSettings::automodActive(BotSettings::automod(['automod' => '{kaputt'])));
        $this->assertSame(0, BotSettings::automod(['automod' => '{"mentions":-3,"words":["a",5]}'])['mentions']);
        $this->assertSame(['a'], BotSettings::automod(['automod' => '{"words":["a",5]}'])['words']);
    }

    public function testDomainAndWordRulesMatchTheBot(): void
    {
        $this->assertSame('beispiel.de', BotSettings::normalizeDomain('https://www.Beispiel.de/seite?x=1'));
        $this->assertSame('sub.beispiel.de', BotSettings::normalizeDomain('sub.beispiel.de:8080'));
        $this->assertSame('übel', BotSettings::normalizeWord('  ÜBEL '));
        $this->expectException(OrgError::class);
        BotSettings::normalizeDomain('localhost');
    }

    /** @return array<string,string> */
    private function sorted(): array
    {
        $all = BotSettings::all($this->orgId);
        ksort($all);
        return $all;
    }
}
