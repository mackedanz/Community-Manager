<?php

declare(strict_types=1);

namespace Hangar\Tests;

use Hangar\Catalog\CatalogError;
use Hangar\Catalog\Fetch;
use Hangar\Catalog\Refresh;
use Hangar\Db;
use Hangar\Http\Client;

final class RefreshTest extends DbTestCase
{
    /** @var list<string> */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        Fetch::$sleep = static fn (int $s) => null;
        $this->calls = [];
    }

    protected function tearDown(): void
    {
        Fetch::$sleep = null;
        parent::tearDown();
    }

    private function installFakes(int $matrixStatus = 200): void
    {
        $matrix = json_decode((string) file_get_contents(__DIR__ . '/fixtures/ship-matrix-sample.json'), true);
        Client::fake(function (string $m, string $url) use ($matrix, $matrixStatus): array {
            $this->calls[] = $url;
            $json = fn ($b, int $s = 200) => ['status' => $s, 'body' => json_encode($b), 'headers' => []];
            if (str_contains($url, 'ship-matrix')) {
                return $json($matrix, $matrixStatus);
            }
            if (str_contains($url, 'fleetyards.net')) {
                return $json(['items' => [], 'meta' => ['pagination' => ['totalPages' => 1]]]);
            }
            if (str_contains($url, 'star-citizen.wiki')) {
                return $json(['data' => [['slug' => 'a23-helmet', 'name' => 'A23 Flight Helmet', 'type_label' => 'Helmet (Armor)']], 'meta' => ['last_page' => 1]]);
            }
            return $json([], 404);
        });
    }

    private function orphan(string $kind, string $name): string
    {
        $u = $this->mkUser();
        $id = new_id();
        Db::insert('owned_items', ['id' => $id, 'user_id' => $u['id'], 'kind' => $kind, 'custom_name' => $name, 'quantity' => 1, 'lti' => 0, 'source' => 'IMPORT']);
        return $id;
    }

    public function testShipsOnlyRunLinksShipsAndLeavesArmorAlone(): void
    {
        $this->installFakes();
        $ship = $this->orphan('SHIP', 'Retaliator');
        $armor = $this->orphan('ARMOR', 'A23 Flight Helmet');

        $r = Refresh::runShips(null, 1);
        $this->assertSame('SHIP', $r[0]['kind']);
        $this->assertNotNull(Db::val('SELECT catalog_item_id FROM owned_items WHERE id = ?', [$ship]), 'Schiff verknüpft');
        $this->assertNull(Db::val('SELECT catalog_item_id FROM owned_items WHERE id = ?', [$armor]), 'Rüstung bleibt unberührt');
        $this->assertSame([], array_filter($this->calls, fn ($u) => str_contains($u, 'star-citizen.wiki')), 'kein Wiki-Abruf');
        $this->assertSame(0, (int) Db::val("SELECT COUNT(*) FROM catalog_items WHERE kind = 'ARMOR'"));
        $last = Refresh::lastRun();
        $this->assertTrue($last['ok']);
        $this->assertSame('ships', $last['scope']);
    }

    public function testFullRunAlsoLoadsArmorAndLinksIt(): void
    {
        $this->installFakes();
        $armor = $this->orphan('ARMOR', 'A23 Flight Helmet');
        Refresh::run(null, 1);
        $this->assertNotNull(Db::val('SELECT catalog_item_id FROM owned_items WHERE id = ?', [$armor]));
        $this->assertSame('full', Refresh::lastRun()['scope']);
    }

    public function testFailedRunIsRecordedAndKeepsTheCatalog(): void
    {
        $this->installFakes(503);
        try {
            Refresh::runShips(null, 1);
            $this->fail('Fehler erwartet');
        } catch (CatalogError) {
            $this->addToAssertionCount(1);
        }
        $last = Refresh::lastRun();
        $this->assertFalse($last['ok']);
        $this->assertNotEmpty($last['error']);
        $this->assertSame(0, (int) Db::val("SELECT COUNT(*) FROM catalog_items"));
    }

    public function testAutoRefreshOnlyWhenShipsWereNotFoundAndAtMostOncePerHour(): void
    {
        $now = 1_800_000_000;
        $this->assertFalse(Refresh::shouldRefreshAfterImport(0, $now), 'alles gefunden: nichts zu tun');
        $this->assertTrue(Refresh::shouldRefreshAfterImport(3, $now));
        $this->assertFalse(Refresh::shouldRefreshAfterImport(3, $now + 600), 'höchstens einmal pro Stunde');
        $this->assertTrue(Refresh::shouldRefreshAfterImport(3, $now + 3700));
    }

    public function testNoAutoRefreshRightAfterAFreshRun(): void
    {
        $this->installFakes();
        Refresh::runShips(null, 1);
        $this->assertFalse(Refresh::shouldRefreshAfterImport(5), 'Katalog ist gerade erst aktualisiert worden');
    }

    public function testAfterImportReturnsAWorkerOnlyWhenNeeded(): void
    {
        $this->assertNull(Refresh::afterImport(0));
        $job = Refresh::afterImport(2);
        $this->assertIsCallable($job);
        $this->installFakes();
        $ship = $this->orphan('SHIP', 'Retaliator');
        // Ein Fehler beim Abruf darf den Import nie stören
        Client::fake(fn () => ['status' => 500, 'body' => '', 'headers' => []]);
        $job();
        $this->assertNull(Db::val('SELECT catalog_item_id FROM owned_items WHERE id = ?', [$ship]));
    }
}
