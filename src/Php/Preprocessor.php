<?php
/**
 * This file is part of Swoole-Compiler(AOT).
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace PhpAot\Php;

use MJS\TopSort\CircularDependencyException;
use MJS\TopSort\ElementNotFoundException;
use MJS\TopSort\Implementations\StringSort;
use PhpAot\Php\Exception\SyntaxError;
use PhpAot\Php\Exception\Unsupported;
use PhpParser\Modifiers;
use PhpParser\Node;
use PhpParser\NodeAbstract;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;

class Preprocessor extends CompilerBase
{
    protected bool $enableCache = false;

    public function sortFiles(array &$list): void
    {
        $sorter = new StringSort();
        $fileDeps = [];

        // 构建依赖关系图
        foreach ($this->symbolCallInFile as $file => $symbols) {
            $deps = [];
            foreach ($symbols as $symbol) {
                if (isset($this->symbolDeclInFile[$symbol])) {
                    $depFile = $this->symbolDeclInFile[$symbol];
                    if ($depFile !== $file) {
                        $deps[] = $depFile;
                    }
                }
            }
            $deps = array_unique($deps);
            $fileDeps[$file] = $deps;
            $sorter->add($file, $deps);
        }

        try {
            // 尝试进行拓扑排序
            $sortedFiles = $sorter->sort();
        } catch (CircularDependencyException $e) {
            // 检测到循环依赖，尝试打破循环
            $this->climate->yellow('Warning: Circular dependency detected, attempting to resolve...');
            $circularNodes = $e->getNodes();
            $this->climate->darkGray('Circular path: ' . implode(' -> ', $circularNodes));
            
            // 使用打破循环后的依赖关系重新排序
            $sortedFiles = $this->resolveCircularDependencies($fileDeps, $circularNodes);
        }

        // 添加未参与依赖管理的文件（非 stub 文件且不在已排序列表中）
        foreach ($list as $file) {
            if (!$this->isStubFile($file) and !in_array($file, $sortedFiles)) {
                $sortedFiles[] = $file;
            }
        }
        
        $list = $sortedFiles;
    }

    /**
     * 解决循环依赖问题
     *
     * @param array $fileDeps 所有文件的依赖关系
     * @param array $circularNodes 循环依赖中的节点
     * @return array 排序后的文件列表
     * @throws ElementNotFoundException
     */
    protected function resolveCircularDependencies(array $fileDeps, array $circularNodes): array
    {
        // 找出循环依赖中最少的边来打破循环
        // 策略：移除被依赖次数最少的文件的依赖关系
        $depCount = [];
        foreach ($circularNodes as $node) {
            $depCount[$node] = 0;
            // 统计该节点在循环中被其他节点依赖的次数
            foreach ($circularNodes as $otherNode) {
                if (isset($fileDeps[$otherNode]) && in_array($node, $fileDeps[$otherNode])) {
                    $depCount[$node]++;
                }
            }
        }
        
        // 找到被依赖最少的节点，打破它的某个依赖
        asort($depCount);
        $breakNode = key($depCount);
        
        $this->climate->darkGray("Breaking circular dependency at: {$breakNode}");
        
        // 创建新的依赖关系，移除导致循环的依赖
        $resolvedDeps = $fileDeps;
        if (isset($resolvedDeps[$breakNode])) {
            // 移除该节点对循环中其他节点的依赖
            $resolvedDeps[$breakNode] = array_filter(
                $resolvedDeps[$breakNode],
                fn($dep) => !in_array($dep, $circularNodes) || $dep === $breakNode
            );
        }
        
        // 使用修正后的依赖关系重新排序
        $sorter = new StringSort();
        foreach ($resolvedDeps as $file => $deps) {
            $sorter->add($file, $deps);
        }
        
        try {
            return $sorter->sort();
        } catch (CircularDependencyException $e) {
            // 如果仍然存在循环，递归处理
            $remainingCircular = $e->getNodes();
            $this->climate->yellow('Still has circular dependency, continuing to resolve...');
            return $this->resolveCircularDependencies($resolvedDeps, $remainingCircular);
        }
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
        $this->symbolCallInFile[$this->file] = [];
        $this->resetFile();
        $this->resetFunction();
        $this->resetClass();
        $this->resetNamespace();

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
                    $this->prepareNamespace($v);
                    break;
                case 'Stmt_Enum':
                case 'Stmt_Class':
                case 'Stmt_Trait':
                    $this->prepareClass($v);
                    break;
                case 'Stmt_Function':
                    $this->prepareFunction($v) . PHP_EOL;
                    break;
                case 'Stmt_Use':
                    $this->parseUse($v);
                    break;
                case 'Stmt_Declare':
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

        $nodeFinder = new NodeFinder();
        $functionCalls = $nodeFinder->findInstanceOf($ast, Node\Expr\FuncCall::class);

        foreach ($functionCalls as $call) {
            if ($call->name instanceof Node\Name) {
                // 内置函数不参与依赖管理
                $funcName = strtolower($call->name->toString());
                if (!$this->isInternalFunction($funcName)) {
                    $this->symbolCallInFile[$this->file][] = $funcName;
                }
            }
        }

        $depClasses = [];
//        $depClasses = array_merge($depClasses, $nodeFinder->findInstanceOf($ast, Node\Expr\StaticCall::class));
//        $depClasses = array_merge($depClasses, $nodeFinder->findInstanceOf($ast, Node\Expr\StaticPropertyFetch::class));
        $depClasses = array_merge($depClasses, $nodeFinder->findInstanceOf($ast, Node\Expr\ClassConstFetch::class));
        $depClasses = array_merge($depClasses, $nodeFinder->findInstanceOf($ast, Node\Expr\New_::class));
        foreach ($depClasses as $call) {
            if ($call->class instanceof Node\Name) {
                $className = $this->parseIdentifier($call->class);
                if ($className !== 'self' && $className !== 'static') {
                    $fullClassName = $this->getNamespacedClassName($className);
                    $this->symbolCallInFile[$this->file][] = strtolower($fullClassName);
                }
            }
        }
        // 依赖去重
        $depClasses = array_unique($this->symbolCallInFile[$this->file]);
        $this->symbolCallInFile[$this->file] = $depClasses;
    }

    protected function prepareNamespace(Node\Stmt\Namespace_ $node): void
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
                    $this->parseUse($v2);
                    break;
                case 'Stmt_Const':
                case 'Stmt_Interface':
                    break;
                case 'Stmt_Expression':
                    $this->foundStrayCode($v2);
                    // no break
                default:
                    throw new Unsupported('Unsupported statement: ' . $type2);
            }
        }
    }

    protected function prepareFunction(Node\Stmt\ClassMethod|Node\Stmt\Function_ $v): void
    {
        $name = $this->getFunctionName($v);
        if ($this->stubFile) {
            $this->addFunction($name, $this->parseFunctionDecl($v));
        } else {
            $functionNameLower = strtolower($name);
            if (isset($this->symbolDeclInFile[$functionNameLower])) {
                $this->fatalError($v, "Duplicate function `{$functionNameLower}`");
            }
            $this->symbolDeclInFile[$functionNameLower] = $this->file;
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
        $fullClassName = $this->getFullClassName();
        $fullClassNameLower = strtolower($fullClassName);

        if (!empty($class->extends)) {
            $this->parentClass = $this->getParentClass($class->extends);
            $parentClassLower = strtolower($this->parentClass);
            if ($parentClassLower === $fullClassNameLower) {
                $this->fatalError($class, "Class {$fullClassName} cannot extend itself");
            }
            $this->classExtends[$fullClassNameLower] = $parentClassLower;
            if (!$this->isInternalClass($parentClassLower)) {
                $this->symbolCallInFile[$this->file][] = $parentClassLower;
            }
        }

        if (isset($this->symbolDeclInFile[$fullClassNameLower])) {
            $this->fatalError($class, "Duplicate class `{$fullClassName}`");
        }

        $this->symbolDeclInFile[$fullClassNameLower] = $this->file;

        $code = '';
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
                    $this->prepareMethod($v, $class);
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

    protected function prepareMethod(Node\Stmt\ClassMethod $v, Node\Stmt\Class_|Node\Stmt\Trait_|Node\Stmt\Enum_ $class): void
    {
        $this->method = $v->name;
        $abstract = $v->flags & Modifiers::ABSTRACT;
        if (!$abstract) {
            $this->prepareFunction($v) . PHP_EOL;
        } else {
            if (!($class->flags & Modifiers::ABSTRACT)) {
                $this->fatalError($v, "Class {$this->class} cannot override non-abstract method {$v->name}");
            }
            if ($this->method === '__construct') {
                foreach ($v->params as $param) {
                    if ($param->isPromoted()) {
                        $this->fatalError($v, 'Cannot declare promoted property in an abstract constructor');
                    }
                }
            }
        }

        $fullClassName = $this->getFullClassName();
        $fullMethodName = $fullClassName . '::' . $this->method;
        $this->classMethodOverride[strtolower($fullMethodName)] = false;
        // 查找父类是否有同名方法，递归查找
        $fullClassNameLower = strtolower($fullClassName);

        while (isset($this->classExtends[$fullClassNameLower])) {
            $parentClass = $this->classExtends[$fullClassNameLower];
            $parentMethodLower = strtolower($parentClass . '::' . $v->name);
            // 父类有同名方法，子类覆盖了父类方法，这种情况不能直接使用 C++ 函数，而是使用 ZendVM 动态调用
            if (isset($this->classMethodOverride[$parentMethodLower])) {
                $this->classMethodOverride[$parentMethodLower] = true;
            }
            $fullClassNameLower = strtolower($parentClass);
        }
    }
}
