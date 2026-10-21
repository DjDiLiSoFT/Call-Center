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
