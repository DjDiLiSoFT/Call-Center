<?php

namespace CallStats\Tests\Integration;

use CallStats\Web\Auth;
use CallStats\Web\Csrf;
use CallStats\Web\View;

class AuthTest extends DbTestCase
{
    /** @var array */
    private $session = [];
    /** @var Auth */
    private $auth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->session = [];
        $this->auth = new Auth($this->db, $this->session);
        $this->auth->setCredentials('admin', 'correct-horse');
    }

    public function test_correct_password_logs_in(): void
    {
        $this->assertFalse($this->auth->check());
        $this->assertTrue($this->auth->attempt('admin', 'correct-horse', '10.0.0.1'));
        $this->assertTrue($this->auth->check());
    }

    public function test_wrong_password_rejected(): void
    {
        $this->assertFalse($this->auth->attempt('admin', 'nope', '10.0.0.1'));
        $this->assertFalse($this->auth->check());
        $this->assertSame('Неверный логин или пароль', $this->auth->error());
        $this->assertFalse($this->auth->attempt('ghost', 'correct-horse', '10.0.0.1'));
    }

    public function test_sixth_attempt_blocked_even_with_correct_password(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->assertFalse($this->auth->attempt('admin', 'bad', '10.0.0.2'));
        }
        $this->assertFalse($this->auth->attempt('admin', 'correct-horse', '10.0.0.2'));
        $this->assertFalse($this->auth->check());
        $this->assertSame('Слишком много попыток, подождите 15 минут', $this->auth->error());
        // another IP is not affected
        $this->assertTrue($this->auth->attempt('admin', 'correct-horse', '10.0.0.3'));
    }

    public function test_attempt_recorded_even_when_blocked_and_sixth_is_blocked(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->auth->attempt('admin', 'bad', '10.0.0.7');
        }
        $this->assertFalse($this->auth->attempt('admin', 'correct-horse', '10.0.0.7'));
        $n = (int) $this->db->query("SELECT COUNT(*) FROM login_attempts WHERE ip='10.0.0.7'")->fetchColumn();
        $this->assertSame(6, $n);
        // fifth attempt (correct) is still allowed: limit is 5 tries
        $this->assertTrue($this->auth->attempt('admin', 'correct-horse', '10.0.0.8'));
    }

    public function test_session_expires_after_absolute_lifetime(): void
    {
        $t = time();
        $this->auth->attempt('admin', 'correct-horse', '10.0.0.1', $t);
        // keep active so only the absolute limit applies
        for ($x = $t + 3600; $x <= $t + 43200; $x += 3600) {
            $this->assertTrue($this->auth->check($x));
        }
        $this->assertFalse($this->auth->check($t + 43201));
    }

    public function test_session_expires_after_idle_timeout(): void
    {
        $t = time();
        $this->auth->attempt('admin', 'correct-horse', '10.0.0.1', $t);
        $this->assertTrue($this->auth->check($t + 7000));
        $this->assertFalse($this->auth->check($t + 7000 + 7201));
    }

    public function test_login_resets_csrf_token(): void
    {
        $csrf = new Csrf($this->session);
        $old = $csrf->token();
        $this->auth->attempt('admin', 'correct-horse', '10.0.0.1');
        $this->assertNotSame($old, $csrf->token());
    }

    public function test_block_expires_after_window_and_success_clears_attempts(): void
    {
        $t = time();
        for ($i = 0; $i < 5; $i++) {
            $this->auth->attempt('admin', 'bad', '10.0.0.4', $t);
        }
        $this->assertFalse($this->auth->attempt('admin', 'correct-horse', '10.0.0.4', $t + 60));
        $this->assertTrue($this->auth->attempt('admin', 'correct-horse', '10.0.0.4', $t + 901));
        $n = (int) $this->db->query("SELECT COUNT(*) FROM login_attempts WHERE ip='10.0.0.4'")->fetchColumn();
        $this->assertSame(0, $n);
    }

    public function test_old_attempts_are_purged(): void
    {
        $t = time();
        $this->auth->attempt('admin', 'bad', '10.0.0.5', $t - 90000);
        $this->auth->attempt('admin', 'bad', '10.0.0.6', $t);
        $this->assertSame(1, (int) $this->db->query('SELECT COUNT(*) FROM login_attempts')->fetchColumn());
    }

    public function test_logout_and_hash_storage(): void
    {
        $this->auth->attempt('admin', 'correct-horse', '10.0.0.1');
        $this->auth->logout();
        $this->assertFalse($this->auth->check());
        $hash = $this->db->query("SELECT v FROM settings WHERE k='auth_hash'")->fetchColumn();
        $this->assertNotSame('correct-horse', $hash);
        $this->assertTrue(password_verify('correct-horse', $hash));
    }

    public function test_csrf_and_view_escape(): void
    {
        $s = [];
        $csrf = new Csrf($s);
        $t = $csrf->token();
        $this->assertSame($t, $csrf->token());
        $this->assertTrue($csrf->verify($t));
        $this->assertFalse($csrf->verify('x'));
        $this->assertFalse($csrf->verify(null));
        $this->assertSame('&lt;a href=&quot;x&quot;&gt;&#039;', View::e('<a href="x">' . "'"));
    }
}
