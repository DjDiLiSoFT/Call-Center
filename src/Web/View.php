<?php

namespace CallStats\Web;

class View
{
    /** @var string */
    private $dir;

    public function __construct(string $templatesDir)
    {
        $this->dir = rtrim($templatesDir, '/\\');
    }

    /** Renders templates/<tpl>.php with $vars extracted; returns the output. */
    public function render(string $tpl, array $vars = []): string
    {
        if (!preg_match('/^[A-Za-z0-9_\/-]+$/', $tpl)) {
            throw new \InvalidArgumentException('Bad template name');
        }
        $file = $this->dir . '/' . $tpl . '.php';
        $render = function (string $__file, array $__vars): void {
            extract($__vars, EXTR_SKIP);
            require $__file;
        };
        ob_start();
        try {
            $render($file, $vars);
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return (string) ob_get_clean();
    }

    public static function e($s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
