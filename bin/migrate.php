<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$applied = Hangar\Migrator::run();
echo $applied === [] ? "Datenbank ist aktuell.\n" : 'Eingespielt: ' . implode(', ', $applied) . "\n";
