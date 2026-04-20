<?php
use PhpAot\Php\Translator;

function main(int $argc, array $argv): void
{
    define("ROOT_PATH", getcwd());
    define('DEBUG', true);

    require ROOT_PATH . '/vendor/autoload.php';

    if (empty($argv[1])) {
        die("php compiler.php [file]\n");
    }

    global $translator;

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
}
