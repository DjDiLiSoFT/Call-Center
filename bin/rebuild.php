<?php
// Full rebuild for a period: php bin/rebuild.php --from=2026-10-01 --to=2026-10-21

use CallStats\Collector;
use CallStats\Db;
use CallStats\ProcessLock;

$config = require __DIR__ . '/../src/bootstrap.php';

$opt = getopt('', ['from:', 'to:']);
$from = isset($opt['from']) ? (string) $opt['from'] : '';
$to = isset($opt['to']) ? (string) $opt['to'] : '';
$re = '/^\d{4}-\d{2}-\d{2}$/';
if (!preg_match($re, $from) || !preg_match($re, $to)) {
    fwrite(STDERR, "Использование: php bin/rebuild.php --from=ГГГГ-ММ-ДД --to=ГГГГ-ММ-ДД\n");
    exit(2);
}

// Do not run together with the cron collector.
try {
    $lock = ProcessLock::open();
} catch (\RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
if (!flock($lock, LOCK_EX)) {
    fwrite(STDERR, "Не удалось получить блокировку\n");
    exit(1);
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
    $t = microtime(true);
    $counters = $collector->rebuild($from, $to);
    foreach ($counters as $k => $v) {
        echo str_pad($k, 16) . $v . "\n";
    }
    printf("%-16s%.1f s\n", 'time', microtime(true) - $t);
} catch (\Throwable $e) {
    fwrite(STDERR, 'rebuild failed: ' . $e->getMessage() . "\n");
    exit(1);
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
