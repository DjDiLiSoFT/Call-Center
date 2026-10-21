<?php

namespace CallStats\Web;

use PDO;

class Auth
{
    public const MAX_FAILURES = 5;
    public const WINDOW_SECONDS = 900;
    private const PURGE_SECONDS = 86400;
    public const MAX_LIFETIME = 43200; // 12 h absolute
    public const IDLE_TIMEOUT = 7200;  // 2 h idle
    private const KEY = 'callstats_auth';

    public const ERR_BAD = 'Неверный логин или пароль';
    public const ERR_BLOCKED = 'Слишком много попыток, подождите 15 минут';

    /** @var PDO */
    private $db;
    /** @var array */
    private $session;
    /** @var string */
    private $error = '';

    /**
     * @param array|null $session session storage (pass $_SESSION); an internal array is used when omitted
     */
    public function __construct(PDO $db, ?array &$session = null)
    {
        $this->db = $db;
        if ($session === null) {
            $session = [];
        }
        $this->session = &$session;
    }

    public function error(): string
    {
        return $this->error;
    }

    public function attempt(string $user, string $pass, string $ip, ?int $now = null): bool
    {
        $now = $now ?? time();
        $this->error = self::ERR_BAD;

        $this->db->prepare('DELETE FROM login_attempts WHERE `at` < ?')
            ->execute([date('Y-m-d H:i:s', $now - self::PURGE_SECONDS)]);

        // Record first, then count (including this attempt), so parallel requests cannot all slip under the limit.
        $this->db->prepare('INSERT INTO login_attempts (ip, `at`) VALUES (?, ?)')
            ->execute([$ip, date('Y-m-d H:i:s', $now)]);
        $st = $this->db->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND `at` >= ?');
        $st->execute([$ip, date('Y-m-d H:i:s', $now - self::WINDOW_SECONDS)]);
        if ((int) $st->fetchColumn() > self::MAX_FAILURES) {
            $this->error = self::ERR_BLOCKED;
            return false;
        }

        $storedUser = $this->setting('auth_user');
        $hash = $this->setting('auth_hash');
        // Always run password_verify so response time does not reveal whether the login exists.
        $hashToCheck = $hash !== '' ? $hash : password_hash('callstats-dummy', PASSWORD_DEFAULT);
        $passOk = password_verify($pass, $hashToCheck);
        $userOk = $storedUser !== '' && hash_equals($storedUser, $user);

        if ($userOk && $passOk && $hash !== '') {
            $this->db->prepare('DELETE FROM login_attempts WHERE ip = ?')->execute([$ip]);
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_regenerate_id(true);
            }
            unset($this->session['csrf']); // fresh CSRF token for the new session
            $this->session[self::KEY] = ['user' => $storedUser, 'login_at' => $now, 'last_at' => $now];
            $this->error = '';
            return true;
        }

        return false;
    }

    public function check(?int $now = null): bool
    {
        $now = $now ?? time();
        $a = $this->session[self::KEY] ?? null;
        if (!is_array($a) || !isset($a['login_at'], $a['last_at'])) {
            return false;
        }
        if ($now - (int) $a['login_at'] > self::MAX_LIFETIME || $now - (int) $a['last_at'] > self::IDLE_TIMEOUT) {
            unset($this->session[self::KEY]);
            return false;
        }
        $this->session[self::KEY]['last_at'] = $now;
        return true;
    }

    public function logout(): void
    {
        $this->session = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public function setCredentials(string $user, string $pass): void
    {
        $st = $this->db->prepare(
            'INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)'
        );
        $st->execute(['auth_user', $user]);
        $st->execute(['auth_hash', password_hash($pass, PASSWORD_DEFAULT)]);
    }

    private function setting(string $key): string
    {
        $st = $this->db->prepare('SELECT v FROM settings WHERE k = ?');
        $st->execute([$key]);
        $v = $st->fetchColumn();
        return $v === false ? '' : (string) $v;
    }
}
