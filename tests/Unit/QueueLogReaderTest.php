<?php

namespace CallStats\Tests\Unit;

use CallStats\Source\QueueLogReader;
use CallStats\Store\StateRepository;
use PHPUnit\Framework\TestCase;

class MemState extends StateRepository
{
    public $d = [];

    public function __construct()
    {
        parent::__construct(null);
    }

    public function get(string $k): ?string
    {
        return $this->d[$k] ?? null;
    }

    public function set(string $k, string $v): void
    {
        $this->d[$k] = $v;
    }
}

class QueueLogReaderTest extends TestCase
{
    private $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qlr_' . uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            unlink($f);
        }
        rmdir($this->dir);
    }

    private function line(string $id, string $ev = 'CONNECT'): string
    {
        return "1792556228|1792556228.$id|1000|1010|$ev|4\n";
    }

    public function test_reads_only_new_lines(): void
    {
        $p = $this->dir . '/queue_log';
        file_put_contents($p, $this->line('1') . $this->line('2'));
        $r = new QueueLogReader($p, new MemState());
        $this->assertCount(2, $r->readNew());
        file_put_contents($p, $this->line('3'), FILE_APPEND);
        $this->assertCount(1, $r->readNew());
        $this->assertCount(0, $r->readNew());
    }

    public function test_partial_last_line_is_left_for_next_run(): void
    {
        $p = $this->dir . '/queue_log';
        file_put_contents($p, $this->line('1') . '1792556228|1792556228.2|1000|1010|CONNECT|4');
        $r = new QueueLogReader($p, new MemState());
        $this->assertCount(1, $r->readNew());
        file_put_contents($p, "|x|4\n", FILE_APPEND);
        $ev = $r->readNew();
        $this->assertCount(1, $ev);
        $this->assertSame(['4', 'x', '4'], $ev[0]->data);
    }

    public function test_rotation_reads_tail_of_old_file_then_new(): void
    {
        $p = $this->dir . '/queue_log';
        file_put_contents($p, $this->line('1'));
        $r = new QueueLogReader($p, new MemState());
        $this->assertCount(1, $r->readNew());
        file_put_contents($p, $this->line('A'), FILE_APPEND);
        rename($p, $p . '-20261021');
        file_put_contents($p, $this->line('B'));
        $ev = $r->readNew();
        $this->assertCount(2, $ev);
        $this->assertSame('1792556228.A', $ev[0]->callid);
        $this->assertSame('1792556228.B', $ev[1]->callid);
    }

    public function test_restarts_when_file_shrinks(): void
    {
        $p = $this->dir . '/queue_log';
        file_put_contents($p, $this->line('1') . $this->line('2'));
        $r = new QueueLogReader($p, new MemState());
        $r->readNew();
        file_put_contents($p, $this->line('1'));
        $this->assertCount(1, $r->readNew());
    }

    public function test_reads_gz(): void
    {
        $p = $this->dir . '/x.gz';
        $gz = gzopen($p, 'wb');
        gzwrite($gz, $this->line('1') . $this->line('2'));
        gzclose($gz);
        $r = new QueueLogReader($this->dir . '/queue_log', new MemState());
        $this->assertCount(2, $r->readFile($p));
    }
}
