<?php

namespace CallStats\Tests\Unit;

use CallStats\Build\AgentOutcome;
use CallStats\Build\AgentResolver;
use CallStats\Build\CallBuilder;
use CallStats\Build\CallRecord;
use CallStats\Source\QueueLogParser;
use PHPUnit\Framework\TestCase;

/**
 * Transfers (spec 5.3: talk before the transfer goes to the first operator, after it to the second).
 *
 * The 3-day sample (samples/callstats-sample) has NO transfer: queue_log holds no TRANSFER /
 * BLINDTRANSFER / ATTENDEDTRANSFER event, no CDR row has an xfer context, and the only queue linkedid
 * with two ANSWERED phone legs (1792397534.227940) is a ring-all pickup race (1001 ANSWERED with
 * billsec 0), not a transfer. All fixtures here are therefore synthetic, built by the Asterisk 18
 * queue_log/CDR formats with invented numbers (see the comment in each tests/fixtures/calls/transfer_*.php).
 */
class CallBuilderTransferTest extends TestCase
{
    private const FIXTURE_COLUMNS = [
        'calldate', 'src', 'dst', 'dcontext', 'channel', 'dstchannel', 'duration', 'billsec',
        'disposition', 'uniqueid', 'recordingfile', 'linkedid', 'sequence',
    ];

    private const CDR_COLUMNS = [
        'calldate', 'clid', 'src', 'dst', 'dcontext', 'channel', 'dstchannel', 'lastapp', 'lastdata',
        'duration', 'billsec', 'disposition', 'amaflags', 'accountcode', 'uniqueid', 'userfield', 'did',
        'recordingfile', 'cnum', 'cnam', 'outbound_cnum', 'outbound_cnam', 'dst_cnam', 'linkedid',
        'peeraccount', 'sequence',
    ];

    /** @var CallBuilder */
    private $builder;

    protected function setUp(): void
    {
        date_default_timezone_set('Asia/Tashkent');
        $exts = [];
        for ($i = 1001; $i <= 1012; $i++) {
            $exts[] = (string) $i;
        }
        $this->builder = new CallBuilder(new AgentResolver($exts));
    }

    private function rows(array $compact): array
    {
        $rows = [];
        foreach ($compact as $values) {
            $row = array_fill_keys(self::CDR_COLUMNS, '');
            foreach (array_combine(self::FIXTURE_COLUMNS, $values) as $k => $v) {
                $row[$k] = (string) $v;
            }
            $rows[] = $row;
        }
        return $rows;
    }

    private function events(array $lines): array
    {
        return array_map([QueueLogParser::class, 'parse'], $lines);
    }

    private function build(string $name, bool $withCdr = true, bool $withQlog = true): CallRecord
    {
        $f = require __DIR__ . '/../fixtures/calls/' . $name . '.php';
        $rows = $this->rows($f['cdr']);
        $call = $this->builder->build(
            $rows[0]['linkedid'],
            $withCdr ? $rows : [],
            $withQlog ? $this->events($f['qlog']) : []
        );
        $this->assertNotNull($call);
        return $call;
    }

    /** @return array<string,AgentOutcome> */
    private function byExt(CallRecord $call): array
    {
        $out = [];
        foreach ($call->agents as $a) {
            $key = $a->extension . '/' . $a->direction;
            $this->assertArrayNotHasKey($key, $out, 'one outcome per operator');
            $out[$key] = $a;
        }
        ksort($out);
        return $out;
    }

    private function outcomes(CallRecord $call): array
    {
        return array_map(function (AgentOutcome $a) {
            return $a->outcome;
        }, $this->byExt($call));
    }

