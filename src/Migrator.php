<?php

declare(strict_types=1);

namespace Hangar;

/** Spielt migrations/NNN_*.sql der Reihe nach ein und merkt sich den Stand in schema_migrations. */
final class Migrator
{
    /** @return list<string> Namen der neu eingespielten Migrationen */
    public static function run(?string $dir = null): array
    {
        $dir ??= dirname(__DIR__) . '/migrations';
        $pdo = Db::pdo();
        $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
            name VARCHAR(190) NOT NULL PRIMARY KEY,
            applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

        $done = array_column(Db::all('SELECT name FROM schema_migrations'), 'name');
        $files = glob($dir . '/*.sql') ?: [];
        sort($files);
        $applied = [];
        foreach ($files as $file) {
            $name = basename($file);
            if (in_array($name, $done, true)) {
                continue;
            }
            $sql = (string) file_get_contents($file);
            foreach (self::statements($sql) as $stmt) {
                $pdo->exec($stmt);
            }
            Db::run('INSERT INTO schema_migrations (name) VALUES (?)', [$name]);
            $applied[] = $name;
        }
        return $applied;
    }

    /** Teilt an Semikolon am Zeilenende; Zeilenkommentare (--) werden entfernt. @return list<string> */
    private static function statements(string $sql): array
    {
        $clean = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
        $parts = preg_split('/;\s*(?:\r?\n|$)/', $clean) ?: [];
        return array_values(array_filter(array_map('trim', $parts), fn ($s) => $s !== ''));
    }
}
