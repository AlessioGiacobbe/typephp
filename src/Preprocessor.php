<?php
/**
 * This file is part of TypePHP.
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace TypePhp;

use MJS\TopSort\Implementations\StringSort;
use TypePhp\Entity\ArgInfo;
use TypePhp\Entity\ArrayInitPlan;
use TypePhp\Entity\ClassDef;
use TypePhp\Entity\ConstantDef;
use TypePhp\Entity\FunctionDef;
use TypePhp\Entity\InterfaceDef;
use TypePhp\Entity\MethodDef;
use TypePhp\Entity\PropertyDef;
use TypePhp\Diagnostics\CompileTimeAttributeDiagnostic;
use TypePhp\Exception\SyntaxError;
use TypePhp\Transform\PropertyHookLowering;
use TypePhp\Transform\PrinterLowering;
use TypePhp\Transform\ArrayableLowering;
use TypePhp\Transform\ClassFieldSelection;
use TypePhp\Transform\FunctionAttributeLowering;
use TypePhp\Transform\ConstantExpressionValidationVisitor;
use TypePhp\Transform\RuntimeAttributeFactoryLowering;
use TypePhp\Transform\Visitor;
use PhpParser\Modifiers;
use PhpParser\ConstExprEvaluator;
use PhpParser\Node;
use PhpParser\Node\IntersectionType;
use PhpParser\Node\NullableType;
use PhpParser\Node\UnionType;
use PhpParser\NodeAbstract;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;

class Preprocessor extends CompilerBase
{
    protected function getSortedFiles(array $list): array
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

        $this->climate->lightBlue('prepare completed: ' . count($sortedFiles) . ' source files in total');
        return $sortedFiles;
    }

    protected function genArgumentDeclaration(ArgInfo $argInfo): string
    {
        $type = $argInfo->type;
        if ($type === Type::STREAM || $type === Type::BOX) {
            $type = Type::VAR;
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
        $normalizedFile = str_replace('\\', '/', $cppFile);
        $normalizedMiscDir = str_replace('\\', '/', $this->getPhpxDir() . '/src/misc/');
        if (str_starts_with($normalizedFile, $normalizedMiscDir)) {
            $separator = $this->getPlatform()->getPathSeparator();
            $objectDir = $this->buildDir . $separator . 'phpx-misc' . $separator . $this->targetName;
            if (!is_dir($objectDir)) {
                mkdir($objectDir, 0777, true);
            }
            return $objectDir . $separator . $info['filename'] . $ext;
        }

        return $info['dirname'] . $this->getPlatform()->getPathSeparator() . $info['filename'] . $ext;
    }

    public function prepareFile(string $file): void
    {
        $previousPhase = $this->enterCompilerPhase(self::PHASE_PREPARE);
        try {
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

            $this->stubImportLibrary = $this->stubFile && $this->hasLibraryImportAnnotation($ast)
                ? $this->getExternalImportLibraryName($this->file)
                : '';
            if ($this->stubImportLibrary !== '') {
                $this->externalImportStubFiles[$this->file] = true;
            }
            if ($this->stubImportLibrary !== '' && !in_array($this->stubImportLibrary, $this->linkLibs, true)) {
                $this->linkLibs[] = $this->stubImportLibrary;
            }

            $traverser = new NodeTraverser();
            $traverser->addVisitor(new NameResolver(null, ['replaceNodes' => false]));
            $traverser->addVisitor(new Visitor(
                fn (Node $node, string $message) => $this->warning($node, $message),
                $this->file,
            ));
            $traverser->addVisitor(new ConstantExpressionValidationVisitor($this->phpVersion));
            $traverser->addVisitor(new RuntimeAttributeFactoryLowering($this->file));
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
        } finally {
            $this->restoreCompilerPhase($previousPhase);
        }
    }

    /** @param array<Node> $stmts */
    private function hasLibraryImportAnnotation(array $stmts): bool
    {
        foreach ($stmts as $stmt) {
            foreach ($stmt->getComments() as $comment) {
                if (preg_match('/@import-library\b/', $comment->getText()) === 1) {
                    return true;
                }
            }
        }

        return false;
    }

    private function hasNoExportAttribute(NodeAbstract $node): bool
    {
        foreach ($node->attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                if (!$this->isRootCompileTimeAttribute($attribute, 'NoExport')) {
                    continue;
                }
                if ($attribute->args !== []) {
                    $this->fatalCompileTimeAttribute(
                        $node,
                        'NoExport',
                        'NoExport does not accept arguments',
                        $attribute,
                    );
                }
                return true;
            }
        }

        return false;
    }

    private function isRootCompileTimeAttribute(Node\Attribute $attribute, string $name): bool
    {
        return strcasecmp($this->getResolvedPhpName($attribute->name), $name) === 0;
    }

    private function getResolvedPhpName(Node\Name $name): string
    {
        $resolvedName = $name->getAttribute('resolvedName')
            ?? $name->getAttribute('namespacedName')
            ?? $name;

        return ltrim($resolvedName->toString(), '\\');
    }

    private function getExternalImportLibraryName(string $stubFile): string
    {
        $name = basename($stubFile, '.stub.php');
        $name = str_replace(['-', '*'], '_', $name);
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
            throw new SyntaxError('Invalid external import stub filename `' . basename($stubFile) . '`');
        }

        return $name;
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
                case 'Stmt_Nop':
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
            return Type::REF;
        }
        // Capture the late-bound parameter type keyword *before* resolveTypeDecl
        // runs, because resolveTypeDecl mutates the `self`/`static`/`parent` node
        // name to the declaring class when the method belongs to a trait.
        $typeKeyword = '';
        if ($param->type instanceof Node\Name) {
            $ptLower = strtolower($param->type->toString());
            if ($ptLower === 'self' || $ptLower === 'static' || $ptLower === 'parent') {
                $typeKeyword = $ptLower;
            }
        }
        [$type, $class] = $this->resolveTypeDecl($param->type, self::DECL_TYPE_OF_PARAM);
        $argInfo->undeclared = $param->type === null;
        if (
            $param->type !== null
            && !$param->type instanceof NullableType
            && !$param->type instanceof UnionType
            && !$param->type instanceof IntersectionType
        ) {
            $argInfo->explicitMixed = in_array(strtolower($this->parseIdentifier($param->type)), ['mixed', 'any'], true);
        }
        if ($class) {
            $argInfo->declaredClass = $class;
        }
        if ($class and !$this->hasInterface($class)) {
            $argInfo->class = $class;
        }
        // Record late-bound parameter type keywords so they can be re-resolved
        // to the consuming class when a trait method is flattened into a class.
        $argInfo->typeKeyword = $typeKeyword;
        return $type;
    }

    /**
     * @param $params array<Node\Param>
     */
    protected function parseParams(array $params, FunctionDef $functionDef): void
    {
        $list                          = [];
        $functionDef->argCountRequired = count($params);
        $lastRequiredIndex = -1;
        $lastRequiredName = '';
        $last = array_key_last($params);
        foreach ($params as $i => $param) {
            if (!$param->default && !$param->variadic) {
                $lastRequiredIndex = $i;
                if (is_string($param->var->name)) {
                    $lastRequiredName = $param->var->name;
                }
            }
        }

        foreach ($params as $i => $param) {
            if (!is_string($param->var->name)) {
                $this->fatalError($param, 'Parameter name must be a string');
            }
            $phpName = $param->var->name;
            $name = $this->escapeVarName($phpName);
            // Local stubs define C++ native functions and require explicit ABI types.
            // Generated external stubs may preserve an untyped PHP declaration as php::Var.
            if ($this->stubFile && $this->stubImportLibrary === '' && !$param->type) {
                throw new \RuntimeException('No type for ' . $phpName);
            }
            // 构造方法属性定义语法（Constructor Property Promotion）
            if ($param->isPromoted()) {
                if (!$this->classDef or !$this->methodDef or $this->methodDef->name !== '__construct') {
                    $this->fatalError($param, 'Promoted properties are not supported');
                }
                $nullable = $param->type instanceof NullableType;
                // Promoted property defaults belong to the constructor parameter,
                // not to the property default table. The property itself must stay
                // uninitialized until __construct assigns it.
                $this->addClassProperty($phpName, $param->flags, $param->type, null, $nullable, $param, true);
            }
            if ($param->variadic) {
                if ($i !== $last) {
                    $this->fatalError($param, 'Variadic parameters must be the last parameter');
                } elseif ($param->byRef) {
                    $this->fatalError($param, 'Variadic parameters cannot be passed by reference');
                }
            }
            if ($param->default && $i < $lastRequiredIndex) {
                $this->fatalError(
                    $param,
                    $this->getFunctionDisplayName($functionDef)
                    . '(): optional parameter `$' . $phpName . '` cannot be declared before required parameter `$'
                    . $lastRequiredName . '`'
                );
            }
            if ($this->method and $name === 'this_') {
                $this->fatalError($param, 'Cannot use `$this` as parameter of class method');
            }
            $argInfo = new ArgInfo();
            $type = $this->parseParameterType($param, $argInfo, $name);
            $argInfo->name = $name;
            $argInfo->phpName = $phpName;
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
                $list[] = Type::ARRAY . ' ' . $name;
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
            } elseif ($param->variadic) {
                // 变长参数可以视为空数组默认值
                $argInfo->default = '{}';
                $argInfo->defaultValue = new Node\Expr\Array_();
            }
            $functionDef->argInfoList[] = $argInfo;
        }
        $functionDef->params = implode(', ', $list);
        $functionDef->argCountRequired = $lastRequiredIndex + 1;
    }

    protected function getFunctionDisplayName(FunctionDef $functionDef): string
    {
        if ($this->class) {
            return $this->class . '::' . $functionDef->name;
        }
        return $functionDef->getNamespacedName();
    }

    protected function parseFunctionDecl(Node\Stmt\Function_|Node\Stmt\ClassMethod $v): FunctionDef
    {
        // Local stubs define C++ native functions and require an explicit ABI return type.
        // Generated external stubs may preserve an untyped PHP declaration as php::Var.
        if ($this->stubFile && $this->stubImportLibrary === '' && !$v->returnType) {
            // 以下魔术方法都不能声明返回值类型 __construct()/__destruct()/__clone()
            if (($this->method and !in_array($this->method, ['__construct', '__destruct', '__clone'])) or !$this->method) {
                $name = $this->class ? $this->class . '::' . $v->name : $v->name;
                $this->fatalError($v, 'The return type of the function `' . $name . '` must be specified');
            }
        }
        if ($this->method and $v->returnType !== null) {
            $methodName = $this->class . '::' . $this->method;
            if (in_array($this->method, ['__construct', '__destruct'], true)) {
                $this->fatalError($v, 'Method `' . $methodName . '()` cannot declare a return type');
            }
            if ($this->method === '__clone'
                and (!$v->returnType instanceof Node\Identifier or strtolower($v->returnType->name) !== 'void')) {
                $this->fatalError($v, 'Method `' . $methodName . '()` return type must be void when declared');
            }
        }

        $fnName = $this->parseIdentifier($v->name);
        // Capture the late-bound return type keyword *before* resolveTypeDecl runs,
        // because resolveTypeDecl mutates the `self`/`static`/`parent` node name to
        // the declaring class when the method belongs to a trait.
        $returnTypeKeyword = '';
        if ($v->returnType instanceof Node\Name) {
            $rtLower = strtolower($v->returnType->toString());
            if ($rtLower === 'self' || $rtLower === 'static' || $rtLower === 'parent') {
                $returnTypeKeyword = $rtLower;
            }
        }
        [$returnType, $class] = $this->resolveTypeDecl($v->returnType, self::DECL_TYPE_OF_RETURN);
        // 构造、析构、克隆方法不能有返回值
        if ($this->method and in_array($this->method, ['__construct', '__destruct', '__clone'])) {
            $returnType = Type::VOID;
        }

        $functionDef = new FunctionDef($fnName, $returnType, $this->namespace);
        $functionDef->mustUse = (bool) $v->getAttribute(FunctionAttributeLowering::MUST_USE_ATTRIBUTE, false);
        $functionDef->overrideRequired = (bool) $v->getAttribute(FunctionAttributeLowering::OVERRIDE_ATTRIBUTE, false);
        $functionDef->hot = (bool) $v->getAttribute(FunctionAttributeLowering::HOT_ATTRIBUTE, false);
        $functionDef->cold = (bool) $v->getAttribute(FunctionAttributeLowering::COLD_ATTRIBUTE, false);
        if ($functionDef->mustUse && $returnType === Type::VOID) {
            $this->fatalCompileTimeAttribute(
                $v,
                'MustUse',
                'MustUse cannot be applied to a function or method returning void',
            );
        }
        $functionDef->exported = !($this->classDef?->exported === false || $this->hasNoExportAttribute($v));
        $functionDef->returnClass = $class;
        // Record late-bound return type keywords so they can be re-resolved to
        // the consuming class when a trait method is flattened into a class.
        $functionDef->returnTypeKeyword = $returnTypeKeyword;
        $functionDef->stub = $this->stubFile;
        $functionDef->importLibrary = $this->stubImportLibrary;
        $functionDef->returnTypeUndeclared = $v->returnType === null;
        $functionDef->returnsByRef = $v->byRef;
        if ($this->containsYield($v)) {
            $this->prepareGeneratorFunction($v, $functionDef);
        }

        if (!$functionDef->generator
            && ($v->returnType instanceof NullableType
                || $v->returnType instanceof UnionType
                || $v->returnType instanceof IntersectionType)) {
            $typeInfo = $this->buildTypeCheckFromNode($v->returnType);
            if (!empty($typeInfo['check'])) {
                $functionDef->returnTypeCheck = $typeInfo['check'];
                $functionDef->returnTypeStr = $typeInfo['typeStr'];
                $functionDef->returnTypeNode = $v->returnType;
            }
        }

        if (!$this->method && $this->canOptimizeMultiReturn($v, $functionDef)) {
            $functionDef->multiReturnCount = count($v->stmts[array_key_last($v->stmts)]->expr->items);
            // The fixed tuple is an internal ABI detail. PHP and ordinary native
            // callers continue to observe an array return value.
            $functionDef->returnType = Type::ARRAY;
        }

        $this->parseParams($v->params, $functionDef);

        // main 函数，返回值必须为 void 类型，参数必须为空或者 argc, argv 两个参数
        if (!$this->class and !$this->namespace and $fnName === self::ENTRY_FUNCTION) {
            if (count($v->params) > 0) {
                if (count($v->params) != 2) {
                    $this->fatalError($v, 'The parameters of the main function must be `(int $argc, array $argv)`.');
                }
                if ($returnType !== Type::VOID) {
                    $this->fatalError($v, 'main function must return void');
                }
                if (!$this->checkArgType($functionDef->argInfoList[0]->type, Type::INT)) {
                    $this->fatalError($v, 'The first parameter of the main function must be of type `int`.');
                }
                if (!$this->checkArgType($functionDef->argInfoList[1]->type, Type::ARRAY)) {
                    $this->fatalError($v, 'The second parameter of the main function must be of type `array`.');
                }
            }
        }

        return $functionDef;
    }

    private function canOptimizeMultiReturn(Node\Stmt\Function_|Node\Stmt\ClassMethod $function, FunctionDef $functionDef): bool
    {
        if ($functionDef->stub || $functionDef->generator || $functionDef->returnsByRef
            || ($functionDef->returnType !== Type::ARRAY && !$functionDef->returnTypeUndeclared)
            || !$function->stmts) {
            return false;
        }

        $return = $function->stmts[array_key_last($function->stmts)] ?? null;
        if (!$return instanceof Node\Stmt\Return_ || !$return->expr instanceof Node\Expr\Array_
            || count($return->expr->items) < 2) {
            return false;
        }

        $returns = (new NodeFinder())->findInstanceOf($function->stmts, Node\Stmt\Return_::class);
        if (count($returns) !== 1) {
            return false;
        }

        foreach ($return->expr->items as $item) {
            if ($item === null || $item->key !== null || $item->unpack || $item->byRef) {
                return false;
            }
            $value = $item->value;
            if (($value instanceof Node\Expr\Variable && is_string($value->name))
                || $value instanceof Node\Scalar
                || $value instanceof Node\Expr\ConstFetch) {
                continue;
            }
            return false;
        }
        return true;
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
        $functionDef->attributeFactory = (bool) $v->getAttribute(
            RuntimeAttributeFactoryLowering::FACTORY_FUNCTION_ATTRIBUTE,
            false,
        );
        if ($functionDef->attributeFactory) {
            $functionDef->exported = false;
            $functionDef->attributeFactoryScope = (string) $v->getAttribute(
                RuntimeAttributeFactoryLowering::FACTORY_SCOPE_ATTRIBUTE,
                '',
            );
        }
        $functionDef->sourceFile = $this->file;
        $functionDef->startLine = $v->getStartLine();
        $this->addFunction($name, $functionDef);
        if ($this->methodDef) {
            $functionDef->method = true;
            $this->methodDef->functionDef = $functionDef;
        }
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
        if (isset($this->symbolDeclInFile[$fullClassNameLower])) {
            $this->fatalError($class, "Duplicate class `{$fullClassName}`");
        }

        $this->classDef = new ClassDef($this->class, $flags, $this->namespace);
        $this->classDef->exported = !$this->hasNoExportAttribute($class);
        $this->classDef->methodsForTarget = $this->parseMethodsForTarget($class);
        $this->addClass($fullClassName, $this->classDef);

        if (!empty($class->extends)) {
            $this->parentClass = $this->getNamespacedClassName($this->parseIdentifier($class->extends));
            $parentClassLower = strtolower($this->parentClass);
            if ($parentClassLower === $fullClassNameLower) {
                $this->fatalError($class, "Class {$fullClassName} cannot extend itself");
            }
            $this->symbols->setParent($fullClassNameLower, $parentClassLower);
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
        $this->symbolDeclInFile[$fullClassNameLower] = $this->file;

        if ($class instanceof Node\Stmt\Class_) {
            $generatedPrinter = null;
            $generatedArrayable = null;
            foreach ($class->getMethods() as $method) {
                if ($method->getAttribute(PrinterLowering::GENERATED_ATTRIBUTE)) {
                    $generatedPrinter = $method;
                }
                if ($method->getAttribute(ArrayableLowering::GENERATED_ATTRIBUTE)) {
                    $generatedArrayable = $method;
                }
            }
            if ($generatedPrinter !== null) {
                $this->classDef->printerGenerated = true;
                $this->classDef->printerFields = $generatedPrinter->getAttribute(PrinterLowering::FIELDS_ATTRIBUTE);
                $properties = $this->classDef->printerFields
                    ?? [...$this->parentPublicProperties($this->classDef->extends), ...ClassFieldSelection::ownPublicProperties($class)];
                PrinterLowering::rebuildGeneratedMethod(
                    $class,
                    $properties,
                    $this->classDef->printerFields,
                    $this->classStringProperties($this->classDef),
                );
            }
            if ($generatedArrayable !== null) {
                $this->classDef->arrayableGenerated = true;
                $this->classDef->arrayableFields = $generatedArrayable->getAttribute(ArrayableLowering::FIELDS_ATTRIBUTE);
                $properties = $this->classDef->arrayableFields
                    ?? [...$this->parentPublicProperties($this->classDef->extends), ...ClassFieldSelection::ownPublicProperties($class)];
                ArrayableLowering::rebuildGeneratedMethod(
                    $class,
                    $properties,
                    $this->classDef->arrayableFields,
                );
            }
        }

        // Property defaults may reference class constants declared later in the
        // class body. Collect every constant first so default-value validation
        // is independent of declaration order, matching PHP's class semantics.
        foreach ($class->stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\ClassConst) {
                $this->parseClassConstDef($stmt);
            }
        }

        $code = '';
        foreach ($class->stmts as $v) {
            $type = $v->getType();
            switch ($type) {
                case 'Stmt_ClassConst':
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

        // Trait members are later injected into the consuming class for stub
        // generation. Fully qualify every declared class type while the
        // trait's own namespace/import context is still active.
        if ($class instanceof Node\Stmt\Trait_) {
            foreach ($class->stmts as $v) {
                if ($v instanceof Node\Stmt\ClassMethod) {
                    $v->returnType = $this->upgradeToFullyQualifiedName($v->returnType);
                    foreach ($v->params as $param) {
                        $param->type = $this->upgradeToFullyQualifiedName($param->type);
                    }
                } elseif ($v instanceof Node\Stmt\Property || $v instanceof Node\Stmt\ClassConst) {
                    $v->type = $this->upgradeToFullyQualifiedName($v->type);
                }
            }
        }

        $this->resetClass();

        return $code;
    }

    /** @return list<string> */
    protected function parentPublicProperties(string $parent): array
    {
        if ($parent === '') {
            return [];
        }
        $classDef = $this->getClassDef($parent);
        if ($classDef === null) {
            return [];
        }
        $properties = $this->parentPublicProperties($classDef->extends);
        foreach ($classDef->properties as $property) {
            if ($property->isPublic() && !$property->isStatic()) {
                $properties[] = $property->name;
            }
        }
        return array_values(array_unique($properties));
    }

    /** @return list<string> */
    protected function selectableProperties(ClassDef $classDef): array
    {
        $properties = [];
        $parent = $classDef->extends;
        while ($parent !== '') {
            $parentDef = $this->getClassDef($parent);
            if ($parentDef === null) {
                break;
            }
            foreach ($parentDef->properties as $property) {
                if (!$property->isStatic() && !$property->isPrivate()) {
                    $properties[] = $property->name;
                }
            }
            $parent = $parentDef->extends;
        }
        foreach ($classDef->properties as $property) {
            if (!$property->isStatic()) {
                $properties[] = $property->name;
            }
        }
        return array_values(array_unique($properties));
    }

    /** @return list<string> */
    protected function classStringProperties(ClassDef $classDef): array
    {
        $types = [];
        if ($classDef->extends !== '') {
            $parent = $this->getClassDef($classDef->extends);
            if ($parent !== null) {
                foreach ($this->classStringProperties($parent) as $property) {
                    $types[$property] = true;
                }
            }
        }
        foreach ($classDef->properties as $property) {
            if (!$property->isStatic()) {
                $types[$property->name] = $property->type === Type::STR;
            }
        }
        return array_keys(array_filter($types));
    }

    protected function parseMethodsForTarget(Node\Stmt\Class_|Node\Stmt\Trait_|Node\Stmt\Enum_ $class): ?string
    {
        foreach ($class->attrGroups as $groupIndex => $group) {
            foreach ($group->attrs as $attributeIndex => $attribute) {
                if (!$this->isRootCompileTimeAttribute($attribute, 'MethodsFor')) {
                    continue;
                }
                if (!$class instanceof Node\Stmt\Class_) {
                    $this->fatalCompileTimeAttribute(
                        $class,
                        'MethodsFor',
                        'MethodsFor can only be applied to classes',
                        $attribute,
                    );
                }
                if (count($attribute->args) !== 1) {
                    $this->fatalCompileTimeAttribute(
                        $class,
                        'MethodsFor',
                        'MethodsFor expects exactly one target',
                        $attribute,
                    );
                }
                $target = $this->parseMethodsForTargetValue($attribute->args[0]->value, $attribute, $class);
                unset($group->attrs[$attributeIndex]);
                $group->attrs = array_values($group->attrs);
                if (empty($group->attrs)) {
                    unset($class->attrGroups[$groupIndex]);
                    $class->attrGroups = array_values($class->attrGroups);
                }
                return $target;
            }
        }
        return null;
    }

    private function parseMethodsForTargetValue(
        Node\Expr $value,
        NodeAbstract $errorNode,
        Node\Stmt\Class_ $class,
    ): string
    {
        if ($value instanceof Node\Scalar\String_ && $value->value === '*') {
            return '*';
        }
        if ($value instanceof Node\Expr\ClassConstFetch
            && $this->isNameExpr($value->class)
            && $this->isIdExpr($value->name)) {
            $targetClass = $this->getResolvedPhpName($value->class);
            $constant = $value->name->toString();
            if (strtolower($constant) === 'class') {
                return $targetClass;
            }
            $targets = [
                'Int' => Type::INT,
                'Float' => Type::FLOAT,
                'Bool' => Type::BOOL,
                'BigInt' => Type::BIGINT,
                'BigFloat' => Type::BIGFLOAT,
                'Decimal' => Type::DECIMAL,
                'String' => Type::STR,
                'Array' => Type::ARRAY,
                'Object' => Type::OBJECT,
                'Any' => Type::VAR,
                'Stream' => Type::STREAM,
                'Box' => Type::BOX,
            ];
            if (strcasecmp($targetClass, 'Type') === 0 && isset($targets[$constant])) {
                return $targets[$constant];
            }
        }
        $this->fatalCompileTimeAttribute(
            $class,
            'MethodsFor',
            "MethodsFor target must be '*', Type::*, or ClassName::class",
            $errorNode,
        );
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
        [$declaredType, $class] = $v->type
            ? $this->resolveTypeDecl($v->type, self::DECL_TYPE_OF_CONST)
            : [null, ''];

        foreach ($v->consts as $const) {
            $type = $declaredType;
            if ($type === null) {
                $type = match ($const->value->getType()) {
                    'Expr_Array' => Type::ARRAY,
                    'Scalar_String' => Type::STR,
                    default => Type::VAR,
                };
                // `::class` is a compile-time magic constant that always yields a string,
                // so a constant declared as `X = self::class` (or `Foo::class`) must be
                // typed as a string rather than a generic variant.
                if ($type === Type::VAR
                    && $const->value instanceof Node\Expr\ClassConstFetch
                    && strtolower((string) $const->value->name) === 'class') {
                    $type = Type::STR;
                }
                // A constant whose value references another class constant
                // (e.g. `X = ParentClass::Y` or `X = self::Y`) must take the referenced
                // constant's type. This keeps override compatibility checks and the C++
                // declaration correct, mirroring PHP where overriding an untyped constant
                // with a value of any (compatible) type is allowed.
                if ($type === Type::VAR
                    && $const->value instanceof Node\Expr\ClassConstFetch
                    && $const->value->class instanceof Node\Name) {
                    $refType = $this->resolveReferencedConstantType($const->value, $this->getFullClassName());
                    if ($refType !== null) {
                        $type = $refType;
                    }
                }
            }
            $constName = $this->parseIdentifier($const->name);
            if ($this->classDef->hasConstant($constName)) {
                $this->fatalError($v, "Duplicate constant `{$constName}`");
            }
            $constInfo = $this->parseClassLikeConstant($const, $flags, $type, $class, $declaredType);
            $constInfo->class = $class;
            $this->classDef->constants[$constInfo->name] = $constInfo;
        }
    }

    /**
     * Resolve the compile-time type of a class constant whose value is a
     * `ClassConstFetch` referencing another constant (e.g. `X = ParentClass::Y`
     * or `X = self::Y`). Returns the referenced constant's type, or null when
     * the reference cannot be resolved yet (for instance when the referenced
     * class has not been prepared). `::class` always resolves to a string.
     */
    protected function resolveReferencedConstantType(Node\Expr\ClassConstFetch $fetch, string $currentClass): ?string
    {
        $constName = $fetch->name->toString();
        if (strcasecmp($constName, 'class') === 0) {
            return Type::STR;
        }
        if (!($fetch->class instanceof Node\Name)) {
            return null;
        }
        $className = $fetch->class->toString();
        if (strcasecmp($className, 'self') === 0 || strcasecmp($className, 'static') === 0) {
            $targetClass = $currentClass;
        } elseif (strcasecmp($className, 'parent') === 0) {
            $targetClass = $this->getParentClass($currentClass);
        } else {
            $targetClass = $this->getNamespacedClassName($className);
        }
        if ($targetClass === '' || !$this->hasClass($targetClass)) {
            return null;
        }
        $def = $this->getClass($targetClass);
        if (!$def->hasConstant($constName)) {
            return null;
        }
        $refConst = $def->getConstant($constName);
        // Follow the chain in case the referenced constant is itself an
        // expression that resolves to another constant.
        if ($refConst->type !== Type::VAR) {
            return $refConst->type;
        }
        if ($refConst->valueExpr instanceof Node\Expr\ClassConstFetch) {
            return $this->resolveReferencedConstantType($refConst->valueExpr, $targetClass);
        }
        return null;
    }

    private function parseClassLikeConstant(Node\Const_ $const, int $flags, string $type, string $class = '', ?string $declaredType = null): ConstantDef
    {
        $constName = $this->parseIdentifier($const->name);
        $constValue = $this->parseIdentifier($const->value);

        $constInfo = new ConstantDef($constName, $flags, $type, $constValue);
        $constInfo->valueExpr = $const->value;
        $constInfo->declaredType = $declaredType;

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
    protected function addClassProperty(string $name, int $flags, ?NodeAbstract $typeNode, $defaultNode, bool $nullable, NodeAbstract $errorNode, bool $promoted = false): PropertyDef
    {
        $flags = $this->parseModifiers($flags);
        [$type, $class] = $this->resolveTypeDecl($typeNode, self::DECL_TYPE_OF_PROPERTY);

        $default = null;
        $arrayInitPlan = null;
        if ($defaultNode !== null) {
            $this->checkPropertyDefaultType($name, $typeNode, $defaultNode, $errorNode);
            if ($defaultNode instanceof Node\Expr\Array_) {
                $arrayInitPlan = $this->buildLiteralArrayInitPlan($defaultNode);
                $default = $arrayInitPlan->expr;
                // Only narrow the property type to `array` when the declared type
                // cannot already hold an array. `mixed`/`iterable`/union/nullable
                // types are represented as php::Var and can legally store an array,
                // so forcing `array` here would wrongly reject non-array assignments
                // (e.g. `mixed $value = []` followed by `$this->value = 123`).
                if ($type !== Type::VAR) {
                    $type = Type::ARRAY;
                }
            } else {
                $default = $this->parseIdentifier($defaultNode);
            }
        }

        if ($this->classDef->hasProperty($name)) {
            $this->fatalError($errorNode, "Duplicate property `{$name}`");
        }

        $propDef = new PropertyDef($name, $flags, $type, $default, $nullable);
        $propDef->readonly = (bool) (($flags | $this->classDef->flags) & Modifiers::READONLY);
        $propDef->class = $class;
        $propDef->arrayInitPlan = $arrayInitPlan;
        $propDef->promoted = $promoted;
        if ($typeNode instanceof NullableType || $typeNode instanceof UnionType || $typeNode instanceof IntersectionType) {
            $typeInfo = $this->buildTypeCheckFromNode($typeNode);
            $propDef->typeCheck = $typeInfo['check'];
            $propDef->typeStr = $typeInfo['typeStr'];
        }
        $this->classDef->properties[$name] = $propDef;
        return $propDef;
    }

    /**
     * Diagnose, during preprocessing, whether a property's default value is
     * compatible with its declared type.
     *
     * TypePHP rejects obvious mismatches such as `int $a = []` at compile time
     * instead of silently coercing the declared type or deferring to a runtime
     * TypeError, matching the static-compilation principles in CLAUDE.md.
     */
    protected function checkPropertyDefaultType(string $name, ?NodeAbstract $typeNode, NodeAbstract $defaultNode, NodeAbstract $errorNode): void
    {
        if ($typeNode === null) {
            // Untyped property accepts any default value.
            return;
        }

        $valueType = $this->detectDefaultValueType($defaultNode);
        if ($valueType === null) {
            // The value type is not statically decidable (e.g. user or class
            // constant references); leave it to later stages.
            return;
        }

        $allowed = $this->collectAllowedDefaultTypes($typeNode);
        if ($allowed === null) {
            // mixed / callable / otherwise unconstrained type declaration.
            return;
        }

        if (in_array($valueType, $allowed, true)) {
            return;
        }

        $className = $this->getFullClassName();
        $typeStr   = $this->propertyTypeDeclToString($typeNode);
        $this->fatalError(
            $errorNode,
            "Cannot use {$valueType} as default value for property {$className}::\${$name} of type {$typeStr}"
        );
    }

    /**
     * Determine the PHP value type of a constant expression used as a default
     * value. Returns one of int/float/string/true/false/array/null, or null when
     * the type cannot be decided statically.
     */
    protected function detectDefaultValueType(NodeAbstract $node, ?string $scopeClass = null, int $depth = 0): ?string
    {
        if ($depth > 16) {
            return null;
        }
        $scopeClass ??= $this->getFullClassName();

        switch ($node->getType()) {
            case 'Scalar_Int':
                return 'int';
            case 'Scalar_Float':
                return 'float';
            case 'Scalar_String':
            case 'Scalar_InterpolatedString':
            case 'Expr_BinaryOp_Concat':
                return 'string';
            case 'Expr_Array':
                return 'array';
            case 'Expr_UnaryMinus':
            case 'Expr_UnaryPlus':
                return $this->detectDefaultValueType($node->expr, $scopeClass, $depth + 1);
            case 'Expr_ConstFetch':
                return match (strtolower($node->name->toString())) {
                    'true'          => 'true',
                    'false'         => 'false',
                    'null'          => 'null',
                    default         => null,
                };
            case 'Expr_ClassConstFetch':
                if (!$node->class instanceof Node\Name || !$node->name instanceof Node\Identifier) {
                    return null;
                }
                $constName = $node->name->toString();
                if (strcasecmp($constName, 'class') === 0) {
                    return 'string';
                }
                $className = $node->class->toString();
                if (strcasecmp($className, 'self') === 0 || strcasecmp($className, 'static') === 0) {
                    $targetClass = $scopeClass;
                } elseif (strcasecmp($className, 'parent') === 0) {
                    $targetClass = $this->getParentClass($scopeClass);
                } else {
                    $targetClass = $this->getNamespacedClassName($className);
                }
                if ($targetClass === '' || !$this->hasClass($targetClass)) {
                    return null;
                }
                $targetDef = $this->getClass($targetClass);
                if (!$targetDef->hasConstant($constName)) {
                    return null;
                }
                return $this->detectDefaultValueType(
                    $targetDef->getConstant($constName)->valueExpr,
                    $targetClass,
                    $depth + 1
                );
            default:
                try {
                    $value = (new ConstExprEvaluator(
                        static function (Node\Expr $expr): never {
                            throw new \RuntimeException('Unresolved constant expression');
                        }
                    ))->evaluateDirectly($node);
                } catch (\Throwable) {
                    return null;
                }
                return match (true) {
                    is_int($value) => 'int',
                    is_float($value) => 'float',
                    is_string($value) => 'string',
                    $value === true => 'true',
                    $value === false => 'false',
                    is_array($value) => 'array',
                    $value === null => 'null',
                    default => null,
                };
        }
    }

    /**
     * Collect the set of value types accepted as a default for a declared type
     * node. Returns null when the type imposes no statically-checkable
     * constraint (mixed / callable / unknown).
     *
     * @return array<int, string>|null
     */
    protected function collectAllowedDefaultTypes(NodeAbstract $typeNode): ?array
    {
        if ($typeNode instanceof NullableType) {
            $inner = $this->collectAllowedDefaultTypes($typeNode->type);
            if ($inner === null) {
                return null;
            }
            return array_values(array_unique(array_merge($inner, ['null'])));
        }

        if ($typeNode instanceof UnionType) {
            $all = [];
            foreach ($typeNode->types as $sub) {
                $part = $this->collectAllowedDefaultTypes($sub);
                if ($part === null) {
                    // A mixed-like member accepts any default value.
                    return null;
                }
                $all = array_merge($all, $part);
            }
            return array_values(array_unique($all));
        }

        if ($typeNode instanceof IntersectionType) {
            // Intersection types are object-only; no scalar/array default valid.
            return [];
        }

        return match (strtolower($this->parseIdentifier($typeNode))) {
            'int'                      => ['int'],
            'float', 'double'          => ['float', 'int'], // int coerces to float
            'string'                   => ['string'],
            'bool'                     => ['true', 'false'],
            'true'                     => ['true'],
            'false'                    => ['false'],
            'array'                    => ['array'],
            'iterable'                 => ['array'],
            'null'                     => ['null'],
            'object'                   => [], // no literal object default exists
            'self', 'parent', 'static' => [],
            'mixed'                    => null,
            'callable'                 => null, // string/array/closure — not checkable
            default                    => [],   // class type: only null via ?Type
        };
    }

    protected function propertyTypeDeclToString(NodeAbstract $typeNode): string
    {
        if ($typeNode instanceof NullableType) {
            return '?' . $this->propertyTypeDeclToString($typeNode->type);
        }
        if ($typeNode instanceof UnionType) {
            $parts = [];
            foreach ($typeNode->types as $t) {
                $parts[] = $this->propertyTypeDeclToString($t);
            }
            return implode('|', $parts);
        }
        if ($typeNode instanceof IntersectionType) {
            $parts = [];
            foreach ($typeNode->types as $t) {
                $parts[] = $this->propertyTypeDeclToString($t);
            }
            return implode('&', $parts);
        }
        return $this->parseIdentifier($typeNode);
    }

    protected function parseClassPropertyDef(Node\Stmt\Property $v): void
    {
        $oriCtx = $this->context;
        $this->context = $this->classDef->propertyContext;
        $nullable = $v->type instanceof NullableType;

        foreach ($v->props as $prop) {
            $propName = $this->parseIdentifier($prop->name);
            $propDef = $this->addClassProperty($propName, $v->flags, $v->type, $prop->default, $nullable, $v);
            foreach ($v->hooks as $hook) {
                $kind = strtolower($hook->name->toString());
                if ($kind === 'get') {
                    $propDef->getter = PropertyHookLowering::getterName($propName);
                } elseif ($kind === 'set') {
                    $propDef->setter = PropertyHookLowering::setterName($propName);
                }
            }
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
            $this->methodDef->node = $v;
            if ($this->classDef->hasMethod($name)) {
                $generatedBy = $v->getAttribute(CompileTimeAttributeDiagnostic::GENERATED_BY);
                $generatedTarget = $v->getAttribute(CompileTimeAttributeDiagnostic::GENERATED_TARGET);
                if (is_string($generatedBy) && $generatedTarget instanceof Node) {
                    $this->fatalCompileTimeAttribute(
                        $generatedTarget,
                        $generatedBy,
                        "Duplicate method `{$this->method}`",
                        $generatedTarget,
                        null,
                        $this->classDef->getMethod($name)->node,
                    );
                }
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
            $this->methodDef->node = $v;
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
        while (($parentClass = $this->symbols->parent($fullClassNameLower)) !== '') {
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
        $this->symbols->putInterface($this->escapeClass($interfaceName), $this->interfaceDef);
        $this->interfacesDefineInFile[$interfaceName] = $this->interfaceDef;

        foreach ($v->stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\ClassConst) {
                foreach ($stmt->consts as $const) {
                    $constName = $this->parseIdentifier($const->name);
                    if ($this->interfaceDef->hasConstant($constName)) {
                        $this->fatalError($stmt, "Duplicate constant `{$constName}`");
                    }
                    if ($stmt->type) {
                        [$type, $class] = $this->resolveTypeDecl($stmt->type, self::DECL_TYPE_OF_CONST);
                    } else {
                        $class = '';
                        $type = match ($const->value->getType()) {
                            'Expr_Array' => Type::ARRAY,
                            'Scalar_String' => Type::STR,
                            default => Type::VAR,
                        };
                    }
                    $constInfo = $this->parseClassLikeConstant($const, $this->parseModifiers($stmt->flags), $type, $class, $stmt->type ? $type : null);
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
                $methodDef->node = $stmt;
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
