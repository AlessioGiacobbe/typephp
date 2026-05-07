<?php
/**
 * This file is part of Swoole-Compiler(AOT).
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace PhpAot\Php;

use MJS\TopSort\Implementations\StringSort;
use PhpAot\Php\Entity\ClassDef;
use PhpAot\Php\Entity\ConstantDef;
use PhpAot\Php\Entity\FunctionDef;
use PhpAot\Php\Entity\InterfaceDef;
use PhpAot\Php\Entity\MethodDef;
use PhpAot\Php\Entity\PropertyDef;
use PhpAot\Php\Exception\SyntaxError;
use PhpParser\Modifiers;
use PhpParser\Node;
use PhpParser\Node\NullableType;
use PhpParser\Node\UnionType;
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

        $sortedFiles = $sorter->sort();

        // 添加未参与依赖管理的文件（非 stub 文件且不在已排序列表中）
        foreach ($list as $file) {
            if (!$this->isStubFile($file) and !in_array($file, $sortedFiles)) {
                $sortedFiles[] = $file;
            }
        }

        $list = $sortedFiles;

        $this->climate->lightBlue('prepare completed: ' . count($list) . ' source files in total');
    }

    protected function genArgumentDeclaration(ArgInfo $argInfo): string
    {
        return $argInfo->type . ' ' . $argInfo->name;
    }

    public function getCppFile(string $file): string
    {
        $info = pathinfo($file);

        // Windows 下使用反斜杠，其他平台使用正斜杠
        $separator = $this->isWindows() ? '\\' : '/';
        $relativePath = $this->removeCommonPrefix($this->buildDir, $info['dirname']);

        return $this->buildDir . $separator . $relativePath . $separator . $info['filename'] . '.cc';
    }

    public function getObjectFile(string $cppFile): string
    {
        $info = pathinfo($cppFile);
        $ext = $this->isWindows() ? '.obj' : '.o';

        // 保持与 cppFile 相同的路径分隔符
        return $info['dirname'] . ($this->isWindows() ? '\\' : '/') . $info['filename'] . $ext;
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

    public function prepareFile(string $file): void
    {
        if ($this->hasCppFileCache($file)) {
            $this->climate->darkGray('skip: ' . $file . ', cache exists');

            return;
        }

        $phpCode = $this->loadFile($file);
        $this->symbolCallInFile[$this->file] = [];
        $this->resetFile();
        $this->resetFunction();
        $this->resetMethod();
        $this->resetClass();
        $this->resetNamespace();

        $this->climate->info('prepare: ' . $this->getRelativePath($this->file));
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
                case 'Stmt_Interface':
                    $this->parseInterface($v);
                    break;
                case 'Stmt_Function':
                    $this->prepareFunction($v);
                    break;
                case 'Stmt_Use':
                    $this->parseUse($v);
                    break;
                case 'Stmt_Declare':
                case 'Stmt_Nop':
                    break;
                case 'Stmt_Const':
                    $this->parseConstDef($v);
                    break;
                case 'Stmt_Expression':
                    $this->foundStrayCode($v);
                    break;
                default:
                    $this->fatalError($v, 'Unsupported statement: ' . $type);
                    break;
            }
        }
    }

    protected function findSymbolUsing(NodeAbstract $ast)
    {
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
        $depClasses = array_merge($depClasses, $nodeFinder->findInstanceOf($ast, Node\Expr\StaticCall::class));
        $depClasses = array_merge($depClasses, $nodeFinder->findInstanceOf($ast, Node\Expr\StaticPropertyFetch::class));
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
        $this->resetClass();
        $this->resetMethod();
        $this->resetFunction();
        $this->resetNamespace();

        $this->namespace = $node->name ? $this->parseIdentifier($node->name) : '';
        foreach ($node->stmts as $v2) {
            $type2 = $v2->getType();
            switch ($type2) {
                case 'Stmt_Class':
                case 'Stmt_Enum':
                case 'Stmt_Trait':
                    $this->prepareClass($v2);
                    break;
                case 'Stmt_Function':
                    $this->prepareFunction($v2);
                    break;
                case 'Stmt_Use':
                    $this->parseUse($v2);
                    break;
                case 'Stmt_Const':
                    break;
                case 'Stmt_Interface':
                    $this->parseInterface($v2);
                    break;
                default:
                    $this->foundStrayCode($v2);
                    break;
            }
        }
    }

    /**
     * @param $params array<Node\Param>
     */
    protected function parseParams(array $params, FunctionDef $functionDef): void
    {
        $list                          = [];
        $functionDef->argCountRequired = count($params);
        $defaultValueCount = 0;
        $last = array_key_last($params);

        foreach ($params as $i => $param) {
            // .stub 存根定义 C++ Native 函数，必须设置函数的参数类型
            if ($this->stubFile and !$param->type) {
                throw new \RuntimeException('No type for ' . $this->parseIdentifier($param->var));
            }
            // 构造方法属性定义语法（Constructor Property Promotion）
            if ($param->isPromoted()) {
                if (!$this->classDef or !$this->methodDef or $this->methodDef->name !== '__construct') {
                    $this->fatalError($param, 'Promoted properties are not supported');
                }
                $name = $this->parseIdentifier($param->var);
                if ($param->type instanceof NullableType) {
                    $type = $param->type->type;
                    $nullable = true;
                } elseif ($param->type instanceof UnionType) {
                    $type = 'mixed';
                    $nullable = false;
                } else {
                    $type = $param->type === null ? '' : $param->type;
                    $nullable = false;
                }
                $default = $this->parseParamDefaultValue($param->default);
                if ($this->classDef->hasProperty($name)) {
                    $this->fatalError($param, "Duplicate property `{$name}`");
                }
                $propertyDef = new PropertyDef($name, $param->flags, $type, $default, $nullable);
                $this->classDef->properties[$name] = $propertyDef;
            }
            if ($param->variadic) {
                if ($i !== $last) {
                    $this->fatalError($param, 'Variadic parameters must be the last parameter');
                } elseif ($param->byRef) {
                    $this->fatalError($param, 'Variadic parameters cannot be passed by reference');
                }
            }
            $name = $this->parseIdentifier($param->var);
            if ($this->method and $name == 'this_') {
                $this->fatalError($param, 'Cannot use `$this` as parameter of class method');
            }
            $argInfo = new ArgInfo();
            $type = $this->parseParameterType($param, $argInfo, $name);
            $argInfo->name = $name;
            $argInfo->type = $type;
            $argInfo->byRef = $param->byRef;
            $argInfo->variadic = $param->variadic;
            $argInfo->property = $param->isPromoted();
            if ($param->type and $param->type instanceof NullableType) {
                $argInfo->nullable = true;
            }
            if ($param->variadic) {
                $list[] = self::TYPE_ARRAY . ' ' . $name;
            } else {
                $list[] = $this->genArgumentDeclaration($argInfo);
            }
            if ($param->default) {
                if ($param->byRef) {
                    if ($this->isEmptyArray($param->default)) {
                        $argInfo->default = 'php::getEmptyArrayRef()';
                        $argInfo->defaultValue = null;
                    } elseif ($this->isNull($param->default)) {
                        $argInfo->default = 'nullptr';
                        $argInfo->defaultValue = null;
                    } else {
                        $argInfo->default = 'php::newReference(' . $this->parseParamDefaultValue($param->default) . ')';
                    }
                } else {
                    $argInfo->default = $this->parseParamDefaultValue($param->default);
                    $argInfo->defaultValue = $param->default;
                }
                $defaultValueCount++;
            } elseif ($param->variadic) {
                // 变长参数可以视为空数组默认值
                $defaultValueCount++;
                $argInfo->default = '{}';
                $argInfo->defaultValue = new Node\Expr\Array_();
            }
            $functionDef->argInfoList[] = $argInfo;
        }
        $functionDef->params = implode(', ', $list);
        $functionDef->argCountRequired -= $defaultValueCount;
    }

    protected function parseFunctionDecl(Node\Stmt\Function_|Node\Stmt\ClassMethod $v): FunctionDef
    {
        // .stub 存根定义 C++ Native 函数，必须设置返回值类型
        if ($this->stubFile and !$v->returnType) {
            // 以下魔术方法都不能声明返回值类型 __construct()/__destruct()/__clone()
            if (($this->method and !in_array($this->method, ['__construct', '__destruct', '__clone'])) or !$this->method) {
                $name = $this->class ? $this->class . '::' . $v->name : $v->name;
                $this->fatalError($v, 'The return type of the function `' . $name . '` must be specified');
            }
        }
        // 返回值不能是引用类型
        if ($v->byRef) {
            $this->fatalError($v, 'The return type of the function `' . $v->name . '` cannot be a reference type');
        }

        $fnName = $this->parseIdentifier($v->name);
        $class = '';
        $returnType = $this->parseTypeDecl($v->returnType, self::DECL_TYPE_OF_RETURN, $class);
        // 构造、析构、克隆方法不能有返回值
        if ($this->method and in_array($this->method, ['__construct', '__destruct', '__clone'])) {
            $returnType = self::TYPE_VOID;
        }

        $functionDef = new FunctionDef($fnName, $returnType, $this->namespace);
        $functionDef->returnClass = $class;
        $functionDef->stub = $this->stubFile;

        $this->parseParams($v->params, $functionDef);

        // main 函数，返回值必须为 void 类型，参数必须为空或者 argc, argv 两个参数
        if (!$this->class and !$this->namespace and $fnName === 'main') {
            if (count($v->params) > 0) {
                if (count($v->params) != 2) {
                    $this->fatalError($v, 'The parameters of the main function must be `(int $argc, array $argv)`.');
                }
                if ($returnType !== self::TYPE_VOID) {
                    $this->fatalError($v, 'main function must return void');
                }
                if (!$this->checkArgType($functionDef->argInfoList[0]->type, self::TYPE_INT)) {
                    $this->fatalError($v, 'The first parameter of the main function must be of type `int`.');
                }
                if (!$this->checkArgType($functionDef->argInfoList[1]->type, self::TYPE_ARRAY)) {
                    $this->fatalError($v, 'The second parameter of the main function must be of type `array`.');
                }
            }
        }

        return $functionDef;
    }

    protected function prepareFunction(Node\Stmt\ClassMethod|Node\Stmt\Function_ $v): void
    {
        $this->resetFunction();
        $this->function = $this->parseIdentifier($v->name);
        $name = $this->getFunctionName($v);
        if ($this->hasFunction($name)) {
            $this->fatalError($v, "Duplicate function `{$name}`");
        }
        // 禁止重定义内置函数
        if (!$this->methodDef and $this->isInternalFunction($name)) {
            $this->fatalError($v, "The function `{$name}` is a built-in function and cannot be redefined");
        }
        $functionDef = $this->parseFunctionDecl($v);
        $this->addFunction($name, $functionDef);
        if ($this->methodDef) {
            $functionDef->method = true;
            $this->methodDef->functionDef = $functionDef;
        }
    }

    protected function getParentClass(NodeAbstract $extends): string
    {
        return $this->getNamespacedClassName($this->parseIdentifier($extends));
    }

    protected function prepareClass(Node\Stmt\Class_|Node\Stmt\Trait_|Node\Stmt\Enum_ $class): string
    {
        $this->resetClass();
        $this->class = $this->parseIdentifier($class->name);
        $fullClassName = $this->getFullClassName();
        $fullClassNameLower = strtolower($fullClassName);

        if ($class instanceof Node\Stmt\Enum_) {
            $flags = Modifiers::PUBLIC;
        } elseif (!$class instanceof Node\Stmt\Trait_) {
            $flags = $class->flags;
        } else {
            $flags = Modifiers::PUBLIC;
        }

        $this->classDef = new ClassDef($this->class, $flags, $this->namespace);
        $this->addClass($fullClassName, $this->classDef);

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
            $this->classDef->extends = $this->parentClass;
            // 是否继承了内置类
            $this->classDef->inheritedFromInternalClass = $this->isInternalClass($parentClassLower);
        }

        if ($class instanceof Node\Stmt\Enum_) {
            $this->classDef->enum = true;
        }
        if (!$class instanceof Node\Stmt\Trait_) {
            $this->classDef->implements = $this->parseImplements($class->implements);
        } else {
            $this->classDef->trait = $class;
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
                    $this->parseClassConstDef($v);
                    break;
                case 'Stmt_Property':
                    $this->parseClassPropertyDef($v);
                    break;
                case 'Stmt_TraitUse':
                    $this->prepareTraitUse($v);
                    break;
                case 'Stmt_Nop':
                case 'Stmt_EnumCase':
                    break;
                case 'Stmt_ClassMethod':
                    $this->prepareClassMethod($v, $class);
                    break;
                case 'Stmt_Expression':
                    $this->foundStrayCode($v);
                    break;
                default:
                    abort($v);
                    break;
            }
        }

        $this->resetClass();

        return $code;
    }

    protected function getMethodName(Node\Stmt\ClassMethod $v): string
    {
        return $this->parseIdentifier($v->name);
    }

    /**
     * 检查父类方法是否可以被重写，私有方法不能被重写
     */
    protected function checkParentMethodCanBeOverridden(Node\Stmt\ClassMethod $v, string $name): void
    {
        $classDef = $this->classDef;
        while (true) {
            $extends = $classDef->extends;
            if (!$extends) {
                break;
            }
            // 父类是内置类
            if ($classDef->inheritedFromInternalClass) {
                if (Reflection::getClassMethodModifiers($extends, $name) & \ReflectionMethod::IS_PRIVATE) {
                    goto _error;
                }
                break;
            }
            $classDef = $this->getClass($extends);
            if ($classDef->hasMethod($this->method)) {
                $methodDef = $classDef->getMethod($this->method);
                if ($methodDef->flags & Modifiers::PRIVATE) {
                    _error:
                    $this->fatalError($v,
                        'Cannot override private method `' .
                        $classDef->getNamespacedName(false) . '::' . $this->method . '()`');
                }
            }
        }
    }

    protected function parseClassConstDef(Node\Stmt\ClassConst $v): void
    {
        $this->resetFunction();
        $flags = $this->parseModifiers($v->flags);
        $class = '';

        if ($v->type) {
            $type = $this->parseTypeDecl($v->type, self::DECL_TYPE_OF_CONST, $class);
        } else {
            $type = null;
        }

        foreach ($v->consts as $const) {
            if ($type === null) {
                $type = match ($const->value->getType()) {
                    'Expr_Array' => self::TYPE_ARRAY,
                    'Scalar_String' => self::TYPE_STR,
                    default => self::TYPE_VAR,
                };
            }
            $constName = $this->parseIdentifier($const->name);
            $constValue = $this->parseIdentifier($const->value);

            $constInfo = new ConstantDef($constName, $flags, $type, $constValue);
            $constInfo->valueExpr = $const->value;

            if ($this->context->beforeStmtLines) {
                $arrayExpr = '';
                if ($this->context->localVars) {
                    $arrayExpr .= $this->genScopeVarDecl();
                }
                $arrayExpr .= $this->parseBeforeStmtLines();
                $constInfo->arrayExpr = $arrayExpr;
            }
            $constInfo->class = $class;
            $this->classDef->constants[$constInfo->name] = $constInfo;
        }
    }

    protected function parseClassPropertyDef(Node\Stmt\Property $v): void
    {
        if ($v->hooks and count($v->hooks) > 0) {
            $this->fatalError($v, 'The class property hooks are not supported');
        }
        $oriCtx = $this->context;
        $this->context = $this->classDef->propertyContext;
        $flags = $this->parseModifiers($v->flags);
        $class = '';
        $type = $this->parseTypeDecl($v->type, self::DECL_TYPE_OF_PROPERTY, $class);

        foreach ($v->props as $prop) {
            $propName = $this->parseIdentifier($prop->name);
            if ($this->classDef->hasProperty($propName)) {
                $this->fatalError($v, "Duplicate property `{$propName}`");
            }
            $propDef = new PropertyDef($propName, $flags, $type);
            if ($prop->default) {
                $propDef->default = $this->parseIdentifier($prop->default);
                if ($prop->default->getType() == 'Expr_Array') {
                    $propDef->type = self::TYPE_ARRAY;
                }
            }
            $propDef->class = $class;
            $this->classDef->properties[$propDef->name] = $propDef;
        }

        $this->context = $oriCtx;
    }

    protected function prepareClassMethod(Node\Stmt\ClassMethod $v, Node\Stmt\Class_|Node\Stmt\Trait_|Node\Stmt\Enum_ $class): void
    {
        $this->resetMethod();
        $name = $this->getMethodName($v);
        $this->method = $name;
        $flags = $this->parseModifiers($v->flags);
        $abstract = $flags & Modifiers::ABSTRACT;

        if (!$abstract) {
            $this->methodDef = new MethodDef($flags, $name);
            if ($this->classDef->hasMethod($name)) {
                $this->fatalError($v, "Duplicate method `{$this->method}`");
            }
            $this->prepareFunction($v);
            $this->checkRequiredArgNum($name, $this->methodDef, $v);
            $this->classDef->addMethod($this->methodDef);
        } else {
            if (isset($class->flags) and !($class->flags & Modifiers::ABSTRACT)) {
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
        $this->resetMethod();
    }

    protected function parseInterface(Node\Stmt\Interface_ $v): void
    {
        $name = $this->parseIdentifier($v->name);
        $this->interface = $name;
        $this->interfaceDef = new InterfaceDef($name, $this->namespace);
        $interfaceName = $this->interfaceDef->getNamespacedName();
        $this->interfaces[$this->escapeClass($interfaceName)] = $this->interfaceDef;
        $this->interfacesDefineInFile[$interfaceName] = $this->interfaceDef;
    }

    protected function parseTraitUseOptions(Node\Stmt\TraitUse $traitUse, array &$aliases, array &$ignored): void
    {
        foreach ($traitUse->adaptations as $adaptation) {
            if ($adaptation instanceof Node\Stmt\TraitUseAdaptation\Alias) {
                $traits = [];
                if (!$adaptation->trait) {
                    // use THello1, THello2 {
                    //    hello as hello3;
                    // }
                    // 未指定 trait，将添加所有 trait 的别名映射，在预处理阶段无法获取 trait 的方法列表
                    $traits = $traitUse->traits;
                } else {
                    $traits[] = $adaptation->trait;
                }
                foreach ($traits as $trait) {
                    $traitName = $this->getNamespacedClassName($trait);
                    $methodName = $adaptation->method->toString();
                    /*
                     * 例如：
                     * use TraitA { TraitA::method as newMethod}
                     * 这表示 TraitA::method() 会被重命名为 TraitA::newMethod()
                     */
                    $aliases[$this->getFullMethodName($traitName, $methodName)] = [
                        'newName' => $adaptation->newName->toString(),
                        'newModifier' => $adaptation->newModifier ?: 0,
                    ];
                }
            }
            if ($adaptation instanceof Node\Stmt\TraitUseAdaptation\Precedence) {
                if (!$adaptation->trait) {
                    $this->fatalError($traitUse, 'Trait precedence cannot be used without a trait');
                }
                $methodName = $adaptation->method->toString();
                /*
                 * 例如：
                 * use TraitA { TraitA::method insteadof TraitB}
                 * 这表示 TraitB::method() 将会被忽略，真正执行的是 TraitA::method()
                 */
                foreach ($adaptation->insteadof as $trait2) {
                    $ignored[$this->getFullMethodName($trait2, $methodName)] = true;
                }
            }
        }
    }

    protected function prepareTraitUse(Node\Stmt\TraitUse $v): void
    {
        $aliases = [];
        $ignored = [];
        if ($v->adaptations) {
            $this->parseTraitUseOptions($v, $aliases, $ignored);
        }
        $this->classDef->traitAliases = array_merge($this->classDef->traitAliases, $aliases);
        $this->classDef->traitIgnored = array_merge($this->classDef->traitIgnored, $ignored);
    }
}
