<?php

namespace CallStats\Source;

use PDO;

/**
 * Read-only FreePBX directory (DB `asterisk`): extensions and queues.
 */
class FreepbxDirectory
{
    /** @var PDO */
    private $asterisk;

    public function __construct(PDO $asterisk)
    {
        $this->asterisk = $asterisk;
    }

    /** @return array<string,string> extension => name (from `users`) */
    public function extensions(): array
    {
        $out = [];
        foreach ($this->asterisk->query('SELECT extension, name FROM users ORDER BY extension') as $r) {
            $ext = trim((string) $r['extension']);
            if ($ext !== '') {
                $out[$ext] = trim((string) $r['name']);
            }
        }
        return $out;
    }

    /** @return array<string,string> queue number => descr (from `queues_config`) */
    public function queues(): array
    {
        $out = [];
        foreach ($this->asterisk->query('SELECT extension, descr FROM queues_config ORDER BY extension') as $r) {
            $q = trim((string) $r['extension']);
            if ($q !== '') {
                $out[$q] = trim((string) $r['descr']);
            }
        }
        return $out;
    }
}
