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

$realpath = realpath($path);
if ($realpath === false) {
    die("path not exists: $path\n");
}
$path = $realpath;

if (is_dir($path)) {
    $scanner = new FileScanner($path);
    $list = $scanner->scan();
    $targetFile = basename($path);
} else {
    $list = [$path];
    $targetFile = FileScanner::getFileName($path);
}

$sourceFiles = [];
$objectFiles = [];

// 分析 PHP 文件，预处理
foreach ($list as $k => $file) {
    if (FileScanner::isPhpFile($file)) {
        try {
            $translator->prepare($file);
        } catch (Unsupported $e) {
            echo " unsupported syntax: " . $e->getMessage() . "\n";
            echo " skip: " . $file . "\n";
            unset($list[$k]);
        }
    }
}

$translator->sortFiles($list);

// 生成 C++ 文件
foreach ($list as $file) {
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
    }
}

if (empty($sourceFiles)) {
    $translator->stop("No valid source file found");
}

// 生成所有函数声明
$translator->genFunctionDeclaration($translator->getBuildDir() . "/include/php_func_decl.h");

// 生成所有全局变量源文件
$globalVarsSourceFile = $translator->getBuildDir() . '/global_vars.cc';
$translator->genGlobalVars($globalVarsSourceFile);
$sourceFiles[] = $globalVarsSourceFile;

// 添加 main.cc 文件
$sourceFiles[] = ROOT_PATH . '/src/cpp/main.cc';

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
