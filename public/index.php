<?php

use CallStats\Build\AgentResolver;
use CallStats\Db;
use CallStats\Live\AmiClient;
use CallStats\Live\LiveSnapshot;
use CallStats\Report\DashboardReport;
use CallStats\Report\DayTimeline;
use CallStats\Report\JournalTable;
use CallStats\Report\OperatorJournal;
use CallStats\Report\TodayStats;
use CallStats\Report\OperatorStatsReport;
use CallStats\Report\Period;
use CallStats\Report\QueueSummaryReport;
use CallStats\Report\RecordingSearch;
use CallStats\Report\StatsInsights;
use CallStats\Web\RecordingLocator;
use CallStats\Web\RecordingStreamer;
use CallStats\Store\OperatorRepository;
use CallStats\Web\App;
use CallStats\Web\Csv;
use CallStats\Web\Format;

$config = require __DIR__ . '/../src/bootstrap.php';

$db = Db::connect(
    (string) $config->get('db.callstats.dsn'),
    (string) $config->get('db.callstats.user'),
    (string) $config->get('db.callstats.pass', '')
);

$app = new App($config, $db);
$ops = new OperatorRepository($db);

$app->route('dashboard', function (array $get, array $post) use ($app, $db, $ops): string {
    $now = time();
    $period = Period::fromRequest($get, $now);
    return $app->view()->render('dashboard', [
        'period' => $period,
        'd' => (new DashboardReport($db, $ops))->fetch($period, $now),
    ]);
});

$app->route('stats', function (array $get, array $post) use ($app, $db, $ops): string {
    $now = time();
    $maxDays = 92; // the client lists and callbacks load every lost call of the period
    $stats = new OperatorStatsReport($db, $ops);
    $queue = new QueueSummaryReport($db);
    $prevLabel = function (Period $prev): string {
        return date('d.m H:i', strtotime($prev->from)) . ' — ' . date('d.m H:i', strtotime($prev->to));
    };
    // No period chosen: today, yesterday and the current half of the month, one under another;
    // the client summary is for today, the client lists for the half month.
    $default = !isset($get['preset']);
    if ($default) {
        $months = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября',
            'октября', 'ноября', 'декабря'];
        $today = Period::fromRequest(['preset' => 'today'], $now);
        $half = Period::fromRequest(['preset' => 'half_month'], $now);
        $sectionPeriods = [
            'Сегодня' => $today,
            'Вчера' => Period::fromRequest(['preset' => 'yesterday'], $now),
            'С ' . (int) date('j', strtotime($half->from)) . ' по ' . (int) date('j', strtotime($half->to)) . ' '
                . $months[(int) date('n', strtotime($half->from)) - 1] => $half,
        ];
        $period = $today;
        $summaryTitle = 'Сегодня';
        $clientsPeriod = $half;
    } else {
        $period = Period::fromRequest($get, $now);
        $summaryTitle = Period::PRESETS[$period->preset] ?? 'Период';
        $sectionPeriods = [$summaryTitle => $period];
        $clientsPeriod = $period;
    }
    $summaryPrev = $period->previous($now);
    $tooLong = $clientsPeriod->days() > $maxDays ? $maxDays : null;
    $callbacks = $period->days() <= $maxDays;

    // one callback batch for every period that needs it (they overlap or touch)
    $need = [];
    if ($callbacks) {
        $need[] = $period;
        if ($period->days() <= 31) {
            $need[] = $summaryPrev;
        }
    }
    if ($tooLong === null) {
        $need[] = $clientsPeriod;
    }
    $lost = [];
    if ($need) {
        $cover = new Period('custom', $need[0]->fromDate(), $need[0]->toDate());
        foreach ($need as $p) {
            $cover->from = min($cover->from, $p->from);
            $cover->to = max($cover->to, $p->to);
        }
        $lost = $queue->lostCalls($cover);
    }
    $s = $callbacks ? $queue->fetch($period, $lost) : $queue->summary($period);
    $prev = $callbacks && $period->days() <= 31 ? $queue->fetch($summaryPrev, $lost) : $queue->summary($summaryPrev);

    $sections = [];
    foreach ($sectionPeriods as $title => $p) {
        $pp = $p->previous($now);
        $report = $stats->fetch($p, null, [], $now);
        $sections[] = [
            'title' => $title,
            'period' => $p,
            'report' => $report,
            'insights' => StatsInsights::build($report['rows'], $stats->fetch($pp, null, [], $now)['rows']),
            'prevLabel' => $prevLabel($pp),
            'line' => $p === $period ? $s : $queue->summary($p),
        ];
    }
    $notCalled = [];
    if ($tooLong === null) {
        foreach ($queue->fetch($clientsPeriod, $lost)['lost_calls'] as $it) {
            if (($it['callback']['kind'] ?? 'none') === 'none') {
                $notCalled[] = $it;
            }
        }
    }
    return $app->view()->render('stats', [
        'period' => $period,
        'default' => $default,
        'names' => $ops->displayNames(),
        'summary' => [
            'title' => $summaryTitle,
            'period' => $period,
            's' => $s,
            'prev' => $prev,
            'prevLabel' => $prevLabel($summaryPrev),
            'byHour' => $queue->byHour($period),
            'waits' => $queue->waitBuckets($period),
            // future days of a running period stay off the chart
            'byDay' => $period->days() > 1 ? array_filter($queue->byDay($period), function (string $d) use ($now): bool {
                return $d <= date('Y-m-d', $now);
            }, ARRAY_FILTER_USE_KEY) : null,
            'heatmap' => $period->days() >= 7 ? $queue->heatmap($period) : null,
            'callbacks' => $callbacks,
        ],
        'sections' => $sections,
        'clients' => [
            'period' => $clientsPeriod,
            'tooLong' => $tooLong,
            'lost' => $notCalled,
            'repeat' => $tooLong === null ? $queue->repeatCallers($clientsPeriod, 3, 20) : [],
        ],
    ]);
});

