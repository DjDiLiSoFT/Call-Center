<?php

namespace CallStats\Tests\Integration;

use CallStats\Collector;
use CallStats\Config;
use CallStats\Store\OperatorRepository;
use CallStats\Store\StateRepository;

class CollectorTest extends DbTestCase
{
    private const FIXTURE_COLUMNS = [
        'calldate', 'src', 'dst', 'dcontext', 'channel', 'dstchannel', 'duration', 'billsec',
        'disposition', 'uniqueid', 'recordingfile', 'linkedid', 'sequence',
    ];

    /** @var string */
    private $dir;
    /** @var string */
    private $qlogPath;

    protected function setUp(): void
    {
        parent::setUp();
        date_default_timezone_set('Asia/Tashkent');
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'coll_' . uniqid();
        mkdir($this->dir);
        $this->qlogPath = $this->dir . DIRECTORY_SEPARATOR . 'queue_log';
        file_put_contents($this->qlogPath, '');

        $ins = $this->fpbx->prepare('INSERT INTO users (extension, name) VALUES (?, ?)');
        for ($i = 1001; $i <= 1012; $i++) {
            $ins->execute([(string) $i, (string) $i]);
        }
        $this->fpbx->exec("INSERT INTO queues_config (extension, descr) VALUES ('1000', 'Bananza')");
    }

