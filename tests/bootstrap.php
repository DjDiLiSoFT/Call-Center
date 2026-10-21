<?php

require __DIR__ . '/../vendor/autoload.php';

// Own autoloader for CallStats\ -> src/ (same one the production code uses).
// Config is optional here: tests build their own configs.
require_once __DIR__ . '/../src/autoload.php';
