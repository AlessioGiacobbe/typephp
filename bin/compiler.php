#!/usr/bin/env php
<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/../src/polyfills.php';
require __DIR__ . '/../src/gen_stub.php';
require __DIR__ . '/../src/compiler-build.php';

main($argc, $argv);
