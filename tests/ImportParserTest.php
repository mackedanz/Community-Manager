<?php

declare(strict_types=1);

namespace Hangar\Tests;

use Hangar\Import\ImportFormatError;
use Hangar\Import\Matcher;
use Hangar\Import\Parser;
use Hangar\Import\Planner;
use Hangar\Text;
use PHPUnit\Framework\TestCase;

final class ImportParserTest extends TestCase
{
    /** @param array<string,mixed> $extra @return array<string,mixed> */
    private static function row(string $kind, string $slug, string $name, array $extra = []): array
    {
        return array_merge([
            'id' => $slug, 'kind' => $kind, 'slug' => $slug, 'name' => $name,
            'match_key' => Text::normalizeName($name), 'alt_match_key' => null, 'code_key' => null, 'manufacturer' => null,
        ], $extra);
    }

    private static function catalog(): array
    {
        return Matcher::buildIndex([
            self::row('SHIP', 'aegs-avenger-titan', 'Avenger Titan', ['manufacturer' => 'Aegis Dynamics']),
            self::row('SHIP', 'aegs-avenger-titan-renegade', 'Avenger Titan Renegade', ['manufacturer' => 'Aegis Dynamics']),
            self::row('SHIP', 'orig-100i', '100i', ['manufacturer' => 'Origin Jumpworks']),
            self::row('SHIP', 'rsi-hammerhead-2949', 'Hammerhead', ['manufacturer' => 'Aegis Dynamics']),
            self::row('SHIP', 'aegs-hammerhead', 'Hammerhead', ['manufacturer' => 'Aegis Dynamics']),
            self::row('SHIP', 'crus-msr', 'Mercury Star Runner', ['alt_match_key' => 'crusadermercurystarrunner']),
            self::row('ARMOR', 'a23-helmet', 'A23 Flight Helmet'),
        ]);
    }

    private static function exportV2(): array
    {
        return [
            'type' => 'hangarexport', 'version' => 2, 'handle' => 'SpaceFan',
            'pledges' => [
                ['pledgeName' => 'Standalone Ship - Avenger Titan - LTI',
                    'items' => [['title' => 'Avenger Titan', 'kind' => 'Ship'], ['title' => 'Lifetime Insurance', 'kind' => 'Insurance']],
                    'alsoContains' => []],
                ['pledgeName' => 'Standalone Ship - Avenger Titan', 'items' => [['title' => 'Avenger Titan', 'kind' => 'Ship']], 'alsoContains' => []],
                ['pledgeName' => 'Package - Starter',
                    'items' => [
                        ['title' => 'AEGIS DYNAMICS Hammerhead', 'kind' => 'Ship'],
                        ['title' => 'Some Skin', 'kind' => 'Skin'],
                        ['title' => 'A23 Flight Helmet', 'kind' => 'Component'],
                    ],
                    'alsoContains' => [['title' => 'Lifetime Insurance']]],
                ['pledgeName' => 'Upgrade - Avenger Titan to Avenger Titan Renegade',
                    'items' => [['title' => 'Avenger Titan Renegade', 'kind' => 'Ship']], 'alsoContains' => []],
                ['pledgeName' => 'Standalone Ship - Unknown', 'items' => [['title' => 'Totally Unknown Ship', 'kind' => 'Ship']], 'alsoContains' => []],
            ],
        ];
    }

    private static function xplor(array $over): array
    {
        return array_merge([
            'name' => '', 'lti' => false, 'ship' => false, 'gear' => false, 'upgrade' => false, 'ship_name' => '', 'orig_name' => '',
        ], $over);
    }

    private static function xplorList(): array
    {
        return [
            self::xplor(['name' => 'Standalone Ships - Avenger Titan', 'ship' => true, 'ship_name' => 'Avenger Titan', 'orig_name' => 'Avenger Titan', 'lti' => true]),
            self::xplor(['name' => 'Packs - Deluxe', 'ship' => true, 'ship_name' => 'Mein Spitzname', 'orig_name' => '100i']),
            self::xplor(['name' => 'Upgrade - A to B', 'upgrade' => true]),
            self::xplor(['name' => 'Gear - A23 Flight Helmet', 'gear' => true]),
            self::xplor(['name' => 'Coin Display Case']),
        ];
    }

