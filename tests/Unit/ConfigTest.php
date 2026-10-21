<?php

namespace CallStats\Tests\Unit;

use CallStats\Config;
use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase
{
    private $file;

    protected function setUp(): void
    {
        $this->file = tempnam(sys_get_temp_dir(), 'cs');
        file_put_contents($this->file, '<?php return ' . var_export([
            'timezone' => 'Asia/Tashkent',
            'db' => ['callstats' => ['dsn' => 'x']],
        ], true) . ';');
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    public function test_reads_nested_keys_and_defaults()
    {
        $config = Config::load($this->file);

        $this->assertSame('x', $config->get('db.callstats.dsn'));
        $this->assertSame('Asia/Tashkent', $config->get('timezone'));
        $this->assertSame(5, $config->get('nope', 5));
        $this->assertNull($config->get('db.callstats.missing'));
    }

    public function test_load_fails_on_missing_file()
    {
        $this->expectException(\RuntimeException::class);
        Config::load($this->file . '.absent');
    }
}
