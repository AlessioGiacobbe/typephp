<?php
require __DIR__ . '/bootstrap.php';

use PhpAot\Php\Translator;

if (empty($argv[1])) {
    die("php compiler.php [file]\n");
}

try {
    $file = $argv[1];
    $translator = new Translator(ROOT_PATH);
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
