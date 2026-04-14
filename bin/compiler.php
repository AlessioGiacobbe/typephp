#!/usr/bin/env php
<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/../src/gen_stub.php';

use PhpAot\Php\Exception\SyntaxError;
use PhpAot\Php\Exception\Unsupported;
use PhpAot\Php\FileScanner;
use PhpAot\Php\Translator;

if (empty($argv[1])) {
    die("php compiler.php [file]\n");
}

$path = $argv[1];
$translator = new Translator(ROOT_PATH);
$translator->setIndent('    ');
$list = $translator->getFiles($path);

$sourceFiles = [];
$objectFiles = [];

// 分析 PHP 文件，预处理
foreach ($list as $k => $file) {
    if (FileScanner::isPhpFile($file)) {
        try {
            $translator->prepare($file);
        } catch (Unsupported $e) {
            $translator->output(" unsupported syntax: " . $e->getMessage() . "\n" . " skip: " . $file . "\n", 'error');
            unset($list[$k]);
        } catch (SyntaxError $e) {
            $translator->output(" syntax error: " . $e->getMessage() . "\n" . " skip: " . $file . "\n", 'error');
            unset($list[$k]);
        }
    }
}

$translator->sortFiles($list);

// 生成 C++ 文件
foreach ($list as $k => $file) {
    try {
        if (FileScanner::isPhpFile($file)) {
            $cppFile = $translator->convert($file);
        } elseif (FileScanner::isCppFile($file)) {
            $cppFile = $file;
        } else {
            continue;
        }
        $sourceFiles[] = $cppFile;
    } catch (Unsupported $e) {
        echo " unsupported syntax: " . $e->getMessage() . "\n";
        echo " skip: " . $file . "\n";
        unset($list[$k]);
    }
}

if (empty($sourceFiles)) {
    $translator->stop("No valid source file found");
}

// 编译所有 C++ 文件
$objectFiles = $translator->compile($sourceFiles);

// 连接所有目标文件，生成可执行文件
$translator->build($objectFiles);