// Operator card: KPI tiles, per day, per hour (layout page under «Статистика»).
$app->route('operator', function (array $get, array $post) use ($app, $db, $ops): string {
    $now = time();
    $names = $ops->displayNames();
    $ext = isset($get['ext']) && is_string($get['ext']) ? trim($get['ext']) : '';
    if (!isset($names[$ext])) {
        http_response_code(404);
        return '<p class="error">Оператор не найден</p><p><a href="?p=stats">← Статистика</a></p>';
    }
    $period = Period::fromRequest($get, $now);
    $prev = $period->previous($now);
    $stats = new OperatorStatsReport($db, $ops);
    $row = $stats->fetch($period, null, [$ext], $now)['rows'][0] ?? null;
    return $app->view()->render('operator', [
        'period' => $period,
        'ext' => $ext,
        'name' => $names[$ext],
        'row' => $row,
        'prev' => $stats->fetch($prev, null, [$ext], $now)['rows'][0] ?? null,
        'prevLabel' => date('d.m H:i', strtotime($prev->from)) . ' — ' . date('d.m H:i', strtotime($prev->to)),
        'days' => $period->days() > 1 ? $stats->operatorByDay($period, $ext) : null,
        'hours' => $stats->operatorByHour($period, $ext),
    ]);
});

$monitorRoot = (string) $config->get('monitor.path', '/var/spool/asterisk/monitor');
$locator = new RecordingLocator($monitorRoot);

