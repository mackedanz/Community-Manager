<?php

declare(strict_types=1);

namespace Hangar\Tests;

use Hangar\Db;
use Hangar\Migration\SqliteImport;
use PDO;

final class SqliteImportTest extends DbTestCase
{
    private PDO $src;

    /** 2026-07-15T18:00:00Z in Millisekunden, so speichert Prisma DateTime in SQLite */
    private const T = 1784138400000;

    protected function setUp(): void
    {
        parent::setUp();
        // neuer Katalog (nach dem Sync): "Retaliator" und "Carrack" kennt er, "Altes Schiff" nicht
        foreach ([['aegs-retaliator', 'Retaliator'], ['anvl-carrack', 'Carrack']] as [$slug, $name]) {
            Db::insert('catalog_items', [
                'id' => new_id(), 'kind' => 'SHIP', 'slug' => $slug, 'name' => $name, 'match_key' => \Hangar\Text::normalizeName($name),
                'source' => 'RSI_MATRIX', 'data' => '{}',
            ]);
        }
        $this->src = new PDO('sqlite::memory:');
        $this->src->exec(<<<'SQL'
            CREATE TABLE User (id TEXT, name TEXT, email TEXT, image TEXT, discordId TEXT, rsiHandle TEXT, hangarVisibility TEXT, achievementsVisibility TEXT, membershipCheckedAt INTEGER, membershipStatus TEXT, createdAt INTEGER);
            CREATE TABLE Account (id TEXT, userId TEXT, type TEXT, provider TEXT, providerAccountId TEXT, access_token TEXT, refresh_token TEXT, expires_at INTEGER, token_type TEXT, scope TEXT);
            CREATE TABLE Organization (id TEXT, slug TEXT, name TEXT, discordGuildId TEXT, iconUrl TEXT, memberRoleIds TEXT, plannerRoleIds TEXT, roleLabels TEXT, createdById TEXT, createdAt INTEGER);
            CREATE TABLE OrgMembership (userId TEXT, orgId TEXT, role TEXT, canPlan INTEGER, verifiedAt INTEGER);
            CREATE TABLE CatalogItem (id TEXT, kind TEXT, slug TEXT, name TEXT);
            CREATE TABLE OwnedItem (id TEXT, userId TEXT, catalogItemId TEXT, customName TEXT, kind TEXT, quantity INTEGER, lti INTEGER, source TEXT, pledgeName TEXT, createdAt INTEGER, updatedAt INTEGER);
            CREATE TABLE ImportLog (id TEXT, userId TEXT, source TEXT, created INTEGER, updated INTEGER, unmatched INTEGER, createdAt INTEGER);
            CREATE TABLE ItemInfo (matchKey TEXT, found INTEGER, name TEXT, description TEXT, typeLabel TEXT, imageUrl TEXT, webUrl TEXT, checkedAt INTEGER);
            CREATE TABLE ApiToken (id TEXT, userId TEXT, name TEXT, tokenHash TEXT, lastUsedAt INTEGER, createdAt INTEGER);
            CREATE TABLE BannedGuild (discordGuildId TEXT, name TEXT, reason TEXT, bannedAt INTEGER, bannedById TEXT);
            CREATE TABLE Event (id TEXT, orgId TEXT, title TEXT, description TEXT, location TEXT, startsAt INTEGER, endsAt INTEGER, status TEXT, createdById TEXT, createdAt INTEGER, updatedAt INTEGER);
            CREATE TABLE EventShip (id TEXT, eventId TEXT, catalogItemId TEXT, customName TEXT, task TEXT, sort INTEGER);
            CREATE TABLE EventSlot (id TEXT, eventShipId TEXT, label TEXT, userId TEXT, sort INTEGER);
            CREATE TABLE EventRsvp (eventId TEXT, userId TEXT, status TEXT, updatedAt INTEGER);
            CREATE TABLE Achievement (id TEXT, key TEXT, title TEXT, description TEXT);
            CREATE TABLE UserAchievement (userId TEXT, achievementId TEXT, earnedAt INTEGER);
            SQL);
        $t = self::T;
        $this->src->exec(<<<SQL
            INSERT INTO User VALUES ('u1','Alice','a@x.test','https://img/a.png','111','AliceRSI','MEMBERS','PRIVATE',$t,'OK',$t);
            INSERT INTO User VALUES ('u2','Bob',NULL,NULL,'222',NULL,'PRIVATE','MEMBERS',NULL,NULL,$t);
            INSERT INTO Account VALUES ('a1','u1','oauth','discord','111','tok','ref',1791632018,'bearer','guilds identify');
            INSERT INTO Organization VALUES ('o1','alpha','Alpha','g1',NULL,'123456789012345678','987654321098765432','{"123456789012345678":"Mitglied"}','u1',$t);
            INSERT INTO OrgMembership VALUES ('u1','o1','ADMIN',1,$t);
            INSERT INTO OrgMembership VALUES ('u2','o1','MEMBER',0,$t);
            INSERT INTO CatalogItem VALUES ('c-ret','SHIP','misc-retaliator','Retaliator');
            INSERT INTO CatalogItem VALUES ('c-old','SHIP','alt','Altes Schiff Das Es Nicht Mehr Gibt');
            INSERT INTO CatalogItem VALUES ('c-armor','ARMOR','arm','Irgendeine Ruestung');
            INSERT INTO OwnedItem VALUES ('i1','u1','c-ret',NULL,'SHIP',2,1,'IMPORT','Standalone',$t,$t);
            INSERT INTO OwnedItem VALUES ('i2','u1','c-old',NULL,'SHIP',1,0,'IMPORT',NULL,$t,$t);
            INSERT INTO OwnedItem VALUES ('i3','u1',NULL,'Coin','ITEM',3,0,'IMPORT',NULL,$t,$t);
            INSERT INTO OwnedItem VALUES ('i4','u2','c-armor',NULL,'ARMOR',1,0,'MANUAL',NULL,$t,$t);
            INSERT INTO ImportLog VALUES ('l1','u1','upload',4,0,1,$t);
            INSERT INTO ItemInfo VALUES ('coin',1,'Coin','Eine Muenze','Decoration',NULL,'https://wiki/coin',$t);
            INSERT INTO ApiToken VALUES ('t1','u1','Skript','hash123',NULL,$t);
            INSERT INTO BannedGuild VALUES ('999999','Boese Orga','Regelverstoss',$t,'u1');
            INSERT INTO Event VALUES ('e1','o1','Mining','Text','Stanton',$t,$t+3600000,'PLANNED','u1',$t,$t);
            INSERT INTO EventShip VALUES ('es1','e1','c-ret',NULL,'Eskorte',0);
            INSERT INTO EventShip VALUES ('es2','e1','c-old',NULL,NULL,1);
            INSERT INTO EventSlot VALUES ('s1','es1','Pilot','u2',0);
            INSERT INTO EventRsvp VALUES ('e1','u2','YES',$t);
            INSERT INTO Achievement VALUES ('ach1','first-ship','Erstes Schiff','x');
            INSERT INTO UserAchievement VALUES ('u1','ach1',1700000000000);
            SQL);
    }

