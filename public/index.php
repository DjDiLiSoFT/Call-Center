<?php

use CallStats\Db;
use CallStats\Report\OperatorJournal;
use CallStats\Report\OperatorStatsReport;
use CallStats\Report\Period;
use CallStats\Store\OperatorRepository;
use CallStats\Web\App;

$config = require __DIR__ . '/../src/bootstrap.php';

$db = Db::connect(
    (string) $config->get('db.callstats.dsn'),
    (string) $config->get('db.callstats.user'),
    (string) $config->get('db.callstats.pass', '')
);

$app = new App($config, $db);
$ops = new OperatorRepository($db);

$app->route('stats', function (array $get, array $post) use ($app, $db, $ops): string {
    $period = Period::fromRequest($get, time());
    $queues = [];
    foreach ($db->query('SELECT queue, name FROM queues ORDER BY queue')->fetchAll(PDO::FETCH_ASSOC) as $q) {
        $queues[(string) $q['queue']] = (string) $q['name'];
    }
    $queue = isset($get['queue']) && is_string($get['queue']) && isset($queues[$get['queue']]) ? $get['queue'] : '';
    $operators = [];
    foreach ($ops->all() as $o) {
        $operators[(string) $o['extension']] = $ops->displayName((string) $o['extension']);
    }
    $selected = [];
    if (isset($get['ext']) && is_array($get['ext'])) {
        foreach ($get['ext'] as $e) {
            if (is_string($e) && isset($operators[$e])) {
                $selected[] = $e;
            }
        }
    }
    $report = (new OperatorStatsReport($db, $ops))->fetch($period, $queue !== '' ? $queue : null, $selected);
    return $app->view()->render('stats', [
        'period' => $period,
        'report' => $report,
        'queues' => $queues,
        'operators' => $operators,
        'queue' => $queue,
        'selected' => $selected,
    ]);
});

$app->route('journal', function (array $get, array $post) use ($app, $db, $ops): string {
    $period = Period::fromRequest($get, time());
    $operators = [];
    foreach ($ops->all() as $o) {
        $operators[(string) $o['extension']] = $ops->displayName((string) $o['extension']);
    }
    $ext = isset($get['ext']) && is_string($get['ext']) && isset($operators[$get['ext']]) ? $get['ext'] : '';
    $summary = null;
    $hasTimeline = false;
    $items = [];
    if ($ext !== '') {
        $report = (new OperatorStatsReport($db, $ops))->fetch($period, null, [$ext]);
        $summary = $report['rows'][0] ?? null;
        $hasTimeline = $report['hasTimeline'];
        $items = OperatorJournal::collapseOffline((new OperatorJournal($db))->fetch($ext, $period));
    }
    return $app->view()->render('journal', [
        'period' => $period,
        'operators' => $operators,
        'ext' => $ext,
        'summary' => $summary,
        'hasTimeline' => $hasTimeline,
        'items' => $items,
    ]);
});

$app->route('operators', function (array $get, array $post) use ($app, $ops): string {
    if ($post) {
        $ext = isset($post['ext']) && is_string($post['ext']) ? $post['ext'] : '';
        $name = isset($post['custom_name']) && is_string($post['custom_name']) ? $post['custom_name'] : '';
        foreach ($ops->all() as $o) {
            if ((string) $o['extension'] === $ext) {
                $ops->setName($ext, $name);
                break;
            }
        }
        header('Location: ?p=operators&saved=1', true, 303);
        exit;
    }
    return $app->view()->render('operators', [
        'operators' => $ops->all(),
        'csrf' => $app->csrf()->token(),
        'saved' => isset($get['saved']),
    ]);
});

$app->handle($_GET, $_POST, $_SERVER);
