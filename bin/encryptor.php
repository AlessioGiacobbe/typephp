<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use PhpAot\Php\Encryptor;
use PhpParser\Error;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;

define('DEBUG', true);

$traverser = new NodeTraverser;
$traverser->addVisitor(new \PhpAot\Php\Visitor());

if (empty($argv[1])) {
    die("php compiler.php [file]\n");
}

$code = file_get_contents($argv[1]);

$parser = (new ParserFactory())->createForNewestSupportedVersion();
try {
    $ast = $parser->parse($code);
    $stmts = $traverser->traverse($ast);
    $encryptor = new Encryptor($stmts);
    $code = $encryptor->convert();
    var_dump($code);
//    $translator->save($code, './tmp/hello.cc');
//    $translator->compileFile('./tmp/hello.cc');
} catch (Error $error) {
    echo "Parse error: {$error->getMessage()}\n";
    return;
}

