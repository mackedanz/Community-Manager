<?php

declare(strict_types=1);

namespace Hangar\Tests;

use Hangar\Db;
use Hangar\Env;
use Hangar\Http\Client;
use Hangar\Time;
use PHPUnit\Framework\TestCase;

/** Basisklasse: leere Tabellen vor jedem Test, keine echten HTTP-Aufrufe, feste Zeit nur auf Wunsch. */
abstract class DbTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $pdo = Db::pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN) as $table) {
            if ($table !== 'schema_migrations') {
                $pdo->exec('TRUNCATE TABLE `' . $table . '`');
            }
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        Time::freeze(null);
        Env::reset();
        Env::set('APP_KEY', 'test-schluessel-test-schluessel-1234567890');   // Verschlüsselung der Discord-Tokens
        // Jeder unerwartete HTTP-Aufruf lässt den Test scheitern
        Client::fake(static function (string $m, string $url): never {
            throw new \LogicException("Unerwarteter HTTP-Aufruf: $m $url");
        });
    }

    protected function tearDown(): void
    {
        Client::fake(null);
        Time::freeze(null);
        parent::tearDown();
    }

    private static int $seq = 0;

    /** @param array<string,mixed> $extra @return array<string,mixed> */
    protected function mkUser(array $extra = []): array
    {
        $n = ++self::$seq;
        $row = array_merge([
            'id' => new_id(),
            'name' => "User-$n",
            'discord_id' => (string) (100000000000000000 + $n),
        ], $extra);
        Db::insert('users', $row);
        return $row;
    }

    protected function ids(string $table, string $col = 'id'): array
    {
        return array_column(Db::all("SELECT `$col` FROM `$table`"), $col);
    }
}
