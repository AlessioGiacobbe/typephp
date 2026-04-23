<?php
use PhpAot\Php\Translator;

function main(int $argc, array $argv): void
{
    define("ROOT_PATH", getcwd());
    define('DEBUG', true);

    require ROOT_PATH . '/vendor/autoload.php';

    global $translator;

    $translator = new Translator(ROOT_PATH);
    if (empty($argv[1])) {
        $translator->showUsage();
        exit(0);
    }

    $translator->setIndent('    ');
    // 扫描所有 PHP 文件，预处理
    $path = $argv[1];
    $files = $translator->prepare($path);
    // 生成 C++ 文件
    $sourceFiles = $translator->convert($files);
    // 编译所有 C++ 文件
    $objectFiles = $translator->compile($sourceFiles);
    // 连接所有目标文件，生成可执行文件
    $translator->build($objectFiles);
}
