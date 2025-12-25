<?php
require __DIR__ . '/bootstrap.php';

use PhpAot\Php\Translator;

if (empty($argv[1])) {
    die("php compiler.php [file]\n");
}

try {
    $file = $argv[1];
    $translator = new Translator(ROOT_PATH);
    $info = pathinfo($file);
    $objectFile = './tmp/' . $info['filename'] . '.cc.o';
    $translator->compileBinary($info['filename'], $objectFile);
} catch (Error $error) {
    echo "Parse error: {$error->getMessage()}\n";
    return;
}
