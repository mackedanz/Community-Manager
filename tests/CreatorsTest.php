<?php

declare(strict_types=1);

namespace Hangar\Tests;

use Hangar\App;
use Hangar\Auth;
use Hangar\BotSettings;
use Hangar\Creators;
use Hangar\Db;
use Hangar\Env;
use Hangar\Http\Client;
use Hangar\Http\Request;
use Hangar\Http\Response;
use Hangar\Time;

/** YouTube-Creator im Web: Kanal prüfen, hinzufügen, ändern, entfernen; nur Orga-Admins. */
final class CreatorsTest extends DbTestCase
{
    private const YT = 'UCabcdefghijklmnopqrstuv';

    private string $orgId;
    /** @var array<string,array{token:string,csrf:string}> */
    private array $u = [];
    private int $feedStatus = 200;
    /** @var list<string> */
    private array $httpLog = [];

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
            $this->httpLog[] = $url;
            $json = static fn (mixed $b): array => ['status' => 200, 'body' => json_encode($b), 'headers' => []];
            if (str_contains($url, 'youtube.com/feeds/videos.xml')) {
                return ['status' => $this->feedStatus, 'headers' => [], 'body' => '<feed><author><name>Space &amp; Co</name></author>'
                    . '<entry><yt:videoId>vid00000001</yt:videoId><title>Neu &amp; gut</title><link rel="alternate" href="https://www.youtube.com/watch?v=vid00000001"/></entry>'
                    . '<entry><yt:videoId>vid00000002</yt:videoId><title>Kurz</title><link rel="alternate" href="https://www.youtube.com/shorts/vid00000002"/></entry></feed>'];
            }
            if (str_contains($url, 'youtube.com/@')) {
                return ['status' => 200, 'headers' => [], 'body' => '<link rel="canonical" href="https://www.youtube.com/channel/' . self::YT . '"><meta property="og:title" content="Space &amp; Co">'];
            }
            if (str_ends_with($url, '/guilds/900/channels')) {
                return $json([['id' => '11', 'type' => 0, 'name' => 'videos', 'position' => 1], ['id' => '12', 'type' => 0, 'name' => 'chat', 'position' => 2]]);
            }
            if (str_ends_with($url, '/guilds/900/roles')) {
                return $json([['id' => '900', 'name' => '@everyone', 'position' => 0], ['id' => '20', 'name' => 'Fans', 'position' => 1]]);
            }
            throw new \LogicException("Unerwarteter HTTP-Aufruf: $method $url");
        });
    }

    /** @param array<string,mixed> $post */
    private function post(string $path, array $post, string $as = 'admin'): Response
    {
        return App::handle(new Request('POST', "/o/alpha/bot/creator/$path", [], $post + ['_csrf' => $this->u[$as]['csrf']], [], [Auth::COOKIE => $this->u[$as]['token']], ''));
    }

    private function page(string $as = 'admin'): Response
    {
        return App::handle(new Request('GET', '/o/alpha/bot', ['m' => 'creator'], [], [], [Auth::COOKIE => $this->u[$as]['token']], ''));
    }

    public function testParsesInputsLikeTheBot(): void
    {
        $this->assertSame(['channelId' => self::YT], Creators::parseInput(' ' . self::YT));
        $this->assertSame(['channelId' => self::YT], Creators::parseInput('https://www.youtube.com/channel/' . self::YT . '/videos'));
        $this->assertSame(['handle' => 'SpaceCo'], Creators::parseInput('@SpaceCo'));
        $this->assertSame(['handle' => 'SpaceCo'], Creators::parseInput('https://youtube.com/@SpaceCo'));
        $this->assertNull(Creators::parseInput('hallo'));
        $feed = Creators::parseFeed('<author><name>A &amp; B</name></author><entry><yt:videoId>abcdef12345</yt:videoId><title>T</title><link rel="alternate" href="https://www.youtube.com/shorts/abcdef12345"/></entry>');
        $this->assertSame('A & B', $feed['name']);
        $this->assertTrue($feed['videos'][0]['short']);
    }

    public function testParsesTwitchInputs(): void
    {
        $this->assertSame('star_citizen', Creators::parseTwitchLogin('https://www.twitch.tv/Star_Citizen'));
        $this->assertSame('knusper', Creators::parseTwitchLogin('twitch.tv/Knusper/'));
        $this->assertSame('fhexy', Creators::parseTwitchLogin('@Fhexy'));
        $this->assertSame('dieloge', Creators::parseTwitchLogin(' DieLoge '));
        foreach (['ab', 'mit leerzeichen', 'https://example.com/abc', ''] as $bad) {
            $this->assertNull(Creators::parseTwitchLogin($bad), $bad);
        }
    }

    public function testTwitchChannelIsStoredUncheckedWithoutAskingTwitch(): void
    {
        $this->post('add', ['platform' => 'twitch', 'source' => 'https://www.twitch.tv/Knusper', 'channel' => '11', 'role' => '20', 'skipShorts' => '1']);
        $row = Creators::list($this->orgId)[0];
        $this->assertSame(['twitch', 'knusper', 'knusper', '11', '20', '0', '0'], [
            $row['platform'], $row['channel_id'], $row['name'], $row['discord_channel_id'], $row['mention_role_id'], (string) $row['skip_shorts'], (string) $row['resolved'],
        ]);
        $this->assertNull($row['checked_at']);
        $this->assertSame([], array_filter($this->httpLog, static fn ($u) => str_contains($u, 'twitch')));

        // Seite: Plattform, Prüfhinweis, Twitch-Hinweis ohne Zugangsdaten
        $html = $this->page()->body;
        $this->assertStringContainsString('https://www.twitch.tv/knusper', $html);
        $this->assertStringContainsString('wird vom Bot bei Twitch geprüft', $html);
        $this->assertStringContainsString('meldet Live-Streams', $html);
        $this->assertStringContainsString('TWITCH_CLIENT_ID', $html);

        // Derselbe Kanal noch einmal ändert nur Discord-Kanal und Rolle; YouTube mit gleichem Namen ist ein anderer Eintrag.
        $this->post('add', ['platform' => 'twitch', 'source' => '@knusper', 'channel' => '12']);
        $rows = Creators::list($this->orgId);
        $this->assertCount(1, $rows);
        $this->assertSame(['12', null], [$rows[0]['discord_channel_id'], $rows[0]['mention_role_id']]);

        // Meldet der Bot Twitch als eingerichtet, verschwindet der Hinweis.
        BotSettings::set($this->orgId, BotSettings::TWITCH_STATUS, '1');
        $this->assertStringNotContainsString('TWITCH_CLIENT_ID', $this->page()->body);
    }

    public function testRejectsBadTwitchInputAndUnknownPlatforms(): void
    {
        $this->post('add', ['platform' => 'twitch', 'source' => 'zu kurz!', 'channel' => '11']);
        $this->post('add', ['platform' => 'twitch', 'source' => 'knusper', 'channel' => '999']);
        $this->post('add', ['platform' => 'tiktok', 'source' => 'knusper', 'channel' => '11']);
        $this->assertSame([], Creators::list($this->orgId));
    }

    public function testCreatorLightIsOnAsSoonAsOneCreatorExistsAndOffWhenNoneIsLeft(): void
    {
        $light = fn (): bool => BotSettings::status(BotSettings::all($this->orgId), $this->orgId)['creator'];
        $this->assertFalse($light());
        $this->assertStringContainsString('0 von 7 Bereichen eingerichtet', $this->page()->body);

        $this->post('add', ['source' => '@SpaceCo', 'channel' => '11']);
        $this->post('add', ['platform' => 'twitch', 'source' => 'knusper', 'channel' => '11']);
        $this->assertTrue($light());
        $this->assertStringContainsString('1 von 7 Bereichen eingerichtet', $this->page()->body);

        // Ein Creator weniger: das Licht bleibt an; erst wenn der letzte weg ist, geht es aus
        $ids = array_column(Creators::list($this->orgId), 'id');
        $this->post('delete', ['id' => (string) $ids[0]]);
        $this->assertTrue($light());
        $this->post('delete', ['id' => (string) $ids[1]]);
        $this->assertFalse($light());
        $this->assertStringContainsString('0 von 7 Bereichen eingerichtet', $this->page()->body);
    }

    public function testOnlyAdminsCanUseIt(): void
    {
        $this->assertSame(404, $this->page('member')->status);
        $this->assertSame(404, $this->post('add', ['source' => '@SpaceCo', 'channel' => '11'], 'member')->status);
        $this->assertSame([], Creators::list($this->orgId));
        $this->assertSame(200, $this->page()->status);
    }

    public function testAddResolvesTheChannelAndMarksExistingVideosAsKnown(): void
    {
        $res = $this->post('add', ['source' => '@SpaceCo', 'channel' => '11', 'role' => '20', 'skipShorts' => '1']);
        $this->assertSame(303, $res->status);
        $row = Creators::list($this->orgId)[0];
        $this->assertSame(self::YT, $row['channel_id']);
        $this->assertSame('Space & Co', $row['name']);
        $this->assertSame(['11', '20', '1'], [$row['discord_channel_id'], $row['mention_role_id'], (string) $row['skip_shorts']]);
        $this->assertSame(2, (int) Db::val('SELECT COUNT(*) FROM bot_creator_videos WHERE creator_id = ?', [$row['id']]));

        $html = $this->page()->body;
        $this->assertStringContainsString('Space &amp; Co', $html);
        $this->assertStringContainsString('# videos', $html);
        $this->assertStringContainsString('erwähnt @Fans', $html);
        $this->assertStringContainsString('ohne Shorts', $html);

        // Derselbe Kanal noch einmal ändert nur, er legt nichts doppelt an.
        $this->post('add', ['source' => self::YT, 'channel' => '12']);
        $rows = Creators::list($this->orgId);
        $this->assertCount(1, $rows);
        $this->assertSame(['12', null, '0'], [$rows[0]['discord_channel_id'], $rows[0]['mention_role_id'], (string) $rows[0]['skip_shorts']]);
    }

    public function testRejectsBadInputAndUnknownChannelsAndYoutubeErrors(): void
    {
        $this->post('add', ['source' => 'quatsch', 'channel' => '11']);
        $this->post('add', ['source' => self::YT, 'channel' => '999']);
        $this->post('add', ['source' => self::YT, 'channel' => '11', 'role' => '777']);
        $this->feedStatus = 404;
        $this->post('add', ['source' => self::YT, 'channel' => '11']);
        $this->assertSame([], Creators::list($this->orgId));
    }

    public function testUpdateAndDeleteStayInsideTheOrga(): void
    {
        $this->post('add', ['source' => self::YT, 'channel' => '11']);
        $id = (string) Creators::list($this->orgId)[0]['id'];

        $this->post('update', ['id' => $id, 'channel' => '12', 'role' => '20', 'skipShorts' => '1']);
        $row = Creators::list($this->orgId)[0];
        $this->assertSame(['12', '20', '1'], [$row['discord_channel_id'], $row['mention_role_id'], (string) $row['skip_shorts']]);

        $other = new_id();
        Db::insert('organizations', ['id' => $other, 'slug' => 'beta', 'name' => 'Beta', 'discord_guild_id' => '901', 'created_by_id' => 'x']);
        $this->assertFalse(Creators::remove($other, $id));
        $this->assertCount(1, Creators::list($this->orgId));

        $this->post('delete', ['id' => $id]);
        $this->assertSame([], Creators::list($this->orgId));
        $this->assertSame(0, (int) Db::val('SELECT COUNT(*) FROM bot_creator_videos'));
    }
}
