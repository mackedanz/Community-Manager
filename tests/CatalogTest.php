<?php

declare(strict_types=1);

namespace Hangar\Tests;

use Hangar\Catalog\CatalogError;
use Hangar\Catalog\Fetch;
use Hangar\Catalog\FleetYards;
use Hangar\Catalog\Relink;
use Hangar\Catalog\ShipMatrix;
use Hangar\Catalog\Store;
use Hangar\Catalog\Sync;
use Hangar\Catalog\WikiArmor;
use Hangar\Db;
use Hangar\Http\Client;

final class CatalogTest extends DbTestCase
{
    /** @var list<string> */
    private array $calls = [];
    private bool $fleetyardsDown = false;

    protected function setUp(): void
    {
        parent::setUp();
        Fetch::$sleep = static fn (int $s) => null;
    }

    protected function tearDown(): void
    {
        Fetch::$sleep = null;
        parent::tearDown();
    }

    private static function matrix(): array
    {
        return json_decode((string) file_get_contents(__DIR__ . '/fixtures/ship-matrix-sample.json'), true);
    }

    private static function ship(string $name): array
    {
        foreach (self::matrix()['data'] as $s) {
            if ($s['name'] === $name) {
                return $s;
            }
        }
        throw new \LogicException("Fixture ohne $name");
    }

    /** Installiert ein gemeinsames Fake für Ship Matrix, FleetYards und Wiki. */
    private function installFakes(?array $matrix = null): void
    {
        $matrix ??= self::matrix();
        Client::fake(function (string $m, string $url) use ($matrix): array {
            $this->calls[] = $url;
            $json = fn ($b, int $s = 200) => ['status' => $s, 'body' => json_encode($b), 'headers' => []];
            if (str_contains($url, 'ship-matrix')) {
                return $json($matrix);
            }
            if (str_contains($url, 'fleetyards.net')) {
                if ($this->fleetyardsDown) {
                    return $json([], 503);
                }
                if (preg_match('#/models/([^/]+)/modules#', $url, $mm)) {
                    return $json(['items' => [
                        ['name' => 'Front Cargo Module', 'slug' => 'front-cargo-module'],
                        ['name' => 'Rear Cargo Module', 'slug' => 'rear-cargo-module'],
                    ]]);
                }
                return $json([
                    'items' => [
                        ['slug' => 'aegs-retaliator', 'name' => 'Retaliator', 'rsiName' => 'Retaliator', 'rsiId' => 99, 'scIdentifier' => 'aegs_retaliator', 'hasModules' => true, 'pledgePrice' => 275],
                        ['slug' => 'rsi-aurora-es', 'name' => 'Aurora ES', 'rsiName' => 'Aurora Mk I ES Pledge', 'rsiId' => 1, 'scIdentifier' => 'rsi_aurora_es', 'hasModules' => false],
                    ],
                    'meta' => ['pagination' => ['totalPages' => 1]],
                ]);
            }
            if (str_contains($url, 'star-citizen.wiki')) {
                return $json(['data' => [
                    ['slug' => 'a23-helmet', 'name' => 'A23 Flight Helmet', 'type_label' => 'Helmet (Armor)', 'images' => ['thumbnail_url' => 'https://example.test/a.png']],
                    ['kaputt' => true],
                ], 'meta' => ['last_page' => 1]]);
            }
            return $json([], 404);
        });
    }

    // --- ShipMatrix --------------------------------------------------------------------------

    public function testMapsShipFields(): void
    {
        $r = ShipMatrix::map(self::ship('Retaliator'));
        $this->assertSame('SHIP', $r['kind']);
        $this->assertSame('RSI_MATRIX', $r['source']);
        $this->assertSame(99, $r['rsi_id']);
        $this->assertSame('retaliator', $r['slug']);
        $this->assertSame('retaliator', $r['match_key']);
        $this->assertSame('Aegis Dynamics', $r['manufacturer']);
        $d = $r['data'];
        $this->assertSame('Combat', $d['career']);
        $this->assertSame('Modular', $d['role']);
        $this->assertSame('flight-ready', $d['status']);
        $this->assertSame('large', $d['size']);
        $this->assertSame('Large', $d['sizeLabel']);
        $this->assertSame(7, $d['crewMax']);
        $this->assertSame(102, $d['cargo']);
        $this->assertSame('https://robertsspaceindustries.com/pledge/ships/aegis-retaliator/Retaliator', $d['webUrl']);
    }

