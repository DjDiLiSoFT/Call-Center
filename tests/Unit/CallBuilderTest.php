<?php

namespace CallStats\Tests\Unit;

use CallStats\Build\AgentOutcome;
use CallStats\Build\AgentResolver;
use CallStats\Build\CallBuilder;
use CallStats\Build\CallRecord;
use CallStats\Source\QueueLogParser;
use PHPUnit\Framework\TestCase;

class CallBuilderTest extends TestCase
{
    private const FIXTURE_COLUMNS = [
        'calldate', 'src', 'dst', 'dcontext', 'channel', 'dstchannel', 'duration', 'billsec',
        'disposition', 'uniqueid', 'recordingfile', 'linkedid', 'sequence',
    ];

    /** All columns of asteriskcdrdb.cdr (see samples tables.txt). */
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

    /** @return array{0: array, 1: array} [cdrRows, qlogEvents] */
    private function fixture(string $name): array
    {
        $f = require __DIR__ . '/../fixtures/calls/' . $name . '.php';
        $rows = [];
        foreach ($f['cdr'] as $values) {
            $row = array_fill_keys(self::CDR_COLUMNS, '');
            foreach (array_combine(self::FIXTURE_COLUMNS, $values) as $k => $v) {
                $row[$k] = (string) $v; // PDO returns strings
            }
            $rows[] = $row;
        }
        $events = [];
        foreach ($f['qlog'] as $line) {
            $events[] = QueueLogParser::parse($line);
        }
        return [$rows, $events];
    }

    private function build(string $name, bool $withQlog = true): CallRecord
    {
        [$rows, $events] = $this->fixture($name);
        $linkedid = $rows ? $rows[0]['linkedid'] : $events[0]->callid;
        $call = $this->builder->build($linkedid, $rows, $withQlog ? $events : []);
        $this->assertNotNull($call);
        return $call;
    }

    /** @return array<string,AgentOutcome> */
    private function byExt(CallRecord $call): array
    {
        $out = [];
        foreach ($call->agents as $a) {
            $this->assertArrayNotHasKey($a->extension . '/' . $a->direction, $out, 'one outcome per operator');
            $out[$a->extension . '/' . $a->direction] = $a;
        }
        return $out;
    }

    private function outcomes(CallRecord $call): array
    {
        $map = [];
        foreach ($this->byExt($call) as $key => $a) {
            $map[$key] = $a->outcome;
        }
        ksort($map);
        return $map;
    }

    public function test_returns_null_without_cdr_and_enterqueue(): void
    {
        $this->assertNull($this->builder->build('1.1', [], []));
        $ev = QueueLogParser::parse('1792321160|1.1|1000|1001|RINGNOANSWER|0');
        $this->assertNull($this->builder->build('1.1', [], [$ev]));
    }

    public function test_queue_ringall_answered_by_1004(): void
    {
        $call = $this->build('queue_ringall_1004');

        $this->assertSame('1792321160.222942', $call->callKey);
        $this->assertSame('queue', $call->kind);
        $this->assertSame('1000', $call->queue);
        $this->assertSame('1004', $call->answeredBy);
        $this->assertSame(30, $call->talkSec);
        $this->assertSame(3, $call->waitSec);
        $this->assertSame('900000001', $call->clientNumber);
        $this->assertSame('2026-10-18 15:59:20', $call->startedAt);
        $this->assertSame('2026-10-18 15:59:23', $call->answeredAt);
        $this->assertSame('2026-10-18 15:59:53', $call->endedAt);
        $this->assertFalse($call->approx);
        $this->assertSame('q-1000-900000001-20261018-155920-1792321160.222942.wav', $call->recordingFile);
        $this->assertSame([
            '1001/in' => 'other_answered',
            '1002/in' => 'other_answered',
            '1003/in' => 'offline',
            '1004/in' => 'answered',
            '1005/in' => 'offline',
            '1009/in' => 'offline',
            '1010/in' => 'offline',
        ], $this->outcomes($call));

        $a = $this->byExt($call)['1004/in'];
        $this->assertSame(3, $a->ringSec);
        $this->assertSame(30, $a->talkSec);
        $this->assertSame('2026-10-18 15:59:20', $a->firstRingAt);
        $this->assertSame('2026-10-18 15:59:23', $a->answeredAt);
        $this->assertSame(3, $this->byExt($call)['1001/in']->ringSec);

        $this->assertCount(10, $call->sources['qlog']);
        $this->assertContains('1792321160.222956', $call->sources['cdr']);
        $this->assertContains('1792321160.222942', $call->sources['cdr']);
    }

    public function test_queue_blind_transfer_is_recorded(): void
    {
        $lines = [
            '1792560000|1792560000.240002|1000|NONE|ENTERQUEUE||900000009|1',
            '1792560004|1792560000.240002|1000|1001|CONNECT|4|1792560000.240003|4',
            '1792560060|1792560000.240002|1000|1001|BLINDTRANSFER|1002|from-internal|4|56|1',
        ];
        $events = array_map([QueueLogParser::class, 'parse'], $lines);
        $call = $this->builder->build('1792560000.240002', [], $events);

        $this->assertSame('1001', $call->answeredBy);
        $this->assertSame([['from' => '1001', 'to' => '1002', 'at' => '2026-10-21 10:21:00']], $call->transfers);
    }

