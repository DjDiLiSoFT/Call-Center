<?php

// PSR-4 style autoloader: CallStats\X\Y -> src/X/Y.php
spl_autoload_register(function ($class) {
    $prefix = 'CallStats\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
