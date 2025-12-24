<?php


require dirname(__DIR__) . '/vendor/autoload.php';

use PhpAot\Php\Translator;

if (empty($argv[1])) {
    die("php compiler.php [file]\n");
}

try {
    $file = $argv[1];
    $translator = new Translator();
    $info = pathinfo($file);
    $objectFile = './tmp/' . $info['filename'] . '.cc.o';
    $translator->compileBinary($info['filename'], $objectFile);
} catch (Error $error) {
    echo "Parse error: {$error->getMessage()}\n";
    return;
}
