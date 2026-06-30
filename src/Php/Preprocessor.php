<?php
/**
 * This file is part of Swoole-Compiler(AOT).
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace PhpAot\Php;

use MJS\TopSort\Implementations\StringSort;
use PhpAot\Php\Entity\ArrayInitPlan;
use PhpAot\Php\Entity\ClassDef;
use PhpAot\Php\Entity\ConstantDef;
use PhpAot\Php\Entity\FunctionDef;
use PhpAot\Php\Entity\InterfaceDef;
use PhpAot\Php\Entity\MethodDef;
use PhpAot\Php\Entity\PropertyDef;
use PhpAot\Php\Exception\SyntaxError;
use PhpParser\Modifiers;
use PhpParser\Node;
use PhpParser\Node\IntersectionType;
use PhpParser\Node\NullableType;
use PhpParser\Node\UnionType;
use PhpParser\NodeAbstract;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;

class Preprocessor extends CompilerBase
{
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
        $type = $argInfo->type;
        if ($type === self::TYPE_STREAM || $type === self::TYPE_BOX) {
            $type = self::TYPE_VAR;
        }
        return $type . ' ' . $argInfo->name;
    }

    public function getCppFile(string $file): string
    {
        $info = pathinfo($file);

        $separator = $this->getPlatform()->getPathSeparator();
        $relativePath = $this->removeCommonPrefix($this->buildDir, $info['dirname']);

        return $this->buildDir . $separator . $relativePath . $separator . $info['filename'] . '.cc';
    }

    public function getObjectFile(string $cppFile): string
    {
        $info = pathinfo($cppFile);
        $ext = $this->getPlatform()->getObjectExtension();

        // 保持与 cppFile 相同的路径分隔符
        return $info['dirname'] . $this->getPlatform()->getPathSeparator() . $info['filename'] . $ext;
    }

    public function prepareFile(string $file): void
    {
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
                case 'Stmt_GroupUse':
                    $this->parseGroupUse($v);
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
                case 'Stmt_GroupUse':
                    $this->parseGroupUse($v2);
                    break;
                case 'Stmt_Const':
                    $this->parseConstDef($v2);
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

    protected function parseParameterType(Node\Param $param, ArgInfo $argInfo, string $var): string
    {
        if ($param->byRef) {
            return self::TYPE_REF;
        }
        $class = '';
        $type = $this->parseTypeDecl($param->type, self::DECL_TYPE_OF_PARAM, $class);
        $argInfo->undeclared = $param->type === null;
        if ($class and !$this->hasInterface($class) and !$this->isAbstractClass($class)) {
            $argInfo->class = $class;
        }
        return $type;
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
                $nullable = $param->type instanceof NullableType;
                // Promoted property defaults belong to the constructor parameter,
                // not to the property default table. The property itself must stay
                // uninitialized until __construct assigns it.
                $this->addClassProperty($name, $param->flags, $param->type, null, $nullable, $param);
            }
            if ($param->variadic) {
                if ($i !== $last) {
                    $this->fatalError($param, 'Variadic parameters must be the last parameter');
                } elseif ($param->byRef) {
                    $this->fatalError($param, 'Variadic parameters cannot be passed by reference');
                }
            }
            $name = $this->parseIdentifier($param->var);
            if ($this->method and $name === 'this_') {
                $this->fatalError($param, 'Cannot use `$this` as parameter of class method');
            }
            $argInfo = new ArgInfo();
            $type = $this->parseParameterType($param, $argInfo, $name);
            $argInfo->name = $name;
            $argInfo->type = $type;
            $argInfo->byRef = $param->byRef;
            $argInfo->variadic = $param->variadic;
            $argInfo->property = $param->isPromoted();
            if ($param->type === null || $param->type instanceof NullableType) {
                $argInfo->nullable = true;
            }
            if ($param->type instanceof NullableType || $param->type instanceof UnionType || $param->type instanceof IntersectionType) {
                $typeInfo = $this->buildTypeCheckFromNode($param->type);
                if (!empty($typeInfo['check'])) {
                    $argInfo->typeCheck = $typeInfo['check'];
                    $argInfo->typeStr = $typeInfo['typeStr'];
                    $argInfo->typeNode = $param->type;
                }
            }
            if ($param->variadic) {
                $list[] = self::TYPE_ARRAY . ' ' . $name;
            } else {
                $list[] = $this->genArgumentDeclaration($argInfo);
            }
            if ($param->default) {
                $arrayInitPlan = $param->default instanceof Node\Expr\Array_
                    ? $this->buildLiteralArrayInitPlan($param->default)
                    : null;
                if ($param->byRef) {
                    if ($this->isEmptyArray($param->default)) {
                        $argInfo->default = 'php::getEmptyArrayRef()';
                        $argInfo->defaultValue = null;
                    } elseif ($this->isNull($param->default)) {
                        $argInfo->default = 'nullptr';
                        $argInfo->defaultValue = null;
                    } elseif ($arrayInitPlan) {
                        $argInfo->default = 'php::newReference(' . $arrayInitPlan->expr . ')';
                        $argInfo->arrayInitPlan = $arrayInitPlan;
                    } else {
                        $argInfo->default = 'php::newReference(' . $this->parseParamDefaultValue($param->default) . ')';
                    }
                } else {
                    $argInfo->default = $arrayInitPlan ? $arrayInitPlan->expr : $this->parseParamDefaultValue($param->default);
                    $argInfo->arrayInitPlan = $arrayInitPlan;
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
        $functionDef->returnTypeUndeclared = $v->returnType === null;

        if ($v->returnType instanceof NullableType || $v->returnType instanceof UnionType || $v->returnType instanceof IntersectionType) {
            $typeInfo = $this->buildTypeCheckFromNode($v->returnType);
            if (!empty($typeInfo['check'])) {
                $functionDef->returnTypeCheck = $typeInfo['check'];
                $functionDef->returnTypeStr = $typeInfo['typeStr'];
                $functionDef->returnTypeNode = $v->returnType;
            }
        }

        $this->parseParams($v->params, $functionDef);

        // main 函数，返回值必须为 void 类型，参数必须为空或者 argc, argv 两个参数
        if (!$this->class and !$this->namespace and $fnName === self::ENTRY_FUNCTION) {
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

        if ($class instanceof Node\Stmt\Class_) {
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
            $this->classSubClasses[$parentClassLower][] = $fullClassNameLower;
            if (!$this->isInternalClass($parentClassLower)) {
                $this->symbolCallInFile[$this->file][] = $parentClassLower;
            }
            $this->classDef->extends = $this->parentClass;
            // 是否继承了内置类
            $this->classDef->inheritedFromInternalClass = $this->isInternalClass($parentClassLower);
        }

        if ($class instanceof Node\Stmt\Enum_) {
            $this->classDef->enum = true;
            if ($class->scalarType !== null) {
                $this->classDef->enumBackingType = $class->scalarType->name;
            }
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
                    break;
                case 'Stmt_EnumCase':
                    $caseName = $this->parseIdentifier($v->name);
                    $this->classDef->enumCases[$caseName] = $v->expr?->value;
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

        // 将 trait 方法参数中的类名升级为 FullyQualified，避免 gen_stub 时上下文丢失
        if ($class instanceof Node\Stmt\Trait_) {
            foreach ($class->stmts as $v) {
                if ($v instanceof Node\Stmt\ClassMethod) {
                    foreach ($v->params as $param) {
                        $param->type = $this->upgradeToFullyQualifiedName($param->type);
                    }
                }
            }
        }

        $this->resetClass();

        return $code;
    }

    protected function buildLiteralArrayInitPlan(Node\Expr\Array_ $defaultNode): ArrayInitPlan
    {
        $localVarCount = count($this->context->localVars);
        $beforeStmtCount = count($this->context->beforeStmtLines);
        $afterStmtCount = count($this->context->afterStmtLines);
        $expr = $this->parseIdentifier($defaultNode);

        $init = '';
        $clean = '';
        $newLocalVars = array_slice($this->context->localVars, $localVarCount, null, true);
        $newBeforeStmtLines = array_slice($this->context->beforeStmtLines, $beforeStmtCount);
        $newAfterStmtLines = array_slice($this->context->afterStmtLines, $afterStmtCount);

        if ($newLocalVars) {
            $init .= $this->genLocalVarDecl($newLocalVars);
            $this->context->localVars = array_slice($this->context->localVars, 0, $localVarCount, true);
        }
        if ($newBeforeStmtLines) {
            $init .= implode(PHP_EOL, $newBeforeStmtLines) . PHP_EOL;
            $this->context->beforeStmtLines = array_slice($this->context->beforeStmtLines, 0, $beforeStmtCount);
        }
        if ($newAfterStmtLines) {
            $clean .= implode(PHP_EOL, $newAfterStmtLines) . PHP_EOL;
            $this->context->afterStmtLines = array_slice($this->context->afterStmtLines, 0, $afterStmtCount);
        }

        return new ArrayInitPlan($expr, $init, $clean);
    }

    protected function getMethodName(Node\Stmt\ClassMethod $v): string
    {
        return $this->parseIdentifier($v->name);
    }

    protected function parseClassConstDef(Node\Stmt\ClassConst $v): void
    {
        $this->resetFunction();
        $flags = $this->parseModifiers($v->flags);
        $class = '';

        $declaredType = $v->type ? $this->parseTypeDecl($v->type, self::DECL_TYPE_OF_CONST, $class) : null;

        foreach ($v->consts as $const) {
            $type = $declaredType;
            if ($type === null) {
                $type = match ($const->value->getType()) {
                    'Expr_Array' => self::TYPE_ARRAY,
                    'Scalar_String' => self::TYPE_STR,
                    default => self::TYPE_VAR,
                };
            }
            $constName = $this->parseIdentifier($const->name);
            if ($this->classDef->hasConstant($constName)) {
                $this->fatalError($v, "Duplicate constant `{$constName}`");
            }
            $constInfo = $this->parseClassLikeConstant($const, $flags, $type, $class);
            $constInfo->class = $class;
            $this->classDef->constants[$constInfo->name] = $constInfo;
        }
    }

    private function parseClassLikeConstant(Node\Const_ $const, int $flags, string $type, string $class = ''): ConstantDef
    {
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
        return $constInfo;
    }

    /**
     * Create and register a class property with type normalization, shared by
     * regular property declarations and constructor property promotion.
     */
    protected function addClassProperty(string $name, int $flags, ?NodeAbstract $typeNode, $defaultNode, bool $nullable, NodeAbstract $errorNode): PropertyDef
    {
        $flags = $this->parseModifiers($flags);
        $class = '';
        $type = $this->parseTypeDecl($typeNode, self::DECL_TYPE_OF_PROPERTY, $class);

        $default = null;
        $arrayInitPlan = null;
        if ($defaultNode !== null) {
            if ($defaultNode instanceof Node\Expr\Array_) {
                $type = self::TYPE_ARRAY;
                $arrayInitPlan = $this->buildLiteralArrayInitPlan($defaultNode);
                $default = $arrayInitPlan->expr;
            } else {
                $default = $this->parseIdentifier($defaultNode);
            }
        }

        if ($this->classDef->hasProperty($name)) {
            $this->fatalError($errorNode, "Duplicate property `{$name}`");
        }

        $propDef = new PropertyDef($name, $flags, $type, $default, $nullable);
        $propDef->class = $class;
        $propDef->arrayInitPlan = $arrayInitPlan;
        $this->classDef->properties[$name] = $propDef;
        return $propDef;
    }

    protected function parseClassPropertyDef(Node\Stmt\Property $v): void
    {
        if ($v->hooks and count($v->hooks) > 0) {
            $this->fatalError($v, 'The class property hooks are not supported');
        }
        $oriCtx = $this->context;
        $this->context = $this->classDef->propertyContext;
        $nullable = $v->type instanceof NullableType;

        foreach ($v->props as $prop) {
            $propName = $this->parseIdentifier($prop->name);
            $this->addClassProperty($propName, $v->flags, $v->type, $prop->default, $nullable, $v);
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
            if ($this->classDef->hasMethod($name) || $this->classDef->hasAbstractMethod($name)) {
                $this->fatalError($v, "Duplicate method `{$this->method}`");
            }
            if (!$class instanceof Node\Stmt\Trait_ && isset($class->flags) && !($class->flags & Modifiers::ABSTRACT)) {
                $this->fatalError($v, "Non-abstract class {$this->class} contains abstract method {$v->name}");
            }
            $this->methodDef = new MethodDef($flags, $name);
            $this->methodDef->functionDef = $this->parseFunctionDecl($v);
            $this->methodDef->functionDef->method = true;
            $this->checkRequiredArgNum($name, $this->methodDef, $v);
            if ($this->method === '__construct') {
                foreach ($v->params as $param) {
                    if ($param->isPromoted()) {
                        $this->fatalError($v, 'Cannot declare promoted property in an abstract constructor');
                    }
                }
            }
            $this->classDef->addAbstractMethod($name, $flags, $this->methodDef);
        }

        $fullClassName = $this->getFullClassName();

        $fullMethodName = $fullClassName . '::' . $this->method;
        $fullMethodNameLower = strtolower($fullMethodName);
        $fullClassNameLower = strtolower($fullClassName);

        // 检查子类是否已覆盖此方法（子类先于父类被预处理的情况）
        $isOverridden = $this->isMethodOverriddenInSubClasses($fullClassNameLower, $this->method);
        $this->classMethodOverride[$fullMethodNameLower] = $isOverridden;

        // 查找父类是否有同名方法，递归向上标记父类方法已被覆盖
        while (isset($this->classExtends[$fullClassNameLower])) {
            $parentClass = $this->classExtends[$fullClassNameLower];
            $parentMethodLower = strtolower($parentClass . '::' . $this->method);
            if (isset($this->classMethodOverride[$parentMethodLower])) {
                $this->classMethodOverride[$parentMethodLower] = true;
            }
            $fullClassNameLower = strtolower($parentClass);
        }

        $this->resetMethod();
    }

    /**
     * 递归检查所有子类（及子类的子类）是否已定义了同名方法，用于处理子类先于父类被预处理的情况。
     */
    private function isMethodOverriddenInSubClasses(string $classNameLower, string $method): bool
    {
        if (!isset($this->classSubClasses[$classNameLower])) {
            return false;
        }
        $stack = $this->classSubClasses[$classNameLower];
        while (!empty($stack)) {
            $subClass = array_shift($stack);
            $subMethodLower = $subClass . '::' . strtolower($method);
            if (isset($this->classMethodOverride[$subMethodLower])) {
                return true;
            }
            if (isset($this->classSubClasses[$subClass])) {
                foreach ($this->classSubClasses[$subClass] as $grandChild) {
                    $stack[] = $grandChild;
                }
            }
        }
        return false;
    }

    protected function parseInterface(Node\Stmt\Interface_ $v): void
    {
        $this->resetClass();
        $this->resetMethod();
        $this->resetFunction();
        $name = $this->parseIdentifier($v->name);
        $this->interface = $name;
        $this->interfaceDef = new InterfaceDef($name, $this->namespace);
        $interfaceName = $this->interfaceDef->getNamespacedName(false);
        $interfaceNameLower = strtolower($interfaceName);

        foreach ($v->extends as $parent) {
            $parentName = $this->getNamespacedClassName($this->parseIdentifier($parent));
            $this->interfaceDef->extendsList[] = $parentName;
            if ($this->interfaceDef->extends === '') {
                $this->interfaceDef->extends = $parentName;
            }
            if (!$this->isInternalInterface($parentName)) {
                $this->symbolCallInFile[$this->file][] = strtolower($parentName);
            }
        }

        if (isset($this->symbolDeclInFile[$interfaceNameLower])) {
            $this->fatalError($v, "Duplicate interface `{$interfaceName}`");
        }

        $this->symbolDeclInFile[$interfaceNameLower] = $this->file;
        $this->interfaces[$this->escapeClass($interfaceName)] = $this->interfaceDef;
        $this->interfacesDefineInFile[$interfaceName] = $this->interfaceDef;

        foreach ($v->stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\ClassConst) {
                foreach ($stmt->consts as $const) {
                    $constName = $this->parseIdentifier($const->name);
                    if ($this->interfaceDef->hasConstant($constName)) {
                        $this->fatalError($stmt, "Duplicate constant `{$constName}`");
                    }
                    $class = '';
                    $type = $stmt->type
                        ? $this->parseTypeDecl($stmt->type, self::DECL_TYPE_OF_CONST, $class)
                        : match ($const->value->getType()) {
                            'Expr_Array' => self::TYPE_ARRAY,
                            'Scalar_String' => self::TYPE_STR,
                            default => self::TYPE_VAR,
                        };
                    $constInfo = $this->parseClassLikeConstant($const, $this->parseModifiers($stmt->flags), $type, $class);
                    $this->interfaceDef->constants[$constName] = $constInfo;
                }
                continue;
            }

            if ($stmt instanceof Node\Stmt\ClassMethod) {
                $methodName = $this->getMethodName($stmt);
                if ($this->interfaceDef->hasMethod($methodName)) {
                    $this->fatalError($stmt, "Duplicate method `{$methodName}`");
                }
                $this->method = $methodName;
                $methodDef = new MethodDef($this->parseModifiers($stmt->flags), $methodName);
                $methodDef->functionDef = $this->parseFunctionDecl($stmt);
                $methodDef->functionDef->method = true;
                $this->interfaceDef->addMethod($methodDef);
                $this->resetMethod();
                $this->resetFunction();
                continue;
            }

            if (!$stmt instanceof Node\Stmt\Nop) {
                $this->fatalError($stmt, 'Unsupported interface statement: ' . $stmt->getType());
            }
        }

        $this->resetMethod();
        $this->resetFunction();
        $this->interface = '';
        $this->interfaceDef = null;
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
                    $traitName = $this->getNamespacedClassName($this->parseIdentifier($trait));
                    $methodName = $adaptation->method->toString();
                    /*
                     * 例如：
                     * use TraitA { TraitA::method as newMethod}
                     * 这表示 TraitA::method() 会被重命名为 TraitA::newMethod()
                     */
                    $aliases[$this->getFullMethodName($traitName, $methodName)][] = [
                        'newName' => $adaptation->newName ? $adaptation->newName->toString() : $methodName,
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
                    $traitName = $this->getNamespacedClassName($this->parseIdentifier($trait2));
                    $ignored[$this->getFullMethodName($traitName, $methodName)] = true;
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
        foreach ($v->traits as $trait) {
            $traitName = $this->getNamespacedClassName($this->parseIdentifier($trait));
            if (!$this->isInternalClass($traitName)) {
                $this->symbolCallInFile[$this->file][] = strtolower($traitName);
            }
        }
        foreach ($aliases as $fullMethodName => $aliasList) {
            foreach ($aliasList as $alias) {
                $this->classDef->traitAliases[$fullMethodName][] = $alias;
            }
        }
        $this->classDef->traitIgnored = array_merge($this->classDef->traitIgnored, $ignored);
    }
}
