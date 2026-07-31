#!/usr/bin/env php
<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/../src/polyfills.php';
require __DIR__ . '/../src/gen_stub.php';
require __DIR__ . '/../src/compiler.php';

const TYPEPHP_PHP_SCRIPT_ENTRY = true;
main($argc, $argv);
