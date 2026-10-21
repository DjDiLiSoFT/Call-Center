<?php

namespace CallStats\Tests\Unit;

use CallStats\Web\RecordingLocator;
use PHPUnit\Framework\TestCase;

class RecordingLocatorTest extends TestCase
{
    /** @var string */
    private $root;

    protected function setUp(): void
    {
        date_default_timezone_set('Asia/Tashkent');
        $this->root = sys_get_temp_dir() . '/cs_loc_' . uniqid();
        mkdir($this->root . '/2026/10/18', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/2026/10/18/*') ?: [] as $f) {
            unlink($f);
        }
        @rmdir($this->root . '/2026/10/18');
        @rmdir($this->root . '/2026/10');
        @rmdir($this->root . '/2026');
        @rmdir($this->root);
    }

    private function put(string $name, int $bytes): string
    {
        $p = $this->root . '/2026/10/18/' . $name;
        file_put_contents($p, str_repeat('x', $bytes));
        return $p;
    }

    public function test_builds_dated_path(): void
    {
        $this->put('a.wav', 100);
        $r = (new RecordingLocator($this->root))->locate('2026-10-18 10:00:00', 'a.wav');
        $this->assertSame('ok', $r['status']);
        $this->assertSame('audio/wav', $r['mime']);
        $this->assertSame(realpath($this->root . '/2026/10/18/a.wav'), $r['path']);
    }

    public function test_missing_file(): void
    {
        $r = (new RecordingLocator($this->root))->locate('2026-10-18 10:00:00', 'nope.wav');
        $this->assertSame('missing', $r['status']);
    }

    public function test_44_byte_file_is_empty(): void
    {
        $this->put('e.wav', 44);
        $r = (new RecordingLocator($this->root))->locate('2026-10-18 10:00:00', 'e.wav');
        $this->assertSame('empty', $r['status']);
    }

    public function test_traversal_rejected(): void
    {
        file_put_contents($this->root . '/secret.wav', str_repeat('x', 100));
        $loc = new RecordingLocator($this->root);
        $this->assertSame('missing', $loc->locate('2026-10-18 10:00:00', '../../etc/passwd')['status']);
        $this->assertSame('missing', $loc->locate('2026-10-18 10:00:00', '../../../secret.wav')['status']);
        $this->assertSame('missing', $loc->locate('2026-10-18 10:00:00', '..')['status']);
        $this->assertSame('missing', $loc->locate('2026-10-18 10:00:00', '')['status']);
        unlink($this->root . '/secret.wav');
    }

    public function test_gsm_unsupported(): void
    {
        $this->put('g.gsm', 100);
        $r = (new RecordingLocator($this->root))->locate('2026-10-18 10:00:00', 'g.gsm');
        $this->assertSame('unsupported', $r['status']);
        $this->assertNull($r['mime']);
    }

    public function test_mp3_and_ogg_mime(): void
    {
        $this->put('a.mp3', 100);
        $this->put('b.ogg', 100);
        $loc = new RecordingLocator($this->root);
        $this->assertSame('audio/mpeg', $loc->locate('2026-10-18 10:00:00', 'a.mp3')['mime']);
        $this->assertSame('audio/ogg', $loc->locate('2026-10-18 10:00:00', 'b.ogg')['mime']);
    }
}
