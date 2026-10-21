<?php

namespace CallStats;

/**
 * Lock file shared by bin/collect.php and bin/rebuild.php (one instance at a time).
 */
class ProcessLock
{
    public static function defaultPath(): string
    {
        $dir = DIRECTORY_SEPARATOR === '/' ? '/tmp' : sys_get_temp_dir();
        return $dir . '/callstats-collect.lock';
    }

    /**
     * Opens (creates) the lock file. A newly created file is made world-writable, so that a
     * run as another user (e.g. rebuild as root) does not lock cron (user asterisk) out forever.
     *
     * @return resource
     * @throws \RuntimeException when the file cannot be opened
     */
    public static function open(?string $path = null)
    {
        $path = $path ?? self::defaultPath();
        $existed = file_exists($path);
        $h = @fopen($path, 'c');
        if ($h === false) {
            $err = error_get_last();
            throw new \RuntimeException(
                'Cannot open lock file ' . $path . ($err ? ': ' . $err['message'] : '')
                . ' (check its owner and permissions)'
            );
        }
        if (!$existed) {
            @chmod($path, 0666);
        }
        return $h;
    }
}
