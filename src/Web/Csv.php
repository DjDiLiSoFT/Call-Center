<?php

namespace CallStats\Web;

/**
 * CSV for Excel: UTF-8 with BOM, ';' separator, CRLF line ends; fields with ; " CR LF are quoted.
 */
class Csv
{
    /**
     * @param array $header field names
     * @param array[] $rows lists of scalar|null
     */
    public static function build(array $header, array $rows): string
    {
        $out = "\xEF\xBB\xBF" . self::line($header);
        foreach ($rows as $r) {
            $out .= self::line($r);
        }
        return $out;
    }

    private static function line(array $fields): string
    {
        $cells = [];
        foreach ($fields as $f) {
            $f = (string) $f;
            if (strpbrk($f, ";\"\r\n") !== false) {
                $f = '"' . str_replace('"', '""', $f) . '"';
            }
            $cells[] = $f;
        }
        return implode(';', $cells) . "\r\n";
    }
}
