<?php

declare(strict_types=1);

namespace Hangar\Tests;

use Hangar\Achievements;
use Hangar\Db;

final class AchievementsTest extends DbTestCase
{
    /** @param array<string,mixed> $extra */
    private static function ship(string $id, string $manufacturer, int $sizeClass = 2, array $extra = []): array
    {
        return array_merge([
            'kind' => 'SHIP', 'quantity' => 1, 'lti' => false, 'customName' => null,
            'catalogItem' => ['id' => $id, 'manufacturer' => $manufacturer, 'data' => json_encode(['sizeClass' => $sizeClass])],
        ], $extra);
    }

    private static function withData(array $data): array
    {
        return ['kind' => 'SHIP', 'quantity' => 1, 'lti' => false, 'customName' => null,
            'catalogItem' => ['id' => 'x', 'manufacturer' => null, 'data' => json_encode($data)]];
    }

    public function testCapitalDetection(): void
    {
        $this->assertTrue(Achievements::computeStats([self::withData(['size' => 'capital'])])['hasCapitalShip']);
        $this->assertTrue(Achievements::computeStats([self::withData(['size' => 'Capital'])])['hasCapitalShip']);
        $this->assertTrue(Achievements::computeStats([self::withData(['sizeClass' => 6])])['hasCapitalShip']);
        $this->assertFalse(Achievements::computeStats([self::withData(['size' => 'large', 'sizeClass' => 4])])['hasCapitalShip']);
        $this->assertFalse(Achievements::computeStats([self::withData([])])['hasCapitalShip']);
    }

    public function testEmptyHangarHasNone(): void
    {
        $this->assertSame([], Achievements::earnedKeys(Achievements::computeStats([])));
    }

    public function testCountsDistinctShipsNotQuantity(): void
    {
        $stats = Achievements::computeStats([self::ship('a', 'X', 2, ['quantity' => 9])]);
        $this->assertSame(1, $stats['distinctShips']);
        $this->assertSame(['first-ship'], Achievements::earnedKeys($stats));
    }

    public function testCapitalLtiManufacturers(): void
    {
        $keys = Achievements::earnedKeys(Achievements::computeStats([
            self::ship('a', 'A', 6, ['lti' => true]),
            self::ship('b', 'B', 2, ['lti' => true]),
            self::ship('c', 'C', 2, ['lti' => true]),
            self::ship('d', 'D'),
            self::ship('e', 'E'),
        ]));
        foreach (['capital', 'lti-3', 'manufacturers-5', 'fleet-5'] as $k) {
            $this->assertContains($k, $keys);
        }
        $this->assertNotContains('fleet-10', $keys);
    }

    public function testArmorCountedByQuantity(): void
    {
        $armor = ['kind' => 'ARMOR', 'quantity' => 5, 'lti' => false, 'customName' => null, 'catalogItem' => null];
        $this->assertContains('armor-5', Achievements::earnedKeys(Achievements::computeStats([$armor])));
    }

    public function testShipsWithoutCatalogEntryCountedByName(): void
    {
        $custom = fn (string $n) => ['kind' => 'SHIP', 'quantity' => 1, 'lti' => false, 'customName' => $n, 'catalogItem' => null];
        $this->assertSame(2, Achievements::computeStats([$custom('A'), $custom('A'), $custom('B')])['distinctShips']);
    }

    public function testRecomputeAwardsAndRevokes(): void
    {
        $userId = $this->mkUser()['id'];
        $catalogId = new_id();
        Db::insert('catalog_items', ['id' => $catalogId, 'kind' => 'SHIP', 'slug' => 'ach-ship', 'name' => 'Ach Ship', 'match_key' => 'achship', 'data' => '{}']);
        $count = fn () => (int) Db::val('SELECT COUNT(*) FROM user_achievements WHERE user_id = ?', [$userId]);

        $this->assertSame([], Achievements::recompute($userId));
        $itemId = new_id();
        Db::insert('owned_items', ['id' => $itemId, 'user_id' => $userId, 'catalog_item_id' => $catalogId, 'kind' => 'SHIP']);
        $this->assertSame(['first-ship'], Achievements::recompute($userId));
        $this->assertSame(1, $count());

        Achievements::recompute($userId);   // idempotent
        $this->assertSame(1, $count());

        Db::run('DELETE FROM owned_items WHERE id = ?', [$itemId]);
        Achievements::recompute($userId);
        $this->assertSame(0, $count());
    }
}
