<?php

declare(strict_types=1);

namespace Hangar\Tests;

use Hangar\Catalog\CatalogError;
use Hangar\Catalog\Erkul;
use Hangar\Env;
use Hangar\Http\Client;
use PHPUnit\Framework\TestCase;

final class ErkulTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/erkul-test-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        Env::set('IMAGE_DIR', $this->dir);
        Erkul::reset();
    }

    protected function tearDown(): void
    {
        Client::fake(null);
        Env::reset();
        Erkul::reset();
        array_map('unlink', glob($this->dir . '/*') ?: []);
        rmdir($this->dir);
    }

    private static function bin(array $data): string
    {
        return (string) gzdeflate((string) json_encode($data));
    }

    /** @param list<array{className:string,name:string,displayName:string}> $ships */
    private function fakeErkul(array $ships, int $status = 200): void
    {
        Client::fake(function (string $m, string $url) use ($ships, $status): array {
            $body = str_ends_with($url, 'catalog.bin')
                ? self::bin(['singles' => [['kind' => 'index', 'path' => 'index.abc.bin']]])
                : self::bin(['ships' => $ships]);
            return ['status' => $status, 'body' => $body, 'headers' => []];
        });
    }

    private static function ships(int $n): array
    {
        $ships = [['className' => 'rsi_apollo_triage', 'name' => 'Roberts Space Industries Apollo Triage', 'displayName' => 'Apollo Triage']];
        for ($i = 1; $i < $n; $i++) {
            $ships[] = ['className' => "test_ship_$i", 'name' => "Test Ship $i", 'displayName' => "Ship $i"];
        }
        return $ships;
    }

    public function testFindsShipDespiteDifferentSpelling(): void
    {
        $this->assertSame('https://www.erkul.games/ship/rsi_apollo_triage', Erkul::url('Apollo Triage', 'Roberts Space Industries'));
        $this->assertSame('https://www.erkul.games/ship/anvl_c8r_pisces', Erkul::url('C8R Pisces'));
        $this->assertSame('https://www.erkul.games/ship/crus_star_runner', Erkul::url('Mercury'));
    }

    public function testUnknownShipGetsNoLink(): void
    {
        $this->assertNull(Erkul::url('Gibt Es Nicht'));
        $this->assertNull(Erkul::url(''));
    }

    public function testRefreshBuildsTableFromErkulAndKeepsOverrides(): void
    {
        $ships = self::ships(120);
        $ships[] = ['className' => 'crus_star_runner', 'name' => 'Crusader Mercury Star Runner', 'displayName' => 'Mercury Star Runner'];
        $this->fakeErkul($ships);

        $this->assertGreaterThan(200, Erkul::refresh());
        $this->assertSame('https://www.erkul.games/ship/test_ship_7', Erkul::url('Ship 7'));
        $this->assertSame('https://www.erkul.games/ship/crus_star_runner', Erkul::url('Mercury'), 'Zuordnung aus erkul-overrides.json');
        $this->assertNull(Erkul::url('C8R Pisces'), 'Überschreibung nur, wenn Erkul die Kennung kennt');
        $this->assertFileExists($this->dir . '/erkul-ships.json');

        Erkul::reset(); // neue Anfrage liest die gespeicherte Tabelle
        $this->assertSame('https://www.erkul.games/ship/test_ship_7', Erkul::url('Ship 7'));
    }

    public function testRefreshFailureKeepsOldTable(): void
    {
        $this->fakeErkul(self::ships(5));
        try {
            Erkul::refresh();
            $this->fail('zu kurze Liste muss abgelehnt werden');
        } catch (CatalogError) {
        }
        $this->fakeErkul(self::ships(120), 503);
        try {
            Erkul::refresh();
            $this->fail('HTTP-Fehler muss gemeldet werden');
        } catch (CatalogError) {
        }
        $this->assertFileDoesNotExist($this->dir . '/erkul-ships.json');
        $this->assertSame('https://www.erkul.games/ship/rsi_apollo_triage', Erkul::url('Apollo Triage'), 'mitgelieferter Stand');
    }
}
