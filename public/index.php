<?php

use CallStats\Build\AgentResolver;
use CallStats\Db;
use CallStats\Live\AmiClient;
use CallStats\Live\LiveSnapshot;
use CallStats\Report\OperatorJournal;
use CallStats\Report\TodayStats;
use CallStats\Report\OperatorStatsReport;
use CallStats\Report\Period;
use CallStats\Report\RecordingSearch;
use CallStats\Web\RecordingLocator;
use CallStats\Web\RecordingStreamer;
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

$monitorRoot = (string) $config->get('monitor.path', '/var/spool/asterisk/monitor');
$locator = new RecordingLocator($monitorRoot);

$app->route('journal', function (array $get, array $post) use ($app, $db, $ops, $locator): string {
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
        $items = (new OperatorJournal($db))->withRecordingStatus($items, $locator);
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

$app->route('recordings', function (array $get, array $post) use ($app, $db, $ops, $locator): string {
    $period = Period::fromRequest($get, time());
    $operators = [];
    foreach ($ops->all() as $o) {
        $operators[(string) $o['extension']] = $ops->displayName((string) $o['extension']);
    }
    $queues = [];
    foreach ($db->query('SELECT queue, name FROM queues ORDER BY queue')->fetchAll(PDO::FETCH_ASSOC) as $q) {
        $queues[(string) $q['queue']] = (string) $q['name'];
    }
    $str = function (string $k) use ($get): string {
        return isset($get[$k]) && is_string($get[$k]) ? trim($get[$k]) : '';
    };
    $f = [
        'ext' => isset($operators[$str('ext')]) ? $str('ext') : '',
        'number' => mb_substr($str('number'), 0, 40),
        'min' => ctype_digit($str('min')) ? (string) min((int) $str('min'), 100000) : '',
        'max' => ctype_digit($str('max')) ? (string) min((int) $str('max'), 100000) : '',
        'dir' => in_array($str('dir'), ['in', 'out'], true) ? $str('dir') : '',
        'queue' => isset($queues[$str('queue')]) ? $str('queue') : '',
    ];
    $page = ctype_digit($str('page')) ? max(1, (int) $str('page')) : 1;
    $res = (new RecordingSearch($db))->search(
        $period,
        $f['ext'] !== '' ? $f['ext'] : null,
        $f['number'] !== '' ? $f['number'] : null,
        $f['min'] !== '' ? (int) $f['min'] * 60 : null,
        $f['max'] !== '' ? (int) $f['max'] * 60 + 59 : null,
        $f['dir'] !== '' ? $f['dir'] : null,
        $f['queue'] !== '' ? $f['queue'] : null,
        $page
    );
    $rows = [];
    foreach ($res['rows'] as $r) {
        $r['status'] = $locator->locate((string) $r['started_at'], (string) $r['recording_file'])['status'];
        $r['operator'] = $r['answered_by'] !== null && $r['answered_by'] !== '' ? $ops->displayName((string) $r['answered_by']) : '';
        $rows[] = $r;
    }
    return $app->view()->render('recordings', [
        'period' => $period,
        'operators' => $operators,
        'queues' => $queues,
        'f' => $f,
        'rows' => $rows,
        'total' => $res['total'],
        'page' => $res['page'],
    ]);
});

// Raw response: streams audio itself and returns '' (App echoes the returned string).
$app->route('play', function (array $get, array $post) use ($db, $locator): string {
    $deny = function (int $code, string $msg): string {
        http_response_code($code);
        header('Content-Type: text/plain; charset=utf-8');
        return $msg;
    };
    $id = isset($get['id']) && is_string($get['id']) && ctype_digit($get['id']) ? (int) $get['id'] : 0;
    $st = $db->prepare('SELECT started_at, recording_file FROM calls WHERE id = ?');
    $st->execute([$id]);
    $call = $id > 0 ? $st->fetch(PDO::FETCH_ASSOC) : false;
    if (!$call || (string) $call['recording_file'] === '') {
        return $deny(404, 'Запись не найдена');
    }
    $loc = $locator->locate((string) $call['started_at'], (string) $call['recording_file']);
    $download = isset($get['dl']) && $get['dl'] === '1';
    if ($loc['status'] === 'missing') {
        return $deny(404, 'Файл не найден');
    }
    if ($loc['status'] === 'empty') {
        return $deny(404, 'Запись пустая');
    }
    if ($loc['status'] === 'unsupported' && !$download) {
        return $deny(415, 'Формат не поддерживается плеером, используйте «скачать»');
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close(); // do not block other pages while streaming
    }
    $range = isset($_SERVER['HTTP_RANGE']) ? (string) $_SERVER['HTTP_RANGE'] : null;
    (new RecordingStreamer(function (string $line): void {
        header($line);
    }))->send($loc['path'], $loc['mime'] ?? 'application/octet-stream', $range, $download);
    return '';
});

$app->route('live', function (array $get, array $post) use ($app, $config): string {
    return $app->view()->render('live', [
        'refreshSec' => max(2, (int) $config->get('live.refresh_sec', 3)),
        'slSec' => (int) $config->get('live.service_level_sec', 60),
    ]);
});

// Raw response: JSON for the live board (polled by assets/live.js).
$app->route('live_data', function (array $get, array $post) use ($config, $db, $ops): string {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close(); // polling must not block other pages
    }
    header('Content-Type: application/json; charset=utf-8');
    $names = [];
    foreach ($ops->all() as $o) {
        $names[(string) $o['extension']] = $ops->displayName((string) $o['extension']);
    }
    $queueNames = [];
    foreach ($db->query('SELECT queue, name FROM queues')->fetchAll(PDO::FETCH_ASSOC) as $q) {
        $queueNames[(string) $q['queue']] = (string) $q['name'];
    }
    $now = time();
    $out = [
        'ok' => true,
        'error' => null,
        'time' => date('H:i:s', $now),
        'today' => (new TodayStats($db))->fetch(
            date('Y-m-d 00:00:00', $now),
            date('Y-m-d 23:59:59', $now),
            (int) $config->get('live.service_level_sec', 60)
        ),
        'summary' => null,
        'queues' => [],
        'agents' => [],
    ];
    try {
        $user = (string) $config->get('ami.user', '');
        if ($user === '') {
            throw new RuntimeException('AMI не настроен (ami.user в config.php)');
        }
        $ami = new AmiClient(
            (string) $config->get('ami.host', '127.0.0.1'),
            (int) $config->get('ami.port', 5038),
            $user,
            (string) $config->get('ami.secret', ''),
            3.0
        );
        $ami->connect();
        try {
            $queue = $ami->request('QueueStatus');
            $channels = $ami->request('CoreShowChannels');
            $hints = $ami->request('ExtensionStateList');
        } finally {
            $ami->close();
        }
        $snap = (new LiveSnapshot(new AgentResolver(array_keys($names))))
            ->build($queue, $channels, $hints, $names, $queueNames);
        $out['summary'] = $snap['summary'];
        $out['queues'] = $snap['queues'];
        $out['agents'] = $snap['agents'];
    } catch (Throwable $e) {
        error_log('callstats live: ' . $e->getMessage());
        $out['ok'] = false;
        $out['error'] = 'Нет связи с Asterisk: ' . $e->getMessage();
    }
    return (string) json_encode($out, JSON_UNESCAPED_UNICODE);
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