    public function testUnifiesInconsistentCaseOfCareerAndSize(): void
    {
        $this->assertSame('Exploration', ShipMatrix::map(self::ship('Carrack w/C8X'))['data']['career']);
        $this->assertSame('large', ShipMatrix::map(self::ship('Carrack w/C8X'))['data']['size']);
        $this->assertSame('Transporter', ShipMatrix::map(self::ship('Merchantman'))['data']['career']);
        $this->assertSame('Multi-Role', ShipMatrix::careerLabel('multi'));
        $this->assertSame('Ground', ShipMatrix::careerLabel('ground'));
        $this->assertNull(ShipMatrix::careerLabel(''));
    }

    public function testShipWithoutSizeHasNullSizeLabel(): void
    {
        $r = ShipMatrix::map(self::ship('Ursa'));
        $this->assertNull($r['data']['size']);
        $this->assertNull($r['data']['sizeLabel']);
    }

    public function testDiscardsUnusableEntriesAndMakesSlugsUnique(): void
    {
        $this->assertNull(ShipMatrix::map(['id' => 5]));
        $this->assertNull(ShipMatrix::map(['name' => 'Ohne ID']));
        $all = ShipMatrix::mapAll([['id' => 1, 'name' => 'Mk. I'], ['id' => 2, 'name' => 'Mk I'], ['x' => 1]]);
        $this->assertCount(2, $all);
        $this->assertSame('mk-i', $all[0]['slug']);
        $this->assertSame('mk-i-2', $all[1]['slug']);
    }

    public function testFetchRejectsTooFewShipsAndBadFormat(): void
    {
        $this->installFakes();
        try {
            ShipMatrix::fetch('https://example.test/ship-matrix');
            $this->fail('zu wenige Schiffe hätten abgelehnt werden müssen');
        } catch (CatalogError $e) {
            $this->assertStringContainsString('verworfen', $e->getMessage());
        }
        $this->assertCount(9, ShipMatrix::fetch('https://example.test/ship-matrix', 5));

        $this->installFakes(['success' => 0, 'data' => []]);
        $this->expectException(CatalogError::class);
        ShipMatrix::fetch('https://example.test/ship-matrix', 0);
    }

    public function testFetchRetriesOnServerErrorsButNotOnClientErrors(): void
    {
        $n = 0;
        Client::fake(function () use (&$n) {
            $n++;
            return ['status' => $n < 3 ? 502 : 200, 'body' => '{"ok":1}', 'headers' => []];
        });
        $this->assertSame(['ok' => 1], Fetch::json('https://example.test/x'));
        $this->assertSame(3, $n);

        $m = 0;
        Client::fake(function () use (&$m) {
            $m++;
            return ['status' => 404, 'body' => '', 'headers' => []];
        });
        try {
            Fetch::json('https://example.test/y');
            $this->fail();
        } catch (CatalogError) {
            $this->assertSame(1, $m);
        }
    }

    // --- Sync --------------------------------------------------------------------------------