    private function rows(string $table): int
    {
        return (int) Db::val("SELECT COUNT(*) FROM $table");
    }

    public function testImportsEverythingAndKeepsIds(): void
    {
        $res = (new SqliteImport($this->src))->run(false, 2);

        $this->assertSame(2, $this->rows('users'));
        $this->assertSame('Alice', Db::val("SELECT name FROM users WHERE id = 'u1'"));
        $this->assertSame('PRIVATE', Db::val("SELECT achievements_visibility FROM users WHERE id = 'u1'"));
        $this->assertSame('2026-07-15 18:00:00', Db::val("SELECT created_at FROM users WHERE id = 'u1'"));
        $this->assertSame('111', Db::val("SELECT provider_account_id FROM accounts WHERE user_id = 'u1'"));
        $this->assertSame('ref', Db::val("SELECT refresh_token FROM accounts WHERE user_id = 'u1'"));
        $this->assertSame(1791632018, (int) Db::val("SELECT expires_at FROM accounts WHERE user_id = 'u1'"));
        $this->assertSame('alpha', Db::val("SELECT slug FROM organizations WHERE id = 'o1'"));
        $this->assertSame('{"123456789012345678":"Mitglied"}', str_replace(' ', '', (string) Db::val("SELECT role_labels FROM organizations WHERE id = 'o1'")));
        $this->assertSame(1, (int) Db::val("SELECT can_plan FROM org_memberships WHERE user_id = 'u1'"));
        $this->assertSame(1, $this->rows('import_logs'));
        $this->assertSame('Eine Muenze', Db::val("SELECT description FROM item_info WHERE match_key = 'coin'"));
        $this->assertSame('hash123', Db::val("SELECT token_hash FROM api_tokens WHERE id = 't1'"));
        $this->assertSame('Boese Orga', Db::val("SELECT name FROM banned_guilds WHERE discord_guild_id = '999999'"));
        $this->assertSame('2026-07-15 18:00:00', Db::val("SELECT starts_at FROM events WHERE id = 'e1'"));
        $this->assertSame('2026-07-15 19:00:00', Db::val("SELECT ends_at FROM events WHERE id = 'e1'"));
        $this->assertSame('u2', Db::val("SELECT user_id FROM event_slots WHERE id = 's1'"));
        $this->assertSame('YES', Db::val("SELECT status FROM event_rsvps WHERE event_id = 'e1'"));

        foreach ($res['counts'] as $table => $c) {
            $this->assertSame($c['source'], $c['imported'], $table);
        }
    }

