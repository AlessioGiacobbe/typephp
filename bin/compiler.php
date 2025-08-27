<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use PhpAot\Php\Translator;
use PhpAot\Php\Visitor;
use PhpParser\Error;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter;

define('DEBUG', true);

$traverser = new NodeTraverser;
$prettyPrinter = new PrettyPrinter\Standard;

$traverser->addVisitor(new Visitor());

if (empty($argv[1])) {
    die("php compiler.php [file]\n");
}

$file = $argv[1];
$code = file_get_contents($file);

$parser = (new ParserFactory())->createForNewestSupportedVersion();
try {
    $ast = $parser->parse($code);
    $stmts = $traverser->traverse($ast);
    $translator = new Translator($stmts);
    $translator->setIndent('    ');
    $code = $translator->convert();
    $info = pathinfo($file);
    $cppFile = './tmp/' . $info['filename'] . '.cc';
    $translator->save($code, $cppFile);
    $translator->compileFile($cppFile);
} catch (Error $error) {
    echo "Parse error: {$error->getMessage()}\n";
    return;
}