    public function testSyncSavesShipsEnrichedByFleetYardsAndModules(): void
    {
        $this->installFakes();
        $res = Sync::run('https://example.test/ship-matrix', 5);

        $this->assertSame('SHIP', $res[0]['kind']);
        $this->assertSame(9, $res[0]['saved']);
        $this->assertSame(1, $res[1]['saved']);          // kaputter Wiki-Eintrag verworfen
        $this->assertSame(1, $res[1]['skipped']);

        $ret = Db::one("SELECT * FROM catalog_items WHERE kind = 'SHIP' AND rsi_id = 99");
        $this->assertSame('aegs-retaliator', $ret['image_slug']);
        $this->assertSame('aegsretaliator', $ret['code_key']);
        $this->assertSame(275, json_decode($ret['data'], true)['msrp']);
        $this->assertSame(2, (int) Db::val('SELECT COUNT(*) FROM ship_modules WHERE ship_id = ?', [$ret['id']]));

        $aurora = Db::one("SELECT * FROM catalog_items WHERE kind = 'SHIP' AND rsi_id = 1");
        $this->assertSame('auroramkiespledge', $aurora['alt_match_key']);   // Pledge-Name weicht ab
        $this->assertSame('RSI_MATRIX', $aurora['source']);

        $armor = Db::one("SELECT * FROM catalog_items WHERE kind = 'ARMOR'");
        $this->assertSame('WIKI', $armor['source']);
        $this->assertSame('https://example.test/a.png', $armor['image_url']);
    }

    public function testSyncIsIdempotentForShipsAndModules(): void
    {
        $this->installFakes();
        Sync::run('https://example.test/ship-matrix', 5);
        $ships = (int) Db::val('SELECT COUNT(*) FROM catalog_items');
        $modules = (int) Db::val('SELECT COUNT(*) FROM ship_modules');
        Sync::run('https://example.test/ship-matrix', 5);
        $this->assertSame($ships, (int) Db::val('SELECT COUNT(*) FROM catalog_items'));
        $this->assertSame($modules, (int) Db::val('SELECT COUNT(*) FROM ship_modules'));
    }

    public function testRemovedModulesAreDeleted(): void
    {
        $this->installFakes();
        Sync::run('https://example.test/ship-matrix', 5);
        $id = Db::val("SELECT id FROM catalog_items WHERE rsi_id = 99");
        Store::saveModules($id, [['name' => 'Front Cargo Module', 'slug' => 'front-cargo-module']]);
        $this->assertSame(['front-cargo-module'], array_column(Db::all('SELECT slug FROM ship_modules WHERE ship_id = ?', [$id]), 'slug'));
        Store::saveModules($id, []);
        $this->assertSame(0, (int) Db::val('SELECT COUNT(*) FROM ship_modules WHERE ship_id = ?', [$id]));
    }

    public function testSyncWorksWithoutFleetYards(): void
    {
        $this->fleetyardsDown = true;
        $this->installFakes();
        $res = Sync::run('https://example.test/ship-matrix', 5);
        $this->assertSame(9, $res[0]['saved']);
        $this->assertStringContainsString('FleetYards nicht erreichbar', (string) $res[0]['note']);
        $this->assertNull(Db::val('SELECT image_slug FROM catalog_items WHERE rsi_id = 99'));
        $this->assertSame(0, (int) Db::val('SELECT COUNT(*) FROM ship_modules'));
    }

    public function testFailedMatrixFetchLeavesCatalogUntouched(): void
    {
        $this->installFakes();
        Sync::run('https://example.test/ship-matrix', 5);
        $before = (int) Db::val('SELECT COUNT(*) FROM catalog_items');
        $this->installFakes(['success' => 1, 'data' => []]);
        try {
            Sync::run('https://example.test/ship-matrix', 5);
            $this->fail();
        } catch (CatalogError) {
            $this->assertSame($before, (int) Db::val('SELECT COUNT(*) FROM catalog_items'));
        }
    }

    public function testRenamedShipKeepsItsIdAndHangarLinks(): void
    {
        $this->installFakes();
        Sync::run('https://example.test/ship-matrix', 5);
        $id = Db::val('SELECT id FROM catalog_items WHERE rsi_id = 99');
        $user = $this->mkUser();
        Db::insert('owned_items', ['id' => new_id(), 'user_id' => $user['id'], 'catalog_item_id' => $id, 'kind' => 'SHIP']);

        $m = self::matrix();
        foreach ($m['data'] as &$s) {
            if ($s['id'] === 99) {
                $s['name'] = 'Retaliator Reloaded';
            }
        }
        unset($s);
        $this->installFakes($m);
        Sync::run('https://example.test/ship-matrix', 5);

        $row = Db::one('SELECT * FROM catalog_items WHERE rsi_id = 99');
        $this->assertSame($id, $row['id']);
        $this->assertSame('retaliator-reloaded', $row['slug']);
        $this->assertSame('Retaliator Reloaded', $row['name']);
        $this->assertSame($id, Db::val('SELECT catalog_item_id FROM owned_items WHERE user_id = ?', [$user['id']]));
        $this->assertSame(1, (int) Db::val("SELECT COUNT(*) FROM catalog_items WHERE rsi_id = 99"));
    }

