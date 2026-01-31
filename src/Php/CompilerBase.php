<?php
/**
 * This file is part of Swoole-Compiler(AOT).
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace PhpAot\Php;

use League\CLImate\CLImate;
use PhpParser\Modifiers;
use PhpParser\Node;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\NullableType;
use PhpParser\Node\Scalar\MagicConst;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\UnionType;
use PhpParser\NodeAbstract;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter;

class CompilerBase extends \PhpAot\Core\Translator
{
    use AstNodeType;
    use FuncCallOptimizer;

    public const string TYPE_VAR = 'php::Var';

    public const string TYPE_BOOL = 'php::Bool';

    public const string TYPE_INT = 'php::Int';

    public const string TYPE_FLOAT = 'php::Float';
    public const string TYPE_OBJECT = 'php::Object';
    public const string TYPE_ARRAY = 'php::Array';
    public const string TYPE_STR = 'php::Str';
    public const string TYPE_REF = 'php::Ref';
    public const string TYPE_VOID = 'void';
    public const string VALUE_NAN = 'std::numeric_limits<double>::quiet_NaN()';
    public const string VALUE_INF = 'std::numeric_limits<double>::infinity()';
    public const string LITERAL_STRINGS = '_literal_strings';
    public const string CLASS_MAP = 'class_map';
    public const string FUNC_MAP = 'func_map';
    public const string EXPR_VARIABLE = 'Expr_Variable';

    public const string EXPR_NEW = 'Expr_New';

    public const string EXPR_ARRAY_DIM_FETCH = 'Expr_ArrayDimFetch';

    public const string NAMESPACE_SEPARATOR = '__';

    public const string PREFIX = 'php_';
    protected string $phpxDir = '~/workspace/projects/phpx';
    protected string $lang = 'PHP';
    protected string $cppCompiler = 'g++';
    protected array $arguments = [];
    protected array $literalStrings = [];
    protected int $literalStringIndex = 0;
    protected int $tmpVarIndex = 0;
    protected int $classIndex = 0;

    /**
     * @var array<string, int>
     */
    protected array $classMap = [];
    protected int $funcIndex = 0;
    protected array $funcMap = [];
    protected array $zendTypeMap = [
        'int'    => self::TYPE_INT,
        'float'  => self::TYPE_FLOAT,
        'bool'   => self::TYPE_BOOL,
        'void'   => self::TYPE_VOID,
        'string' => self::TYPE_STR,
        'array'  => self::TYPE_ARRAY,
        'object' => self::TYPE_OBJECT,
        'mixed'  => self::TYPE_VAR,
    ];
    protected array $globalHeaders = [
        'phpx.h',
        'phpx_helper.h',
        'phpx_func.h',
        'php_func_decl.h',
        'php_global_var_decl.h',
        'php_aot_helper.h',
    ];
    protected array $localHeaders = [];
    protected array $nativeFunctions = [];
    protected array $internalFunctions = [];
    protected array $nativeConstants = [];
    protected array $functionDeclInFile = [];
    protected array $functionCallInFile = [];
    protected array $redoAfterDeclare = [];
    protected int $optimizeLevel = 0;
    protected string $buildMode = 'bin';
    protected string $cxxflags = '';
    protected string $ldflags = '';
    protected int $floatPrecision = 17;
    protected bool $debugInfo = true;
    protected bool $noLiteralStrings = false;
    protected bool $useCppNamespace = false;
    protected string $file;
    protected string $dir;

    /**
     * 原始值，可能包含 `\\` 多层空间.
     */
    protected string $namespace = '';
    protected string $method = '';
    protected string $function = '';
    protected array $useNamespaces = [];
    protected array $useAliases = [];
    protected array $useFunctions = [];

    /**
     * 原始类名，不包含命名空间.
     */
    protected string $class = '';
    protected string $interface = '';

    /**
     * key 类名，包含命名空间
     * @var array<string, ClassDef>
     */
    protected array $classes = [];
    protected array $interfaces = [];

    /**
     * @var array<string, FunctionDef>
     */
    protected array $functions = [];

    /**
     * @var array<string, ClassDef>
     */
    protected array $classesDefineInFile = [];

    /**
     * @var array<string, InterfaceDef>
     */
    protected array $interfacesDefineInFile = [];

    /**
     * @var array<string, FunctionDef>
     */
    protected array $functionDefineInFile = [];

    /**
     * @var array<string>
     */
    protected array $classCeList = [];
    protected array $classCeInfo = [];
    protected FunctionDef $functionDef;
    protected ClassDef $classDef;
    protected InterfaceDef $interfaceDef;
    protected array $superGlobalVars = [
        '_GET'     => self::TYPE_ARRAY,
        '_POST'    => self::TYPE_ARRAY,
        '_COOKIE'  => self::TYPE_ARRAY,
        '_SERVER'  => self::TYPE_ARRAY,
        '_FILES'   => self::TYPE_ARRAY,
        '_SESSION' => self::TYPE_ARRAY,
        '_REQUEST' => self::TYPE_ARRAY,
    ];
    protected array $globalVars = [];

    /**
     * @var array<string, string>
     */
    protected array $objects = [];
    protected array $localVars = [];
    protected array $objectWrappers = [];
    protected bool $strictTypes = false;
    protected string $rootPath;
    protected string $buildDir;
    protected int $debugLine = 0;
    protected CLImate $climate;
    protected array $beforeStmtLines = [];
    protected array $afterStmtLines = [];
    protected bool $inLoop = false;

    /**
     * 赋值表达式的左值，写操作，右值为读操作.
     */
    protected bool $inAssignExpr = false;
    protected bool $stubFile = false;
    protected bool $enableProfiler = false;
    protected Parser $parser;

    public function __construct(string $rootPath)
    {
        $this->rootPath = $rootPath;
        $this->parser   = (new ParserFactory())->createForNewestSupportedVersion();
        // $this->prettyPrinter = new PrettyPrinter\Standard;
        $this->setBuildDir($rootPath . '/build');
        $climate       = new CLImate();
        $this->climate = $climate;
        //        $this->noLiteralStrings = $climate->arguments->get('no-literal-strings');
    }

    public function setPhpxDir($dir): void
    {
        $this->phpxDir = $dir;
    }

    public function save(string $code, string $file): void
    {
        $this->writeFile($file, $code);
        $this->formatCppCode($file);
    }

    public function getLine($node): int
    {
        return $node->getLine();
    }

    public function getType($node): string
    {
        return $node->getType();
    }

    public function getTypeFromZendType(string $type): string
    {
        return $this->zendTypeMap[$type] ?? self::TYPE_VAR;
    }

    public function isNativeFunction(string $name): bool
    {
        return isset($this->nativeFunctions[$name]);
    }

    public function isTypedObject(string $object): bool
    {
        return isset($this->objects[$object]);
    }

    public function parseExpr(mixed $expr)
    {
        $type = $expr->getType();
        $this->writeLog('Line ' . $this->getLine($expr) . ': ' . $type);
        if ($expr->getLine() === $this->debugLine) {
            dump($expr);
        }
        switch ($type) {
            case 'Expr_Isset':
                return $this->parseIsset($expr);
            case 'Expr_Empty':
                return $this->parseEmpty($expr);
            case 'Expr_Assign':
                $result = $this->parseAssign($expr);

                return $result;
            case 'Expr_AssignRef':
                $result = $this->parseAssignRef($expr);

                return $result;
            case 'Expr_Print':
                return $this->parsePrint($expr);
            case 'Expr_BinaryOp_Equal':
                return $this->parseBinaryOpEqual($expr);
            case 'Expr_BinaryOp_NotEqual':
                return $this->parseBinaryOpNotEqual($expr);
            case 'Expr_BinaryOp_Identical':
                return $this->parseBinaryOpIdentical($expr);
            case 'Expr_BinaryOp_NotIdentical':
                return $this->parseBinaryOpNotIdentical($expr);
            case 'Expr_BooleanNot':
                return $this->parseBooleanNot($expr);
            case 'Expr_BinaryOp_Plus':
                return $this->parseBinaryOpPlus($expr);
            case 'Expr_BinaryOp_Div':
                return $this->parseBinaryOpDiv($expr);
            case 'Expr_BinaryOp_Smaller':
                return $this->parseBinaryOpSmaller($expr);
            case 'Expr_BinaryOp_SmallerOrEqual':
                return $this->parseBinaryOpSmallerOrEqual($expr);
            case 'Expr_BinaryOp_GreaterOrEqual':
                return $this->parseBinaryOpGreaterOrEqual($expr);
            case 'Expr_BinaryOp_Spaceship':
                return $this->parseBinaryOpSpaceship($expr);
            case 'Expr_PreInc':
                return $this->parsePreInc($expr);
            case 'Expr_PostInc':
                return $this->parsePostInc($expr);
            case 'Expr_PreDec':
                return $this->parsePreDec($expr);
            case 'Expr_PostDec':
                return $this->parsePostDec($expr);
            case 'Expr_AssignOp_Plus':
                return $this->parseAssignOpPlus($expr);
            case 'Expr_AssignOp_Minus':
                return $this->parseAssignOpMinus($expr);
            case 'Expr_AssignOp_Mul':
                return $this->parseAssignOpMul($expr);
            case 'Expr_AssignOp_Div':
                return $this->parseAssignOpDiv($expr);
            case 'Expr_AssignOp_Mod':
                return $this->parseAssignOpMod($expr);
            case 'Expr_AssignOp_Concat':
                return $this->parseAssignOpConcat($expr);
            case 'Expr_AssignOp_ShiftRight':
                return $this->parseAssignOpShiftRight($expr);
            case 'Expr_AssignOp_BitwiseAnd':
                return $this->parseAssignOpBitwiseAnd($expr);
            case 'Expr_AssignOp_BitwiseXor':
                return $this->parseAssignOpBitwiseXor($expr);
            case 'Expr_AssignOp_Pow':
                return $this->parseAssignOpPow($expr);
            case 'Expr_BinaryOp_Mul':
                return $this->parseBinaryOpMul($expr);
            case 'Expr_BinaryOp_Concat':
                return $this->parseBinaryOpConcat($expr);
            case 'Expr_BinaryOp_Greater':
                return $this->parseBinaryOpGreater($expr);
            case 'Expr_BinaryOp_LogicalAnd':
            case 'Expr_BinaryOp_BooleanAnd':
                return $this->parseBinaryOpLogicalAnd($expr);
            case 'Expr_BinaryOp_LogicalOr':
            case 'Expr_BinaryOp_BooleanOr':
                return $this->parseBinaryOpLogicalOr($expr);
            case 'Expr_BinaryOp_LogicalXor':
                return $this->parseBinaryOpLogicalXor($expr);
            case 'Expr_BinaryOp_Minus':
                return $this->parseBinaryOpMinus($expr);
            case 'Expr_Array':
                return $this->parseArray($expr);
            case self::EXPR_ARRAY_DIM_FETCH:
                return $this->parseArrayDimFetch($expr, $this->inAssignExpr);
            case 'Expr_PropertyFetch':
                return $this->parsePropertyFetch($expr, $this->inAssignExpr);
            case 'Expr_BinaryOp_ShiftLeft':
                return $this->parseBinaryOpShiftLeft($expr);
            case 'Expr_BinaryOp_ShiftRight':
                return $this->parseBinaryOpShiftRight($expr);
            case 'Expr_BinaryOp_BitwiseAnd':
                return $this->parseBinaryOpBitwiseAnd($expr);
            case 'Expr_BinaryOp_BitwiseOr':
                return $this->parseBinaryOpBitwiseOr($expr);
            case 'Expr_BinaryOp_BitwiseXor':
                return $this->parseBinaryOpBitwiseXor($expr);
            case 'Expr_BitwiseNot':
                return $this->parseBitwiseNot($expr);
            case 'Expr_BinaryOp_Mod':
                return $this->parseBinaryOpMod($expr);
            case 'Expr_BinaryOp_Pow':
                return $this->parseBinaryOpPow($expr);
            case 'Expr_Ternary':
                return $this->parseTernary($expr);
            case 'Expr_FuncCall':
                return $this->parseFuncCall($expr);
            case 'Expr_MethodCall':
                return $this->parseMethodCall($expr);
            case 'Expr_StaticCall':
                return $this->parseStaticCall($expr);
            case 'Expr_StaticPropertyFetch':
                return $this->parseStaticPropertyFetch($expr);
            case 'Expr_ClassConstFetch':
                return $this->parseClassConstFetch($expr);
            case 'Expr_Include':
                return $this->parseInclude($expr);
            case 'Expr_Eval':
                return $this->parseEval($expr);
            case 'Expr_New':
                return $this->parseNew($expr);
            case 'Expr_Clone':
                return $this->parseClone($expr);
            case 'Expr_Instanceof':
                return $this->parseInstanceof($expr);
            case 'Expr_Throw':
                return $this->parseThrow($expr);
            case 'Expr_ShellExec':
                return $this->parseShellExec($expr);
            case 'Name_FullyQualified':
                return $expr->name;
            case 'Scalar_Int':
            case 'Scalar_Float':
            case 'Scalar_String':
            case self::EXPR_VARIABLE:
                return $this->parseIdentifier($expr);
            case 'Scalar_MagicConst_File':
            case 'Scalar_MagicConst_Dir':
            case 'Scalar_MagicConst_Line':
            case 'Scalar_MagicConst_Function':
            case 'Scalar_MagicConst_Method':
            case 'Scalar_MagicConst_Class':
                return $this->parseMagicConst($expr);
            case 'Scalar_InterpolatedString':
                return $this->parseInterpolatedString($expr);
            case 'Expr_Cast_Int':
                return $this->parseCastInt($expr);
            case 'Expr_Cast_Double':
                return $this->parseCastDouble($expr);
            case 'Expr_Cast_Bool':
                return $this->parseCastBool($expr);
            case 'Expr_Cast_String':
                return $this->parseCastString($expr);
            case 'Expr_Cast_Array':
                return $this->parseCastArray($expr);
            case 'Expr_Cast_Object':
                return $this->parseCastObject($expr);
            case 'Expr_ConstFetch':
                return $this->parseConstFetch($expr);
            case 'Expr_UnaryMinus':
                return $this->parseUnaryMinus($expr);
            case 'Expr_UnaryPlus':
                return $this->parseUnaryPlus($expr);
            case 'InterpolatedStringPart':
                return $this->parseInterpolatedStringPart($expr);
            case 'Expr_ErrorSuppress':
                return $this->parseErrorSuppress($expr);
            case 'Expr_Exit':
                return $this->parseExit($expr);
            default:
                abort($expr);
        }
    }

    public function isClosedCall($expr, $call): bool
    {
        if ($call === '') {
            if (!str_starts_with($expr, '(')) {
                return false;
            }
            $startPos = 0;
        } else {
            if (!str_starts_with($expr, $call . '(')) {
                return false;
            }
            $startPos = strlen($call);
        }

        $bracketCount = 0;
        $length       = strlen($expr);

        for ($i = $startPos; $i < $length; $i++) {
            $char = $expr[$i];
            if ($char === '(') {
                $bracketCount++;
            } elseif ($char === ')') {
                $bracketCount--;
                if ($bracketCount === 0) {
                    return $i === $length - 1;
                }
            }
        }

        return false;
    }

    public function stop(string $string): void
    {
        $this->climate->red($string . "\n");
        exit(1);
    }

    public function genTmpVarName(): string
    {
        return 'tmp_var_' . $this->tmpVarIndex++;
    }

    public function writeFile(string $file, string $content): void
    {
        $dir = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        if (!file_put_contents($file, $content)) {
            throw new \RuntimeException('Can not write file: ' . $file);
        }
    }

    public function getIncludeDir(): string
    {
        return $this->getBuildDir() . '/include';
    }

    public function getBuildDir(): string
    {
        return $this->buildDir;
    }

    protected function isSuperGlobal(string $var): bool
    {
        if (isset($this->superGlobalVars[$var])) {
            return true;
        }
        return false;
    }

    protected function removeCommonPrefix(string $short, string $long): string
    {
        $len       = min(strlen($short), strlen($long));
        $prefixLen = 0;

        for ($i = 0; $i < $len; $i++) {
            if ($short[$i] === $long[$i]) {
                $prefixLen++;
            } else {
                break;
            }
        }

        return substr($long, $prefixLen);
    }

    protected function getVarType(string $name): string
    {
        if ($this->hasLocalVar($name)) {
            return $this->localVars[$name];
        }
        if ($this->hasLocalVar($name)) {
            return $this->globalVars[$name];
        }

        return self::TYPE_VAR;
    }

    protected function resetFunction(): void
    {
        $this->localVars      = [];
        $this->arguments      = [];
        $this->objectWrappers = [];
        $this->tmpVarIndex    = 0;
        $this->inLoop         = false;
        $this->function       = '';
    }

    protected function resetClass(): void
    {
        $this->class     = '';
        $this->interface = '';
        $this->method    = '';
    }

    protected function resetFile(): void
    {
        $this->indentLevel            = 0;
        $this->strictTypes            = false;
        $this->classesDefineInFile    = [];
        $this->interfacesDefineInFile = [];
        $this->functionDefineInFile   = [];
    }

    protected function resetNamespace(): void
    {
        $this->useNamespaces = [];
        $this->useFunctions  = [];
        $this->namespace     = '';
    }

    protected function getFunctionName(FunctionLike $v): string
    {
        return $this->getNativeName($this->parseIdentifier($v->name), $this->namespace, $this->class);
    }

    protected function getNamespacedClassName(string $class): string
    {
        $ns2 = explode('\\', trim($class, '\\'));

        if (isset($this->useAliases[$ns2[0]])) {
            $ns = '\\' . $this->useAliases[$ns2[0]];
            _return:
            if (count($ns2) > 1) {
                $ns .= '\\' . implode('\\', array_slice($ns2, 1));
            }

            return $ns;
        }

        foreach ($this->useNamespaces as $useNamespace) {
            $ns1 = explode('\\', trim($useNamespace, '\\'));
            if ($ns1[array_key_last($ns1)] === $ns2[0]) {
                $ns = '\\' . implode('\\', $ns1);
                goto _return;
            }
        }

        $currentNamespace = $this->namespace;
        if (!empty($currentNamespace)) {
            return '\\' . trim($currentNamespace, '\\') . '\\' . $class;
        }

        return '\\' . $class;
    }

    protected function getPropertyOffset(string $property, string $class, string $namespace = ''): string
    {
        return $this->getNativeName('property_offset_' . $property, $namespace, $class);
    }

    protected function getNativeName(string $fn, string $ns = '', string $class = ''): string
    {
        $names[] = $this->escapeName($fn);
        if ($ns) {
            $names[] = $this->escapeNamespace($ns);
        }
        if ($class) {
            $names[] = $this->escapeClass($class);
        }

        return implode(self::NAMESPACE_SEPARATOR, array_reverse($names));
    }

    protected function getClassEntryPtr(string $className): string
    {
        if (isset($this->classMap[$className])) {
            $id = $this->classMap[$className];
        } else {
            $id = $this->classIndex++;
            $this->classMap[$className] = $id;
        }

        return 'php_get_class(' . $id . ', ' . $this->getLiteralString($className) . ')';
    }

    protected function getFuncPtr(string $funcName, bool $macro = true): string
    {
        if (isset($this->funcMap[$funcName])) {
            $id = $this->funcMap[$funcName];
        } else {
            $id = $this->funcIndex++;
            $this->funcMap[$funcName] = $id;
        }
        if ($macro) {
            return $id . ', ' . $this->getLiteralString($funcName);
        } else {
            return 'php_get_func(' . $id . ', ' . $this->getLiteralString($funcName) . ')';
        }
    }

    protected function parseFunctionDeclaration(Node\Stmt\Function_|Node\Stmt\ClassMethod $v): FunctionDef
    {
        // .stub 存根定义 C++ Native 函数，必须设置返回值类型
        if (!$v->returnType && $this->stubFile) {
            throw new \Exception('No return type for ' . $v->name);
        }
        $returnType        = $v->returnType ? $this->getTypeFromZendType($this->parseIdentifier($v->returnType)) : self::TYPE_VOID;
        $functionDef       = new FunctionDef($this->parseIdentifier($v->name), $returnType);
        $this->functionDef = $functionDef;
        $this->parseParams($v->params, $functionDef);

        return $functionDef;
    }

    protected function parseFunction(FunctionLike $v): string
    {
        $this->resetFunction();
        $this->function = $this->parseIdentifier($v->name);
        $name           = $this->getFunctionName($v);
        if (isset($this->nativeFunctions[$name])) {
            $this->functionDef = $this->nativeFunctions[$name];
        } else {
            $this->nativeFunctions[$name] = $this->parseFunctionDeclaration($v);
            if (isset($this->redoAfterDeclare[$name])) {
                unset($this->redoAfterDeclare[$name]);
                $this->climate->cyan('Received redo request, retrying...');
                throw new RedoException();
            }
        }

        if ($this->class) {
            $this->arguments['this_'] = self::TYPE_OBJECT;
            $this->addLocalVar('this_', self::TYPE_OBJECT);
        } else {
            $this->functions[$name]            = $this->functionDef;
            $this->functionDefineInFile[$name] = $this->functionDef;
        }

        foreach ($this->functionDef->argInfoList as $argInfo) {
            $this->arguments[$argInfo->name] = $argInfo->type;
            if (!$this->hasLocalVar($argInfo->name)) {
                $this->addLocalVar($argInfo->name, $argInfo->type);
            }
        }

        if ($v->stmts) {
            $this->indentLevel++;
            $stmts = $this->parseStmts($v->stmts);
            $this->indentLevel--;
        } else {
            $stmts = '';
        }

        $functionDeclCode = $this->getReturnType() . ' ' . self::PREFIX . $name . '(';
        if ($this->class) {
            $functionDeclCode .= self::TYPE_OBJECT . ' &this_';
            if ($this->functionDef->params) {
                $functionDeclCode .= ', ';
            }
        }
        $functionDeclCode .= $this->functionDef->params . ')';

        $code = $functionDeclCode . ' {' . PHP_EOL;
        $this->indentLevel++;
        foreach ($this->localVars as $name => $type) {
            if (isset($this->arguments[$name])) {
                continue;
            }
            $code .= $this->getIndent() . $type . ' ' . $name . ';' . PHP_EOL;
        }
        $code .= "\n";
        $this->indentLevel--;
        $code .= $stmts;
        $code .= "}\n";

        $this->resetFunction();

        return $code;
    }

    protected function writeLog($msg)
    {
        if ($this->verbose) {
            echo $msg . PHP_EOL;
        }
    }

    protected function getLiteralString(string $string): string
    {
        if ($this->noLiteralStrings) {
            return '"' . $this->escapeString($string) . '"';
        }
        $index = $this->literalStrings[$string] ?? $this->addLiteralString($string);
        return self::LITERAL_STRINGS . '[' . $index . ']';
    }

    protected function parseScalar(Node\Scalar $expr)
    {
        $type = $expr->getType();
        switch ($type) {
            case 'Scalar_Int':
                return $expr->value . 'L';
            case 'Scalar_Float':
                return $this->parseScalarFloat($expr);
            case 'Scalar_String':
                return $this->getLiteralString($expr->value);
            default:
                abort($expr);
        }
    }

    protected function parseIdentifier(Node $expr): string
    {
        $type = $expr->getType();
        switch ($type) {
            case self::EXPR_VARIABLE:
                if (is_object($expr->name) and $this->isVarExpr($expr->name)) {
                    $this->fatalError($expr, 'The `$$` syntax is not supported');
                }
                if ($this->isSuperGlobal($expr->name) and !$this->hasGlobalVar($expr->name)) {
                    $this->addGlobalVar($expr->name, $this->superGlobalVars[$expr->name]);
                }
                return $this->escapeVarName($expr->name);
            case 'Name':
            case 'VarLikeIdentifier':
            case 'Identifier':
                return $expr->name;
            case 'Scalar_Int':
            case 'Scalar_Float':
            case 'Scalar_String':
                return $this->parseScalar($expr);
            case 'Expr_ConstFetch':
                return $this->parseConstFetch($expr);
            case 'Expr_Assign':
            case 'Expr_AssignRef':
                if (!$this->isVarExpr($expr->var)) {
                    $this->fatalError($expr, 'When an assignment expression serves as an rvalue, it must be an assignment of a variable');
                }
                return $this->parseExpr($expr);
            default:
                return $this->parseExpr($expr);
        }
    }

    protected function parseParams($params, FunctionDef $functionDef): void
    {
        $list                          = [];
        $functionDef->argCountRequired = count($params);
        foreach ($params as $param) {
            // .stub 存根定义 C++ Native 函数，必须设置函数的参数类型
            if ($this->stubFile and !$param->type) {
                throw new \RuntimeException('No type for ' . $this->parseIdentifier($param->var));
            }
            $name          = $this->parseIdentifier($param->var);
            $type          = $this->parseParameterType($param, $name);
            $list[]        = $type . ' ' . $name;
            $argInfo       = new ArgInfo();
            $argInfo->name = $name;
            $argInfo->type = $type;
            if (isset($param->default)) {
                $functionDef->argCountRequired = count($list) - 1;
                $argInfo->default              = $this->parseIdentifier($param->default);
            }
            $functionDef->argInfoList[] = $argInfo;
        }
        $functionDef->params = implode(', ', $list);
    }

    protected function getComment(Node\Stmt $v, string $class): string
    {
        if ($class == 'Stmt_Expression') {
            $class = 'Stmt_Expression(' . $v->expr->getType() . ')';
        }

        return $this->getIndent() . '// ' . $class . ' [' . $v->getStartLine() . ':' . $v->getEndLine() . ']';
    }

    /**
     * 在 for/foreach 等包含子语句的语句，之前检查当前待添加的代码是否为空，
     * 如果不为空，需要将语句追加到 {} 作用域符号之前.
     */
    protected function parseBeforeStmtLines(): string
    {
        if ($this->beforeStmtLines) {
            $code                  = implode(PHP_EOL, $this->beforeStmtLines);
            $this->beforeStmtLines = [];

            return $code . PHP_EOL;
        }
        return '';
    }

    protected function parseStmts(array $stmts): string
    {
        $lines     = [];
        $inLoopTop = $this->inLoop;
        foreach ($stmts as $v) {
            $class                 = $v->getType();
            $this->beforeStmtLines = [];
            $this->afterStmtLines  = [];
            $result                = '';
            $this->writeLog('Line ' . $this->getLine($v) . ': ' . $class);

            $lines[] = $this->getComment($v, $class);
            switch ($class) {
                case 'Stmt_Expression':
                    $result = $this->parseExpr($v->expr) . ';';
                    break;
                case 'Stmt_Echo':
                    $result = $this->parseEcho($v);
                    break;
                case 'Stmt_Return':
                    $result = $this->parseReturn($v) . ';';
                    break;
                case 'Stmt_For':
                    $this->inLoop = true;
                    $result       = $this->parseFor($v);
                    $this->inLoop = $inLoopTop;
                    break;
                case 'Stmt_Foreach':
                    $this->inLoop = true;
                    $result       = $this->parseForeach($v);
                    $this->inLoop = $inLoopTop;
                    break;
                case 'Stmt_Switch':
                    $this->inLoop = true;
                    $result       = $this->parseSwitch($v);
                    $this->inLoop = $inLoopTop;
                    break;
                case 'Stmt_While':
                    $this->inLoop = true;
                    $result       = $this->parseWhile($v);
                    $this->inLoop = $inLoopTop;
                    break;
                case 'Stmt_Do':
                    $this->inLoop = true;
                    $result       = $this->parseDo($v);
                    $this->inLoop = $inLoopTop;
                    break;
                case 'Stmt_If':
                    $result = $this->parseIf($v);
                    break;
                case 'Stmt_Break':
                    $result = $this->parseBreak($v);
                    break;
                case 'Stmt_Goto':
                    $result = $this->parseGoto($v);
                    break;
                case 'Stmt_Label':
                    $result = $this->parseLabel($v);
                    break;
                case 'Stmt_Continue':
                    $result = $this->parseContinue($v);
                    break;
                case 'Stmt_Nop':
                    break;
                case 'Stmt_Global':
                    $result = $this->parseGlobal($v);
                    break;
                case 'Stmt_Static':
                    $result = $this->parseStatic($v);
                    break;
                case 'Stmt_Unset':
                    $result = $this->parseUnset($v);
                    break;
                case 'Stmt_TryCatch':
                    $result = $this->parseTryCatch($v);
                    break;
                case 'Stmt_Class':
                    $this->fatalError($v, 'Cannot declare class in function');
                    break;
                default:
                    abort($v);
            }
            $lines                 = array_merge($lines, $this->beforeStmtLines);
            $this->beforeStmtLines = [];
            if ($result) {
                $lines[] = $result;
            }
            if ($this->afterStmtLines) {
                $lines                = array_merge($lines, $this->afterStmtLines);
                $this->afterStmtLines = [];
            }
        }

        $code = '';
        foreach ($lines as $line) {
            $code .= $this->getIndent() . $line . PHP_EOL;
        }

        return $code;
    }

    protected function parseAssignArrayDim(Node $left, Node $right): string
    {
        if ($this->isPropertyFetch($left)) {
            return $this->parseAssignPropertyArrayDim($left, $right);
        }
        $oriInAssignExpr    = $this->inAssignExpr;
        $this->inAssignExpr = true;
        $array              = $this->parseIdentifier($left->var);
        $this->inAssignExpr = $oriInAssignExpr;
        $code               = '';
        // 这是 PHP 的初始化+赋值写法，需要先创建数组
        if (!$this->hasVar($array) and $this->isVarExpr($left->var)) {
            $this->addLocalVar($array, self::TYPE_ARRAY);
        }

        $value = $this->trimBrackets($this->parseExpr($right));
        if ($left->dim === null) {
            return $code . "{$array}.offsetSet(php::null, {$value})";
        }
        $dim = $this->trimBrackets($this->parseIdentifier($left->dim));

        return $code . "{$array}.offsetSet({$dim}, {$value})";
    }

    protected function parseAssignPropertyFetch(Node $left, Node $right): string
    {
        $array    = $this->parseIdentifier($left->var);
        $propName = $this->identifierToStr($left->name);

        return "{$array}.setProperty({$propName}, " . $this->trimBrackets($this->parseExpr($right)) . ')';
    }

    protected function parseRightAssociativeAssign(NodeAbstract $left, Node\Expr\Assign $right): string
    {
        $checkVarFn = function ($var) {
            if ($this->isArrayDimFetch($var)) {
                $array = $this->parseIdentifier($var->var);
                if (!$this->hasVar($array)) {
                    $this->addLocalVar($array, self::TYPE_ARRAY);
                }
            }
        };

        $checkVarFn($left);
        $chain[] = $left;
        $next    = $right;
        while ($next->getType() === 'Expr_Assign') {
            $var = $next->var;
            $checkVarFn($var);
            $chain[] = $var;
            $next    = $next->expr;
        }

        $tmpVar = $this->genTmpVarName();
        $this->addLocalVar($tmpVar, self::TYPE_VAR);

        // 翻转赋值链
        $chain = array_reverse($chain);
        $list  = [];

        $list[] = $this->getIndent() . $tmpVar . ' = ' . $this->parseExpr($next);
        $right  = new Variable($tmpVar);
        foreach ($chain as $var) {
            $list[] = $this->getIndent() . $this->parseFinallyAssign($var, $right);
        }

        return implode(";\n" . $this->getIndent(), $list);
    }

    protected function parseAssignStaticProperty($left, $right)
    {
        $value    = $this->trimBrackets($this->parseExpr($right));
        $native = $this->parseNativeStaticPropertyFetch($left);
        if ($native) {
            return $native . ' = ' . $value;
        }
        $class    = $this->identifierToStr($left->class);
        $propName = $this->identifierToStr($left->name);
        return "php::setStaticProperty({$class}, {$propName}, {$value})";
    }

    protected function parseAssign(Node\Expr\Assign $v): string
    {
        $left  = $v->var;
        $right = $v->expr;

        if ($right->getType() === 'Expr_Assign') {
            return $this->parseRightAssociativeAssign($left, $right);
        }
        if ($this->isArrayDimFetch($left)) {
            return $this->parseAssignArrayDim($left, $right);
        }
        if ($this->isStaticPropertyFetch($left)) {
            return $this->parseAssignStaticProperty($left, $right);
        }
        return $this->parseFinallyAssign($left, $right);
    }

    protected function parseFinallyAssign($left, $right): string
    {
        if ($left instanceof Node\Expr\List_) {
            $items = $left->items;
            $code  = '{';
            $this->indentLevel++;
            $tmpVar = $this->genTmpVarName();
            $this->addLocalVar($tmpVar, self::TYPE_VAR);
            $code .= $this->getIndent() . $tmpVar . ' = ' . $this->parseExpr($right) . '; ';
            foreach ($items as $k => $item) {
                if (!$item) {
                    continue;
                }
                if ($item instanceof Node\Expr\ArrayItem) {
                    $var = $this->parseIdentifier($item->value);
                    if (!$this->hasVar($var)) {
                        $this->addLocalVar($var, self::TYPE_VAR);
                    }
                    $code .= "{$var} = {$tmpVar}.item({$k}); ";
                } else {
                    abort($item);
                }
            }
            $this->indentLevel--;

            return $code . '}';
        }

        $this->inAssignExpr = true;
        $var                = $this->parseIdentifier($left);
        $this->inAssignExpr = false;
        if ($var === 'this_') {
            $this->fatalError($left, 'Cannot re-assign $this');
        }

        $expr = $this->parseExpr($right);
        $type = $this->detectExprType($right);

        if ($this->isVarExpr($left)) {
            // 类型推断，获取对象的类名
            if ($this->isNewExpr($right) and $this->isNameExpr($right->class)) {
                $class               = $this->parseIdentifier($right->class);
                $this->objects[$var] = $class;
                $type                = self::TYPE_OBJECT;
            } elseif ($this->isFuncCallExpr($right) and $this->isNameExpr($right->name)) {
                $fn = $this->parseIdentifier($right->name);
                if (count($right->args) === 2 and $fn === 'objval' and $this->isScalarString($right->args[1]->value)) {
                    $this->objects[$var] = $this->parseIdentifier($right->args[1]->value);
                    $type                = self::TYPE_OBJECT;
                } elseif (count($right->args) === 1 and $fn === 'any') {
                    $type = self::TYPE_VAR;
                    if (!$this->hasVar($var)) {
                        $this->addLocalVar($var, $type);
                    }

                    return $var . ' = ' . $this->parseIdentifier($right->args[0]->value);
                } else {
                    $type = $type === self::TYPE_VOID ? self::TYPE_VAR : $type;
                }
            }

            if (!$this->hasVar($var)) {
                $this->addLocalVar($var, $type);
            }
        } elseif ($this->isPropertyFetch($left)) {
            $var = $this->parsePropertyFetch($left, true);
        }

        return $var . ' = ' . $this->convertExprType($expr, $this->detectExprType($left), $this->detectExprType($right));
    }

    protected function parseEcho(mixed $v): string
    {
        foreach ($v->exprs as $expr) {
            if ($expr instanceof Node\Expr\Assign) {
                $this->fatalError($expr, 'Cannot echo assign expression');
            } else {
                $lines[] = 'php::echo(' . $this->parseExpr($expr) . ');';
            }
        }

        return implode("\n" . $this->getIndent(), $lines);
    }

    protected function isFloatStr(string $str): bool
    {
        return filter_var($str, FILTER_VALIDATE_FLOAT) !== false;
    }

    protected function isIntStr(string $str): bool
    {
        return filter_var($str, FILTER_VALIDATE_INT) !== false;
    }

    protected function isBoolStr(string $str): bool
    {
        return $str === 'true' || $str === 'false';
    }

    protected function isInternalFunction(string $fname): bool
    {
        return array_key_exists($fname, $this->internalFunctions);
    }

    protected function isAssignOpConcat(string $op): bool
    {
        return $op === '.=';
    }

    protected function isAssignOpPow(string $op): bool
    {
        return $op === '**=';
    }

    /**
     * 尽可能转为数字，优先级 浮点 > 整数 > 字符串.
     * @param mixed $expr
     */
    protected function parseNumericIdentifier($expr)
    {
        if ($expr->getType() === 'Scalar_String') {
            if ($this->isFloatStr($expr->value)) {
                return floatval($expr->value);
            }
            if ($this->isIntStr($expr->value)) {
                return intval($expr->value);
            }
            if ($expr->value === '0') {
                return 0;
            }
        }

        return $this->parseIdentifier($expr);
    }

    protected function parseBinaryOp($left, $right, $op): string
    {
        // 运算逻辑，优先转为数字
        $leftExpr  = $this->parseNumericIdentifier($left);
        $rightExpr = $this->parseNumericIdentifier($right);

        $leftType  = $this->detectExprType($left);
        $rightType = $this->detectExprType($right);

        if ($leftType === self::TYPE_FLOAT) {
            $rightExpr = $this->convertExprType($rightExpr, self::TYPE_FLOAT, $rightType);
        } elseif ($rightType === self::TYPE_FLOAT) {
            $leftExpr = $this->convertExprType($leftExpr, $leftType, self::TYPE_FLOAT);
        } elseif ($leftType === self::TYPE_INT) {
            $rightExpr = $this->convertExprType($rightExpr, self::TYPE_INT, $rightType);
        } elseif ($rightType === self::TYPE_INT) {
            $leftExpr = $this->convertExprType($leftExpr, $leftType, self::TYPE_INT);
        }

        if ($op === '%' and !($leftType === self::TYPE_INT and $rightType === self::TYPE_INT)) {
            return 'php::math::mod(' . $leftExpr . ', ' . $rightExpr . ')';
        }

        return '((' . $leftExpr . ') ' . $op . ' (' . $rightExpr . '))';
    }

    protected function parseBinaryOpPlus(mixed $expr): string
    {
        return $this->parseBinaryOp($expr->left, $expr->right, '+');
    }

    protected function parseReturn(mixed $v): string
    {
        if ($v->expr === null) {
            return 'return;';
        }
        // 实际函数的返回值
        $type = $this->detectExprType($v->expr);
        $expr = $this->parseExpr($v->expr);
        // 函数定义时没有声明返回值，但函数体中有返回值，修改为实际的返回值类型
        if ($this->getReturnType() === 'void') {
            $this->resetReturnType($type);
        } elseif ($this->getReturnType() !== self::TYPE_VAR and $this->getReturnType() !== $type) {
            // 返回值类型不一致，说明存在多种类型的返回值，修改为 var 表示 any
            $this->resetReturnType(self::TYPE_VAR);
        }
        $exprCode = $this->convertExprType($expr, $this->getReturnType(), $type);
        // return 如果使用了 Indirect 语句，可能会导致变量提前析构，出现悬空指针
        // 将 Indirect 赋值给临时变量后，使用 Ctor::Copy 解除了 Indirect，保证内存安全
        if (!$this->isVarExpr($v->expr)) {
            $tmpVar = $this->genTmpVarName();
            // 必须提前声明变量，否则在末尾声明并 return 可能会被 gcc 优化掉
            $this->addLocalVar($tmpVar, $type);
            $code = $tmpVar . ' = ' . $exprCode . ';' . PHP_EOL;
            $code .= $this->getIndent() . 'return ' . $tmpVar;
        } else {
            $code = 'return ' . $exprCode;
        }

        return $code;
    }

    protected function parseBinaryOpMul(mixed $expr): string
    {
        return $this->parseBinaryOp($expr->left, $expr->right, '*');
    }

    protected function addLocalVar(string $name, string $type): void
    {
        $this->localVars[$name] = $type;
    }

    protected function addLiteralString(string $value): int
    {
        $index                        = $this->literalStringIndex++;
        $this->literalStrings[$value] = $index;

        return $index;
    }

    protected function addGlobalVar(string $name, string $type): void
    {
        $this->globalVars[$name] = $type;
    }

    protected function hasVar(string $name): bool
    {
        return $this->hasLocalVar($name) || $this->hasGlobalVar($name);
    }

    protected function hasLocalVar(string $name): bool
    {
        return isset($this->localVars[$name]);
    }

    protected function hasNativeClass(string $name): bool
    {
        $name = trim($name, '\\');
        return array_key_exists($name, $this->classes);
    }

    protected function getClassDef(string $name): ClassDef
    {
        $name = trim($name, '\\');
        return $this->classes[$name];
    }

    protected function resetReturnType(string $type): void
    {
        $this->functionDef->returnType = $type;
        // 返回值变更，需要重新解析
        $this->climate->cyan('Return type changed, retrying...');
        throw new RedoException();
    }

    protected function detectVarType($var): string
    {
        $name = $this->parseIdentifier($var);

        return $this->getVarType($name);
    }

    protected function detectExprType($expr): string
    {
        $exprType = $expr->getType();
        switch ($exprType) {
            case 'Expr_Cast_Int':
            case 'Scalar_Int':
                return self::TYPE_INT;
            case 'Expr_Cast_Float':
            case 'Expr_Cast_Double':
            case 'Scalar_Float':
                return self::TYPE_FLOAT;
            case 'Expr_Cast_Bool':
            case 'Scalar_Bool':
                return self::TYPE_BOOL;
            case 'Expr_Array':
                return self::TYPE_ARRAY;
            case 'Expr_BinaryOp_Plus':
            case 'Expr_BinaryOp_Minus':
            case 'Expr_BinaryOp_Mul':
            case 'Expr_BinaryOp_Div':
            case 'Expr_BinaryOp_Mod':
            case 'Expr_BinaryOp_Pow':
            case 'Expr_BinaryOp_ShiftLeft':
            case 'Expr_BinaryOp_ShiftRight':
            case 'Expr_BinaryOp_BitwiseAnd':
            case 'Expr_BinaryOp_BitwiseOr':
            case 'Expr_BinaryOp_BitwiseXor':
            case 'Expr_BinaryOp_BooleanAnd':
                $leftType  = $this->detectExprType($expr->left);
                $rightType = $this->detectExprType($expr->right);
                if ($leftType === self::TYPE_FLOAT || $rightType === self::TYPE_FLOAT) {
                    return self::TYPE_FLOAT;
                }
                if ($leftType === self::TYPE_INT || $rightType === self::TYPE_INT) {
                    return self::TYPE_INT;
                }
                break;
            case 'Expr_FuncCall':
                $name = $this->parseIdentifier($expr->name);
                if ($this->isNativeFunction($name)) {
                    return $this->nativeFunctions[$name]->returnType;
                }

                return $this->detectFuncCallReturnType($name);
            case 'Expr_New':
                return self::TYPE_OBJECT;
            case 'Expr_Assign':
                return $this->detectVarType($expr->var);
            case self::EXPR_VARIABLE:
                return $this->detectVarType($expr);
            case 'Expr_ConstFetch':
                return $this->detectConstType($expr);
            case 'Scalar_String':
                return self::TYPE_STR;
            default:
                break;
        }

        return self::TYPE_VAR;
    }

    protected function parseArray($node): string
    {
        $items = $node->items;
        // 优化代码风格，空数组直接返回{}，否则会产生一些空洞内容
        if (count($items) === 0) {
            return self::TYPE_ARRAY . '{}';
        }

        $assocArray = false;
        foreach ($items as $item) {
            if ($item->key) {
                $assocArray = true;
                break;
            }
        }

        $list = [];
        $this->indentLevel++;
        foreach ($items as $item) {
            $value = $this->parseIdentifier($item->value);
            if ($assocArray) {
                // TODO 混杂模式数组赋值
                $key = $item->key ? $this->parseIdentifier($item->key) : 'php::null';
                if (str_starts_with($key, self::LITERAL_STRINGS)) {
                    $key = "{$key}.toStdString()";
                } elseif ($key === '0L') {
                    $key = 'php::zero';
                }
                $list[] = $this->getIndent() . '{ ' . $key . ', ' .
                    self::TYPE_VAR . '(' . $value . ') }';
            } else {
                $list[] = $this->getIndent() . self::TYPE_VAR . '(' . $value . ')';
            }
        }
        $this->indentLevel--;

        return self::TYPE_ARRAY . '{' . PHP_EOL .
            implode(', ' . PHP_EOL, $list) . PHP_EOL .
            $this->getIndent() .
            '}';
    }

    protected function parseParameterType(Node\Param $param, string $var): string
    {
        $type = $param->type;
        if ($type == null) {
            return self::TYPE_VAR;
        }
        if ($type instanceof NullableType or $type instanceof UnionType) {
            return self::TYPE_VAR;
        }
        $name = $type->name;
        switch ($name) {
            case 'int':
                return self::TYPE_INT;
            case 'array':
                return self::TYPE_ARRAY;
            case 'float':
                return self::TYPE_FLOAT;
            case 'bool':
                return self::TYPE_BOOL;
            case 'string':
                return self::TYPE_STR;
            case 'void':
                $this->fatalError($param, 'Cannot use `void` as a parameter type.');
                break;
            case 'mixed':
                return self::TYPE_VAR;
            case 'resource':
                $this->fatalError($param, 'Cannot use `resource` as a parameter type.');
                break;
            default:
                $this->objects[$var] = $name;

                return self::TYPE_OBJECT;
        }
    }

    protected function parseIncludes(): string
    {
        $list = [
            $this->phpxDir . '/include',
            $this->getBuildDir() . '/include',
            $this->rootPath . '/src/cpp',
        ];
        $out = '$(php-config --includes) ';
        foreach ($list as $li) {
            $out .= '-I ' . $li . ' ';
        }

        return $out;
    }

    protected function parseLdflags(): string
    {
        $list = [
            '$(php-config --prefix)/lib',
            $this->phpxDir . '/lib',
        ];
        $out = '';
        foreach ($list as $li) {
            $out .= '-L ' . $li . ' ';
        }

        return $out;
    }

    protected function parseLibs(): string
    {
        $list = ['phpx'];
        if ($this->buildMode === 'bin') {
            $list[] = 'php';
        }
        $out = '';
        foreach ($list as $li) {
            $out .= '-l' . $li . ' ';
        }

        return $out;
    }

    protected function addCompilationOption(string &$cmd, bool $link): void
    {
        $cmd .= ' ' . $this->parseIncludes();
        $cmd .= ' -O' . $this->optimizeLevel;
        $cmd .= ' -g';
        $cmd .= ' -Wall';
        if ($this->enableProfiler) {
            $cmd .= ' -lprofiler';
            $cmd .= ' -DPPROF_ON=1';
        }

        if ($this->buildMode === 'ext') {
            if ($link) {
                $cmd .= ' -shared';
            } else {
                $cmd .= ' -fPIC -D BUILD_PHP_EXTENSION=1';
            }
        }

        if ($link) {
            $cmd .= ' ' . $this->parseLdflags();
            $cmd .= ' ' . $this->parseLibs();
            if ($this->ldflags) {
                $cmd .= ' ' . $this->ldflags;
            }
        } else {
            if ($this->cxxflags) {
                $cmd .= ' ' . $this->cxxflags;
            }
        }
    }

    protected function parseBinaryOpConcat(mixed $expr): string
    {
        $left  = $this->parseIdentifier($expr->left);
        $right = $this->parseIdentifier($expr->right);

        return 'php::concat(' . $left . ', ' . $right . ')';
    }

    protected function parseFor(mixed $v): string
    {
        $init  = $v->init;
        $cond  = $v->cond;
        $loop  = $v->loop;
        $stmts = $v->stmts;
        $code  = '';

        $list_expr = [];
        foreach ($init as $expr) {
            $list_expr[] = $this->parseExpr($expr);
        }
        $list_expr[] = '';
        $code .= implode(";\n" . $this->getIndent(), $list_expr);

        $list_cond = [];
        foreach ($cond as $expr) {
            if ($expr->getType() === 'Expr_Assign') {
                $left = $expr->var;
                $name = $this->parseIdentifier($left);
                $type = $this->detectExprType($expr->expr);
                // for 循环的变量声明，必须在循环体之外，不能创建 scope tmp var
                if (!$this->hasVar($name)) {
                    $this->addLocalVar($name, $type);
                }
                $code .= $name . ' = (' . $this->parseIdentifier($expr->expr) . ');';
            }
            $list_cond[] = $this->parseExpr($expr);
        }

        $code .= $this->parseBeforeStmtLines() . PHP_EOL;
        $code .= 'for (;';
        $code .= implode(', ', $list_cond);
        $code .= '; ';

        $list_loop = [];
        foreach ($loop as $expr) {
            $list_loop[] = $this->parseExpr($expr);
        }
        $code .= implode(', ', $list_loop);
        $code .= ') {' . PHP_EOL;

        $this->indentLevel++;
        $code .= $this->parseStmts($stmts);
        $this->indentLevel--;

        $code .= $this->getIndent() . '}' . PHP_EOL;

        return $code;
    }

    protected function parseBinaryOpSmaller(mixed $expr): string
    {
        return $this->convertBoolExpr($this->parseBinaryOp($expr->left, $expr->right, '<'));
    }

    protected function parsePreInc(mixed $expr): string
    {
        return '++' . $this->parseIdentifier($expr->var);
    }

    protected function removeAssignOp(string $op): string
    {
        return str_replace('=', '', $op);
    }

    protected function parseAssignOp(mixed $node, string $op): string
    {
        $var          = $this->parseIdentifier($node->var);
        $expr         = $this->parseIdentifier($node->expr);
        $leftExprType = $node->var->getType();
        if ($this->isVarExpr($node->var)) {
            if (!$this->hasVar($var)) {
                $this->fatalError($node->var, 'Cannot assign to undefined variable');
            }
            $type         = $this->detectVarType($node->var);
            $rightExprStr = $this->convertExprType($expr, $type, $this->detectExprType($node->expr));
            if ($this->isAssignOpConcat($op)) {
                if ($this->isArrayVar($node->var)) {
                    $this->fatalError($node->var, 'Cannot concat string to array');
                }

                return $var . '.append(' . $rightExprStr . ')';
            }
            if ($this->isAssignOpPow($op)) {
                $powExpr = 'php::call(php::pow, {' . $var . ', ' . $rightExprStr . '})';

                return $var . ' = ' . $this->convertVarType($var, $powExpr);
            }
            return $var . ' ' . $op . ' ' . $rightExprStr;
        }
        if ($leftExprType === self::EXPR_ARRAY_DIM_FETCH) {
            /**
             * $count[$r] -= 1;
             * 需要转为下面语句：
             * $tmp_var = $count[$r] - 1;
             * $count[$r] = $tmp_var;.
             */
            $type      = $this->detectVarType($node->var);
            $rightType = $this->detectExprType($node->expr);
            $tmpVar    = $this->genTmpVarName();
            $this->addLocalVar($tmpVar, $rightType);
            $dim      = $this->parseIdentifier($node->var->dim);
            $binaryOp = $this->removeAssignOp($op);

            if ($binaryOp === '.') {
                $this->beforeStmtLines[] = "{$tmpVar} = php::concat(" .
                    $this->convertVarType($tmpVar, $var) . ', ' .
                    $this->convertExprType($expr, $type, $rightType) . ');';
            } else {
                $this->beforeStmtLines[] = "{$tmpVar} = " .
                    $this->convertVarType($tmpVar, $var) . ' ' .
                    $binaryOp . ' ' .
                    $this->convertExprType($expr, $type, $rightType) . ';';
            }

            return $this->parseArrayDimStore($node->var->var, $dim, $tmpVar);
        }
        return $var . ' ' . $op . ' (' . $expr . ')';
    }

    protected function parseAssignOpConcat(mixed $expr): string
    {
        return $this->parseAssignOp($expr, '.=');
    }

    protected function parseAssignOpPlus(mixed $expr): string
    {
        return $this->parseAssignOp($expr, '+=');
    }

    protected function parseAssignOpMinus(mixed $expr): string
    {
        return $this->parseAssignOp($expr, '-=');
    }

    protected function parseAssignOpMod(mixed $expr): string
    {
        return $this->parseAssignOp($expr, '%=');
    }

    protected function parseAssignOpMul(mixed $expr): string
    {
        return $this->parseAssignOp($expr, '*=');
    }

    protected function parseAssignOpDiv(mixed $expr): string
    {
        return $this->parseAssignOp($expr, '/=');
    }

    protected function parseAssignOpBitwiseAnd(mixed $expr): string
    {
        return $this->parseAssignOp($expr, '&=');
    }

    protected function parseAssignOpPow(mixed $expr): string
    {
        return $this->parseAssignOp($expr, '**=');
    }

    protected function error(string $msg): void
    {
        $this->climate->red("Fatal error: {$msg}");
        debug_print_backtrace();
        exit(255);
    }

    protected function fatalError(Node $node, string $msg): void
    {
        $this->error("{$msg} in {$this->file}:{$node->getStartLine()}");
    }

    protected function dump(NodeAbstract $v): void
    {
        if ($this->debugLine == $v->getStartLine()) {
            var_dump($v);
        }
    }

    protected function parseArrayDimFetch(Node\Expr\ArrayDimFetch $node, bool $write): string
    {
        $var = $this->parseIdentifier($node->var);
        if ($this->isVarExpr($node->var) and $var === 'GLOBALS') {
            if ($node->dim === null) {
                $this->fatalError($node, 'Cannot use [] for GLOBALS');
            }
            return 'php::global(' . $this->parseIdentifier($node->dim) . ')';
        }
        if ($node->dim === null) {
            if (!$write) {
                $this->fatalError($node, 'Cannot use [] for reading');
            } else {
                return $var . '.newItem()';
            }
        } else {
            $dim = $this->trimBrackets($this->parseIdentifier($node->dim));

            return $var . '.item(' . $dim . ', ' . $this->escapeBool($write) . ')';
        }
    }

    protected function parseArrayDimStore($array, $dim, $var): string
    {
        $id = $this->parseIdentifier($array);

        return $id . '.offsetSet(' . $this->trimBrackets($dim) . ', ' . $this->trimBrackets($var) . ')';
    }

    protected function parseBinaryOpShiftLeft($expr): string
    {
        return $this->parseBinaryOp($expr->left, $expr->right, '<<');
    }

    protected function parseBinaryOpShiftRight($expr): string
    {
        return $this->parseBinaryOp($expr->left, $expr->right, '>>');
    }

    protected function parseBinaryOpMod(mixed $expr): string
    {
        return $this->parseBinaryOp($expr->left, $expr->right, '%');
    }

    /**
     * 查找原生函数.
     *
     * @return bool
     */
    protected function findNativeFunction(string $fname): string|false
    {
        $possibleFunctionNames = [$this->escapeName($fname)];
        if ($this->namespace) {
            $possibleFunctionNames[] = $this->escapeNamespace($this->namespace) . self::NAMESPACE_SEPARATOR . $fname;
        }
        if (isset($this->useFunctions[$fname])) {
            $possibleFunctionNames[] = $this->escapeNamespace($this->useFunctions[$fname]) . self::NAMESPACE_SEPARATOR . $fname;
        }
        foreach ($possibleFunctionNames as $name) {
            // 在预处理阶段检测到函数声明，但是未定义，说明在当前文件，但是顺序错误
            if (isset($this->functionDeclInFile[$name])
                and $this->functionDeclInFile[$name] === $this->file
                and !$this->isNativeFunction($name)) {
                $this->redoAfterDeclare[$name] = true;

                return $name;
            }
            if ($this->isNativeFunction($name)) {
                return $name;
            }
        }

        return false;
    }

    protected function parseFuncCall(Node\Expr\FuncCall $expr, bool $silent = false): string
    {
        $call = '';
        if ($this->isVarExpr($expr->name)) {
            $fn   = $this->parseIdentifier($expr->name);
            $name = '';
        } elseif ($expr->name->getType() === 'Name') {
            $name = $this->parseIdentifier($expr->name);
            if (in_array($name, $this->unsupportedFunctions)) {
                $this->fatalError($expr, 'Unsupported function: `' . $name . '`');
            }
            $nativeFn = $this->findNativeFunction($name);
            if ($nativeFn) {
                return self::PREFIX . $nativeFn . '(' . $this->parseNativeCallArgs($expr->args, $nativeFn) . ')';
            }
            $code = $this->parseFuncCallWithOptimizer($name, $expr);
            if ($code) {
                return $code;
            }
            $fn = $this->getFuncPtr($name);
            $this->beforeStmtLines[] = '// Func Call: ' . $name;
            $call = $silent ? 'CALL_SILENT' : 'CALL';
        } else {
            $tmpVar = $this->genTmpVarName();
            $this->addLocalVar($tmpVar, self::TYPE_VAR);
            $this->beforeStmtLines[] = $tmpVar . ' = ' . $this->parseExpr($expr->name) . ';';
            $fn                      = $tmpVar;
            $name                    = '';
        }
        if (!$call) {
            $call = $silent ? 'php::silentCall' : 'php::call';
        }
        if (empty($expr->args)) {
            return $call . '(' . $fn . ')';
        }
        return $call . '(' . $fn . ', {' . $this->parseCallArgs($expr->args, $name) . '})';
    }

    protected function parseNativeCallArgs(array $args, string $nativeFunc): string
    {
        $list_args = [];
        foreach ($args as $i => $arg) {
            $argInfo     = $this->getArgInfo($arg, $nativeFunc, $i);
            $list_args[] = $this->getTypeConvertedArg($arg, $argInfo);
        }

        return implode(', ', $list_args);
    }

    protected function parseCallArgs(array $args, string $funcName = '', string $className = ''): string
    {
        if (!$className) {
            if ($this->isNativeFunction($funcName)) {
                return $this->parseNativeCallArgs($args, $funcName);
            }
        }

        $list_args = [];
        foreach ($args as $i => $arg) {
            if ($this->isVarExpr($arg->value)) {
                $name = $this->parseIdentifier($arg->value);
                // 调用了不存在的变量，可能是引用
                if (!$this->hasVar($name)) {
                    $this->addLocalVar($name, self::TYPE_REF);
                    $this->beforeStmtLines[] = $name . ' = php::newReference();';
                } elseif ($funcName and Reflection::isReferenceArg($funcName, $i)) {
                    // 需要引用类型的参数，使用临时变量作为引用，并替换掉实际的参数
                    $tmpVar = $this->genTmpVarName();
                    $this->addLocalVar($tmpVar, self::TYPE_REF);
                    $this->beforeStmtLines[] = $tmpVar . ' = ' . $this->parseExpr($arg->value) . '.toReference();';
                    $list_args[]             = '&' . $tmpVar;
                    continue;
                }
            } elseif ($this->isPropertyFetch($arg->value)) {
                if ($funcName and Reflection::isReferenceArg($funcName, $i)) {
                    $obj         = $this->parseIdentifier($arg->value->var);
                    $list_args[] = $obj . '.getPropertyReference(' . $this->identifierToStr($arg->value->name) . ')';
                    continue;
                }
            }
            // 不支持变长参数展开的语法，例如：array_merge(...$arr)
            if ($arg->unpack) {
                $this->fatalError($arg, 'The syntax for variable parameter expansion is not supported');
            }
            $list_args[] = $this->parseArg($arg);
        }

        return implode(', ', $list_args);
    }

    protected function parseArg($arg): string
    {
        return $this->parseIdentifier($arg->value);
    }

    protected function parsePostOp($expr, string $op): string
    {
        if ($this->isVarExpr($expr->var) or $this->isPropertyFetch($expr->var)) {
            return $this->parseIdentifier($expr->var) . str_repeat($op, 2);
        }
        if ($this->isStaticPropertyFetch($expr->var)) {
            $native = $this->parseNativeStaticPropertyFetch($expr->var);
            if ($native) {
                return $native . str_repeat($op, 2);
            }

            $class  = $this->identifierToStr($expr->var->class);
            $prop   = $this->identifierToStr($expr->var->name);
            $tmpVar = $this->genTmpVarName();
            $this->addLocalVar($tmpVar, self::TYPE_VAR);
            $this->beforeStmtLines[] = $tmpVar . ' = php::getStaticProperty(' . $class . ', ' . $prop . ');';
            $this->afterStmtLines[]  = 'php::setStaticProperty(' . $class . ', ' . $prop . ', ' . $tmpVar . ' ' . $op . ' 1);';

            return $tmpVar;
        }
        $this->fatalError($expr, 'Post-increment operator is not supported for non-variable expressions');
    }

    protected function parsePostDec($expr): string
    {
        return $this->parsePostOp($expr, '-');
    }

    protected function parsePostInc($expr): string
    {
        return $this->parsePostOp($expr, '+');
    }

    protected function parseTernary(mixed $expr): string
    {
        $cond = $expr->cond;
        $if   = $expr->if;
        $else = $expr->else;
        if ($if === null) {
            $cond = $this->parseExpr($cond);

            return '(' . $cond . ') ? (' . $cond . ') : (' . $this->parseExpr($else) . ')';
        }
        return '(' . $this->parseExpr($cond) . ') ? (' . $this->parseExpr($if) . ') : (' . $this->parseExpr($else) . ')';
    }

    protected function parseBinaryOpGreater(mixed $expr): string
    {
        return $this->convertBoolExpr($this->parseBinaryOp($expr->left, $expr->right, '>'));
    }

    protected function parseBinaryOpPow(mixed $expr): string
    {
        $left  = $this->parseIdentifier($expr->left);
        $right = $this->parseIdentifier($expr->right);

        return 'php::pow(' . $left . ', ' . $right . ')';
    }

    protected function parsePreDec(mixed $expr): string
    {
        return '--' . $this->parseIdentifier($expr->var);
    }

    protected function parseBinaryOpBitwiseAnd(mixed $expr): string
    {
        return $this->parseBinaryOp($expr->left, $expr->right, '&');
    }

    protected function parseBinaryOpBitwiseOr(mixed $expr): string
    {
        return $this->parseBinaryOp($expr->left, $expr->right, '|');
    }

    protected function parseBinaryOpBitwiseXor(mixed $expr): string
    {
        return $this->parseBinaryOp($expr->left, $expr->right, '^');
    }

    protected function parseBitwiseNot(mixed $expr): string
    {
        $var = $this->parseIdentifier($expr->expr);

        return '~' . $var;
    }

    protected function parseIf(mixed $v): string
    {
        $cond = $this->parseExpr($v->cond);

        $code = $this->parseBeforeStmtLines() . PHP_EOL;
        $code .= 'if (' . $cond . ') {' . PHP_EOL;
        $this->indentLevel++;
        $code .= $this->parseStmts($v->stmts);
        $this->indentLevel--;
        $code .= $this->getIndent() . '}';

        if ($v->elseifs) {
            foreach ($v->elseifs as $elseif) {
                $elseifCond = $this->parseExpr($elseif->cond);
                $code .= ' else if (' . $elseifCond . ') {' . PHP_EOL;
                $this->indentLevel++;
                $code .= $this->parseStmts($elseif->stmts);
                $this->indentLevel--;
                $code .= $this->getIndent() . '}';
            }
        }

        if ($v->else) {
            $code .= ' else {' . PHP_EOL;
            $this->indentLevel++;
            $code .= $this->parseStmts($v->else->stmts);
            $this->indentLevel--;
            $code .= $this->getIndent() . '}';
        }

        return $code . PHP_EOL;
    }

    protected function parseBinaryOpEqual(mixed $expr): string
    {
        return 'php::equals(' . $this->parseExpr($expr->left) . ', ' . $this->parseExpr($expr->right) . ')';
    }

    protected function parseBinaryOpNotEqual(mixed $expr): string
    {
        return '!php::equals(' . $this->parseExpr($expr->left) . ', ' . $this->parseExpr($expr->right) . ')';
    }

    /**
     * 逻辑比较的运算，必须返回 bool 类型.
     */
    protected function parseBinaryOpLogicalAnd(Node $expr): string
    {
        return $this->convertBoolExpr($this->parseBinaryOp($expr->left, $expr->right, '&&'));
    }

    protected function parseBinaryOpLogicalOr(Node $expr): string
    {
        return $this->convertBoolExpr($this->parseBinaryOp($expr->left, $expr->right, '||'));
    }

    protected function parseBinaryOpLogicalXor(Node $expr): string
    {
        return $this->convertBoolExpr($this->parseBinaryOp($expr->left, $expr->right, '^'));
    }

    protected function parseBooleanNot(Node $expr): string
    {
        $expr = $this->parseExpr($expr->expr);

        return '!' . $expr;
    }

    protected function parseWhile(Node $v): string
    {
        $cond  = $this->parseExpr($v->cond);
        $stmts = $v->stmts;

        $code = $this->parseBeforeStmtLines() . PHP_EOL;
        $code .= 'while (' . $cond . ') {' . PHP_EOL;
        $this->indentLevel++;
        $code .= $this->parseStmts($stmts);
        $this->indentLevel--;
        $code .= $this->getIndent() . '}' . PHP_EOL;

        return $code;
    }

    protected function trimBrackets(string $str): string
    {
        if ($this->isClosedCall($str, '')) {
            return substr($str, 1, -1);
        }

        return $str;
    }

    protected function convertIntExpr(string $expr): string
    {
        if (!$this->isClosedCall($expr, 'php::toInt')) {
            return 'php::toInt(' . $this->trimBrackets($expr) . ')';
        }

        return $expr;
    }

    protected function convertFloatExpr(string $expr): string
    {
        if (!$this->isClosedCall($expr, 'php::toFloat')) {
            return 'php::toFloat(' . $this->trimBrackets($expr) . ')';
        }

        return $expr;
    }

    protected function convertStringExpr(string $expr): string
    {
        if (!$this->isClosedCall($expr, 'php::toString')) {
            return 'php::toString(' . $this->trimBrackets($expr) . ')';
        }

        return $expr;
    }

    protected function convertObjectExpr(string $expr, string $class = ''): string
    {
        if (!$this->isClosedCall($expr, 'php::toObject')) {
            if ($class === '') {
                return 'php::toObject(' . $this->trimBrackets($expr) . ')';
            }
            return 'php::toObject(' . $this->trimBrackets($expr) . ', ' . $class . ')';
        }

        return $expr;
    }

    protected function convertArrayExpr(string $expr): string
    {
        if (!$this->isClosedCall($expr, 'php::toArray')) {
            return 'php::toArray(' . $this->trimBrackets($expr) . ')';
        }

        return $expr;
    }

    protected function convertBoolExpr(string $expr): string
    {
        if (!$this->isClosedCall($expr, 'php::toBool')) {
            return 'php::toBool(' . $this->trimBrackets($expr) . ')';
        }

        return $expr;
    }

    protected function parseBinaryOpSmallerOrEqual(Node\Expr\BinaryOp\SmallerOrEqual $expr): string
    {
        return $this->convertBoolExpr($this->parseBinaryOp($expr->left, $expr->right, '<='));
    }

    protected function parseBinaryOpGreaterOrEqual(Node\Expr\BinaryOp\GreaterOrEqual $expr): string
    {
        return $this->convertBoolExpr($this->parseBinaryOp($expr->left, $expr->right, '>='));
    }

    protected function parsePrint(Node\Expr\Print_ $expr): string
    {
        return 'php::echo(' . $this->parseExpr($expr->expr) . ')';
    }

    protected function parseDo(Node\Stmt\Do_ $v): string
    {
        $stmts = $v->stmts;
        $cond  = $this->parseExpr($v->cond);
        $code  = $this->parseBeforeStmtLines() . PHP_EOL;
        $code .= 'do {' . PHP_EOL;
        $this->indentLevel++;
        $code .= $this->parseStmts($stmts);
        $this->indentLevel--;
        $code .= $this->getIndent() . '} while (' . $cond . ');' . PHP_EOL;

        return $code;
    }

    protected function parseBinaryOpIdentical(Node\Expr\BinaryOp $expr): string
    {
        $left  = $this->parseIdentifier($expr->left);
        $right = $this->parseIdentifier($expr->right);

        if ($right === 'nullptr') {
            return $left . '.isNull()';
        }

        return 'php::same(' . $left . ', ' . $right . ')';
    }

    protected function parseBinaryOpSpaceship(Node\Expr\BinaryOp\Spaceship $expr): string
    {
        $left  = $this->parseIdentifier($expr->left);
        $right = $this->parseIdentifier($expr->right);

        return 'php::compare(' . $left . ', ' . $right . ')';
    }

    protected function parseBinaryOpNotIdentical(Node\Expr\BinaryOp $expr): string
    {
        return '!(' . $this->parseBinaryOpIdentical($expr) . ')';
    }

    protected function parseNew(Node\Expr\New_ $expr): string
    {
        $className = $this->parseIdentifier($expr->class);
        $className = $this->getNamespacedClassName($className);
        $args      = $expr->args;
        $cePtr     = $this->getClassEntryPtr($className);
        if (empty($args)) {
            return 'php::newObject(' . $cePtr . ')';
        }
        return 'php::newObject(' . $cePtr . ', {' . $this->parseCallArgs($args) . '})';
    }

    protected function parseClone(Node\Expr\Clone_ $expr): string
    {
        $var = $this->parseIdentifier($expr->expr);

        return $var . '.clone()';
    }

    protected function parseInstanceof(Node\Expr\Instanceof_ $expr): string
    {
        $var = $this->parseIdentifier($expr->expr);

        return $var . '.instanceOf(' . $this->identifierToStr($expr->class) . ')';
    }

    protected function parseCastInt(Node\Expr\Cast\Int_ $node): string
    {
        return $this->convertIntExpr($this->parseExpr($node->expr));
    }

    protected function parseCastString(Node\Expr\Cast\String_ $node): string
    {
        return $this->convertStringExpr($this->parseExpr($node->expr));
    }

    protected function parseCastBool(Node\Expr\Cast\Bool_ $node): string
    {
        return $this->convertBoolExpr($this->parseExpr($node->expr));
    }

    protected function parseCastObject(Node\Expr\Cast\Object_ $node): string
    {
        return $this->convertObjectExpr($this->parseExpr($node->expr));
    }

    protected function parseConstFetch(Node\Expr\ConstFetch $expr): string
    {
        if ($expr->name->getType() != 'Name' and !($expr->name instanceof Node\Name\FullyQualified)) {
            abort($expr);
        }
        $name = $this->parseIdentifier($expr->name);
        if ($this->isNameExpr($expr->name) and $this->hasConstant($name)) {
            return $this->getConstant($name);
        }
        if ($name === 'null') {
            return 'php::null';
        }
        if ($name === 'true') {
            return 'true';
        }
        if ($name === 'false') {
            return 'false';
        }
        if ($name === 'PHP_EOL') {
            return '"' . $this->escapeString(PHP_EOL) . '"';
        }
        if ($this->isNameExpr($expr->name)) {
            if (str_contains($name, '::')) {
                $ns = explode('::', $name)[0];
                $ce = $this->getClassEntryPtr($ns[0]);
                return 'php::constant(' . $ce . ', ' . $this->getLiteralString($ns[1]) . ')';
            } else {
                return 'php::constant(nullptr, ' . $this->getLiteralString($name) . ')';
            }
        }
        return 'php::constant("' . $this->escapeString($name) . '")';
    }

    protected function parseUnaryMinus(Node $expr): string
    {
        $code = $this->parseExpr($expr->expr);

        return '-' . $code;
    }

    protected function parseUnaryPlus(mixed $expr)
    {
        return $this->parseExpr($expr->expr);
    }

    protected function parseBinaryOpDiv(Node\Expr\BinaryOp\Div $expr): string
    {
        $left  = $this->parseIdentifier($expr->left);
        $right = $this->parseIdentifier($expr->right);

        return $left . ' / (' . $right . ')';
    }

    protected function parseBinaryOpMinus(Node\Expr\BinaryOp\Minus $expr): string
    {
        return $this->parseBinaryOp($expr->left, $expr->right, '-');
    }

    protected function parseInterpolatedString(Node\Scalar\InterpolatedString $expr): string
    {
        $parts = $expr->parts;
        $list  = [];
        foreach ($parts as $part) {
            $list[] = $this->parseExpr($part);
        }

        return 'php::concat({' . implode(', ', $list) . '})';
    }

    protected function escapeString(string $str): string
    {
        return addcslashes($str, "\\\"\n\r\t\v\f\0\x01..\x1f\x7f..\xff");
    }

    protected function escapeBool(bool $bool): string
    {
        return $bool ? 'true' : 'false';
    }

    protected function escapeVarName(string $name): string
    {
        if (in_array($name, Constants::CPP_RESERVED_NAMES)) {
            return '_php__var__' . $name;
        }
        if ($name === 'this') {
            return 'this_';
        }
        return $name;
    }

    protected function escapeNamespace(string $ns): string
    {
        return str_replace('\\', self::NAMESPACE_SEPARATOR, strtolower($ns));
    }

    protected function escapeName(string $name): string
    {
        return strtolower($name);
    }

    protected function escapeClass(string $class): string
    {
        return strtolower($class);
    }

    protected function escapeFileName(string $file): string
    {
        return str_replace('-', '_', $file);
    }

    protected function unescapeVarName(string $name): string
    {
        return str_replace('_php__var__', '', $name);
    }

    protected function parseInterpolatedStringPart(Node $expr): string
    {
        return '"' . $this->escapeString($expr->value) . '"';
    }

    protected function parseGlobal(Node $v): string
    {
        foreach ($v->vars as $v) {
            $name = $this->escapeVarName($v->name);
            if (!$this->hasGlobalVar($name)) {
                $this->addGlobalVar($name, self::TYPE_VAR);
            }
        }

        return '';
    }

    protected function getArgInfo(Node $arg, string $funcName, int $index): ArgInfo
    {
        if (!isset($this->nativeFunctions[$funcName])) {
            $this->fatalError($arg, "Function `{$funcName}` is undefined, you must adjust the order of function definition");
        }
        $funcDef = $this->nativeFunctions[$funcName];
        if (!array_key_exists($index, $funcDef->argInfoList)) {
            $this->fatalError($arg, "Argument `{$index}` of function `{$funcName}` not found");
        }

        return $funcDef->argInfoList[$index];
    }

    protected function getReturnType(): string
    {
        return $this->functionDef->returnType;
    }

    protected function getTypeConvertedArg($arg, $argInfo): string
    {
        $expr = $this->parseArg($arg);
        $type = $this->detectExprType($arg->value);

        return $this->convertExprType($expr, $argInfo->type, $type);
    }

    protected function convertExprType(string $expr, $leftType, $rightType): string
    {
        if ($leftType === self::TYPE_FLOAT or $rightType === self::TYPE_FLOAT) {
            return $this->convertFloatExpr($expr);
        }
        if ($leftType === self::TYPE_INT or $rightType === self::TYPE_INT) {
            return $this->convertIntExpr($expr);
        }
        if ($leftType === self::TYPE_BOOL or $rightType === self::TYPE_BOOL) {
            return $this->convertBoolExpr($expr);
        }

        return $expr;
    }

    protected function parseExit(Node\Expr\Exit_ $node): string
    {
        if (!$node->expr) {
            return 'php::exit(0)';
        }
        return 'php::exit(' . $this->parseIdentifier($node->expr) . ')';
    }

    protected function parseUnset(Node\Stmt\Unset_ $node): string
    {
        $vars  = $node->vars;
        $lines = [];
        foreach ($vars as $var) {
            $type = $var->getType();
            if ($type === self::EXPR_ARRAY_DIM_FETCH) {
                $array   = $this->parseIdentifier($var->var);
                $dim     = $this->parseIdentifier($var->dim);
                $lines[] = $array . '.offsetUnset(' . $dim . ');';
            } elseif ($type === 'Expr_PropertyFetch') {
                $object   = $this->parseIdentifier($var->var);
                $propName = $this->parseIdentifier($var->name);
                $lines[]  = $object . '.unsetProperty("' . $propName . '");';
            } elseif ($type === self::EXPR_VARIABLE) {
                $name    = $this->parseIdentifier($var);
                $lines[] = "{$name}.unset();";
            } else {
                abort($var);
            }
        }

        return implode(PHP_EOL . $this->getIndent(), $lines);
    }

    protected function getPropertyIdentifier(NodeAbstract $object, NodeAbstract $property): string
    {
        $id = $this->identifierToStr($property);
        if ($this->isVarExpr($object) and $this->isIdExpr($property)) {
            $objectName   = $this->parseIdentifier($object);
            $propertyName = $this->parseIdentifier($property);
            if ($objectName === 'this_') {
                $id = self::PREFIX . $this->getPropertyOffset($propertyName, $this->class, $this->namespace);
            } elseif ($this->isTypedObject($objectName)) {
                $class = $this->objects[$objectName];
                if (isset($this->classes[$class])) {
                    $classDef = $this->classes[$class];
                    if ($classDef->hasProperty($propertyName)) {
                        $propertyDef = $classDef->getProperty($propertyName);
                        if ($propertyDef->isPublic() or $this->class === $class) {
                            $id = self::PREFIX . $this->getPropertyOffset($propertyName, $class, $classDef->namespace);
                        } else {
                            $this->fatalError($property, "Cannot access private/protected property `{$propertyName}` of class `{$class}`");
                        }
                    }
                }
            }
        }

        return $id;
    }

    protected function parsePropertyFetch(Node\Expr\PropertyFetch $expr, bool $update = false): string
    {
        $object   = $expr->var;
        $property = $expr->name;
        $id       = $this->getPropertyIdentifier($object, $property);

        return $this->convertToObject($object) . '.attr(' . $id . ', ' . $this->escapeBool($update) . ')';
    }

    protected function parseAssignOpShiftRight(Node $node): string
    {
        return $this->parseAssignOp($node, '>>=');
    }

    protected function parseAssignOpBitwiseXor(Node $node): string
    {
        return $this->parseAssignOp($node, '^=');
    }

    protected function parseMagicConst(MagicConst $expr): string
    {
        switch ($expr->getType()) {
            case 'Scalar_MagicConst_Dir':
                return '"' . $this->escapeString($this->dir) . '"';
            case 'Scalar_MagicConst_File':
                return '"' . $this->escapeString($this->file) . '"';
            case 'Scalar_MagicConst_Line':
                return (string) $expr->getStartLine();
            case 'Scalar_MagicConst_Function':
                return '"' . $this->escapeString($this->function) . '"';
            case 'Scalar_MagicConst_Class':
                return '"' . $this->escapeString($this->class) . '"';
            case 'Scalar_MagicConst_Method':
                return '"' . $this->escapeString($this->class) . '::' . $this->escapeString($this->method) . '"';
            default:
                abort($expr);
        }
    }

    protected function parseForeachArray(Foreach_ $node, string $iteratorVar): string
    {
        if ($node->keyVar) {
            $keyVar = $this->parseIdentifier($node->keyVar);
        }

        $code = 'for (auto iter = ' . $iteratorVar . '.begin(); iter != ' . $iteratorVar . '.end(); ++iter) {' . PHP_EOL;
        $this->indentLevel++;
        if ($node->keyVar) {
            $this->checkVar($node, $keyVar);
            $code .= $this->getIndent() . ' ' . $keyVar . ' = iter.key();' . PHP_EOL;
        }

        if ($node->valueVar->getType() == self::EXPR_ARRAY_DIM_FETCH) {
            $array = $this->parseIdentifier($node->valueVar->var);
            if (!$this->hasVar($array) or $node->valueVar->dim === null) {
                abort($node->valueVar);
            }
            $dim = $this->parseIdentifier($node->valueVar->dim);
            $code .= $this->getIndent() . "{$array}.offsetSet({$dim}, iter.value());";
        } else {
            $valueVar = $this->parseIdentifier($node->valueVar);
            $this->checkVar($node, $valueVar);
            $code .= $this->getIndent() . ' ' . $valueVar . ' = iter.value();' . PHP_EOL;
        }

        $body = $this->parseStmts($node->stmts);
        $this->indentLevel--;

        $code .= $this->parseBeforeStmtLines() . PHP_EOL;
        $code .= $body . PHP_EOL;

        $code .= $this->getIndent() . '}';

        return $code;
    }

    protected function parseForeach(Foreach_ $node): string
    {
        if ($node->byRef) {
            $this->fatalError($node, 'Cannot use & with foreach');
        }
        if ($this->isVarExpr($node->expr)) {
            $name = $this->parseIdentifier($node->expr);
            if ($this->hasVar($name)) {
                $type = $this->getVarType($name);
                if ($type === self::TYPE_OBJECT) {
                    return $this->parseForeachObject($node);
                }
            }
        }

        $iteratorVar = $this->genTmpVarName();

        $code = '';
        $expr = $this->parseIdentifier($node->expr);
        $code .= self::TYPE_ARRAY . " {$iteratorVar} = " . $expr . ';' . PHP_EOL;
        $code .= $this->parseBeforeStmtLines() . PHP_EOL;
        $code .= $this->parseForeachArray($node, $iteratorVar);

        return $code;
    }

    protected function formatCppCode(string $file): void
    {
        $cmd = 'cd ' . $this->rootPath . ' && clang-format -i ' . $file;
        $this->climate->info('format: ' . $file);
        $this->climate->comment($cmd);
        shell_exec($cmd);
    }

    protected function detectConstType($expr): string
    {
        $name = $this->parseIdentifier($expr->name);
        if ($this->hasConstant($name)) {
            return $this->getConstantType($name);
        }
        if ($name === 'true') {
            return self::TYPE_BOOL;
        }
        if ($name === 'false') {
            return self::TYPE_BOOL;
        }
        if ($name === 'NAN' or $name === 'INF') {
            return self::TYPE_FLOAT;
        }

        return self::TYPE_VAR;
    }

    protected function parseSwitch(mixed $v): string
    {
        $cond    = $v->cond;
        $tmp_var = $this->genTmpVarName();
        $type    = $this->detectExprType($cond);
        $var_def = $type . ' ' . $tmp_var . ' = ' . $this->parseExpr($cond) . ';' . PHP_EOL;

        // 保存作用域，switch 可能会解析失败，在这个过程中会增加变量，需重置
        $localVars = $this->localVars;
        $code      = $this->parseBeforeStmtLines() . PHP_EOL;

        if ($type === self::TYPE_INT or $type === self::TYPE_FLOAT) {
            $code .= 'switch (' . $tmp_var . ') {' . PHP_EOL;
            $this->indentLevel++;
            foreach ($v->cases as $case) {
                if (empty($case->cond)) {
                    $code .= $this->getIndent() . 'default: {' . PHP_EOL;
                } else {
                    $condType = $case->cond->getType();
                    if ($condType !== 'Scalar_Int' and $condType !== 'Scalar_Float') {
                        $this->localVars = $localVars;
                        goto _fail;
                    }
                    $code .= $this->getIndent() . 'case ' . $this->parseScalar($case->cond) . ': {' . PHP_EOL;
                }
                $this->indentLevel++;
                $code .= $this->parseStmts($case->stmts);
                $this->indentLevel--;
                $code .= $this->getIndent() . '}' . PHP_EOL;
            }
            $this->indentLevel--;
            $code .= $this->getIndent() . '}';

            return $var_def . $code;
        }

        _fail:
        $code = 'do {' . PHP_EOL;
        $this->indentLevel++;
        foreach ($v->cases as $case) {
            if (empty($case->cond)) {
                $code .= $this->getIndent() . 'else {' . PHP_EOL;
            } else {
                $code .= $this->getIndent() . 'if (' . $tmp_var . '==' . $this->parseIdentifier($case->cond) . ') {' . PHP_EOL;
            }
            $this->indentLevel++;
            $code .= $this->parseStmts($case->stmts);
            $this->indentLevel--;
            $code .= $this->getIndent() . '}' . PHP_EOL;
        }
        $this->indentLevel--;
        $code .= $this->getIndent() . '} while (0);';

        return $var_def . $code;
    }

    protected function parseStatic(mixed $v): string
    {
        $list = [];
        foreach ($v->vars as $var) {
            if ($var->default) {
                $type   = $this->detectExprType($var->default);
                $list[] = 'static ' . $type . ' ' . $this->parseIdentifier($var->var) . ' = ' . $this->parseIdentifier($var->default) . ';';
            } else {
                $list[] = 'static ' . self::TYPE_VAR . ' ' . $this->parseIdentifier($var->var) . ';';
            }
        }

        return implode(PHP_EOL . $this->getIndent(), $list);
    }

    protected function parseEval(mixed $expr): string
    {
        return 'php::eval(' . $this->parseIdentifier($expr->expr) . ')';
    }

    protected function parseInclude(Node\Expr\Include_ $expr): string
    {
        switch ($expr->type) {
            case Node\Expr\Include_::TYPE_INCLUDE:
                $type = 'php::INCLUDE';
                break;
            case Node\Expr\Include_::TYPE_INCLUDE_ONCE:
                $type = 'php::INCLUDE_ONCE';
                break;
            case Node\Expr\Include_::TYPE_REQUIRE:
                $type = 'php::REQUIRE';
                break;
            case Node\Expr\Include_::TYPE_REQUIRE_ONCE:
                $type = 'php::REQUIRE_ONCE';
                break;
            default:
                $this->fatalError($expr, 'Invalid include type');
        }

        return 'php::include(' . $this->parseIdentifier($expr->expr) . ', ' . $type . ')';
    }

    protected function parseBreak(mixed $v): string
    {
        if (!$this->inLoop) {
            $this->fatalError($v, 'Cannot break outside loop');
        }
        $num = $v->num;
        if ($num) {
            $value = $this->parseIdentifier($num);
            if ($value > 1) {
                $this->fatalError($v, 'Cannot break more than 1 level');
            }
        }

        return 'break;';
    }

    protected function parseScalarFloat(Node $expr): string
    {
        $value = $expr->value;

        if (is_nan($value)) {
            return self::VALUE_NAN;
        }
        if (is_infinite($value)) {
            return $value > 0 ? self::VALUE_INF : '-' . self::VALUE_INF;
        }
        if (floor($value) == $value && abs($value) < 1e15) {
            return number_format($value, 1, '.', '');
        }
        return sprintf('%.' . $this->floatPrecision . 'g', $value);
    }

    protected function parseIsset(mixed $expr)
    {
        $vars = $expr->vars;
        foreach ($vars as $var) {
            if ($var instanceof Variable) {
                return $this->hasVar($var->name) ? 'true' : 'false';
            }
            if ($var instanceof Node\Expr\ArrayDimFetch) {
                return $this->parseIdentifier($var->var) . '.offsetExists(' . $this->parseIdentifier($var->dim) . ')';
            }
            if ($var instanceof Node\Expr\StaticPropertyFetch) {
                $nativeProp = $this->findNativeStaticProperty($var, $class, $namespace);
                if ($nativeProp) {
                    return 'true';
                }
                return 'php::hasStaticProperty(' . $this->identifierToStr($var->class) . ', ' . $this->identifierToStr($var->name) . ')';
            }
            if ($var instanceof Node\Expr\PropertyFetch) {
                $prop = $var->name;
                $object = $this->parseIdentifier($var->var);
                if ($object === 'this_' and $this->isIdExpr($prop)) {
                    return $this->escapeBool($this->classDef->hasProperty($this->parseIdentifier($prop)));
                }
                return $object . '.propertyExists(' . $this->identifierToStr($prop) . ')';
            }
            abort($var);
        }
    }

    protected function parseEmpty(mixed $expr): string
    {
        return 'php::empty(' . $this->parseExpr($expr->expr) . ')';
    }

    protected function parseCastArray(mixed $expr): string
    {
        return $this->convertArrayExpr($this->parseIdentifier($expr->expr));
    }

    protected function hasGlobalVar($name): bool
    {
        return array_key_exists($name, $this->globalVars);
    }

    protected function parseCastDouble(mixed $expr): string
    {
        return $this->convertFloatExpr($this->parseIdentifier($expr->expr));
    }

    protected function detectFuncCallReturnType(string $name): string
    {
        $returnType = Reflection::getFunctionReturnType($name);
        if ($returnType) {
            return $this->getTypeFromZendType($returnType);
        }
        return self::TYPE_VAR;
    }

    protected function convertExprFromType(string $type, string $expr): string
    {
        if ($type === self::TYPE_FLOAT) {
            return $this->convertFloatExpr($expr);
        }
        if ($type === self::TYPE_INT) {
            return $this->convertIntExpr($expr);
        }
        if ($type === self::TYPE_BOOL) {
            return $this->convertBoolExpr($expr);
        }

        return $expr;
    }

    protected function convertVarType($var, $expr): string
    {
        if ($this->hasVar($var)) {
            return $this->convertExprFromType($this->getVarType($var), $expr);
        }

        return $expr;
    }

    protected function convertToObject(NodeAbstract $object): string
    {
        $id = $this->parseIdentifier($object);
        if ($this->isVarExpr($object) and $this->getVarType($id) === self::TYPE_OBJECT) {
            return $id;
        }

        if (isset($this->objectWrappers[$id])) {
            return $this->objectWrappers[$id];
        }

        $tmpVar = $this->genTmpVarName();
        $this->addLocalVar($tmpVar, self::TYPE_OBJECT);
        $this->beforeStmtLines[]   = $this->getIndent() . $tmpVar . ' = ' . $id . ';';
        $this->objectWrappers[$id] = $tmpVar;

        return $tmpVar;
    }

    protected function parseAssignRef(Node\Expr\AssignRef $expr): string
    {
        if ($this->isVarExpr($expr->var)) {
            $this->inAssignExpr = true;
            $left               = $this->parseIdentifier($expr->var);
            $this->inAssignExpr = false;
            if (!$this->hasVar($left)) {
                $this->addLocalVar($left, self::TYPE_REF);
            } else {
                $type = $this->getVarType($left);
                if ($type !== self::TYPE_REF) {
                    $this->fatalError($expr, 'Cannot assign reference to variable of type ' . $type);
                }
            }
            if ($this->isVarExpr($expr->expr)) {
                return $left . ' = ' . $this->parseIdentifier($expr->expr) . '.toReference()';
            }
            if ($expr->expr->getType() === self::EXPR_ARRAY_DIM_FETCH) {
                return $left . ' = ' . $this->parseIdentifier($expr->expr);
            }
            if ($this->isPropertyFetch($expr->expr)) {
                $left   = $this->parseIdentifier($expr->var);
                $object = $this->convertToObject($expr->expr->var);
                $prop   = $this->identifierToStr($expr->expr->name);

                return $left . ' = ' . $object . '.attrRef(' . $prop . ')';
            }
        }
        abort($expr);
    }

    protected function parseMethodCall(Node\Expr\MethodCall $expr): string
    {
        $object     = $this->convertToObject($expr->var);
        $method     = $this->parseIdentifier($expr->name);
        $nativeFunc = $this->findNativeMethod($expr, $object, $method);
        if ($nativeFunc) {
            return $this->parseNativeMethodCall($object, $nativeFunc, $expr->args);
        }
        if (empty($expr->args)) {
            return $object . '.exec("' . $method . '")';
        }
        return $object . '.exec("' . $method . '", ' . $this->parseCallArgs($expr->args) . ')';
    }

    protected function identifierToStr(NodeAbstract $node, bool $require = true): string
    {
        $id = $this->parseIdentifier($node);
        if ($this->isVarExpr($node)) {
            if ($require) {
                $this->requireVar($node, $id);
            }

            return $id;
        }
        if ($id === 'self') {
            $id = $this->class;
        }

        return '"' . $id . '"';
    }

    protected function requireVar($node, string $var): void
    {
        if (!$this->hasVar($var)) {
            $this->fatalError($node, 'The variable `' . $var . '` is not defined');
        }
    }

    protected function parseStaticCall(Node\Expr\StaticCall $expr): string
    {
        if ($this->isVarExpr($expr->class) or $this->isVarExpr($expr->name)) {
            $fn = 'php::concat({' . $this->identifierToStr($expr->class) . ', "::", ' . $this->identifierToStr($expr->name) . '})';
        } else {
            $class = $this->parseIdentifier($expr->class);
            if ($class === 'self') {
                $class = $this->class;
            } elseif ($class === 'parent') {
                return $this->parseParentMethodCall($expr);
            }
            $method = $this->parseIdentifier($expr->name);
            $ce = $this->getClassEntryPtr($class);
            $fn = $ce . ', ' . $this->getFuncPtr($class . '::' . $method, false);
        }
        $call = 'php::call';
        if (empty($expr->args)) {
            return $call . '(' . $fn . ')';
        }
        return $call . '(' . $fn . ', {' . $this->parseCallArgs($expr->args) . '})';
    }

    protected function findNativeStaticProperty(Node\Expr\StaticPropertyFetch $expr, ?string &$class, ?string &$namespace): ?PropertyDef
    {
        if ($this->isNameExpr($expr->class) and $this->isIdExpr($expr->name)) {
            $class = $this->parseIdentifier($expr->class);
            $prop = $this->parseIdentifier($expr->name);
            if ($class === 'self') {
                $classDef = $this->classDef;
                $class = $this->class;
                $namespace = $this->namespace;
            } else {
                $classDef = $this->classes[$class];
                $namespace = $classDef->namespace;
            }
            if ($classDef->hasProperty($prop)) {
                $propDef = $classDef->getProperty($prop);
                if ($propDef->isStatic()) {
                    return $propDef;
                }
            }
        }
        return null;
    }

    protected function parseNativeStaticPropertyFetch(Node\Expr\StaticPropertyFetch $expr): string|bool
    {
        $nativeProp = $this->findNativeStaticProperty($expr, $class, $namespace);
        if ($nativeProp) {
            $classPtr = $this->getClassEntryPtr($class);
            $propOffset = self::PREFIX . $this->getPropertyOffset($nativeProp->name, $class, $namespace);
            return 'php::getStaticProperty(' . $classPtr . ', ' . $propOffset . ')';
        }
        return false;
    }

    protected function parseStaticPropertyFetch(Node\Expr\StaticPropertyFetch $expr): string
    {
        $native = $this->parseNativeStaticPropertyFetch($expr);
        if ($native) {
            return $native;
        }
        return 'php::getStaticProperty(' . $this->identifierToStr($expr->class) . ', ' . $this->identifierToStr($expr->name) . ')';
    }

    protected function parseClassConstFetch(Node\Expr\ClassConstFetch $expr): string
    {
        $class = $this->parseIdentifier($expr->class);
        $self = false;
        if ($class === 'self' or $class === 'this_') {
            $self = true;
            $class = $this->class;
        }

        $const = $this->escapeString($this->parseIdentifier($expr->name));
        $class = $this->getNamespacedClassName($class);
        if ($const === 'class') {
            return '"' . $this->escapeString($class) . '"';
        }
        if (($self or $this->isNameExpr($expr->class)) and $this->isIdExpr($expr->name)) {
            if ($this->hasNativeClass($class)) {
                $classDef = $this->getClassDef($class);
                if ($classDef->hasConstant($const)) {
                    return $classDef->getConstant($const)->value;
                }
            }
            $ce = $this->getClassEntryPtr($class);
            return 'php::constant(' . $ce . ', ' . $this->getLiteralString($const) . ')';
        } else {
            $name = $class . '::' . $const;
            $name = $this->getLiteralString($name);
            return 'php::constant(' . $name . ')';
        }
    }

    protected function parseThrow(mixed $expr): string
    {
        if (!$this->isVarExpr($expr->expr) and $expr->expr->getType() != self::EXPR_NEW) {
            $this->fatalError($expr, 'The throw statement only accepts a object variable');
        }

        return 'php::throwException(' . $this->parseIdentifier($expr->expr) . ')';
    }

    protected function parseTryCatch(mixed $v): string
    {
        $code = $this->parseBeforeStmtLines() . PHP_EOL;
        $code .= 'try {';
        $stmts = $v->stmts;

        $code .= PHP_EOL;
        $this->indentLevel++;
        $code .= $this->parseStmts($stmts);
        $this->indentLevel--;
        $code .= $this->getIndent() . '}' . PHP_EOL;

        $catches = $v->catches;
        $finally = $v->finally;

        $exVar = $this->genTmpVarName();
        $this->addLocalVar($exVar, self::TYPE_OBJECT);

        $code .= 'catch(zend_object *_ex) {' . PHP_EOL;
        if ($catches) {
            $code .= $this->getIndent() . $exVar . ' = php::catchException();' . PHP_EOL;
            $this->indentLevel++;
            foreach ($catches as $catch) {
                $code .= $this->parseCatch($catch, $exVar);
            }
            $this->indentLevel--;
        }
        $code .= '}' . PHP_EOL;
        if ($finally) {
            $code .= $this->parseStmts($finally->stmts);
            $code .= PHP_EOL;
        }
        $code .= 'if (' . $exVar . ') {' . PHP_EOL . $this->getIndent() . 'php::throwException(' . $exVar . ');' . PHP_EOL . $this->getIndent() . '}';

        return $code;
    }

    protected function parseCatch(mixed $catch, string $exVar): string
    {
        $types = $catch->types;
        $var   = $this->parseIdentifier($catch->var);
        if (!$this->hasVar($var)) {
            $this->addLocalVar($var, self::TYPE_OBJECT);
        }
        $code = $this->getIndent() . $var . ' = ' . $exVar . ';' . PHP_EOL;

        $code .= $this->parseBeforeStmtLines() . PHP_EOL;

        $code .= $this->getIndent() . 'if (' . $var . ' && ';
        foreach ($types as $type) {
            $code .= 'php::instanceOf(' . $var . ', "' . $this->parseIdentifier($type) . '")';
        }

        $code .= ') {' . PHP_EOL;
        $this->indentLevel++;
        $code .= $this->parseStmts($catch->stmts);
        $code .= $this->getIndent() . "{$exVar}.unset();" . PHP_EOL;
        $this->indentLevel--;
        $code .= $this->getIndent() . '}';

        return $code;
    }

    protected function parseShellExec(mixed $expr): string
    {
        return 'php::call("shell_exec", {' . $this->parseInterpolatedString($expr) . '})';
    }

    protected function parseGoto(Node $v): string
    {
        $this->fatalError($v, 'Goto statement is not supported');

        return 'goto ' . $v->name->name . ';';
    }

    protected function parseLabel(Node $v): string
    {
        $this->fatalError($v, 'Label statement is not supported');

        return $v->name->name . ':';
    }

    protected function parseConstDef(mixed $v2): string
    {
        foreach ($v2->consts as $const) {
            $name  = $this->parseIdentifier($const->name);
            $value = $this->parseIdentifier($const->value);
            $this->addConstant($name, $value);
        }

        return '';
    }

    protected function addConstant(string $name, string $value): void
    {
        $constInfo                    = new \stdClass();
        $constInfo->value             = $value;
        $constInfo->type              = $this->detectStrValueType($value);
        $this->nativeConstants[$name] = $constInfo;
    }

    protected function hasConstant(string $name): bool
    {
        return isset($this->nativeConstants[$name]);
    }

    protected function getConstant(string $name): string
    {
        return $this->nativeConstants[$name]->value;
    }

    protected function getConstantType(string $name): string
    {
        return $this->nativeConstants[$name]->type;
    }

    protected function detectStrValueType(mixed $constant): string
    {
        if ($this->isIntStr($constant)) {
            return self::TYPE_INT;
        }
        if ($this->isFloatStr($constant)) {
            return self::TYPE_FLOAT;
        }
        if ($this->isBoolStr($constant)) {
            return self::TYPE_BOOL;
        }

        return self::TYPE_VAR;
    }

    protected function isArrayVar($var): bool
    {
        return $this->isVarExpr($var) and $this->hasVar($var->name) and $this->getVarType($var->name) == self::TYPE_ARRAY;
    }

    protected function setBuildDir(string $string): void
    {
        $this->buildDir = $string;
        if (!is_dir($this->buildDir)) {
            mkdir($this->buildDir, 0777, true);
        }
    }

    protected function isStubFile(string $file): bool
    {
        return str_ends_with($file, '.stub.php');
    }

    /**
     * @throws \Exception
     */
    protected function loadFile(string $file): string
    {
        if (!file_exists($file)) {
            throw new \Exception('File not exists: ' . $file);
        }
        $phpCode = file_get_contents($file);
        if (!$phpCode) {
            throw new \Exception('Can not read file: ' . $file);
        }
        $this->file     = realpath($file);
        $this->dir      = dirname($this->file);
        $this->stubFile = $this->isStubFile($file);

        return $phpCode;
    }

    protected function parseDeclare(mixed $v): void
    {
        $declares = $v->declares;
        foreach ($declares as $declare) {
            $key   = $this->parseIdentifier($declare->key);
            $value = $this->parseIdentifier($declare->value);
            if ($key === 'ticks') {
                $this->fatalError($v, 'declare(ticks=1) is not supported');
            } elseif ($key === 'encoding') {
                if (strtolower($value) !== 'utf-8') {
                    $this->fatalError($v, 'declare(encoding="' . $value . '") is not supported, only UTF-8 is supported');
                }
            }
            $this->strictTypes = boolval(intval($value));
        }
    }

    protected function parseUse(mixed $v2): string
    {
        $code = '';
        if ($this->useCppNamespace) {
            foreach ($v2->uses as $use) {
                $code .= 'using ' . str_replace('\\', '::', $use->name->toString()) . ';' . PHP_EOL;
            }
        } else {
            foreach ($v2->uses as $use) {
                $id = $this->parseIdentifier($use->name);
                if ($use->type === Node\Stmt\Use_::TYPE_FUNCTION) {
                    $rpos = strrpos($id, '\\');
                    $fn   = substr($id, $rpos + 1);
                    $ns   = substr($id, 0, $rpos);
                    // fn => namespace
                    $this->useFunctions[$fn] = $ns;
                } else {
                    $this->useNamespaces[] = $id;
                    if ($use->alias) {
                        $this->useAliases[$use->alias->toString()] = $id;
                    }
                }
            }
        }

        return $code;
    }

    protected function parseErrorSuppress(Node\Expr\ErrorSuppress $expr): string
    {
        if ($expr->expr instanceof Node\Expr\FuncCall) {
            return $this->parseFuncCall($expr->expr, true);
        }
        abort($expr);
    }

    protected function parseContinue(mixed $v): string
    {
        return 'continue;';
    }

    protected function checkVar(NodeAbstract $node, string $name): void
    {
        if (!$this->hasVar($name)) {
            $this->addLocalVar($name, self::TYPE_VAR);
        } else {
            if ($this->getVarType($name) !== self::TYPE_VAR) {
                $this->fatalError($node, 'Cannot assign value to variable of type ' . $this->getVarType($name));
            }
        }
    }

    protected function checkAccessible(ClassDef $classDef, MethodDef $methodDef): bool
    {
        // 在当前类中，允许调用所有方法
        if ($classDef->namespace === $this->namespace and $classDef->name == $this->class) {
            return true;
        }

        // 类外部调用，只允许调用 public 方法
        return $methodDef->flags & Modifiers::PUBLIC;
    }

    protected function findNativeMethod(Node\Expr\MethodCall $expr, string $object, string $method): string|false
    {
        $nativeFunc = '';
        if ($object === 'this_') {
            $nativeFunc = $this->getNativeName($method, $this->namespace, $this->class);
        } elseif (isset($this->objects[$object])) {
            $class = $this->objects[$object];
            if (!$this->hasNativeClass($class)) {
                return false;
            }
            $classDef = $this->classes[$class];
            if (!$classDef->hasMethod($method)) {
                return false;
            }
            $methodDef = $classDef->methods[$method];
            if (!$this->checkAccessible($classDef, $methodDef)) {
                $this->fatalError($expr, 'Method `' . $classDef->getNamespacedName() . '::' . $method . '()` is not accessible');
            }
            if (count($expr->args) < $methodDef->functionDef->argCountRequired) {
                $this->fatalError($expr, 'Method `' . $classDef->getNamespacedName() . '::' . $method . '()` requires ' . $methodDef->functionDef->argCountRequired . ' arguments, ' . count($expr->args) . ' given');
            } elseif (count($expr->args) > count($methodDef->functionDef->argInfoList)) {
                $this->fatalError($expr, 'Method `' . $classDef->getNamespacedName() . '::' . $method . '()` accepts ' . count($methodDef->functionDef->argInfoList) . ' arguments, ' . count($expr->args) . ' given');
            }
            $nativeFunc = $this->getNativeName($method, $classDef->namespace, $classDef->name);
        }
        if ($nativeFunc and $this->isNativeFunction($nativeFunc)) {
            return $nativeFunc;
        }
        return false;
    }

    protected function parseNativeMethodCall(string $object, string $nativeFunc, array $args): string
    {
        if (count($args) === 0) {
            return self::PREFIX . $nativeFunc . '(' . $object . ')';
        }
        return self::PREFIX . $nativeFunc . '(' . $object . ', ' . $this->parseNativeCallArgs($args, $nativeFunc) . ')';
    }

    private function parseAssignPropertyArrayDim(Node $left, Node $right): string
    {
        $obj      = $this->parseIdentifier($left->var->var);
        $propName = $this->identifierToStr($left->var->name);
        $code     = '';
        $value    = $this->trimBrackets($this->parseExpr($right));
        if ($left->dim === null) {
            return $code . "{$obj}.appendArrayProperty({$propName}, {$value})";
        }
        $dim = $this->trimBrackets($this->parseIdentifier($left->dim));

        return $code . "{$obj}.updateArrayProperty({$propName}, {$dim}, {$value})";
    }

    private function parseParentMethodCall(Node\Expr\StaticCall $expr): string
    {
        $method = $this->identifierToStr($expr->name);
        if (empty($expr->args)) {
            return 'this_.callParentMethod(' . $method . ')';
        }
        return 'this_.callParentMethod(' . $method . ', {' . $this->parseCallArgs($expr->args) . '})';
    }
}
