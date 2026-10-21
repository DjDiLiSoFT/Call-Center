<?php

namespace CallStats\Store;

use PDO;

class StateRepository
{
    /** @var PDO|null */
    protected $db;

    public function __construct(?PDO $db)
    {
        $this->db = $db;
    }

    public function get(string $k): ?string
    {
        $st = $this->db->prepare('SELECT v FROM collector_state WHERE k = ?');
        $st->execute([$k]);
        $v = $st->fetchColumn();
        return $v === false ? null : (string)$v;
    }

    public function set(string $k, string $v): void
    {
        $st = $this->db->prepare('INSERT INTO collector_state (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)');
        $st->execute([$k, $v]);
    }
}
