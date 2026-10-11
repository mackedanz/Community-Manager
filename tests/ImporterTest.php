<?php

declare(strict_types=1);

namespace Hangar\Tests;

use Hangar\Db;
use Hangar\Import\ImportFormatError;
use Hangar\Import\Importer;

final class ImporterTest extends DbTestCase
{
    private string $userId;
    private string $otherId;
    private string $titanId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userId = $this->mkUser(['name' => 'Importer'])['id'];
        $this->otherId = $this->mkUser(['name' => 'Other'])['id'];
        $this->titanId = new_id();
        Db::insert('catalog_items', ['id' => $this->titanId, 'kind' => 'SHIP', 'slug' => 'imp-titan', 'name' => 'Imp Titan', 'match_key' => 'imptitan', 'data' => '{}']);
        Db::insert('catalog_items', ['id' => new_id(), 'kind' => 'SHIP', 'slug' => 'imp-other', 'name' => 'Imp Other', 'match_key' => 'impother', 'data' => '{}']);
    }

    private static function exportOf(string ...$titles): array
    {
        return [
            'type' => 'hangarexport', 'version' => 2, 'handle' => 'ImportTester',
            'pledges' => array_map(fn ($t) => [
                'pledgeName' => "Standalone Ship - $t - LTI",
                'items' => [['title' => $t, 'kind' => 'Ship']],
                'alsoContains' => [['title' => 'Lifetime Insurance']],
            ], $titles),
        ];
    }

    private function owned(string $where, array $p = []): int
    {
        return (int) Db::val("SELECT COUNT(*) FROM owned_items WHERE $where", $p);
    }

    public function testPreviewWritesNothing(): void
    {
        $plan = Importer::buildPlan($this->userId, self::exportOf('Imp Titan', 'Unbekannt X'));
        $this->assertCount(1, $plan['matched']);
        $this->assertCount(1, $plan['unmatched']);
        $this->assertSame(0, $this->owned('user_id = ?', [$this->userId]));
    }

    public function testAppliesMatchesAndUnknownsAndSetsHandle(): void
    {
        Importer::applyPlan($this->userId, Importer::buildPlan($this->userId, self::exportOf('Imp Titan', 'Unbekannt X')), 'test');

        $items = Db::all('SELECT * FROM owned_items WHERE user_id = ?', [$this->userId]);
        $this->assertCount(2, $items);
        $titan = array_values(array_filter($items, fn ($i) => $i['catalog_item_id'] === $this->titanId))[0];
        $this->assertSame(1, (int) $titan['lti']);
        $this->assertSame('IMPORT', $titan['source']);
        $this->assertNotEmpty(array_filter($items, fn ($i) => $i['custom_name'] === 'Unbekannt X'));

        $this->assertSame('ImportTester', Db::val('SELECT rsi_handle FROM users WHERE id = ?', [$this->userId]));
        $this->assertSame(1, (int) Db::val('SELECT COUNT(*) FROM import_logs WHERE user_id = ?', [$this->userId]));
        $this->assertGreaterThan(0, (int) Db::val('SELECT COUNT(*) FROM user_achievements WHERE user_id = ?', [$this->userId]));
    }

    public function testResyncReplacesOnlyImportedKeepsManualNeverTouchesOthers(): void
    {
        Importer::applyPlan($this->userId, Importer::buildPlan($this->userId, self::exportOf('Imp Titan', 'Unbekannt X')), 'test');
        $manual = ['catalog_item_id' => $this->titanId, 'kind' => 'SHIP', 'source' => 'MANUAL'];
        Db::insert('owned_items', ['id' => new_id(), 'user_id' => $this->userId] + $manual);
        Db::insert('owned_items', ['id' => new_id(), 'user_id' => $this->otherId] + $manual);

        $plan = Importer::buildPlan($this->userId, self::exportOf('Imp Other'));
        $this->assertSame(2, $plan['replaced']);
        Importer::applyPlan($this->userId, $plan, 'test');

        $items = Db::all('SELECT * FROM owned_items WHERE user_id = ? ORDER BY source ASC', [$this->userId]);
        $this->assertSame(['IMPORT', 'MANUAL'], array_column($items, 'source'));
        $this->assertNotSame($this->titanId, $items[0]['catalog_item_id']);
        $this->assertSame($this->titanId, $items[1]['catalog_item_id']);
        $this->assertSame(1, $this->owned('user_id = ?', [$this->otherId]));
    }

    public function testVeryLongNamesAreShortenedInsteadOfFailing(): void
    {
        $plan = ['matched' => [], 'unmatched' => [['name' => str_repeat('ä', 400), 'quantity' => 1, 'lti' => false]], 'others' => [['name' => str_repeat('x', 500), 'kind' => 'PAINT', 'quantity' => 1]], 'handle' => null];
        Importer::applyPlan($this->userId, $plan, 'api');
        $lens = array_map('intval', array_column(Db::all('SELECT CHAR_LENGTH(custom_name) AS l FROM owned_items WHERE user_id = ?', [$this->userId]), 'l'));
        sort($lens);
        $this->assertSame([190, 190], $lens);
    }

    public function testHandleFromSyncAlwaysApplies(): void
    {
        Db::run("UPDATE users SET rsi_handle = 'Alter_Handle' WHERE id = ?", [$this->userId]);
        Importer::applyPlan($this->userId, Importer::buildPlan($this->userId, self::exportOf('Imp Other')), 'test');
        $this->assertSame('ImportTester', Db::val('SELECT rsi_handle FROM users WHERE id = ?', [$this->userId]));
    }

    public function testThrowsOnUnknownFormat(): void
    {
        $this->expectException(ImportFormatError::class);
        Importer::buildPlan($this->userId, ['foo' => 1]);
    }
}
