<?php
// Cron collector: php bin/collect.php (every minute). Only one instance runs at a time.

use CallStats\Collector;
use CallStats\Db;

$config = require __DIR__ . '/../src/bootstrap.php';

$lockDir = DIRECTORY_SEPARATOR === '/' ? '/tmp' : sys_get_temp_dir();
$lock = fopen($lockDir . '/callstats-collect.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0); // another run is in progress
}

try {
    $connect = function (string $name) use ($config) {
        return Db::connect(
            (string) $config->get("db.$name.dsn"),
            (string) $config->get("db.$name.user"),
            (string) $config->get("db.$name.pass", '')
        );
    };
    $collector = new Collector($config, $connect('callstats'), $connect('source'), $connect('fpbx'));
    $collector->runOnce(time());
} catch (\Throwable $e) {
    fwrite(STDERR, date('Y-m-d H:i:s') . ' callstats collect failed: ' . $e->getMessage() . "\n");
    exit(1);
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
