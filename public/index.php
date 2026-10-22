<?php

use CallStats\Db;
use CallStats\Report\DashboardReport;
use CallStats\Report\DayTimeline;
use CallStats\Report\JournalTable;
use CallStats\Report\OperatorJournal;
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
            'lost' => QueueSummaryReport::groupLostByNumber($notCalled),
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

// Raw response: a stats table as CSV for Excel (operators | lost | repeat).
$app->route('stats_csv', function (array $get, array $post) use ($db, $ops): string {
    $now = time();
    $deny = function (int $code, string $msg): string {
        http_response_code($code);
        header('Content-Type: text/plain; charset=utf-8');
        return $msg;
    };
    $table = isset($get['table']) && is_string($get['table']) ? $get['table'] : '';
    if (!in_array($table, ['operators', 'lost', 'repeat'], true)) {
        return $deny(400, 'Неизвестная таблица');
    }
    $period = Period::fromRequest($get, $now);
    if ($period->days() > 92) {
        return $deny(400, 'Период слишком длинный: выгрузка — максимум за 92 дня');
    }
    $names = $ops->displayNames();
    $who = function (string $ext) use ($names): string {
        $n = $names[$ext] ?? $ext;
        return $n !== $ext ? $n . ' (' . $ext . ')' : $ext;
    };
    $dec = function ($v): string {
        return $v === null ? '' : str_replace('.', ',', (string) $v);
    };
    $queue = new QueueSummaryReport($db);
    $textCols = [];
    $rows = [];
    if ($table === 'operators') {
        $header = ['Оператор', 'Принято', 'Пропущено (клиент потерян)', 'Ушло коллегам', '% ответа', 'Не в сети',
            'Исходящие всего', 'Исходящие состоялись', 'Время разговоров, сек', 'Ср. разговор, сек', 'Ср. ответ, сек',
            'В сети, сек', 'Пауза, сек', 'Занятость %', 'Звонков в час'];
        $report = (new OperatorStatsReport($db, $ops))->fetch($period, null, [], $now);
        $ins = StatsInsights::build($report['rows'], []);
        $line = function (array $r, string $name) use ($dec): array {
            $online = !empty($r['busy_approx']) ? ($r['active_span_sec'] > 0 ? $r['active_span_sec'] : '') : $r['session_sec'];
            return [$name, $r['answered'], $r['missed_lost'], $r['missed_other'], $dec($r['answer_pct']), $r['offline'],
                $r['out_total'], $r['out_connected'], $r['talk_sec'], $r['avg_talk_sec'], $r['avg_ring_sec'],
                $online === null ? '' : $online, $r['pause_sec'] === null ? '' : $r['pause_sec'],
                $dec($r['busy_pct']), $dec($r['calls_per_hour'])];
        };
        foreach (array_merge($ins['active'], $ins['inactive']) as $r) {
            $rows[] = $line($r, $who((string) $r['extension']));
        }
        $rows[] = $line($report['total'], 'Итого');
    } elseif ($table === 'lost') {
        $header = ['Дата', 'Номер', 'Ожидание, сек', 'Звонил операторам'];
        $textCols = [1];
        foreach ($queue->fetch($period)['lost_calls'] as $it) {
            if (($it['callback']['kind'] ?? 'none') === 'none') {
                $rows[] = [$it['at'], $it['client'], $it['wait_sec'], implode(', ', array_map($who, $it['rang']))];
            }
        }
    } else {
        $header = ['Номер', 'Звонков', 'Принято', 'Потеряно', 'Последний звонок'];
        $textCols = [0];
        foreach ($queue->repeatCallers($period, 3, 10000) as $r) {
            $rows[] = [$r['number'], $r['calls'], $r['answered'], $r['lost'], $r['last_at']];
        }
    }
    $file = 'stats-' . $table . '-' . $period->fromDate() . '-' . $period->toDate() . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $file . '"');
    return Csv::build($header, $rows, $textCols);
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

$app->handle($_GET, $_POST, $_SERVER);