    private static function titles(array $entries): array
    {
        return array_column($entries, 'title');
    }

    // --- parseExport -------------------------------------------------------------------------

    public function testOnlyValidRsiHandlesAreTakenOver(): void
    {
        foreach (['SpaceFan', 'Space_Fan-2', ' Trim_Me '] as $ok) {
            $this->assertSame(trim($ok), Parser::parse(['handle' => $ok] + self::exportV2())['handle']);
        }
        foreach (['', 'x', 'Space Fan', '<script>', '../x', str_repeat('a', 101), 123, ['a']] as $bad) {
            $parsed = Parser::parse(['handle' => $bad] + self::exportV2());
            $this->assertNull($parsed['handle'], 'ungültig: ' . json_encode($bad));
            $this->assertNotEmpty($parsed['entries'], 'der Import selbst läuft trotzdem');
        }
    }

    public function testReadsV2AndUpgradesBecomeOwnEntry(): void
    {
        $parsed = Parser::parse(self::exportV2());
        $this->assertSame('SpaceFan', $parsed['handle']);
        $this->assertNotContains('Avenger Titan Renegade', self::titles($parsed['entries']));
        $this->assertCount(4, array_filter($parsed['entries'], fn ($e) => $e['isShip']));
        $upgrade = array_values(array_filter($parsed['entries'], fn ($e) => ($e['category'] ?? null) === 'UPGRADE'))[0];
        $this->assertSame('Avenger Titan to Avenger Titan Renegade', $upgrade['title']);
    }

    public function testLtiPerPledge(): void
    {
        $parsed = Parser::parse(self::exportV2());
        $titans = array_values(array_filter($parsed['entries'], fn ($e) => $e['title'] === 'Avenger Titan'));
        $this->assertSame([true, false], array_column($titans, 'lti'));
        $hammer = array_values(array_filter($parsed['entries'], fn ($e) => str_contains($e['title'], 'Hammerhead')))[0];
        $this->assertTrue($hammer['lti']);
    }

    public function testReadsSimpleList(): void
    {
        $parsed = Parser::parse([['name' => '100i', 'lti' => true, 'quantity' => 2], ['foo' => 1]]);
        $this->assertSame([['title' => '100i', 'isShip' => true, 'lti' => true, 'quantity' => 2]], $parsed['entries']);
    }

    public function testXplorDetectedBeforeSimpleList(): void
    {
        $parsed = Parser::parse(self::xplorList());
        $this->assertSame('hangarxplor', $parsed['format']);
        $this->assertCount(2, array_filter($parsed['entries'], fn ($e) => $e['isShip']));
    }

    public function testXplorSkipsUpgradesAndKeepsLti(): void
    {
        $parsed = Parser::parse(self::xplorList());
        $this->assertNotContains('Upgrade - A to B', self::titles($parsed['entries']));
        $titan = array_values(array_filter($parsed['entries'], fn ($e) => $e['title'] === 'Avenger Titan'))[0];
        $this->assertTrue($titan['lti']);
    }

    public function testXplorUsesOriginalNameWhenShipNameUnknown(): void
    {
        $plan = Planner::plan(Parser::parse(self::xplorList()), self::catalog());
        $names = array_column($plan['matched'], 'name');
        $this->assertContains('Avenger Titan', $names);
        $this->assertContains('100i', $names);
        $this->assertSame([], $plan['unmatched']);
    }

