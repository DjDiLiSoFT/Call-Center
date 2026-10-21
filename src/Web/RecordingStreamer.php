<?php

namespace CallStats\Web;

/**
 * Sends an audio file with HTTP Range support (single range: a-b, a-, -n).
 * A multi-range request ("bytes=0-1,5-6") is answered with the full file (200), which the
 * HTTP spec allows. A malformed or unsatisfiable single range gives 416.
 */
class RecordingStreamer
{
    private const CHUNK = 65536;

    /** @var callable fn(string $headerLine): void; status goes as "HTTP/1.1 206 Partial Content" */
    private $header;

    public function __construct(callable $header)
    {
        $this->header = $header;
    }

    public function send(string $path, string $mime, ?string $rangeHeader, bool $download): void
    {
        $size = @filesize($path);
        $fh = $size === false ? false : @fopen($path, 'rb');
        if ($fh === false) {
            $this->h('HTTP/1.1 404 Not Found');
            return;
        }

        $start = 0;
        $end = $size - 1;
        $partial = false;

        if ($rangeHeader !== null && stripos(trim($rangeHeader), 'bytes=') === 0) {
            $spec = trim(substr(trim($rangeHeader), 6));
            if (strpos($spec, ',') === false) {
                $r = $this->parseRange($spec, $size);
                if ($r === null) {
                    fclose($fh);
                    $this->h('HTTP/1.1 416 Range Not Satisfiable');
                    $this->h('Content-Range: bytes */' . $size);
                    $this->h('Content-Length: 0');
                    return;
                }
                list($start, $end) = $r;
                $partial = true;
            }
        }

        $length = $end - $start + 1;
        $name = str_replace(['"', "\r", "\n", '\\'], '_', basename($path));
        $this->h($partial ? 'HTTP/1.1 206 Partial Content' : 'HTTP/1.1 200 OK');
        $this->h('Content-Type: ' . $mime);
        $this->h('Accept-Ranges: bytes');
        $this->h('Content-Length: ' . $length);
        if ($partial) {
            $this->h('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
        }
        $this->h('Content-Disposition: ' . ($download ? 'attachment' : 'inline')
            . '; filename="' . $name . '"; filename*=UTF-8\'\'' . rawurlencode(basename($path)));

        if ($length > 0) {
            fseek($fh, $start);
            $left = $length;
            while ($left > 0 && !feof($fh)) {
                $buf = fread($fh, min(self::CHUNK, $left));
                if ($buf === false || $buf === '') {
                    break;
                }
                echo $buf;
                $left -= strlen($buf);
                if (connection_aborted()) {
                    break;
                }
            }
        }
        fclose($fh);
    }

    /** @return int[]|null [start, end] inclusive, null when malformed/unsatisfiable */
    private function parseRange(string $spec, int $size): ?array
    {
        if (!preg_match('/^(\d*)-(\d*)$/', $spec, $m) || ($m[1] === '' && $m[2] === '') || $size <= 0) {
            return null;
        }
        if ($m[1] === '') { // suffix: last n bytes
            $n = (int) $m[2];
            if ($n <= 0) {
                return null;
            }
            return [max(0, $size - $n), $size - 1];
        }
        $start = (int) $m[1];
        $end = $m[2] === '' ? $size - 1 : min((int) $m[2], $size - 1);
        if ($start >= $size || $start > $end) {
            return null;
        }
        return [$start, $end];
    }

    private function h(string $line): void
    {
        call_user_func($this->header, $line);
    }
}
