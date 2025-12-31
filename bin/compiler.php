#!/usr/bin/env php
<?php
require __DIR__ . '/bootstrap.php';

use PhpAot\Php\Translator;

if (empty($argv[1])) {
    die("php compiler.php [file]\n");
}

$file = $argv[1];
$translator = new Translator(ROOT_PATH);
$translator->setIndent('    ');
$code = $translator->convert($file);
$info = pathinfo($file);
$cppFile = $info['dirname'] . '/' . $info['filename'] . '.cc';
$translator->save($code, $cppFile);
$translator->genFunctionDeclaration("./php_func_decl.h");
$translator->compileFile($cppFile);

$objectFile = $info['dirname'] . '/' . $info['filename'] . '.cc.o';
if (!is_file($objectFile)) {
    throw new Exception("compile error");
}
$translator->compileBinary($info['filename'], $objectFile);
