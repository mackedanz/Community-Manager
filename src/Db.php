<?php

declare(strict_types=1);

namespace Hangar;

use PDO;
use PDOStatement;

/** Dünne PDO-Hülle: eine Verbindung pro Prozess, UTC, Exceptions bei Fehlern. */
final class Db
{
    private static ?PDO $pdo = null;
    private static int $depth = 0;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $host = Env::get('DB_HOST', '127.0.0.1');
            $port = Env::get('DB_PORT', '3306');
            $name = Env::get('DB_NAME', 'hangar');
            $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
            self::$pdo = new PDO($dsn, Env::get('DB_USER', 'hangar'), Env::get('DB_PASSWORD', ''), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+00:00', sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'",
            ]);
        }
        return self::$pdo;
    }

    /** Für Tests und Neustart nach Konfigurationswechsel. */
    public static function disconnect(): void
    {
        self::$pdo = null;
        self::$depth = 0;
    }

    /** @param array<int|string,mixed> $params */
    public static function run(string $sql, array $params = []): PDOStatement
    {
        $st = self::pdo()->prepare($sql);
        $st->execute(self::norm($params));
        return $st;
    }

    /** @param array<int|string,mixed> $params @return list<array<string,mixed>> */
    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    /** @param array<int|string,mixed> $params @return array<string,mixed>|null */
    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** @param array<int|string,mixed> $params */
    public static function val(string $sql, array $params = []): mixed
    {
        $row = self::run($sql, $params)->fetch(PDO::FETCH_NUM);
        return $row === false ? null : $row[0];
    }

    /** @param array<int|string,mixed> $params Anzahl betroffener Zeilen */
    public static function exec(string $sql, array $params = []): int
    {
        return self::run($sql, $params)->rowCount();
    }

    /**
     * Fügt eine Zeile ein; Spaltennamen aus den Schlüsseln (nur feste, nie Nutzereingaben).
     * @param array<string,mixed> $row
     */
    public static function insert(string $table, array $row): void
    {
        $cols = array_keys($row);
        $sql = 'INSERT INTO ' . $table . ' (' . implode(',', array_map(fn ($c) => "`$c`", $cols)) . ') VALUES ('
            . implode(',', array_fill(0, count($cols), '?')) . ')';
        self::run($sql, array_values($row));
    }

    /** Platzhalterliste für IN (...); leere Liste ergibt eine nie zutreffende Bedingung. */
    public static function in(array $values): string
    {
        return $values === [] ? 'NULL' : implode(',', array_fill(0, count($values), '?'));
    }

    /**
     * @template T
     * @param callable():T $fn
     * @return T
     */
    public static function transaction(callable $fn): mixed
    {
        $pdo = self::pdo();
        if (self::$depth > 0) {
            return $fn();
        }
        $pdo->beginTransaction();
        self::$depth++;
        try {
            $result = $fn();
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        } finally {
            self::$depth--;
        }
    }

    /** @param array<int|string,mixed> $params @return array<int|string,mixed> */
    private static function norm(array $params): array
    {
        foreach ($params as $k => $v) {
            if (is_bool($v)) {
                $params[$k] = $v ? 1 : 0;
            } elseif ($v instanceof \DateTimeInterface) {
                $params[$k] = Time::db($v);
            }
        }
        return $params;
    }
}
