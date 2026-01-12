<?php

namespace PhpAot\Php;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;

class Preprocessor extends CompilerBase
{
    public function sortFiles(array &$list): void
    {
        foreach ($this->functionCallInFile as $k => $call) {
            if (!isset($this->functionDeclInFile[$call['name']])) {
                unset($this->functionCallInFile[$k]);
            }
        }
        $sorter = new FileSorter($this->functionDeclInFile, $this->functionCallInFile);
        $sortedFiles = $sorter->sort();

        foreach ($list as $file) {
            if (!$this->isStubFile($file) and !in_array($file, $sortedFiles)) {
                $sortedFiles[] = $file;
            }
        }
        $list = $sortedFiles;
    }

    public function prepare(string $file): void
    {
        $phpCode = $this->loadFile($file);

        $this->climate->info('prepare: ' . $this->file);
        $ast = $this->parser->parse($phpCode);

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new Visitor());
        $stmts = $traverser->traverse($ast);

        foreach ($stmts as $v) {
            $type = $v->getType();
            switch ($type) {
                case 'Stmt_Namespace':
                    $this->prepareNamespaceDef($v);
                    break;
                case 'Stmt_Class':
                    $this->prepareClass($v);
                    break;
                case 'Stmt_Function':
                    $this->prepareFunction($v) . PHP_EOL;
                    break;
                case 'Stmt_Declare':
                case 'Stmt_Use':
                case 'Stmt_Const':
                    break;
                default:
                    $this->fatalError($v, 'Unsupported statement: ' . $type);
                    break;
            }
        }

        $nodeFinder = new NodeFinder();
        $functionCalls = $nodeFinder->findInstanceOf($ast, Node\Expr\FuncCall::class);

        foreach ($functionCalls as $call) {
            if ($call->name instanceof Node\Name) {
                $name = $call->name->toString();
                $this->functionCallInFile[] = [
                    'name' => $name,
                    'file' => $this->file,
                    'line' => $call->getLine(),
                ];
            }
        }
    }

    protected function prepareFunction(Node $v): void
    {
        $name = $this->getFunctionName($v);
        if ($this->stubFile) {
            $this->nativeFunctions[$name] = $this->parseFunctionDeclaration($name, $v);
        } else {
            $this->functionDeclInFile[$name] = $this->file;
        }
    }

    protected function prepareNamespaceDef(Node\Stmt\Namespace_ $node): void
    {
        $this->resetNamespace();
        $this->namespace = $this->escapeNamespace($this->parseIdentifier($node->name));
        foreach ($node->stmts as $v2) {
            $type2 = $v2->getType();
            switch ($type2) {
                case 'Stmt_Class':
                    $this->prepareClass($v2);
                    break;
                case 'Stmt_Function':
                    $this->prepareFunction($v2) . PHP_EOL;
                    break;
                case 'Stmt_Use':
                case 'Stmt_Const':
                    break;
                default:
                    abort($v2);
            }
        }
        $this->resetNamespace();
    }

    protected function prepareClass(Node\Stmt\Class_ $class): string
    {
        $this->class = $this->parseIdentifier($class->name);
        $code = '';
        foreach ($class->stmts as $v) {
            $type = $v->getType();
            switch ($type) {
                case 'Stmt_ClassConst':
                case 'Stmt_Property':
                    break;
                case 'Stmt_ClassMethod':
                    $code .= $this->prepareFunction($v) . PHP_EOL;
                    break;
                default:
                    abort($v);
            }
        }
        $this->class = '';
        return $code;
    }
}