    public function test_transfer_splits_talk_time(): void
    {
        $call = $this->build('transfer_blind');

        $this->assertSame('queue', $call->kind);
        $this->assertFalse($call->approx);
        $this->assertSame('1001', $call->answeredBy);
        $this->assertSame('2026-10-21 15:53:24', $call->answeredAt);
        $this->assertSame([['from' => '1001', 'to' => '1002', 'at' => '2026-10-21 15:54:04']], $call->transfers);
        $this->assertSame(['1001/in' => 'answered', '1002/in' => 'answered'], $this->outcomes($call));

        $a = $this->byExt($call);
        $this->assertSame(40, $a['1001/in']->talkSec);
        $this->assertSame(60, $a['1002/in']->talkSec);
        $this->assertSame(5, $a['1002/in']->ringSec);
        $this->assertSame('2026-10-21 15:54:09', $a['1002/in']->answeredAt);
        $this->assertSame('2026-10-21 15:53:20', $a['1002/in']->firstRingAt);
        $this->assertSame(100, $call->talkSec, 'the caller talked to both operators');
        $this->assertSame(4, $call->waitSec);
        $this->assertSame('2026-10-21 15:55:09', $call->endedAt);
    }

    public function test_transfer_without_queue_log_found_from_cdr(): void
    {
        $call = $this->build('transfer_blind', true, false);

        $this->assertTrue($call->approx);
        $this->assertSame('1001', $call->answeredBy);
        $this->assertSame([['from' => '1001', 'to' => '1002', 'at' => '2026-10-21 15:54:04']], $call->transfers);
        $a = $this->byExt($call);
        $this->assertSame('answered', $a['1001/in']->outcome);
        $this->assertSame(40, $a['1001/in']->talkSec);
        $this->assertSame('answered', $a['1002/in']->outcome);
        $this->assertSame(60, $a['1002/in']->talkSec);
        $this->assertSame(100, $call->talkSec);
    }

    public function test_transfer_from_queue_log_only_uses_calltime(): void
    {
        // No CDR: the transfer target cannot be confirmed as answered; 1001's talk is the calltime.
        $call = $this->build('transfer_blind', false, true);

        $this->assertSame('1001', $call->answeredBy);
        $this->assertSame([['from' => '1001', 'to' => '1002', 'at' => '2026-10-21 15:54:04']], $call->transfers);
        $this->assertSame(40, $this->byExt($call)['1001/in']->talkSec);
        $this->assertSame(40, $call->talkSec);
    }

    public function test_attended_transfer_target_from_cdr(): void
    {
        $call = $this->build('transfer_attended');

        $this->assertSame('1001', $call->answeredBy);
        $this->assertSame([['from' => '1001', 'to' => '1002', 'at' => '2026-10-21 16:10:54']], $call->transfers);
        $this->assertSame(['1001/in' => 'answered', '1002/in' => 'answered'], $this->outcomes($call));
        $a = $this->byExt($call);
        $this->assertSame(50, $a['1001/in']->talkSec, 'calltime of ATTENDEDTRANSFER');
        $this->assertSame(69, $a['1002/in']->talkSec, 'consultation 9 s + 60 s with the caller');
        $this->assertSame(5, $a['1002/in']->ringSec);
        $this->assertSame('2026-10-21 16:10:45', $a['1002/in']->answeredAt);
        $this->assertSame(119, $call->talkSec);
    }

    public function test_attended_transfer_without_queue_log_is_one_transfer(): void
    {
        $call = $this->build('transfer_attended', true, false);

        $this->assertSame([['from' => '1001', 'to' => '1002', 'at' => '2026-10-21 16:10:40']], $call->transfers);
        $this->assertSame(69, $this->byExt($call)['1002/in']->talkSec);
    }

    public function test_transfer_to_external_number_has_no_outcome(): void
    {
        foreach ([true, false] as $withQlog) {
            $call = $this->build('transfer_external', true, $withQlog);

            $this->assertSame('1001', $call->answeredBy);
            $this->assertSame([['from' => '1001', 'to' => '900000099', 'at' => '2026-10-21 16:27:13']], $call->transfers);
            $this->assertSame(['1001/in' => 'answered'], $this->outcomes($call));
            $this->assertSame(30, $this->byExt($call)['1001/in']->talkSec);
            $this->assertSame(30, $call->talkSec);
        }
    }