    protected function tearDown(): void
    {
        if ($this->dir !== null && is_dir($this->dir)) {
            foreach (glob($this->dir . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
                unlink($f);
            }
            rmdir($this->dir);
        }
        parent::tearDown();
    }

    private function collector(): Collector
    {
        $cfg = new Config([
            'timezone' => 'Asia/Tashkent',
            'queue_log' => ['path' => $this->qlogPath],
            'collector' => ['lookback_minutes' => 120, 'queue_ring_timeout' => 15, 'direct_ring_timeout' => 20, 'max_call_hours' => 24],
        ]);
        return new Collector($cfg, $this->db, $this->src, $this->fpbx);
    }

    private function fixture(string $name): array
    {
        return require __DIR__ . '/../fixtures/calls/' . $name . '.php';
    }

    private function insertCdr(array $compactRows): void
    {
        $cols = implode(', ', self::FIXTURE_COLUMNS);
        $marks = implode(', ', array_fill(0, count(self::FIXTURE_COLUMNS), '?'));
        $st = $this->src->prepare("INSERT INTO cdr ($cols) VALUES ($marks)");
        foreach ($compactRows as $r) {
            $st->execute(array_map('strval', $r));
        }
    }

    private function appendQlog(array $lines): void
    {
        file_put_contents($this->qlogPath, implode("\n", $lines) . "\n", FILE_APPEND);
    }

    private function rowCount(string $table): int
    {
        return (int) $this->db->query("SELECT COUNT(*) FROM $table")->fetchColumn();
    }

    private function outcomes(): array
    {
        $rows = $this->db->query("SELECT extension, direction, outcome FROM call_agents ORDER BY extension")->fetchAll();
        $map = [];
        foreach ($rows as $r) {
            $map[$r['extension'] . '/' . $r['direction']] = $r['outcome'];
        }
        return $map;
    }

    public function test_second_run_creates_no_duplicates(): void
    {
        $f = $this->fixture('queue_ringall_1004');
        $this->insertCdr($f['cdr']);
        $this->appendQlog($f['qlog']);
        $now = 1792321160 + 300;

        $c1 = $this->collector()->runOnce($now);
        $c2 = $this->collector()->runOnce($now + 60);

        $this->assertSame(10, $c1['qlog_new']);
        $this->assertSame(0, $c2['qlog_new']);
        $this->assertSame(1, $this->rowCount('calls'));
        $this->assertSame(7, $this->rowCount('call_agents'));
        $this->assertSame(10, $this->rowCount('qlog_events'));

        $call = $this->db->query('SELECT * FROM calls')->fetch();
        $this->assertSame('1792321160.222942', $call['call_key']);
        $this->assertSame('queue', $call['kind']);
        $this->assertSame('1004', $call['answered_by']);
        $this->assertSame('0', (string) $call['approx']);
        $sources = json_decode($call['sources'], true);
        $this->assertCount(10, $sources['qlog']);
        $this->assertContains('1792321160|1792321160.222942|1000|NONE|ENTERQUEUE||900000001|1', $sources['qlog']);
        $this->assertSame('Bananza', $this->db->query("SELECT name FROM queues WHERE queue = '1000'")->fetchColumn());
    }

    public function test_call_in_progress_is_completed_on_next_run(): void
    {
        $f = $this->fixture('queue_ringall_1004');
        // While the call is still talking, the answerer's legs are not in CDR yet and
        // queue_log has no COMPLETE* line.
        $early = array_values(array_filter($f['cdr'], function (array $r) {
            return strpos($r[4] . $r[5], '1004@') === false && $r[2] !== '1004';
        }));
        $late = array_values(array_filter($f['cdr'], function (array $r) {
            return strpos($r[4] . $r[5], '1004@') !== false || $r[2] === '1004';
        }));
        $this->assertCount(8, $early);
        $this->insertCdr($early);
        $this->appendQlog(array_slice($f['qlog'], 0, 9));

        $this->collector()->runOnce(1792321170);
        $this->assertSame(1, $this->rowCount('calls'));

        $this->insertCdr($late);
        $this->appendQlog(array_slice($f['qlog'], 9));
        $this->collector()->runOnce(1792321260);

        $this->assertSame(1, $this->rowCount('calls'));
        $call = $this->db->query('SELECT * FROM calls')->fetch();
        $this->assertSame('1004', $call['answered_by']);
        $this->assertSame('30', (string) $call['talk_sec']);
        $this->assertSame('3', (string) $call['wait_sec']);
        $this->assertSame('2026-10-18 15:59:53', $call['ended_at']);
        $this->assertSame([
            '1001/in' => 'other_answered',
            '1002/in' => 'other_answered',
            '1003/in' => 'offline',
            '1004/in' => 'answered',
            '1005/in' => 'offline',
            '1009/in' => 'offline',
            '1010/in' => 'offline',
        ], $this->outcomes());
    }

    public function test_long_call_ending_now_is_collected(): void
    {
        // A 3-hour call: CDR writes its rows only at hangup, with calldate = call start,
        // i.e. far before the lookback window (120 min).
        $f = $this->fixture('queue_ringall_1004');
        $rows = $f['cdr'];
        foreach ($rows as &$r) {
            if ($r[9] === '1792321160.222956' || strpos($r[5], 'Local/1004@') === 0) {
                $r[6] = 10833; // duration
                $r[7] = $r[3] === 'ext-queues' ? 10833 : 10830; // billsec
            }
        }
        unset($r);
        $start = 1792321160;
        $this->collector()->runOnce($start + 3 * 3600 - 60);
        $this->assertSame(0, $this->rowCount('calls'));

        $this->insertCdr($rows);
        $this->collector()->runOnce($start + 3 * 3600 + 60);

        $this->assertSame(1, $this->rowCount('calls'));
        $call = $this->db->query('SELECT * FROM calls')->fetch();
        $this->assertSame('1004', $call['answered_by']);
        $this->assertSame('1', (string) $call['approx']);
    }

    public function test_outage_longer_than_max_call_hours_recovers_cdr(): void
    {
        // Last run at 16:00 on 2026-10-18, then the collector was down for 30 h. An outbound call
        // made one hour after the last run must still be picked up by the next run.
        $lastRun = strtotime('2026-10-18 16:00:00');
        $this->collector()->runOnce($lastRun);
        $f = $this->fixture('outbound_answered'); // 2026-10-18 16:56:37, 51 s
        $this->insertCdr($f['cdr']);

        $warnings = [];
        $c = $this->collector();
        $c->setWarningHandler(function (string $msg) use (&$warnings) {
            $warnings[] = $msg;
        });
        $c->runOnce($lastRun + 30 * 3600);

        $this->assertSame(1, $this->rowCount('calls'));
        $this->assertSame('outbound', $this->db->query('SELECT kind FROM calls')->fetchColumn());
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('max_call_hours', $warnings[0]);
    }

    public function test_operator_names_synced_custom_kept(): void
    {
        $ops = new OperatorRepository($this->db);
        $this->collector()->runOnce(1792321160);
        $ops->setName('1002', 'Иванова');
        $this->fpbx->exec("UPDATE users SET name = 'Petrova' WHERE extension = '1002'");
        $this->fpbx->exec("UPDATE users SET name = 'Sidorov' WHERE extension = '1003'");

        $this->collector()->runOnce(1792321220);

        $all = [];
        foreach ($ops->all() as $r) {
            $all[$r['extension']] = $r;
        }
        $this->assertCount(12, $all);
        $this->assertSame('Petrova', $all['1002']['fpbx_name']);
        $this->assertSame('Иванова', $all['1002']['custom_name']);
        $this->assertSame('Иванова', $ops->displayName('1002'));
        $this->assertSame('Sidorov', $ops->displayName('1003'));
        $this->assertSame('1004', $ops->displayName('1004'));
        $this->assertSame('1099', $ops->displayName('1099'));

        $ops->setName('1002', null);
        $this->assertSame('Petrova', $ops->displayName('1002'));
    }

    public function test_qlog_state_restored_when_insert_fails(): void
    {
        $f = $this->fixture('queue_ringall_1004');
        $this->appendQlog(array_slice($f['qlog'], 0, 5));
        $this->collector()->runOnce(1792321200);
        $state = new StateRepository($this->db);
        $inode = $state->get('queue_log.inode');
        $offset = $state->get('queue_log.offset');
        $this->assertNotNull($offset);

        $this->appendQlog(array_slice($f['qlog'], 5));
        $this->db->exec('RENAME TABLE qlog_events TO qlog_events_off');
        try {
            $this->collector()->runOnce(1792321260);
            $this->fail('insert into qlog_events must fail');
        } catch (\PDOException $e) {
            // expected
        }
        $this->db->exec('RENAME TABLE qlog_events_off TO qlog_events');
        $this->assertSame($inode, $state->get('queue_log.inode'));
        $this->assertSame($offset, $state->get('queue_log.offset'));

        // the lines are not lost: the next run reads them again
        $c = $this->collector()->runOnce(1792321320);
        $this->assertSame(5, $c['qlog_new']);
        $this->assertSame(10, $this->rowCount('qlog_events'));
    }

    public function test_qlog_state_removed_when_first_insert_fails(): void
    {
        $f = $this->fixture('queue_ringall_1004');
        $this->appendQlog($f['qlog']);
        $this->db->exec('RENAME TABLE qlog_events TO qlog_events_off');
        try {
            $this->collector()->runOnce(1792321260);
            $this->fail('insert into qlog_events must fail');
        } catch (\PDOException $e) {
            // expected
        }
        $state = new StateRepository($this->db);
        $this->assertNull($state->get('queue_log.inode'));
        $this->assertNull($state->get('queue_log.offset'));
    }

    public function test_timeline_open_session_closed_on_later_run(): void
    {
        $t = 1792321160; // 2026-10-18 15:59:20
        $this->appendQlog([
            "$t|NONE|1000|PJSIP/1006|ADDMEMBER|",
            ($t + 60) . "|NONE|1000|PJSIP/1006|PAUSE||Обед",
        ]);
        $this->collector()->runOnce($t + 120);
        // the raw line is kept as is (trailing empty field included)
        $this->assertSame(
            "$t|NONE|1000|PJSIP/1006|ADDMEMBER|",
            $this->db->query("SELECT raw FROM qlog_events WHERE event = 'ADDMEMBER'")->fetchColumn()
        );
        $s = $this->db->query('SELECT * FROM agent_sessions')->fetchAll();
        $this->assertCount(1, $s);
        $this->assertNull($s[0]['end_at']);
        $p = $this->db->query('SELECT * FROM agent_pauses')->fetchAll();
        $this->assertCount(1, $p);
        $this->assertSame('Обед', $p[0]['reason']);

        // Three days later the operator leaves: the open session started before "yesterday",
        // it must still be closed, not duplicated.
        $later = $t + 3 * 86400;
        $this->appendQlog([
            "$later|NONE|1000|PJSIP/1006|UNPAUSE|",
            "$later|NONE|1000|PJSIP/1006|REMOVEMEMBER|",
        ]);
        $this->collector()->runOnce($later + 60);
        $s = $this->db->query('SELECT * FROM agent_sessions')->fetchAll();
        $this->assertCount(1, $s);
        $this->assertSame('1006', $s[0]['extension']);
        $this->assertSame('2026-10-18 15:59:20', $s[0]['start_at']);
        $this->assertSame(date('Y-m-d H:i:s', $later), $s[0]['end_at']);
        $p = $this->db->query('SELECT * FROM agent_pauses')->fetchAll();
        $this->assertCount(1, $p);
        $this->assertSame(date('Y-m-d H:i:s', $later), $p[0]['end_at']);
    }

    public function test_rebuild_reads_rotated_files_and_rebuilds_range(): void
    {
        $f = $this->fixture('queue_ringall_1004');
        $this->insertCdr($f['cdr']);
        // first half in a rotated .gz file, second half in the current file
        file_put_contents($this->qlogPath . '-20261019.gz', gzencode(implode("\n", array_slice($f['qlog'], 0, 5)) . "\n"));
        $this->appendQlog(array_slice($f['qlog'], 5));

        $c = $this->collector()->rebuild('2026-10-18', '2026-10-18');
        $this->assertSame(2, $c['qlog_files']);
        $this->assertSame(10, $c['qlog_read']);
        $this->assertSame(1, $c['calls']);
        $this->assertSame(7, $this->rowCount('call_agents'));
        $this->assertSame('1004', $this->db->query('SELECT answered_by FROM calls')->fetchColumn());

        // rebuild again: same result
        $this->collector()->rebuild('2026-10-18', '2026-10-18');
        $this->assertSame(1, $this->rowCount('calls'));
        $this->assertSame(7, $this->rowCount('call_agents'));
        $this->assertSame(10, $this->rowCount('qlog_events'));
    }
}
