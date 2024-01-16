<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use PhpAot\Php\Translator;
use PhpParser\Error;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter;

define('DEBUG', true);

$traverser = new NodeTraverser;
$prettyPrinter = new PrettyPrinter\Standard;

$traverser->addVisitor(new \PhpAot\Php\Visitor());

if (empty($argv[1])) {
    die("php compiler.php [file]\n");
}

$code = file_get_contents($argv[1]);

$parser = (new ParserFactory())->createForNewestSupportedVersion();
try {
    $ast = $parser->parse($code);
    $stmts = $traverser->traverse($ast);
    $translator = new Translator($stmts);
    $translator->setIndent('    ');
    $code = $translator->convert();
    $translator->save($code, './tmp/hello.cc');
    $translator->compileFile('./tmp/hello.cc');
} catch (Error $error) {
    echo "Parse error: {$error->getMessage()}\n";
    return;
}

