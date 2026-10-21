<?php

namespace CallStats\Tests\Integration;

use CallStats\Db;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Base class for integration tests. Recreates three databases before each test:
 *  callstats_test      (own schema)    -> $this->db
 *  asteriskcdrdb_test  (cdr table)     -> $this->src
 *  asterisk_test       (users, queues_config) -> $this->fpbx
 *
 * Connection: DSN without dbname, default mysql:host=127.0.0.1;port=3307, root/empty.
 * Override with env CALLSTATS_TEST_DSN / CALLSTATS_TEST_USER / CALLSTATS_TEST_PASS.
 */
abstract class DbTestCase extends TestCase
{
    public const DEFAULT_DSN = 'mysql:host=127.0.0.1;port=3307';
    public const DEFAULT_USER = 'root';
    public const DEFAULT_PASS = '';

    public const DB_OWN = 'callstats_test';
    public const DB_CDR = 'asteriskcdrdb_test';
    public const DB_FPBX = 'asterisk_test';

    /** @var PDO */
    protected $db;
    /** @var PDO */
    protected $src;
    /** @var PDO */
    protected $fpbx;

    protected static function testDsn(): string
    {
        $v = getenv('CALLSTATS_TEST_DSN');
        return ($v !== false && $v !== '') ? $v : self::DEFAULT_DSN;
    }

    protected static function testUser(): string
    {
        $v = getenv('CALLSTATS_TEST_USER');
        return $v !== false ? $v : self::DEFAULT_USER;
    }

    protected static function testPass(): string
    {
        $v = getenv('CALLSTATS_TEST_PASS');
        return $v !== false ? $v : self::DEFAULT_PASS;
    }

    protected function setUp(): void
    {
        parent::setUp();

        try {
            $admin = Db::connect(self::testDsn(), self::testUser(), self::testPass());
        } catch (\PDOException $e) {
            $this->markTestSkipped(
                'Test DB is not reachable (' . self::testDsn() . '): ' . $e->getMessage()
                . '. Start it with: bash tools/test-db.sh start'
            );
            return;
        }

        foreach ([self::DB_OWN, self::DB_CDR, self::DB_FPBX] as $name) {
            $admin->exec("DROP DATABASE IF EXISTS `$name`");
            $admin->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        }

        $this->db = $this->connectTo(self::DB_OWN);
        $this->src = $this->connectTo(self::DB_CDR);
        $this->fpbx = $this->connectTo(self::DB_FPBX);

        self::applySqlFile($this->db, __DIR__ . '/../../sql/schema.sql');
        self::applySqlFile($this->src, __DIR__ . '/../fixtures/cdr.sql');
        self::applySqlFile($this->fpbx, __DIR__ . '/../fixtures/asterisk.sql');
    }

    private function connectTo(string $dbname): PDO
    {
        return Db::connect(self::testDsn() . ';dbname=' . $dbname, self::testUser(), self::testPass());
    }

    /** Executes a file with several statements separated by ";" at end of line. */
    protected static function applySqlFile(PDO $pdo, string $path): void
    {
        $sql = file_get_contents($path);
        foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql) as $stmt) {
            // strip leading comment lines
            $stmt = trim(preg_replace('/^\s*--.*$/m', '', $stmt));
            if ($stmt !== '') {
                $pdo->exec($stmt);
            }
        }
    }
}
