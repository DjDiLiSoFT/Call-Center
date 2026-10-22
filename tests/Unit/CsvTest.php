<?php

namespace CallStats\Tests\Unit;

use CallStats\Web\Csv;
use PHPUnit\Framework\TestCase;

class CsvTest extends TestCase
{
    public function test_bom_separator_crlf_and_quoting(): void
    {
        $csv = Csv::build(['Дата', 'Номер'], [
            ['2026-10-20 09:00:00', '+998901112233'],
            ['a;b', 'say "hi"'],
            ["line\nbreak", "cr\rx"],
            [42, null],
        ]);
        $this->assertSame(
            "\xEF\xBB\xBF" . "Дата;Номер\r\n"
            . "2026-10-20 09:00:00;+998901112233\r\n"
            . "\"a;b\";\"say \"\"hi\"\"\"\r\n"
            . "\"line\nbreak\";\"cr\rx\"\r\n"
            . "42;\r\n",
            $csv
        );
    }

    public function test_header_only(): void
    {
        $this->assertSame("\xEF\xBB\xBFa;b\r\n", Csv::build(['a', 'b'], []));
    }
}
