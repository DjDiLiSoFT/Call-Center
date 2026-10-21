<?php

namespace CallStats\Tests\Unit;

use CallStats\Web\RecordingStreamer;
use PHPUnit\Framework\TestCase;

class RecordingStreamerTest extends TestCase
{
    /** @var string */
    private $file;
    /** @var string[] */
    private $headers = [];

    protected function setUp(): void
    {
        $this->file = tempnam(sys_get_temp_dir(), 'cs_rec');
        $data = '';
        for ($i = 0; $i < 200000; $i++) {
            $data .= chr($i % 251);
        }
        file_put_contents($this->file, $data);
        $this->headers = [];
    }

    protected function tearDown(): void
    {
        unlink($this->file);
    }

    private function stream(?string $range, bool $dl = false): string
    {
        $s = new RecordingStreamer(function (string $h): void {
            $this->headers[] = $h;
        });
        ob_start();
        $s->send($this->file, 'audio/wav', $range, $dl);
        return (string) ob_get_clean();
    }

    public function test_no_range_200_full(): void
    {
        $body = $this->stream(null);
        $this->assertSame(file_get_contents($this->file), $body);
        $this->assertContains('HTTP/1.1 200 OK', $this->headers);
        $this->assertContains('Content-Length: 200000', $this->headers);
        $this->assertContains('Accept-Ranges: bytes', $this->headers);
        $this->assertContains('Content-Type: audio/wav', $this->headers);
    }

    public function test_range_returns_206_and_slice(): void
    {
        $body = $this->stream('bytes=10-19');
        $this->assertSame(substr(file_get_contents($this->file), 10, 10), $body);
        $this->assertContains('HTTP/1.1 206 Partial Content', $this->headers);
        $this->assertContains('Content-Range: bytes 10-19/200000', $this->headers);
        $this->assertContains('Content-Length: 10', $this->headers);
    }

    public function test_open_ended_and_suffix_ranges(): void
    {
        $all = file_get_contents($this->file);
        $this->assertSame(substr($all, 199990), $this->stream('bytes=199990-'));
        $this->headers = [];
        $this->assertSame(substr($all, -5), $this->stream('bytes=-5'));
        $this->assertContains('Content-Range: bytes 199995-199999/200000', $this->headers);
    }

    public function test_range_across_chunks(): void
    {
        $all = file_get_contents($this->file);
        $this->assertSame(substr($all, 100, 150000), $this->stream('bytes=100-150099'));
    }

    public function test_end_past_size_is_clamped(): void
    {
        $this->stream('bytes=199998-999999');
        $this->assertContains('Content-Range: bytes 199998-199999/200000', $this->headers);
    }

    public function test_bad_range_416(): void
    {
        foreach (['bytes=300000-', 'bytes=20-10', 'bytes=abc', 'bytes=-'] as $r) {
            $this->headers = [];
            $body = $this->stream($r);
            $this->assertSame('', $body, $r);
            $this->assertContains('HTTP/1.1 416 Range Not Satisfiable', $this->headers, $r);
            $this->assertContains('Content-Range: bytes */200000', $this->headers, $r);
        }
    }

    public function test_multi_range_falls_back_to_full(): void
    {
        $this->stream('bytes=0-1,5-6');
        $this->assertContains('HTTP/1.1 200 OK', $this->headers);
    }

    public function test_download_disposition(): void
    {
        $this->stream(null, true);
        $found = array_filter($this->headers, function ($h) {
            return strpos($h, 'Content-Disposition: attachment; filename="') === 0;
        });
        $this->assertNotEmpty($found);
    }
}
