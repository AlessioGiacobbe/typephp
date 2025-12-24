<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use PhpAot\Php\Translator;

define('DEBUG', true);


if (empty($argv[1])) {
    die("php compiler.php [file]\n");
}

try {
    $file = $argv[1];
    $translator = new Translator();
    $translator->setIndent('    ');
    $code = $translator->convert($file);
    $info = pathinfo($file);
    $cppFile = './tmp/' . $info['filename'] . '.cc';
    $translator->save($code, $cppFile);
    $translator->compileFile($cppFile);
} catch (Error $error) {
    echo "Parse error: {$error->getMessage()}\n";
    return;
}
