<?php

namespace CallStats\Source;

use CallStats\Store\StateRepository;

class QueueLogReader
{
    private $path;
    private $state;

    public function __construct(string $path, StateRepository $state)
    {
        $this->path = $path;
        $this->state = $state;
    }

    /** @return QueueLogEvent[] */
    public function readNew(): array
    {
        $events = [];
        if (!is_file($this->path)) {
            return $events;
        }
        clearstatcache();
        $storedId = $this->state->get('queue_log.inode');
        $offStr = $this->state->get('queue_log.offset');
        $offset = $offStr === null ? 0 : (int)$offStr;
        $curId = $this->fileId($this->path);

        if ($storedId !== null && $storedId !== $curId) {
            // Rotated: finish the old file's tail (if it is still a plain file), then start the new one.
            foreach (glob($this->path . '-*') ?: [] as $old) {
                if (substr($old, -3) === '.gz' || !is_file($old)) {
                    continue; // tail of a compressed file cannot be re-read by offset
                }
                if ($this->fileId($old) === $storedId) {
                    [$evs] = $this->readFrom($old, $offset, true);
                    $events = array_merge($events, $evs);
                    break;
                }
            }
            $offset = 0;
        } elseif ($storedId !== null && filesize($this->path) < $offset) {
            $offset = 0; // truncated in place
        }

        [$evs, $newOffset] = $this->readFrom($this->path, $offset, false);
        $events = array_merge($events, $evs);

        $this->state->set('queue_log.inode', $curId);
        $this->state->set('queue_log.offset', (string)$newOffset);
        return $events;
    }

    /** @return QueueLogEvent[] */
    public function readFile(string $path): array
    {
        $events = [];
        $isGz = substr($path, -3) === '.gz';
        $h = $isGz ? gzopen($path, 'rb') : fopen($path, 'rb');
        if (!$h) {
            return $events;
        }
        while (($line = $isGz ? gzgets($h) : fgets($h)) !== false) {
            $e = QueueLogParser::parse($line);
            if ($e !== null) {
                $events[] = $e;
            }
        }
        $isGz ? gzclose($h) : fclose($h);
        return $events;
    }

    /**
     * Reads complete lines from $offset. An unterminated last line is left unread
     * (unless $final, for files that will not grow any more).
     * @return array [QueueLogEvent[], int newOffset]
     */
    private function readFrom(string $path, int $offset, bool $final): array
    {
        $events = [];
        $h = fopen($path, 'rb');
        if (!$h) {
            return [$events, $offset];
        }
        fseek($h, $offset);
        $buf = stream_get_contents($h);
        fclose($h);
        if ($buf === false || $buf === '') {
            return [$events, $offset];
        }
        $last = strrpos($buf, "\n");
        if ($final) {
            $chunk = $buf;
            $consumed = strlen($buf);
        } elseif ($last === false) {
            return [$events, $offset];
        } else {
            $chunk = substr($buf, 0, $last + 1);
            $consumed = $last + 1;
        }
        foreach (explode("\n", $chunk) as $line) {
            $e = QueueLogParser::parse($line);
            if ($e !== null) {
                $events[] = $e;
            }
        }
        return [$events, $offset + $consumed];
    }

    /**
     * File identity. Linux: inode. Windows (tests): mtime changes on every append, so it is
     * not usable; instead use a hash of the first line (max 256 bytes), which is stable while
     * the file grows and survives rename. An unfinished first line yields '' (no identity yet).
     */
    protected function fileId(string $path): string
    {
        if (DIRECTORY_SEPARATOR === '/') {
            $ino = fileinode($path);
            if ($ino !== false) {
                return (string)$ino;
            }
        }
        $h = fopen($path, 'rb');
        if (!$h) {
            return '';
        }
        $head = (string)fread($h, 256);
        fclose($h);
        $nl = strpos($head, "\n");
        if ($nl !== false) {
            $head = substr($head, 0, $nl);
        } elseif (strlen($head) < 256) {
            return '';
        }
        return 'h' . md5($head);
    }
}
