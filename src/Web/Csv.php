<?php

namespace CallStats\Web;

/**
 * CSV for Excel: UTF-8 with BOM, ';' separator, CRLF line ends; fields with ; " CR LF are quoted.
 * Cells starting with = + - @ TAB CR get a leading ' so Excel never runs them as formulas.
 */
class Csv
{
    /**
     * @param array $header field names
     * @param array[] $rows lists of scalar|null
     * @param int[] $textCols column indexes whose digit-only values are written as ="…" so Excel
     *                        keeps them as text (phone numbers: no lost "+", no 9,98E+11)
     */
    public static function build(array $header, array $rows, array $textCols = []): string
    {
        $out = "\xEF\xBB\xBF" . self::line($header, []);
        foreach ($rows as $r) {
            $out .= self::line($r, $textCols);
        }
        return $out;
    }

    private static function line(array $fields, array $textCols): string
    {
        $cells = [];
        foreach (array_values($fields) as $i => $f) {
            $f = (string) $f;
            if (in_array($i, $textCols, true) && preg_match('/^\+?[0-9]+$/', $f)) {
                $cells[] = '="' . $f . '"';
                continue;
            }
            if ($f !== '' && strpos("=+-@\t\r", $f[0]) !== false) {
                $f = "'" . $f;
            }
            if (strpbrk($f, ";\"\r\n") !== false) {
                $f = '"' . str_replace('"', '""', $f) . '"';
            }
            $cells[] = $f;
        }
        return implode(';', $cells) . "\r\n";
    }
}
