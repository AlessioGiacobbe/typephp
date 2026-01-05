#!/usr/bin/env php
<?php
require __DIR__ . '/bootstrap.php';

use PhpAot\Php\FileScanner;
use PhpAot\Php\Translator;
use PhpAot\Php\Unsupported;

if (empty($argv[1])) {
    die("php compiler.php [file]\n");
}

$path = $argv[1];
$translator = new Translator(ROOT_PATH);
$translator->setIndent('    ');

if (is_dir($path)) {
    $scanner = new FileScanner($path);
    $list = $scanner->scan();
    $targetFile = basename($path);
} else {
    $list = [$path];
    $targetFile = basename($path, '.php');
}

$sourceFiles = [];
$objectFiles = [];

// 分析 PHP 文件，生成 C++ 文件
foreach ($list as $file) {
    try {
        if (str_ends_with($file, '.php')) {
            $code = $translator->convert($file);
            $info = pathinfo($file);
            $cppFile = $info['dirname'] . '/' . $info['filename'] . '.cc';
            $translator->save($code, $cppFile);
        } else {
            $cppFile = $file;
        }
        $sourceFiles[] = $cppFile;
    } catch (Unsupported $e) {
        echo " unsupported syntax: " . $e->getMessage() . "\n";
        echo " skip: " . $file . "\n";
    }
}

// 生成所有函数声明
$translator->genFunctionDeclaration("./php_func_decl.h");

// 编译所有 C++ 文件
foreach ($sourceFiles as $cppFile) {
    $translator->compileFile($cppFile);
    $objectFile = $cppFile . '.o';
    if (!is_file($objectFile)) {
        throw new Exception("compile error");
    }
    $objectFiles[] = $objectFile;
}

// 连接所有目标文件，生成可执行文件
$translator->compileBinary($targetFile, $objectFiles);