    public function testRelinksShipsByNameAndKeepsUnknownOnesAsFreeNames(): void
    {
        $res = (new SqliteImport($this->src))->run(false, 2);
        $newRet = Db::val("SELECT id FROM catalog_items WHERE slug = 'aegs-retaliator'");

        $i1 = Db::one("SELECT * FROM owned_items WHERE id = 'i1'");
        $this->assertSame($newRet, $i1['catalog_item_id']);
        $this->assertNull($i1['custom_name']);
        $this->assertSame(2, (int) $i1['quantity']);
        $this->assertSame(1, (int) $i1['lti']);

        $i2 = Db::one("SELECT * FROM owned_items WHERE id = 'i2'");
        $this->assertNull($i2['catalog_item_id']);
        $this->assertSame('Altes Schiff Das Es Nicht Mehr Gibt', $i2['custom_name']);

        $i3 = Db::one("SELECT * FROM owned_items WHERE id = 'i3'");
        $this->assertSame('Coin', $i3['custom_name']);

        // Armor ohne Treffer im neuen Katalog: freier Name
        $this->assertSame('Irgendeine Ruestung', Db::val("SELECT custom_name FROM owned_items WHERE id = 'i4'"));

        $this->assertSame($newRet, Db::val("SELECT catalog_item_id FROM event_ships WHERE id = 'es1'"));
        $this->assertSame('Altes Schiff Das Es Nicht Mehr Gibt', Db::val("SELECT custom_name FROM event_ships WHERE id = 'es2'"));
        $this->assertNotEmpty($res['notes']);
    }

    public function testRecomputesAchievementsButKeepsTheirDate(): void
    {
        (new SqliteImport($this->src))->run(false, 2);
        $earned = Db::val("SELECT ua.earned_at FROM user_achievements ua JOIN achievements a ON a.id = ua.achievement_id WHERE ua.user_id = 'u1' AND a.`key` = 'first-ship'");
        $this->assertSame('2023-11-14 22:13:20', $earned);
    }

    public function testDryRunWritesNothingButReportsCounts(): void
    {
        $res = (new SqliteImport($this->src))->dryRun(2);
        $this->assertSame(0, $this->rows('users'));
        $this->assertSame(0, $this->rows('owned_items'));
        $this->assertSame(0, $this->rows('events'));
        $this->assertSame(['source' => 4, 'imported' => 4], $res['counts']['owned_items']);
        // danach ist ein echter Lauf möglich
        (new SqliteImport($this->src))->run(false, 2);
        $this->assertSame(2, $this->rows('users'));
    }

    public function testRefusesToRunTwiceOrIntoANonEmptyTarget(): void
    {
        (new SqliteImport($this->src))->run(false, 2);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('enthält schon');
        (new SqliteImport($this->src))->run(false, 2);
    }

    public function testRefusesWithoutAnImportedCatalog(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('catalog-sync');
        (new SqliteImport($this->src))->run(false, 100);
    }

    public function testFailureRollsBackEverything(): void
    {
        $this->src->exec("INSERT INTO User VALUES ('u3','Doppelt',NULL,NULL,'111',NULL,'MEMBERS','MEMBERS',NULL,NULL," . self::T . ')');   // gleiche Discord-ID
        try {
            (new SqliteImport($this->src))->run(false, 2);
            $this->fail('Doppelte Discord-ID hätte scheitern müssen');
        } catch (\PDOException) {
            $this->assertSame(0, $this->rows('users'));
            $this->assertSame(0, $this->rows('owned_items'));
        }
    }
}
