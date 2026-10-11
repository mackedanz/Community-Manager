<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Hangar\Db;
use Hangar\Env;
use Hangar\Migrator;

// Tests laufen gegen eine eigene Datenbank, nie gegen die Entwicklungsdatenbank.
putenv('DB_NAME=hangar_test');
Env::reset();
Db::disconnect();

$pdo = Db::pdo();
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
    $pdo->exec('DROP TABLE `' . $table . '`');
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
Migrator::run();
