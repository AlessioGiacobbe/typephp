<?php

require __DIR__ . '/vendor/autoload.php';

use PhpAot\Php\CompilerBase;
use League\CLImate\CLImate;

$climate = new CLImate();
$compiler = new CompilerBase($climate);
$ref = new ReflectionMethod($compiler, 'getPhpxDir');
$ref->setAccessible(true);

echo "Testing getPhpxDir()...\n";
echo "PHPX_HOME env: " . (getenv('PHPX_HOME') ?: '(not set)') . "\n";
echo "Result: " . $ref->invoke($compiler) . "\n";
echo "Directory exists: " . (is_dir($ref->invoke($compiler)) ? 'YES' : 'NO') . "\n";
