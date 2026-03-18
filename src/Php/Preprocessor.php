<?php
/**
 * This file is part of Swoole-Compiler(AOT).
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace PhpAot\Php;

use PhpAot\Php\Exception\SyntaxError;
use PhpParser\Node;
use PhpParser\NodeAbstract;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;

class Preprocessor extends CompilerBase
{
    protected bool $enableCache = false;

    public function sortFiles(array &$list): void
    {
        foreach ($this->functionCallInFile as $k => $call) {
            if (!isset($this->functionDeclInFile[$call['name']])) {
                unset($this->functionCallInFile[$k]);
            }
        }
        $sorter      = new FileSorter($this->functionDeclInFile, $this->functionCallInFile);
        $sortedFiles = $sorter->sort();

        foreach ($list as $file) {
            if (!$this->isStubFile($file) and !in_array($file, $sortedFiles)) {
                $sortedFiles[] = $file;
            }
        }
        $list = $sortedFiles;
    }

    public function getCppFile(string $file): string
    {
        $info = pathinfo($file);

        return $this->buildDir . '/' . $this->removeCommonPrefix($this->buildDir, $info['dirname'] . '/' . $info['filename'] . '.cc');
    }

    public function getObjectFile(string $cppFile): string
    {
        $info = pathinfo($cppFile);

        return $info['dirname'] . '/' . $info['filename'] . '.o';
    }

    public function hasCppFileCache(string $file): bool
    {
        if (!$this->enableCache or $this->climate->arguments->defined('force')) {
            return false;
        }
        $cppFile = $this->getCppFile($file);
        if (file_exists($cppFile) and filemtime($cppFile) > filemtime($file)) {
            return true;
        }

        return false;
    }

    public function prepare(string $file): void
    {
        if ($this->hasCppFileCache($file)) {
            $this->climate->darkGray('skip: ' . $file . ', cache exists');

            return;
        }

        $phpCode = $this->loadFile($file);

        $this->climate->info('prepare: ' . $this->file);
        try {
            $ast = $this->parser->parse($phpCode);
        } catch (\PhpParser\Error $e) {
            $this->climate->red("Fatal error: {$e->getMessage()} in {$this->file}");
            throw new SyntaxError($e->getMessage(), $e->getCode());
        }

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new Visitor());
        $stmts = $traverser->traverse($ast);

        foreach ($stmts as $v) {
            $type = $v->getType();
            switch ($type) {
                case 'Stmt_Namespace':
                    $this->prepareNamespaceDef($v);
                    break;
                case 'Stmt_Enum':
                case 'Stmt_Class':
                case 'Stmt_Trait':
                    $this->prepareClass($v);
                    break;
                case 'Stmt_Function':
                    $this->prepareFunction($v) . PHP_EOL;
                    break;
                case 'Stmt_Declare':
                case 'Stmt_Use':
                case 'Stmt_Interface':
                case 'Stmt_Nop':
                    break;
                case 'Stmt_Const':
                    $this->parseConstDef($v);
                    break;
                case 'Stmt_Expression':
                    $this->foundStrayCode($v);
                    // no break
                default:
                    $this->fatalError($v, 'Unsupported statement: ' . $type);
            }
        }

        $nodeFinder    = new NodeFinder();
        $functionCalls = $nodeFinder->findInstanceOf($ast, Node\Expr\FuncCall::class);

        foreach ($functionCalls as $call) {
            if ($call->name instanceof Node\Name) {
                $name                       = $call->name->toString();
                $this->functionCallInFile[] = [
                    'name' => $name,
                    'file' => $this->file,
                    'line' => $call->getLine(),
                ];
            }
        }
    }

    protected function prepareNamespaceDef(Node\Stmt\Namespace_ $node): void
    {
        $this->resetNamespace();
        $this->namespace = $node->name ? $this->parseIdentifier($node->name) : '';
        foreach ($node->stmts as $v2) {
            $type2 = $v2->getType();
            switch ($type2) {
                case 'Stmt_Class':
                case 'Stmt_Enum':
                    $this->prepareClass($v2);
                    break;
                case 'Stmt_Function':
                    $this->prepareFunction($v2) . PHP_EOL;
                    break;
                case 'Stmt_Use':
                case 'Stmt_Const':
                case 'Stmt_Interface':
                    break;
                case 'Stmt_Expression':
                    $this->foundStrayCode($v2);
                    // no break
                default:
                    abort($v2);
            }
        }
        $this->resetNamespace();
    }

    protected function prepareFunction(Node $v): void
    {
        $name = $this->getFunctionName($v);
        if ($this->stubFile) {
            $this->nativeFunctions[$name] = $this->parseFunctionDecl($v);
        } else {
            $this->functionDeclInFile[$name] = $this->file;
        }
    }

    protected function getParentClass(NodeAbstract $extends): string
    {
        if ($extends instanceof Node\Name\FullyQualified) {
            return $this->parseIdentifier($extends);
        } else {
            return $this->getNamespacedClassName($this->parseIdentifier($extends));
        }
    }

    protected function prepareClass(Node\Stmt\Class_|Node\Stmt\Trait_|Node\Stmt\Enum_ $class): string
    {
        $this->class = $this->parseIdentifier($class->name);
        if (!empty($class->extends)) {
            $this->parentClass = $this->getParentClass($class->extends);
            $fullClassName = $this->getNamespacedClassName($this->class);
            $this->classExtends[$fullClassName] = $this->parentClass;
        }
        $code        = '';
        foreach ($class->stmts as $v) {
            $type = $v->getType();
            switch ($type) {
                case 'Stmt_ClassConst':
                case 'Stmt_Property':
                case 'Stmt_Nop':
                case 'Stmt_TraitUse':
                case 'Stmt_EnumCase':
                    break;
                case 'Stmt_ClassMethod':
                    $this->prepareMethod($v);
                    break;
                case 'Stmt_Expression':
                    $this->foundStrayCode($v);
                    // no break
                default:
                    abort($v);
            }
        }
        $this->class = '';
        $this->parentClass = '';

        return $code;
    }

    protected function prepareMethod(Node\Stmt\ClassMethod $v): void
    {
        $this->prepareFunction($v) . PHP_EOL;
        if ($this->isIdExpr($v->name) or $this->isNameExpr($v->name)) {
            $fullClassName = $this->getNamespacedClassName($this->class);
            $fullName = $fullClassName . '::' . $v->name;
            $this->classMethodOverride[$fullName] = false;
            // 查找父类是否有同名方法，递归查找
            while (isset($this->classExtends[$fullClassName])) {
                $parentClass = $this->classExtends[$fullClassName];
                $parentMethod = $parentClass . '::' . $v->name;
                if (isset($this->classMethodOverride[$parentMethod])) {
                    $this->classMethodOverride[$parentMethod] = true;
                }
                $fullClassName = $parentClass;
            }
        }
    }
}