// Journal data shared by the page and its CSV export: params validated, rows filtered (not paged).
$journalData = function (array $get) use ($db, $ops, $locator): array {
    $now = time();
    $period = Period::fromRequest($get, $now);
    $operators = $ops->displayNames();
    $str = function (string $k) use ($get): string {
        return isset($get[$k]) && is_string($get[$k]) ? trim($get[$k]) : '';
    };
    $ext = $str('ext');
    if ($ext !== '*' && !isset($operators[$ext])) {
        $ext = '';
    }
    $t = [
        'q' => mb_substr($str('q'), 0, 40),
        'type' => in_array($str('type'), JournalTable::CATS, true) ? $str('type') : '',
        'noise' => $str('noise') === '1' ? '1' : '',
        'sort' => $str('sort') === 'dur' ? 'dur' : 'at',
        'order' => $str('order') === 'asc' ? 'asc' : 'desc',
        'per' => in_array((int) $str('per'), JournalTable::PER_PAGE, true) ? (int) $str('per') : 100,
    ];
    $items = [];
    $kpi = null;
    $kpiPrev = null;
    $timeline = null;
    // the journal loads every row of the period, so long periods are refused instead of stalling
    $maxDays = $ext === '*' ? 31 : 92;
    $span = (int) round((strtotime($period->toDate()) - strtotime($period->fromDate())) / 86400) + 1;
    $tooLong = $ext !== '' && $span > $maxDays ? $maxDays : null;
    if ($ext !== '' && $tooLong === null) {
        $journal = new OperatorJournal($db);
        $stats = new OperatorStatsReport($db, $ops);
        $exts = $ext === '*' ? [] : [$ext];
        $report = $stats->fetch($period, null, $exts, $now);
        $prevReport = $stats->fetch($period->previous($now), null, $exts, $now);
        if ($ext === '*') {
            $items = OperatorJournal::mergeSharedMissed($journal->fetchAll($period, array_keys($operators), true));
            $kpi = $report['total'];
            $kpiPrev = $prevReport['total'];
        } else {
            $items = OperatorJournal::collapseOffline($journal->fetch($ext, $period));
            $kpi = $report['rows'][0] ?? null;
            $kpiPrev = $prevReport['rows'][0] ?? null;
            if ($period->fromDate() === $period->toDate()) {
                $timeline = DayTimeline::build($items, $period->fromDate(), $now);
            }
        }
        $items = $journal->withRecordingStatus($journal->withCallbacks($items), $locator);
    }
    $searched = JournalTable::search(JournalTable::rows($items), $t['q']);
    $filtered = JournalTable::filterType($searched, $t['type'], $t['noise'] === '1');
    return [
        'period' => $period,
        'operators' => $operators,
        'ext' => $ext,
        't' => $t,
        'kpi' => $kpi,
        'kpiPrev' => $kpiPrev,
        'timeline' => $timeline,
        'tooLong' => $tooLong,
        'counts' => JournalTable::counts($searched),
        'allCount' => count(JournalTable::filterType($searched, '', $t['noise'] === '1')),
        'rows' => $filtered,
        // day totals describe the whole day, whichever chip is active
        'days' => JournalTable::days($searched),
    ];
};

$app->route('journal', function (array $get, array $post) use ($app, $journalData): string {
    $d = $journalData($get);
    $page = isset($get['page']) && is_string($get['page']) && ctype_digit($get['page']) ? (int) min((int) $get['page'], 1000000) : 1;
    // the search has already been applied by $journalData
    $d['table'] = JournalTable::apply($d['rows'], '', $d['t']['sort'], $d['t']['order'], $page, $d['t']['per']);
    unset($d['rows']);
    return $app->view()->render('journal', $d);
});

// Raw response: the filtered journal (all pages) as CSV for Excel.
$app->route('journal_csv', function (array $get, array $post) use ($journalData): string {
    $d = $journalData($get);
    $res = JournalTable::apply($d['rows'], '', $d['t']['sort'], $d['t']['order'], 1, max(1, count($d['rows'])));
    $names = $d['operators'];
    $dirs = ['in' => 'входящий', 'out' => 'исходящий', 'internal' => 'внутренний', 'event' => 'событие'];
    $out = [];
    foreach ($res['rows'] as $r) {
        $opExt = $d['ext'] === '*' ? (string) $r['ext'] : $d['ext'];
        $out[] = [
            $r['at'],
            implode(', ', array_map(function (string $one) use ($names): string {
                return ($names[$one] ?? $one) !== $one ? $names[$one] . ' (' . $one . ')' : $one;
            }, $opExt === '' ? [] : explode(', ', $opExt))),
            $dirs[$r['dir']],
            $r['number'],
            $r['result'],
            $r['duration'] === null ? '' : (int) $r['duration'],
            $r['source'],
            $r['callback'] === null ? '' : Format::callback($r['callback'], $names)[0],
        ];
    }
    $file = 'journal-' . ($d['ext'] === '*' ? 'all' : preg_replace('/[^A-Za-z0-9_-]/', '', $d['ext']))
        . '-' . $d['period']->fromDate() . '-' . $d['period']->toDate() . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $file . '"');
    return Csv::build(['Дата', 'Оператор', 'Направление', 'Номер', 'Результат', 'Длительность, сек', 'Источник', 'Перезвон'], $out, [3]);
});

