<?php

namespace CallStats\Tests\Integration;

class SchemaTest extends DbTestCase
{
    public function test_schema_applies_on_mariadb55(): void
    {
        $tables = $this->db->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
        $expected = [
            'agent_pauses', 'agent_sessions', 'call_agents', 'calls', 'collector_state',
            'login_attempts', 'operators', 'qlog_events', 'queues', 'settings', 'transfers',
        ];
        sort($tables);
        $this->assertSame($expected, $tables);
        $this->assertCount(11, $tables);

        $this->assertStringStartsWith('5.5', (string)$this->db->query('SELECT VERSION()')->fetchColumn());
    }

    public function test_schema_is_idempotent(): void
    {
        self::applySqlFile($this->db, __DIR__ . '/../../sql/schema.sql');
        $this->assertCount(11, $this->db->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function test_fixture_databases_exist(): void
    {
        $this->assertSame(0, (int)$this->src->query('SELECT COUNT(*) FROM cdr')->fetchColumn());
        $this->assertSame(0, (int)$this->fpbx->query('SELECT COUNT(*) FROM users')->fetchColumn());
        $this->assertSame(0, (int)$this->fpbx->query('SELECT COUNT(*) FROM queues_config')->fetchColumn());
    }
}