    public function testMatchesByShipCodeAndWithoutEditionSuffix(): void
    {
        $cat = Matcher::buildIndex([
            self::row('SHIP', 'rsi-ursa-rover', 'Ursa'),
            self::row('SHIP', 'drak-dragonfly-pink', 'Dragonfly Star Kitten'),
        ]);
        $plan = Planner::plan(Parser::parse([
            self::xplor(['ship' => true, 'ship_name' => 'Ursa Rover', 'orig_name' => 'Ursa Rover', 'ship_code' => 'RSI_Ursa_Rover']),
            self::xplor(['ship' => true, 'ship_name' => 'Dragonfly Star Kitten Edition', 'orig_name' => 'Dragonfly Star Kitten Edition', 'ship_code' => 'DRAK_X']),
        ]), $cat);
        $names = array_column($plan['matched'], 'name');
        sort($names);
        $this->assertSame(['Dragonfly Star Kitten', 'Ursa'], $names);
        $this->assertSame([], $plan['unmatched']);
    }

    public function testUsesShipCodeWhenNameFindsNothing(): void
    {
        $cat = Matcher::buildIndex([self::row('SHIP', 'orig-100i', '100i', ['code_key' => 'orig100i'])]);
        $plan = Planner::plan(Parser::parse([
            self::xplor(['ship' => true, 'ship_name' => 'Ganz Anderer Name', 'orig_name' => 'Ganz Anderer Name', 'ship_code' => 'ORIG_100i']),
        ]), $cat);
        $this->assertSame('orig-100i', $plan['matched'][0]['catalogItemId']);
    }

    public function testDoesNotConfuseVariantsWithAmbiguousCode(): void
    {
        $cat = Matcher::buildIndex([
            self::row('SHIP', 'rsi-ursa', 'Ursa', ['code_key' => 'rsiursarover']),
            self::row('SHIP', 'rsi-ursa-fortuna', 'Ursa Fortuna', ['code_key' => 'rsiursaroveremerald']),
        ]);
        $plan = Planner::plan(Parser::parse([
            self::xplor(['ship' => true, 'ship_name' => 'Ursa Rover', 'orig_name' => 'Ursa Rover', 'ship_code' => 'RSI_Ursa']),
            self::xplor(['ship' => true, 'ship_name' => 'Ursa Rover Fortuna', 'orig_name' => 'Ursa Rover Fortuna', 'ship_code' => 'RSI_Ursa']),
        ]), $cat);
        $ids = array_column($plan['matched'], 'catalogItemId');
        sort($ids);
        $this->assertSame(['rsi-ursa', 'rsi-ursa-fortuna'], $ids);
    }

    public function testDropsLastWordForVariantsWithoutOwnEntry(): void
    {
        $cat = Matcher::buildIndex([self::row('SHIP', 'rsi-constellation-phoenix', 'Constellation Phoenix')]);
        $plan = Planner::plan(Parser::parse([
            self::xplor(['ship' => true, 'ship_name' => 'Constellation Phoenix Emerald', 'orig_name' => 'Constellation Phoenix Emerald', 'ship_code' => 'X']),
        ]), $cat);
        $this->assertSame('Constellation Phoenix', $plan['matched'][0]['name']);
    }

    public function testDoesNotShortenTwoWordNames(): void
    {
        $cat = Matcher::buildIndex([self::row('SHIP', 'cutlass', 'Cutlass')]);
        $plan = Planner::plan(Parser::parse([['name' => 'Cutlass Unbekannt']]), $cat);
        $this->assertCount(0, $plan['matched']);
        $this->assertCount(1, $plan['unmatched']);
    }

    public function testGearMatchedToArmorAndRestIgnored(): void
    {
        $plan = Planner::plan(Parser::parse(self::xplorList()), self::catalog());
        $armor = array_values(array_filter($plan['matched'], fn ($m) => $m['kind'] === 'ARMOR'))[0];
        $this->assertSame('A23 Flight Helmet', $armor['name']);
        $this->assertSame(1, $plan['ignored']);
    }

    public function testRejectsUnknownFormats(): void
    {
        foreach ([['hello' => 'world'], [], 'text'] as $bad) {
            try {
                Parser::parse($bad);
                $this->fail('Format hätte abgelehnt werden müssen');
            } catch (ImportFormatError) {
                $this->addToAssertionCount(1);
            }
        }
    }

    // --- planFromParsed ----------------------------------------------------------------------