// Raw response: HTML fragment with the call history of one client number (journal drawer).
$app->route('client', function (array $get, array $post) use ($app, $db, $ops, $locator): string {
    $number = isset($get['number']) && is_string($get['number']) ? mb_substr(trim($get['number']), 0, 40) : '';
    if (OperatorJournal::normalizeNumber($number) === '') {
        http_response_code(400);
        return '<p class="error">Неверный номер</p>';
    }
    $journal = new OperatorJournal($db);
    return $app->view()->render('_client_history', [
        'number' => $number,
        'calls' => $journal->withRecordingStatus($journal->clientHistory($number, 100), $locator),
        'names' => $ops->displayNames(),
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
    ];
    $page = ctype_digit($str('page')) ? max(1, (int) $str('page')) : 1;
    $t = [
        'sort' => $str('sort') === 'dur' ? 'dur' : 'at',
        'order' => $str('order') === 'asc' ? 'asc' : 'desc',
        'per' => in_array((int) $str('per'), JournalTable::PER_PAGE, true) ? (int) $str('per') : 50,
    ];
    $res = (new RecordingSearch($db))->search(
        $period,
        $f['ext'] !== '' ? $f['ext'] : null,
        $f['number'] !== '' ? $f['number'] : null,
        null,
        null,
        null,
        null,
        $page,
        $t['sort'],
        $t['order'],
        $t['per']
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
        'res' => $res,
        't' => $t,
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
    $now = time();
    $out = ['ok' => true, 'error' => null, 'time' => date('H:i:s', $now), 'today' => null,
        'summary' => null, 'queues' => [], 'agents' => []];
    $json = function (array $out): string {
        // Caller names from trunks may be non-UTF-8 (e.g. cp1251): substitute, never fail.
        $s = json_encode($out, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($s === false) {
            error_log('callstats live: json_encode failed: ' . json_last_error_msg());
            $s = (string) json_encode(['ok' => false, 'error' => 'Ошибка формирования данных', 'time' => $out['time'],
                'today' => null, 'summary' => null, 'queues' => [], 'agents' => []], JSON_UNESCAPED_UNICODE);
        }
        return $s;
    };
    try {
        $names = $ops->displayNames();
        $queueNames = [];
        foreach ($db->query('SELECT queue, name FROM queues')->fetchAll(PDO::FETCH_ASSOC) as $q) {
            $queueNames[(string) $q['queue']] = (string) $q['name'];
        }
        $out['today'] = (new TodayStats($db))->fetch(
            date('Y-m-d 00:00:00', $now),
            date('Y-m-d 23:59:59', $now),
            (int) $config->get('live.service_level_sec', 60)
        );
    } catch (Throwable $e) {
        error_log('callstats live: ' . $e->getMessage());
        $out['ok'] = false;
        $out['error'] = 'Ошибка базы данных сервиса';
        return $json($out);
    }
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
            // Each list is optional: e.g. without app_queue QueueStatus errors, the rest still works.
            $failed = [];
            $list = function (string $action) use ($ami, &$failed): array {
                try {
                    return $ami->request($action);
                } catch (RuntimeException $e) {
                    error_log('callstats live: ' . $e->getMessage());
                    $failed[] = $e->getMessage();
                    return [];
                }
            };
            $queue = $list('QueueStatus');
            $channels = $list('CoreShowChannels');
            $hints = $list('ExtensionStateList');
            if (count($failed) === 3) {
                throw new RuntimeException($failed[0]);
            }
            if ($failed) {
                $out['error'] = 'Часть данных Asterisk недоступна: ' . implode('; ', $failed);
            }
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
    return $json($out);
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