    public function test_old_transfer_event_format(): void
    {
        // Asterisk < 12: TRANSFER|<ext>|<context>|<holdtime>|<calltime>|<origposition>
        $call = $this->builder->build('1792583000.250301', [], $this->events([
            '1792583000|1792583000.250301|1000|NONE|ENTERQUEUE||900000024|1',
            '1792583005|1792583000.250301|1000|1003|CONNECT|5|1792583000.250302|5',
            '1792583050|1792583000.250301|1000|1003|TRANSFER|1004|from-internal|5|45|1',
        ]));

        $this->assertSame([['from' => '1003', 'to' => '1004', 'at' => '2026-10-21 16:44:10']], $call->transfers);
        $this->assertSame(45, $this->byExt($call)['1003/in']->talkSec);
    }

    public function test_direct_call_transfer(): void
    {
        $id = '1792501200.300101';
        $call = $this->builder->build($id, $this->rows([
            ['2026-10-20 13:00:00', '900000023', '1001', 'ext-local', 'PJSIP/552020515-00009c01', 'PJSIP/1001-00009c02', 45, 40, 'ANSWERED', $id, '', $id, 300101],
            ['2026-10-20 13:00:45', '900000023', '1002', 'ext-local', 'PJSIP/552020515-00009c01', 'PJSIP/1002-00009c03', 65, 60, 'ANSWERED', '1792501245.300103', '', $id, 300103],
        ]), []);

        $this->assertSame('direct', $call->kind);
        $this->assertSame('1001', $call->answeredBy);
        $this->assertSame('2026-10-20 13:00:05', $call->answeredAt);
        $this->assertSame([['from' => '1001', 'to' => '1002', 'at' => '2026-10-20 13:00:45']], $call->transfers);
        $this->assertSame(['1001/in' => 'answered', '1002/in' => 'answered'], $this->outcomes($call));
        $this->assertSame(40, $this->byExt($call)['1001/in']->talkSec);
        $this->assertSame(60, $this->byExt($call)['1002/in']->talkSec);
        $this->assertSame(100, $call->talkSec);
    }

    public function test_ring_all_pickup_race_is_not_a_transfer(): void
    {
        // Shape of real 1792397534.227940: two phones pick up at once, one leg ANSWERED with billsec 0.
        $id = '1792584000.250401';
        $call = $this->builder->build($id, $this->rows([
            ['2026-10-21 17:00:00', '900000025', '1000', 'ext-queues', 'PJSIP/552020515-0000b401', 'Local/1001@from-queue-0000f401;1', 2, 2, 'NO ANSWER', $id, '', $id, 250401],
            ['2026-10-21 17:00:00', '900000025', '1000', 'ext-queues', 'PJSIP/552020515-0000b401', 'Local/1002@from-queue-0000f402;1', 92, 92, 'ANSWERED', $id, '', $id, 250402],
            ['2026-10-21 17:00:00', '900000025', '1001', 'ext-local', 'Local/1001@from-queue-0000f401;2', 'PJSIP/1001-0000b402', 2, 0, 'ANSWERED', '1792584000.250403', '', $id, 250403],
            ['2026-10-21 17:00:00', '900000025', '1002', 'ext-local', 'Local/1002@from-queue-0000f402;2', 'PJSIP/1002-0000b403', 92, 90, 'ANSWERED', '1792584000.250404', '', $id, 250404],
        ]), []);

        $this->assertSame([], $call->transfers);
        $this->assertSame('1002', $call->answeredBy);
        $this->assertSame(90, $call->talkSec);
        $this->assertSame(['1001/in' => 'other_answered', '1002/in' => 'answered'], $this->outcomes($call));
    }
}
