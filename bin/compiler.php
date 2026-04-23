#!/usr/bin/env php
<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/../src/polyfills.php';
require __DIR__ . '/../src/gen_stub.php';

use PhpAot\Php\Translator;

if (empty($argv[1])) {
    die("php compiler.php [file]\n");
}

$path = $argv[1];
$translator = new Translator(ROOT_PATH);
$translator->setIndent('    ');
// 扫描所有 PHP 文件，预处理
$files = $translator->prepare($path);
// 生成 C++ 文件
$sourceFiles = $translator->convert($files);
// 编译所有 C++ 文件
$objectFiles = $translator->compile($sourceFiles);
// 连接所有目标文件，生成可执行文件
$translator->build($objectFiles);