    public function test_queue_retry_rounds_give_one_missed(): void
    {
        $call = $this->build('queue_retry_abandon');

        $this->assertSame('queue', $call->kind);
        $this->assertNull($call->answeredBy);
        $this->assertNull($call->answeredAt);
        $this->assertSame(40, $call->waitSec);
        $this->assertSame(0, $call->talkSec);
        $this->assertSame('900000002', $call->clientNumber);
        $this->assertSame([
            '1001/in' => 'missed',
            '1002/in' => 'missed',
            '1005/in' => 'offline',
        ], $this->outcomes($call));
        $this->assertSame(30, $this->byExt($call)['1001/in']->ringSec);
        $this->assertSame('2026-10-21 09:29:24', $call->startedAt);
        $this->assertSame('2026-10-21 09:30:04', $call->endedAt);
    }

    public function test_queue_abandon_while_ringing_is_caller_hangup(): void
    {
        $call = $this->build('queue_abandon_ringing');

        $this->assertNull($call->answeredBy);
        $this->assertSame(['1001/in' => 'caller_hangup'], $this->outcomes($call));
        $this->assertSame(8, $this->byExt($call)['1001/in']->ringSec);
    }

    public function test_queue_offline_member_not_missed(): void
    {
        $call = $this->build('queue_offline_member');

        $this->assertNull($call->answeredBy);
        $this->assertSame(20, $call->waitSec);
        $this->assertSame([
            '1001/in' => 'missed',
            '1005/in' => 'offline',
        ], $this->outcomes($call));
    }

    public function test_queue_without_qlog_uses_cdr_and_marks_approx(): void
    {
        $call = $this->build('queue_ringall_1004', false);

        $this->assertTrue($call->approx);
        $this->assertSame('queue', $call->kind);
        $this->assertSame('1000', $call->queue);
        $this->assertSame('1004', $call->answeredBy);
        $this->assertSame(30, $call->talkSec);
        $this->assertSame('900000001', $call->clientNumber);
        $this->assertSame('q-1000-900000001-20261018-155920-1792321160.222942.wav', $call->recordingFile);
        $this->assertSame([
            '1001/in' => 'other_answered',
            '1002/in' => 'other_answered',
            '1003/in' => 'offline',
            '1004/in' => 'answered',
            '1005/in' => 'offline',
            '1009/in' => 'offline',
            '1010/in' => 'offline',
        ], $this->outcomes($call));
        $a = $this->byExt($call)['1004/in'];
        $this->assertSame(3, $a->ringSec);
        $this->assertSame(30, $a->talkSec);
        $this->assertSame([], $call->sources['qlog']);
    }

    public function test_direct_inbound_answered(): void
    {
        $call = $this->build('direct_inbound_answered');

        $this->assertSame('direct', $call->kind);
        $this->assertNull($call->queue);
        $this->assertSame('1001', $call->answeredBy);
        $this->assertSame('900000005', $call->clientNumber);
        $this->assertSame(20, $call->talkSec);
        $this->assertSame(5, $call->waitSec);
        $this->assertSame('2026-10-20 11:00:05', $call->answeredAt);
        $this->assertSame('2026-10-20 11:00:25', $call->endedAt);
        $this->assertFalse($call->approx);
        $this->assertSame(['1001/in' => 'answered'], $this->outcomes($call));
        $a = $this->byExt($call)['1001/in'];
        $this->assertSame(5, $a->ringSec);
        $this->assertSame(20, $a->talkSec);
    }

    public function test_direct_inbound_missed(): void
    {
        $call = $this->build('direct_inbound_missed');

        $this->assertSame('direct', $call->kind);
        $this->assertNull($call->answeredBy);
        $this->assertNull($call->recordingFile);
        $this->assertSame(['1001/in' => 'missed'], $this->outcomes($call));
        $this->assertSame(20, $this->byExt($call)['1001/in']->ringSec);
        $this->assertNull($this->byExt($call)['1001/in']->answeredAt);
    }

    public function test_outbound_connected_and_not(): void
    {
        $call = $this->build('outbound_answered');
        $this->assertSame('outbound', $call->kind);
        $this->assertSame('900000007', $call->clientNumber);
        $this->assertSame('1004', $call->answeredBy);
        $this->assertSame(44, $call->talkSec);
        $this->assertSame('out-900000007-1004-20261018-165637-1792324597.223910.wav', $call->recordingFile);
        $this->assertSame(['1004/out' => 'answered'], $this->outcomes($call));
        $a = $this->byExt($call)['1004/out'];
        $this->assertSame(7, $a->ringSec);
        $this->assertSame(44, $a->talkSec);
        $this->assertSame('2026-10-18 16:56:44', $a->answeredAt);

        $call = $this->build('outbound_not_connected');
        $this->assertSame('outbound', $call->kind);
        $this->assertSame('900000008', $call->clientNumber);
        $this->assertNull($call->answeredBy);
        $this->assertSame(0, $call->talkSec);
        $this->assertSame(['1002/out' => 'not_connected'], $this->outcomes($call));
    }

    public function test_internal_call(): void
    {
        $call = $this->build('internal_call');

        $this->assertSame('internal', $call->kind);
        $this->assertSame('1001', $call->answeredBy);
        $this->assertSame(1, $call->talkSec);
        $this->assertSame([
            '1001/in' => 'answered',
            '1004/out' => 'answered',
        ], $this->outcomes($call));
        $this->assertSame(7, $this->byExt($call)['1001/in']->ringSec);
    }
}
