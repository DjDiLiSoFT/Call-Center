<?php
/*
 * Manual check on a real data sample (the sample itself is NOT in git).
 *
 *   tools/php/php.exe tests/manual/load-sample.php [sampleDir] [fromDate] [toDate]
 *
 * Recreates the test databases (callstats_test, asteriskcdrdb_test, asterisk_test on the test
 * MariaDB, see tests/Integration/DbTestCase.php), loads <sampleDir>/cdr.tsv, users.tsv,
 * queues_config.tsv (TSV with header, as written by `mysql -B`), copies queue_log.txt to a
 * temp file, runs Collector::rebuild for the range and prints counters and checks.
 */

use CallStats\Collector;
use CallStats\Config;
use CallStats\Db;

require __DIR__ . '/../../src/autoload.php';
date_default_timezone_set('Asia/Tashkent');

$sampleDir = $argv[1] ?? __DIR__ . '/../../samples/callstats-sample';
$fromDate = $argv[2] ?? '2026-10-18';
$toDate = $argv[3] ?? '2026-10-21';

$dsn = getenv('CALLSTATS_TEST_DSN') ?: 'mysql:host=127.0.0.1;port=3307';
$user = getenv('CALLSTATS_TEST_USER') !== false ? getenv('CALLSTATS_TEST_USER') : 'root';
$pass = getenv('CALLSTATS_TEST_PASS') !== false ? getenv('CALLSTATS_TEST_PASS') : '';

$admin = Db::connect($dsn, $user, $pass);
foreach (['callstats_test', 'asteriskcdrdb_test', 'asterisk_test'] as $name) {
    $admin->exec("DROP DATABASE IF EXISTS `$name`");
    $admin->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
}
$db = Db::connect("$dsn;dbname=callstats_test", $user, $pass);
$src = Db::connect("$dsn;dbname=asteriskcdrdb_test", $user, $pass);
$fpbx = Db::connect("$dsn;dbname=asterisk_test", $user, $pass);

$applySql = function (PDO $pdo, string $path) {
    foreach (preg_split('/;\s*(?:\r?\n|$)/', file_get_contents($path)) as $stmt) {
        $stmt = trim(preg_replace('/^\s*--.*$/m', '', $stmt));
        if ($stmt !== '') {
            $pdo->exec($stmt);
        }
    }
};
$applySql($db, __DIR__ . '/../../sql/schema.sql');
$applySql($src, __DIR__ . '/../fixtures/cdr.sql');
$applySql($fpbx, __DIR__ . '/../fixtures/asterisk.sql');

/** Loads a `mysql -B` TSV into $table, only the columns the table has. */
$loadTsv = function (PDO $pdo, string $table, string $file) {
    $cols = array_column($pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(), 'Field');
    $h = fopen($file, 'rb');
    $header = explode("\t", rtrim(fgets($h), "\r\n"));
    $use = array_values(array_intersect($header, $cols));
    $st = $pdo->prepare("INSERT INTO `$table` (`" . implode('`,`', $use) . '`) VALUES ('
        . implode(',', array_fill(0, count($use), '?')) . ')');
    $n = 0;
    $pdo->beginTransaction();
    while (($line = fgets($h)) !== false) {
        $line = rtrim($line, "\r\n");
        if ($line === '') {
            continue;
        }
        $row = array_combine($header, explode("\t", $line));
        $vals = [];
        foreach ($use as $c) {
            $v = $row[$c];
            $v = $v === 'NULL' ? '' : strtr($v, ['\\t' => "\t", '\\n' => "\n", '\\0' => "\0", '\\\\' => '\\']);
            $vals[] = $v;
        }
        $st->execute($vals);
        $n++;
    }
    $pdo->commit();
    fclose($h);
    return $n;
};

echo 'cdr rows:           ' . $loadTsv($src, 'cdr', "$sampleDir/cdr.tsv") . "\n";
echo 'users:              ' . $loadTsv($fpbx, 'users', "$sampleDir/users.tsv") . "\n";
echo 'queues_config:      ' . $loadTsv($fpbx, 'queues_config', "$sampleDir/queues_config.tsv") . "\n";

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'callstats_sample_' . uniqid();
mkdir($tmp);
$qlogPath = $tmp . DIRECTORY_SEPARATOR . 'queue_log';
copy("$sampleDir/queue_log.txt", $qlogPath);

$cfg = new Config([
    'timezone' => 'Asia/Tashkent',
    'queue_log' => ['path' => $qlogPath],
    'collector' => ['lookback_minutes' => 120, 'queue_ring_timeout' => 15, 'direct_ring_timeout' => 20],
]);
$t = microtime(true);
$counters = (new Collector($cfg, $db, $src, $fpbx))->rebuild($fromDate, $toDate);
$elapsed = microtime(true) - $t;
unlink($qlogPath);
rmdir($tmp);

echo "\nrebuild $fromDate .. $toDate\n";
foreach ($counters as $k => $v) {
    printf("  %-16s%s\n", $k, $v);
}
printf("  %-16s%.2f s\n", 'time', $elapsed);

$q = function (string $sql) use ($db) {
    return $db->query($sql)->fetchAll();
};
echo "\ncalls by kind / approx:\n";
foreach ($q('SELECT kind, approx, COUNT(*) n FROM calls GROUP BY kind, approx ORDER BY kind, approx') as $r) {
    printf("  %-10s approx=%s %5d\n", $r['kind'], $r['approx'], $r['n']);
}
$enter = (int) $db->query("SELECT COUNT(*) FROM qlog_events WHERE event = 'ENTERQUEUE'")->fetchColumn();
$exact = (int) $db->query("SELECT COUNT(*) FROM calls WHERE kind = 'queue' AND approx = 0")->fetchColumn();
echo "\nENTERQUEUE in qlog_events: $enter, queue calls with approx=0: $exact\n";

echo "\noutcomes per operator (direction/outcome):\n";
$per = [];
foreach ($q('SELECT extension, direction, outcome, COUNT(*) n FROM call_agents GROUP BY extension, direction, outcome') as $r) {
    $per[$r['extension']][$r['direction'] . '/' . $r['outcome']] = (int) $r['n'];
}
ksort($per, SORT_STRING);
foreach ($per as $ext => $o) {
    ksort($o);
    $parts = [];
    foreach ($o as $k => $n) {
        $parts[] = "$k=$n";
    }
    printf("  %-24s %s\n", $ext, implode(' ', $parts));
}

$exts = $fpbx->query('SELECT extension FROM users')->fetchAll(PDO::FETCH_COLUMN);
$bad = [];
foreach (array_keys($per) as $ext) {
    if (!in_array((string) $ext, $exts, true)) {
        $bad[] = $ext;
    }
}
echo "\noperators that are not extensions: " . ($bad ? implode(', ', $bad) : 'none') . "\n";
foreach (['1005', '1009', '1010'] as $ext) {
    echo "  missed $ext: " . ($per[$ext]['in/missed'] ?? 0) . "\n";
}
