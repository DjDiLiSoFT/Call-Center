<?php

namespace CallStats\Web;

use CallStats\Config;
use PDO;

class App
{
    private const MENU = [
        'stats' => 'Статистика',
        'journal' => 'Журнал',
        'recordings' => 'Записи',
        'operators' => 'Операторы',
    ];

    /** @var Config */
    private $config;
    /** @var PDO */
    private $db;
    /** @var View */
    private $view;
    /** @var Auth */
    private $auth;
    /** @var Csrf */
    private $csrf;
    /** @var array<string, callable> page => fn(array $get, array $post): string */
    private $routes = [];

    public function __construct(Config $config, PDO $db, ?string $templatesDir = null)
    {
        $this->config = $config;
        $this->db = $db;
        $this->view = new View($templatesDir ?? __DIR__ . '/../../templates');
    }

    /**
     * Registers a page handler. For stats/journal/recordings/operators it returns the HTML of the
     * page body (rendered inside the layout); for play it returns the full response body and may
     * send its own headers.
     */
    public function route(string $page, callable $handler): self
    {
        $this->routes[$page] = $handler;
        return $this;
    }

    public function view(): View
    {
        return $this->view;
    }

    public function csrf(): Csrf
    {
        return $this->csrf;
    }

    public function handle(array $get, array $post, array $server): void
    {
        $this->startSession($server);
        $this->auth = new Auth($this->db, $_SESSION);
        $this->csrf = new Csrf($_SESSION);

        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: same-origin');
        header('Cache-Control: no-store');
        header('Content-Type: text/html; charset=utf-8');

        $method = strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET'));
        $page = isset($get['p']) && is_string($get['p']) ? $get['p'] : 'stats';

        if ($method === 'POST') {
            $token = isset($post['csrf']) && is_string($post['csrf']) ? $post['csrf'] : null;
            if (!$this->csrf->verify($token)) {
                $this->fail(400, 'Недействительный токен формы. Обновите страницу и повторите.');
                return;
            }
        }

        if ($page === 'login') {
            $this->login($method, $post, $server);
            return;
        }

        if (!$this->auth->check()) {
            if ($page === 'play') {
                $this->fail(403, 'Доступ запрещён');
            } else {
                $this->redirect('?p=login');
            }
            return;
        }

        if ($page === 'logout') {
            if ($method !== 'POST') {
                $this->fail(405, 'Метод не поддерживается');
                return;
            }
            $this->auth->logout();
            $this->redirect('?p=login');
            return;
        }

        if (isset(self::MENU[$page])) {
            $body = isset($this->routes[$page])
                ? (string) call_user_func($this->routes[$page], $get, $post)
                : '<p class="muted">Раздел в разработке</p>';
            echo $this->layout(self::MENU[$page], $body, $page);
            return;
        }

        if ($page === 'play' && isset($this->routes['play'])) {
            echo (string) call_user_func($this->routes['play'], $get, $post);
            return;
        }

        $this->fail(404, 'Страница не найдена');
    }

    private function login(string $method, array $post, array $server): void
    {
        if ($this->auth->check()) {
            $this->redirect('?p=stats');
            return;
        }
        $error = '';
        $user = '';
        if ($method === 'POST') {
            $user = isset($post['login']) && is_string($post['login']) ? $post['login'] : '';
            $pass = isset($post['password']) && is_string($post['password']) ? $post['password'] : '';
            $ip = (string) ($server['REMOTE_ADDR'] ?? '');
            if ($this->auth->attempt($user, $pass, $ip)) {
                $this->redirect('?p=stats');
                return;
            }
            $error = $this->auth->error();
            http_response_code($error === Auth::ERR_BLOCKED ? 429 : 401);
        }
        $body = $this->view->render('login', [
            'error' => $error,
            'user' => $user,
            'csrf' => $this->csrf->token(),
        ]);
        echo $this->layout('Вход', $body, '', false);
    }

    private function layout(string $title, string $content, string $active, bool $menu = true): string
    {
        return $this->view->render('layout', [
            'title' => $title,
            'content' => $content,
            'active' => $active,
            'menu' => $menu ? self::MENU : [],
            'csrf' => $this->csrf->token(),
        ]);
    }

    private function fail(int $code, string $message): void
    {
        http_response_code($code);
        echo $this->layout('Ошибка', '<p class="error">' . View::e($message) . '</p>', '', $this->auth->check());
    }

    private function redirect(string $url): void
    {
        header('Location: ' . $url, true, 302);
    }

    private function startSession(array $server): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $https = !empty($server['HTTPS']) && strtolower((string) $server['HTTPS']) !== 'off';
        session_name('CALLSTATSSID');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => (string) $this->config->get('session.cookie_path', '/callstats'),
            'secure' => $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}