    public function testSeparatesLtiAndNonLti(): void
    {
        $plan = Planner::plan(Parser::parse(self::exportV2()), self::catalog());
        $titans = array_values(array_filter($plan['matched'], fn ($m) => $m['catalogItemId'] === 'aegs-avenger-titan'));
        $pairs = array_map(fn ($t) => [$t['lti'], $t['quantity']], $titans);
        usort($pairs, fn ($a, $b) => (int) $a[0] <=> (int) $b[0]);
        $this->assertSame([[false, 1], [true, 1]], $pairs);
    }

    public function testFindsShipsWithManufacturerPrefixAndPicksShortestSlug(): void
    {
        $plan = Planner::plan(Parser::parse(self::exportV2()), self::catalog());
        $hammer = array_values(array_filter($plan['matched'], fn ($m) => $m['name'] === 'Hammerhead'))[0];
        $this->assertSame('aegs-hammerhead', $hammer['catalogItemId']);
    }

    public function testNonShipsToArmorSkinsAndUpgradesOwnEntriesInsuranceIgnored(): void
    {
        $plan = Planner::plan(Parser::parse(self::exportV2()), self::catalog());
        $armor = array_values(array_filter($plan['matched'], fn ($m) => $m['kind'] === 'ARMOR'))[0];
        $this->assertSame('A23 Flight Helmet', $armor['name']);
        $this->assertSame([
            ['name' => 'Some Skin', 'kind' => 'PAINT', 'quantity' => 1],
            ['name' => 'Avenger Titan to Avenger Titan Renegade', 'kind' => 'UPGRADE', 'quantity' => 1],
        ], $plan['others']);
        $this->assertSame(1, $plan['ignored']);
    }

    public function testTakesLootAndGearSumsEqualAndDropsGameAccess(): void
    {
        $p = Planner::plan(Parser::parse([
            'type' => 'hangarexport', 'version' => 2, 'handle' => null,
            'pledges' => [
                ['pledgeName' => 'Loot - Coin', 'items' => [['title' => 'Crossed Swords Coin', 'kind' => 'Item']], 'alsoContains' => []],
                ['pledgeName' => 'Loot - Coin', 'items' => [['title' => 'Crossed Swords Coin', 'kind' => 'Item']], 'alsoContains' => []],
                ['pledgeName' => 'Paint - Disco', 'items' => [['title' => 'Aurora Disco Paint', 'kind' => 'Item']], 'alsoContains' => []],
                ['pledgeName' => 'Package - X', 'items' => [['title' => 'Upgrade - Reclaimer To Hull D', 'kind' => 'Item']], 'alsoContains' => []],
                ['pledgeName' => 'Game Package', 'items' => [['title' => 'Star Citizen Digital Download', 'kind' => 'Item']], 'alsoContains' => []],
            ],
        ]), self::catalog());
        $this->assertSame([
            ['name' => 'Crossed Swords Coin', 'kind' => 'ITEM', 'quantity' => 2],
            ['name' => 'Aurora Disco Paint', 'kind' => 'PAINT', 'quantity' => 1],
            ['name' => 'Reclaimer To Hull D', 'kind' => 'UPGRADE', 'quantity' => 1],
        ], $p['others']);
        $this->assertSame(1, $p['ignored']);
        $this->assertSame([], $p['matched']);
    }

    public function testReportsUnknownShips(): void
    {
        $plan = Planner::plan(Parser::parse(self::exportV2()), self::catalog());
        $this->assertSame([['name' => 'Totally Unknown Ship', 'lti' => false, 'quantity' => 1]], $plan['unmatched']);
    }

    public function testUsesPledgeNameAsSecondKey(): void
    {
        $p = Planner::plan(Parser::parse([['name' => 'Crusader Mercury Star Runner']]), self::catalog());
        $this->assertSame('crus-msr', $p['matched'][0]['catalogItemId']);
    }

    public function testSumsEqualShips(): void
    {
        $p = Planner::plan(Parser::parse([['name' => '100i'], ['name' => '100i']]), self::catalog());
        $this->assertCount(1, $p['matched']);
        $this->assertSame(2, $p['matched'][0]['quantity']);
    }
}
