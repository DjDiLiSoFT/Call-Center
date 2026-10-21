<?php

namespace CallStats\Tests\Unit;

use CallStats\ProcessLock;
use PHPUnit\Framework\TestCase;

class ProcessLockTest extends TestCase
{
    public function test_open_fails_loudly_when_file_cannot_be_opened(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot open lock file');
        ProcessLock::open(sys_get_temp_dir() . '/no_such_dir_' . uniqid() . '/callstats.lock');
    }

    public function test_second_nonblocking_lock_is_refused(): void
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'callstats_lock_' . uniqid();
        $a = ProcessLock::open($path);
        $b = ProcessLock::open($path);
        $this->assertTrue(flock($a, LOCK_EX | LOCK_NB));
        $this->assertFalse(flock($b, LOCK_EX | LOCK_NB));
        flock($a, LOCK_UN);
        fclose($a);
        fclose($b);
        if (DIRECTORY_SEPARATOR === '/') {
            $this->assertSame('0666', substr(sprintf('%o', fileperms($path)), -4));
        }
        unlink($path);
    }
}
