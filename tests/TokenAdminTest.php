<?php

declare(strict_types=1);

namespace Hangar\Tests;

use Hangar\ApiToken;
use Hangar\Db;
use Hangar\Env;
use Hangar\Http\Request;
use Hangar\OrgError;
use Hangar\Orgs;
use Hangar\RateLimit;
use Hangar\ServerAdmin;

final class TokenAdminTest extends DbTestCase
{
    private const ADMIN_DISCORD = '900000000000000001';
    private string $userId;
    private string $otherId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userId = $this->mkUser(['name' => 'Tokener', 'discord_id' => self::ADMIN_DISCORD])['id'];
        $this->otherId = $this->mkUser(['name' => 'NoSA', 'discord_id' => '900000000000000002'])['id'];
        Env::set('SERVER_ADMIN_DISCORD_ID', self::ADMIN_DISCORD);
    }

    // --- API-Token ---------------------------------------------------------------------------

    public function testStoresOnlyTheHash(): void
    {
        ['token' => $token, 'id' => $id] = ApiToken::create($this->userId, 'Test');
        $row = Db::one('SELECT * FROM api_tokens WHERE id = ?', [$id]);
        $this->assertSame(ApiToken::hash($token), $row['token_hash']);
        $this->assertStringNotContainsString($token, json_encode($row));
        $this->assertStringStartsWith('sch_', $token);
    }

    public function testVerifiesValidTokensAndSetsLastUsedAt(): void
    {
        ['token' => $token, 'id' => $id] = ApiToken::create($this->userId, 'Test');
        $this->assertSame($this->userId, ApiToken::verify($token));
        $this->assertNotNull(Db::val('SELECT last_used_at FROM api_tokens WHERE id = ?', [$id]));
    }

    public function testRejectsWrongTokens(): void
    {
        $this->assertNull(ApiToken::verify('sch_falsch'));
        $this->assertNull(ApiToken::verify('kein-praefix'));
        $this->assertNull(ApiToken::verify(''));
    }

    public function testRevokesOnlyOwnTokens(): void
    {
        ['token' => $token, 'id' => $id] = ApiToken::create($this->userId, 'Weg');
        $this->assertSame(0, ApiToken::revoke($this->otherId, $id));
        $this->assertSame($this->userId, ApiToken::verify($token));
        $this->assertSame(1, ApiToken::revoke($this->userId, $id));
        $this->assertNull(ApiToken::verify($token));
    }

    public function testBearerHeaderIsExclusive(): void
    {
        ['token' => $token] = ApiToken::create($this->userId, 'X');
        $req = fn (string $h) => new Request('POST', '/api/v1/import/hangar', [], [], ['authorization' => $h]);
        $this->assertSame($this->userId, ApiToken::userIdFromRequest($req("Bearer $token")));
        $this->assertNull(ApiToken::userIdFromRequest($req('Bearer sch_falsch')));
        $this->assertNull(ApiToken::userIdFromRequest($req('Basic abc')));
    }

    // --- RateLimit ---------------------------------------------------------------------------

    public function testAllowsUpToLimitThenBlocks(): void
    {
        $t = 1_000_000;
        $this->assertTrue(RateLimit::hit('k', 2, 1, $t)['ok']);
        $this->assertTrue(RateLimit::hit('k', 2, 1, $t)['ok']);
        $blocked = RateLimit::hit('k', 2, 1, $t);
        $this->assertFalse($blocked['ok']);
        $this->assertSame(1, $blocked['retryAfterSec']);
    }

    public function testReopensAfterWindow(): void
    {
        RateLimit::hit('k', 1, 1, 0);
        $this->assertFalse(RateLimit::hit('k', 1, 1, 0)['ok']);
        $this->assertTrue(RateLimit::hit('k', 1, 1, 2)['ok']);
    }

    public function testSeparatesKeys(): void
    {
        RateLimit::hit('a', 1, 10, 0);
        $this->assertTrue(RateLimit::hit('b', 1, 10, 0)['ok']);
    }

    // --- Server-Admin ------------------------------------------------------------------------

    public function testRecognizesOnlyConfiguredDiscordId(): void
    {
        $this->assertTrue(ServerAdmin::isAdmin($this->userId));
        $this->assertFalse(ServerAdmin::isAdmin($this->otherId));
        $this->assertFalse(ServerAdmin::isAdmin(null));
    }

    public function testNoAdminWithoutConfiguredId(): void
    {
        Env::set('SERVER_ADMIN_DISCORD_ID', '');
        putenv('SERVER_ADMIN_DISCORD_ID');
        $this->assertFalse(ServerAdmin::isAdmin($this->userId));
    }

    private function mkOrg(): string
    {
        $id = new_id();
        Db::insert('organizations', ['id' => $id, 'slug' => 'sa-org', 'name' => 'SA Org', 'discord_guild_id' => '800000000000000001', 'created_by_id' => $this->otherId]);
        return $id;
    }

    public function testOthersMayNeitherBanNorDeleteNorUnban(): void
    {
        $orgId = $this->mkOrg();
        foreach ([
            fn () => ServerAdmin::banGuild($this->otherId, '800000000000000001', 'x'),
            fn () => ServerAdmin::deleteOrg($this->otherId, $orgId),
            fn () => ServerAdmin::unbanGuild($this->otherId, '800000000000000001'),
            fn () => ServerAdmin::listBans($this->otherId),
        ] as $action) {
            try {
                $action();
                $this->fail('Aktion war erlaubt');
            } catch (OrgError) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertNotNull(Db::val('SELECT id FROM organizations WHERE id = ?', [$orgId]));
    }

    public function testBanDeletesOrgAndUnbanLiftsIt(): void
    {
        $orgId = $this->mkOrg();
        ServerAdmin::banGuild($this->userId, '800000000000000001', '', ' Regelverstoß ');
        $this->assertNull(Db::val('SELECT id FROM organizations WHERE id = ?', [$orgId]));
        $this->assertTrue(ServerAdmin::isGuildBanned('800000000000000001'));
        $ban = ServerAdmin::listBans($this->userId)[0];
        $this->assertSame('SA Org', $ban['name']);
        $this->assertSame('Regelverstoß', $ban['reason']);

        ServerAdmin::unbanGuild($this->userId, '800000000000000001');
        $this->assertFalse(ServerAdmin::isGuildBanned('800000000000000001'));
    }

    public function testRejectsInvalidGuildIdsAndPreventsCreatingBannedGuilds(): void
    {
        try {
            ServerAdmin::banGuild($this->userId, 'abc', 'x');
            $this->fail();
        } catch (OrgError) {
            $this->addToAssertionCount(1);
        }
        ServerAdmin::banGuild($this->userId, '800000000000000009', 'Fremd');
        $this->expectException(OrgError::class);
        $this->expectExceptionMessageMatches('/gesperrt/');
        \Hangar\Onboarding::ensureOrg('800000000000000009', $this->otherId, 'Neu');
    }

    public function testMultipleAdminsAsCommaSeparatedListTakeEffectImmediately(): void
    {
        Env::set('SERVER_ADMIN_DISCORD_ID', ' 900000000000000002 , ' . self::ADMIN_DISCORD);
        $this->assertTrue(ServerAdmin::isAdmin($this->userId));
        $this->assertTrue(ServerAdmin::isAdmin($this->otherId));
        Env::set('SERVER_ADMIN_DISCORD_ID', '900000000000000002');
        $this->assertFalse(ServerAdmin::isAdmin($this->userId));
    }
}