    public function testSyncRelinksFreeNameHangarEntries(): void
    {
        $user = $this->mkUser();
        $oid = new_id();
        Db::insert('owned_items', ['id' => $oid, 'user_id' => $user['id'], 'kind' => 'SHIP', 'custom_name' => 'Retaliator', 'source' => 'IMPORT']);
        $this->installFakes();
        $res = Sync::run('https://example.test/ship-matrix', 5);

        $row = Db::one('SELECT * FROM owned_items WHERE id = ?', [$oid]);
        $this->assertNotNull($row['catalog_item_id']);
        $this->assertNull($row['custom_name']);
        $this->assertStringContainsString('neu verknüpft', (string) end($res)['note']);
        $this->assertGreaterThan(0, (int) Db::val('SELECT COUNT(*) FROM user_achievements WHERE user_id = ?', [$user['id']]));
    }

    // --- Relink / Wiki -----------------------------------------------------------------------

    public function testRelinkOnlyWhenCatalogKnowsTheShip(): void
    {
        $user = $this->mkUser();
        $orphan = new_id();
        $unknown = new_id();
        Db::insert('owned_items', ['id' => $orphan, 'user_id' => $user['id'], 'kind' => 'SHIP', 'custom_name' => 'Kraken Test', 'source' => 'IMPORT']);
        Db::insert('owned_items', ['id' => $unknown, 'user_id' => $user['id'], 'kind' => 'SHIP', 'custom_name' => 'Gibt Es Nicht Xyz', 'source' => 'IMPORT']);

        $this->assertSame(0, Relink::orphans());
        $this->assertNull(Db::val('SELECT catalog_item_id FROM owned_items WHERE id = ?', [$orphan]));

        $cat = new_id();
        Db::insert('catalog_items', ['id' => $cat, 'kind' => 'SHIP', 'slug' => 'test-kraken', 'name' => 'Kraken Test', 'match_key' => 'krakentest', 'source' => 'RSI_MATRIX', 'data' => '{}']);
        $this->assertSame(1, Relink::orphans());
        $row = Db::one('SELECT * FROM owned_items WHERE id = ?', [$orphan]);
        $this->assertSame($cat, $row['catalog_item_id']);
        $this->assertNull($row['custom_name']);
        $this->assertNull(Db::val('SELECT catalog_item_id FROM owned_items WHERE id = ?', [$unknown]));
    }

    public function testWikiArmorAcceptsImageAsObjectOrList(): void
    {
        $base = ['slug' => 'a23', 'name' => 'A23 Flight Helmet', 'type_label' => 'Helmet (Armor)'];
        $this->assertSame('https://example.test/a.png', WikiArmor::map($base + ['images' => ['thumbnail_url' => 'https://example.test/a.png']])['image_url']);
        $this->assertSame('https://example.test/b.png', WikiArmor::map($base + ['images' => [['thumbnail_url' => 'https://example.test/b.png']]])['image_url']);
        $this->assertSame('WIKI', WikiArmor::map($base)['source']);
        $this->assertNull(WikiArmor::map(['slug' => 'x']));
    }

    public function testFleetYardsMatchesByNameWhenRsiIdMissing(): void
    {
        $ships = ShipMatrix::mapAll([['id' => 500, 'name' => 'Bengal Carrier']]);
        $enriched = FleetYards::enrich($ships, [['slug' => 'misc-bengal', 'name' => 'Bengal Carrier', 'scIdentifier' => 'MISC_Bengal']]);
        $this->assertSame('misc-bengal', $enriched[0]['image_slug']);
        $this->assertSame('miscbengal', $enriched[0]['code_key']);
    }
}
