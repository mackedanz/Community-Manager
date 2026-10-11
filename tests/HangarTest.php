<?php

declare(strict_types=1);

namespace Hangar\Tests;

use Hangar\Db;
use Hangar\Hangar;
use Hangar\HangarError;

final class HangarTest extends DbTestCase
{
    private string $alice;
    private string $bob;
    private string $shipId;
    private string $helmetId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shipId = new_id();
        $this->helmetId = new_id();
        Db::insert('catalog_items', ['id' => $this->shipId, 'kind' => 'SHIP', 'slug' => 'test-ship', 'name' => 'Test Ship', 'match_key' => 'testship', 'data' => '{}']);
        Db::insert('catalog_items', ['id' => $this->helmetId, 'kind' => 'ARMOR', 'slug' => 'test-helmet', 'name' => 'Test Helmet', 'match_key' => 'testhelmet', 'data' => '{}']);
        $this->alice = $this->mkUser(['name' => 'Alice'])['id'];
        $this->bob = $this->mkUser(['name' => 'Bob'])['id'];
        foreach ([
            ['catalog_item_id' => $this->shipId, 'kind' => 'SHIP'],
            ['catalog_item_id' => $this->helmetId, 'kind' => 'ARMOR'],
            ['custom_name' => 'Unbekanntes Schiff', 'kind' => 'SHIP'],
        ] as $row) {
            Db::insert('owned_items', ['id' => new_id(), 'user_id' => $this->alice, 'source' => 'IMPORT'] + $row);
        }
    }

    public function testListsOnlyOwnHangar(): void
    {
        $this->assertCount(3, Hangar::listHangar($this->alice));
        $this->assertCount(0, Hangar::listHangar($this->bob));
    }

    public function testGroupsByKindAndNamesEntriesWithoutCatalogByFreeName(): void
    {
        $groups = Hangar::groupByKind(Hangar::listHangar($this->alice));
        $ships = array_map([Hangar::class, 'entryName'], $groups['SHIP']);
        sort($ships);
        $this->assertSame(['Test Ship', 'Unbekanntes Schiff'], $ships);
        $this->assertSame(['Test Helmet'], array_map([Hangar::class, 'entryName'], $groups['ARMOR']));
    }

    public function testAddsManualEntriesAndSumsEqualOnes(): void
    {
        Hangar::addItem($this->bob, $this->shipId, '2', true);
        Hangar::addItem($this->bob, $this->shipId, 1, true);
        Hangar::addItem($this->bob, $this->shipId, 1, false);

        $items = Hangar::listHangar($this->bob);
        $this->assertCount(2, $items);
        foreach ($items as $i) {
            $this->assertSame('MANUAL', $i['source']);
        }
        $lti = array_values(array_filter($items, fn ($i) => $i['lti']))[0];
        $plain = array_values(array_filter($items, fn ($i) => !$i['lti']))[0];
        $this->assertSame(3, $lti['quantity']);
        $this->assertSame(1, $plain['quantity']);
    }

    public function testRejectsInvalidQuantitiesAndUnknownObjects(): void
    {
        foreach ([0, 100, 'abc', 1.5] as $bad) {
            try {
                Hangar::addItem($this->bob, $this->shipId, $bad, false);
                $this->fail("Menge $bad wurde akzeptiert");
            } catch (HangarError) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(HangarError::class);
        Hangar::addItem($this->bob, 'gibt-es-nicht', 1, false);
    }

    public function testRemovesOnlyOwnEntries(): void
    {
        Hangar::addItem($this->bob, $this->shipId, 1, true);
        $mine = Hangar::listHangar($this->bob)[0];
        $foreign = Hangar::listHangar($this->alice)[0];

        $this->assertFalse(Hangar::removeItem($this->bob, $foreign['id']));
        $this->assertCount(3, Hangar::listHangar($this->alice));

        $this->assertTrue(Hangar::removeItem($this->bob, $mine['id']));
        $this->assertTrue(Hangar::removeItem($this->alice, $foreign['id']));
        $this->assertCount(2, Hangar::listHangar($this->alice));
    }

    public function testRemovesAllOfKindOnlyForOwner(): void
    {
        Hangar::addItem($this->bob, $this->shipId, 1, false);
        Hangar::addItem($this->bob, $this->helmetId, 1, false);
        $aliceShips = count(array_filter(Hangar::listHangar($this->alice), fn ($i) => $i['kind'] === 'SHIP'));

        $this->assertSame(1, Hangar::removeAllOfKind($this->bob, 'SHIP'));
        $this->assertSame(['ARMOR'], array_column(Hangar::listHangar($this->bob), 'kind'));
        $this->assertCount($aliceShips, array_filter(Hangar::listHangar($this->alice), fn ($i) => $i['kind'] === 'SHIP'));
        $this->assertSame(0, Hangar::removeAllOfKind($this->bob, 'SHIP'));
    }

    public function testSearchFindsShipsAndArmor(): void
    {
        $this->assertSame(['Test Ship'], array_column(Hangar::searchCatalog('test ship'), 'name'));
        $this->assertSame([$this->helmetId], array_column(Hangar::searchCatalog('helmet'), 'id'));
        $this->assertSame([], Hangar::searchCatalog('   '));
    }

    public function testSearchTreatsWildcardsLiterally(): void
    {
        $this->assertSame([], Hangar::searchCatalog('%'));
    }

    public function testAddsInfoFromWikiForLootWithoutCatalog(): void
    {
        Db::insert('owned_items', ['id' => new_id(), 'user_id' => $this->bob, 'kind' => 'ITEM', 'custom_name' => 'Crossed Swords Coin', 'source' => 'IMPORT']);
        Db::insert('item_info', ['match_key' => 'crossedswordscoin', 'found' => 1, 'name' => 'Crossed Swords Coin', 'description' => 'Münze']);
        $items = Hangar::listHangar($this->bob);
        $this->assertSame('Münze', $items[0]['info']['description']);
    }

    public function testCsvHeaderQuotingAndFormulaGuard(): void
    {
        $csv = Hangar::toCsv([
            ['kategorie' => 'Schiffe', 'name' => 'A;"B"', 'hersteller' => 'Aegis', 'anzahl' => 2, 'lti' => true, 'quelle' => 'RSI'],
            ['kategorie' => 'Sonstiges', 'name' => '=SUMME(A1)', 'hersteller' => '', 'anzahl' => 1, 'lti' => false, 'quelle' => 'manuell'],
        ]);
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $lines = explode("\r\n", trim(substr($csv, 3)));
        $this->assertSame('Kategorie;Name;Hersteller;Anzahl;LTI;Quelle', $lines[0]);
        $this->assertSame('Schiffe;"A;""B""";Aegis;2;ja;RSI', $lines[1]);
        $this->assertSame("Sonstiges;'=SUMME(A1);;1;nein;manuell", $lines[2]);
    }

    public function testExportRowsOptionallyFilteredByKind(): void
    {
        $rows = Hangar::exportRows(Hangar::listHangar($this->alice), 'ARMOR');
        $this->assertCount(1, $rows);
        $this->assertSame('Rüstungen', $rows[0]['kategorie']);
        $this->assertSame('RSI', $rows[0]['quelle']);
        $this->assertCount(3, Hangar::exportRows(Hangar::listHangar($this->alice)));
    }
}
