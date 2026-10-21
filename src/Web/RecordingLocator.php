<?php

namespace CallStats\Web;

/**
 * Finds the audio file of a call: <root>/Y/m/d/<file>, the date being the call start date.
 */
class RecordingLocator
{
    private const MIME = [
        'wav' => 'audio/wav',
        'mp3' => 'audio/mpeg',
        'ogg' => 'audio/ogg',
    ];

    /** Files up to this size hold only a header (no audio). */
    private const EMPTY_MAX_BYTES = 44;

    /** @var string */
    private $root;

    public function __construct(string $monitorRoot)
    {
        $this->root = $monitorRoot;
    }

    /** @return array{status:string, path:?string, mime:?string} status: ok|missing|empty|unsupported */
    public function locate(string $startedAt, string $file): array
    {
        $none = function (string $status): array {
            return ['status' => $status, 'path' => null, 'mime' => null];
        };

        $ts = strtotime($startedAt);
        if ($file === '' || $ts === false || $file === '.' || $file === '..'
            || basename($file) !== $file || strpbrk($file, "/\\\0") !== false) {
            return $none('missing');
        }

        $path = rtrim($this->root, '/\\') . '/' . date('Y/m/d', $ts) . '/' . $file;
        $real = @realpath($path);
        $rootReal = @realpath($this->root);
        if ($real === false || $rootReal === false || !is_file($real)) {
            return $none('missing');
        }
        $prefix = rtrim($rootReal, '/\\') . DIRECTORY_SEPARATOR;
        if (strncmp($real, $prefix, strlen($prefix)) !== 0) {
            return $none('missing');
        }

        $mime = self::MIME[strtolower(pathinfo($file, PATHINFO_EXTENSION))] ?? null;
        if ($mime === null) {
            return ['status' => 'unsupported', 'path' => $real, 'mime' => null];
        }
        $size = @filesize($real);
        if ($size === false || $size <= self::EMPTY_MAX_BYTES) {
            return ['status' => 'empty', 'path' => $real, 'mime' => $mime];
        }
        return ['status' => 'ok', 'path' => $real, 'mime' => $mime];
    }
}
