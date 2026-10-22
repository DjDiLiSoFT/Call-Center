<?php

use CallStats\Db;
use CallStats\Report\DashboardReport;
use CallStats\Report\DayTimeline;
use CallStats\Report\JournalTable;
use CallStats\Report\OperatorJournal;
use CallStats\Report\OperatorStatsReport;
use CallStats\Report\Period;
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
    $stats = new OperatorStatsReport($db, $ops);
    $section = function (string $title, Period $p) use ($stats, $now): array {
        $prev = $p->previous($now);
        $report = $stats->fetch($p, null, [], $now);
        return [
            'title' => $title,
            'period' => $p,
            'report' => $report,
            'insights' => StatsInsights::build($report['rows'], $stats->fetch($prev, null, [], $now)['rows']),
            'prevLabel' => date('d.m H:i', strtotime($prev->from)) . ' — ' . date('d.m H:i', strtotime($prev->to)),
        ];
    };
    // No period chosen: today, yesterday and the current half of the month, one under another.
    $default = !isset($get['preset']);
    if ($default) {
        $months = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября',
            'октября', 'ноября', 'декабря'];
        $half = Period::fromRequest(['preset' => 'half_month'], $now);
        $sections = [
            $section('Сегодня', Period::fromRequest(['preset' => 'today'], $now)),
            $section('Вчера', Period::fromRequest(['preset' => 'yesterday'], $now)),
            $section('С ' . (int) date('j', strtotime($half->from)) . ' по ' . (int) date('j', strtotime($half->to)) . ' '
                . $months[(int) date('n', strtotime($half->from)) - 1], $half),
        ];
        $period = Period::fromRequest([], $now);
    } else {
        $period = Period::fromRequest($get, $now);
        $sections = [$section(Period::PRESETS[$period->preset] ?? 'Период', $period)];
    }
    return $app->view()->render('stats', ['period' => $period, 'sections' => $sections, 'default' => $default]);
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
