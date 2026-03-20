<?php
/**
 * This file is part of Swoole-Compiler(AOT).
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace PhpAot\Php;

use League\CLImate\CLImate;
use PhpAot\Php\Context\FunctionContext;
use PhpAot\Php\Entity\ClassDef;
use PhpAot\Php\Entity\FunctionDef;
use PhpAot\Php\Entity\InterfaceDef;
use PhpAot\Php\Entity\MethodDef;
use PhpAot\Php\Entity\PropertyDef;
use PhpAot\Php\Exception\DynamicCall;
use PhpAot\Php\Exception\PlaceHolder;
use PhpAot\Php\Exception\Redo;
use PhpAot\Php\Exception\Skip;
use PhpAot\Php\Generator\ClosureGenerator;
use PhpAot\Php\Generator\PlaceHolderGenerator;
use PhpAot\Php\Generator\PropertyPromotion;
use PhpAot\Php\Generator\Utils;
use PhpParser\Modifiers;
use PhpParser\Node;
use PhpParser\Node\Expr\CallLike;
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
    use ClosureGenerator;
    use PlaceHolderGenerator;
    use PropertyPromotion;
    use Utils;

    public const string TYPE_VAR = 'php::Var';

    public const string TYPE_BOOL = 'php::Bool';

    public const string TYPE_INT = 'php::Int';

    public const string TYPE_FLOAT = 'php::Float';
    public const string TYPE_OBJECT = 'php::Object';
    public const string TYPE_ARRAY = 'php::Array';
    public const string TYPE_ARGS = 'php::Args';
    public const string TYPE_STR = 'php::Str';
    public const string TYPE_REF = 'php::Ref';
    public const string TYPE_VOID = 'void';
    public const int DECL_TYPE_OF_RETURN = 1;
    public const int DECL_TYPE_OF_PROPERTY = 2;
    public const int DECL_TYPE_OF_CONST = 3;
    public const int DECL_TYPE_OF_PARAM = 4;
    public const string VALUE_NAN = 'std::numeric_limits<double>::quiet_NaN()';
    public const string VALUE_INF = 'std::numeric_limits<double>::infinity()';
    public const string VALUE_NULL = 'php::null';
    public const string LITERAL_STRINGS = '_literal_strings';
    public const string CLASS_MAP = 'class_map';
    public const string FUNC_MAP = 'func_map';
    public const string PROP_MAP = 'property_map';
    public const string EXPR_VARIABLE = 'Expr_Variable';

    public const string EXPR_NEW = 'Expr_New';

    public const string EXPR_ARRAY_DIM_FETCH = 'Expr_ArrayDimFetch';
    public const string EXPR_PROPERTY_FETCH = 'Expr_PropertyFetch';

    public const string NAMESPACE_SEPARATOR = '__';

    public const string PREFIX = 'php_';
    public const string ANON_CLASS = '_anon_class_';
    public const string STATIC_VAR = '_static_var_';
    public const string OP_ISSET = 'isset';
    public const string OP_EMPTY = 'empty';
    public const string OP_REFVAL = 'toReference';
    public const string OP_NOP = "if (0) {}\n";
    protected string $phpxDir = '~/workspace/projects/phpx';
    protected string $lang = 'PHP';
    protected string $cppCompiler = 'g++';
    protected array $literalStrings = [];
    protected int $literalStringIndex = 0;
    protected int $anonClassIndex = 0;
    protected int $classIndex = 0;

    /**
     * @var array<string, int>
     */
    protected array $classMap = [];
    protected int $funcIndex = 0;

    /**
     * @var array<string, int>
     */
    protected array $funcMap = [];
    protected int $propIndex = 0;
    protected array $propMap = [];
    protected array $zendTypeMap = [
        'int' => self::TYPE_INT,
        'float' => self::TYPE_FLOAT,
        'bool' => self::TYPE_BOOL,
        'false' => self::TYPE_BOOL,
        'true' => self::TYPE_BOOL,
        'void' => self::TYPE_VOID,
        'never' => self::TYPE_VOID,
        'string' => self::TYPE_STR,
        'array' => self::TYPE_ARRAY,
        'object' => self::TYPE_OBJECT,
        'mixed' => self::TYPE_VAR,
        'null' => self::TYPE_VAR,
        // callable 类型，可以是字符串、数组、对象
        // 1) 'foo' 函数名称字符串, 2) [ $obj, 'bar' ] 对象方法数组, 3) Closure 对象， 4) [ 'class', 'staticMethod'] 类名+静态方法数组
        'callable' => self::TYPE_VAR,
        // iterable 类型，可以是数组或者对象
        'iterable' => self::TYPE_VAR,
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

    /**
     * 存储所有函数、类方法的定义，key 是 native name，命名空间需要转为 `_`，并且必须为小写
     * @var array<string, FunctionDef>
     */
    protected array $nativeFunctions = [];
    protected array $internalFunctions = [];
    protected array $nativeConstants = [];
    protected array $functionDeclInFile = [];
    protected array $functionCallInFile = [];
    protected array $redoAfterDeclare = [];
    protected array $constData = [];
    protected int $optimizeLevel = 0;
    protected int $maxJob = 4;
    protected string $buildMode = 'bin';
    protected string $cxxflags = '';
    protected string $ldflags = '';
    protected int $floatPrecision = 17;
    protected bool $debugInfo = true;
    protected bool $noLiteralStrings = false;
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
    protected string $parentClass = '';
    protected string $interface = '';

    /**
     * key 类名，包含命名空间
     * @var array<string, ClassDef>
     */
    protected array $classes = [];

    /**
     * @var array<string, InterfaceDef>
     */
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
    protected ?FunctionDef $functionDef = null;
    protected ?ClassDef $classDef = null;
    protected ?MethodDef $methodDef = null;
    protected ?InterfaceDef $interfaceDef = null;
    protected FunctionContext $context;

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
    protected bool $strictTypes = false;
    protected string $rootPath;
    protected string $buildDir;
    protected int $debugLine = 0;
    protected CLImate $climate;
    protected bool $defaultNativeType = false;
    protected bool $stubFile = false;
    protected bool $enableProfiler = false;
    protected Parser $parser;
    protected PrettyPrinter $printer;

    /**
     * 在预处理阶段获取所有类的方法名称，检测子类和父类中存在的同名方法，解决动态绑定方法调用的问题
     * `static::methodCall()`
     * `$this->methodCall()` 子类和父类中存在同名方法
     * @var array<string, bool>
     */
    protected array $classMethodOverride = [];
    protected array $classExtends = [];

    public function __construct(string $rootPath)
    {
        $this->rootPath = $rootPath;
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
        $this->printer = new PrettyPrinter\Standard();
        $this->setBuildDir($rootPath . '/build');
        $climate = new CLImate();
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

    public function isTypedObject(string $object): bool
    {
        return isset($this->context->objects[$object]);
    }

    public function getObjectType(string $object): string
    {
        return $this->context->objects[$object] ?? 'stdClass';
    }

    public function parseExpr(NodeAbstract $expr)
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
                return $this->parseAssign($expr);
            case 'Expr_AssignRef':
                return $this->parseAssignRef($expr);
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
            case 'Expr_BinaryOp_Coalesce':
                return $this->parseBinaryOpCoalesce($expr);
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
            case 'Expr_AssignOp_Coalesce':
                return $this->parseAssignOpCoalesce($expr);
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
                return $this->parseArrayDimFetch($expr, $this->context->inAssignExpr);
            case self::EXPR_PROPERTY_FETCH:
                return $this->parsePropertyFetch($expr, $this->context->inAssignExpr);
            case 'Expr_NullsafePropertyFetch':
                return $this->parseNullsafePropertyFetch($expr);
            case 'Expr_NullsafeMethodCall':
                return $this->parseNullsafeMethodCall($expr);
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
            case 'Expr_Match':
                return $this->parseMatch($expr);
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
            case 'Expr_Closure':
                return $this->parseClosure($expr);
            case 'Expr_ArrowFunction':
                return $this->parseArrowFunction($expr);
            case 'Name_FullyQualified':
                return $this->parseFullyQualifiedName($expr);
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
            case 'Expr_Yield':
            case 'Expr_YieldFrom':
                $this->fatalError($expr, 'The `' . $type . '` is not supported');
                // no break
            default:
                abort($expr);
        }
    }

    public function stop(string $string): never
    {
        $this->climate->red($string . "\n");
        exit(1);
    }

    public function genTmpVarName(): string
    {
        return 'tmp_var_' . $this->context->tmpVarIndex++;
    }

    public function genAnonClassName(): string
    {
        return self::ANON_CLASS . $this->anonClassIndex++;
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
            return $this->context->localVars[$name];
        }
        if ($this->hasLocalVar($name)) {
            return $this->globalVars[$name];
        }

        return self::TYPE_VAR;
    }

    protected function resetFunction(): void
    {
        $this->context = new FunctionContext();
        $this->function = '';
        $this->functionDef = null;
    }

    protected function resetMethod(): void
    {
        $this->method = '';
        $this->methodDef = null;
    }

    protected function resetClass(): void
    {
        $this->class     = '';
        $this->interface = '';
        $this->method    = '';
        $this->classDef  = null;
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

    protected function getStaticVarName(string $name): string
    {
        $prefix = self::STATIC_VAR;
        if ($this->namespace) {
            $prefix .= $this->escapeNamespace($this->namespace) . '_';
        }
        if ($this->class) {
            $prefix .= $this->escapeClass($this->class) . '_';
            if ($this->method) {
                $prefix .= $this->method . '_';
            }
        } else {
            if ($this->function) {
                $prefix .= $this->function . '_';
            }
        }
        return $this->escapeName($prefix . $name);
    }

    protected function getNamespacedClassName(string $class): string
    {
        if ($class === '') {
            $this->error('Class name can not be empty');
        }
        if ($class[0] === '\\') {
            return ltrim($class, '\\');
        }

        $ns2 = explode('\\', trim($class, '\\'));

        if (isset($this->useAliases[$ns2[0]])) {
            $ns = '\\' . $this->useAliases[$ns2[0]];
            _return:
            if (count($ns2) > 1) {
                $ns .= '\\' . implode('\\', array_slice($ns2, 1));
            }
            return ltrim($ns, '\\');
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
            return trim($currentNamespace, '\\') . '\\' . $class;
        }

        return $class;
    }

    protected function getNativeName(string $fn, string $ns = '', string $class = ''): string
    {
        $names = [];
        if ($ns) {
            $names[] = $this->escapeNamespace($ns);
        }
        if ($class) {
            $names[] = $this->escapeClass($class);
        }
        if ($fn) {
            $names[] = $this->escapeName($fn);
        }
        return implode(self::NAMESPACE_SEPARATOR, $names);
    }

    protected function getClassId(string $className): int
    {
        if (isset($this->classMap[$className])) {
            $id = $this->classMap[$className];
        } else {
            $id = $this->classIndex++;
            $this->classMap[$className] = $id;
        }
        return $id;
    }

    protected function getFuncId(string $funcName): int
    {
        if (isset($this->funcMap[$funcName])) {
            $id = $this->funcMap[$funcName];
        } else {
            $id = $this->funcIndex++;
            $this->funcMap[$funcName] = $id;
        }
        return $id;
    }

    /**
     * @param string $className 必须是带有命名空间的完整类名
     */
    protected function getPropertyId(string $className, string $propName): int
    {
        $key = $className . '::' . $propName;
        if (isset($this->propMap[$key])) {
            $id = $this->propMap[$key];
        } else {
            $id = $this->propIndex++;
            $this->propMap[$key] = $id;
        }
        return $id;
    }

    protected function getClassEntryPtr(string $className): string
    {
        $id = $this->getClassId($className);
        return 'php_get_class(' . $id . ', ' . $this->getLiteralString($className) . ')';
    }

    protected function getCeWrapper(string $className): string
    {
        if (isset($this->context->ceWrappers[$className])) {
            return $this->context->ceWrappers[$className];
        }
        $object = $this->addTmpVar(self::TYPE_OBJECT);
        $this->context->beforeStmtLines[] = 'Z_PTR_P(' . $object . '.ptr()) = ' . $this->getClassEntryPtr($className) . ';';
        $this->context->ceWrappers[$className] = $object;
        return $object;
    }

    protected function getFuncPtr(string $funcName): string
    {
        return 'php_get_func(' . $this->getFuncId($funcName) . ', ' . $this->getLiteralString($funcName) . ')';
    }

    protected function getMethodPtr(string $class, string $method): string
    {
        $funcId = $this->getFuncId($class . '::' . $method);
        $classId = $this->getClassId($class);
        return 'php_get_method(' . $funcId . ', ' . $this->getLiteralString($method) . ', ' . $classId . ', ' . $this->getLiteralString($class) . ')';
    }

    protected function getPropertyOffset(string $class, string $prop): string
    {
        $funcId = $this->getPropertyId($class, $prop);
        $classId = $this->getClassId($class);
        return 'php_get_prop(' . $funcId . ', ' . $this->getLiteralString($prop) . ', ' . $classId . ', ' . $this->getLiteralString($class) . ')';
    }

    protected function parseTypeDecl(?NodeAbstract $type, int $what): string
    {
        // 未定义类型，默认是 var (mixed, any)
        if ($type === null) {
            return self::TYPE_VAR;
        } elseif ($type instanceof UnionType or $type instanceof NullableType) {
            // 联合类型暂时不支持，使用 var 类型代替
            return self::TYPE_VAR;
        } else {
            $typeName = $this->parseIdentifier($type);
            // 属性和类常量的类型不能声明为 void/never ，只有返回值可以
            if ($what !== self::DECL_TYPE_OF_RETURN and ($typeName === 'void' or $typeName === 'never')) {
                $this->fatalError($type, 'The type `void`/`never` is allowed only for return type');
            } else {
                return $this->getTypeFromZendType($typeName);
            }
        }
    }

    protected function parseFunctionDecl(Node\Stmt\Function_|Node\Stmt\ClassMethod $v): FunctionDef
    {
        // .stub 存根定义 C++ Native 函数，必须设置返回值类型
        if (!$v->returnType && $this->stubFile) {
            throw new \Exception('No return type for ' . $v->name);
        }

        $fnName = $this->parseIdentifier($v->name);
        $returnType = $this->parseTypeDecl($v->returnType, self::DECL_TYPE_OF_RETURN);
        $functionDef = new FunctionDef($fnName, $returnType, $this->namespace);
        $this->functionDef = $functionDef;

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
                if ($functionDef->argInfoList[0]->type !== self::TYPE_VAR and $functionDef->argInfoList[0]->type !== self::TYPE_INT) {
                    $this->fatalError($v, 'The first parameter of the main function must be of type `int`.');
                }
                if ($functionDef->argInfoList[1]->type !== self::TYPE_VAR and $functionDef->argInfoList[1]->type !== self::TYPE_ARRAY) {
                    $this->fatalError($v, 'The second parameter of the main function must be of type `array`.');
                }
            }
        }

        return $functionDef;
    }

    /**
     * @throws \Exception
     */
    protected function parseFunction(Node\Stmt\Function_|Node\Stmt\ClassMethod $v): string
    {
        $this->resetFunction();
        $this->function = $this->parseIdentifier($v->name);
        $name = $this->getFunctionName($v);
        if (!empty($this->nativeFunctions[$name])) {
            $this->functionDef = $this->nativeFunctions[$name];
        } else {
            $this->nativeFunctions[$name] = $this->parseFunctionDecl($v);
            if (isset($this->redoAfterDeclare[$name])) {
                unset($this->redoAfterDeclare[$name]);
                $this->climate->cyan('Received redo request, retrying...');
                throw new Redo();
            }
        }

        // 类方法不要保存到 functions 中
        if ($this->class) {
            $this->addArgument('this_', self::TYPE_OBJECT);
            if ($this->methodDef) {
                $this->functionDef->method = true;
                $this->methodDef->functionDef = $this->functionDef;
            }
        } else {
            $this->functions[$name] = $this->functionDef;
            $this->functionDefineInFile[$name] = $this->functionDef;
        }

        foreach ($this->functionDef->argInfoList as $argInfo) {
            $this->addArgument($argInfo->name, $argInfo->type);
        }

        $stmts = '';
        if ($v->stmts) {
            $this->indentLevel++;
            try {
                $stmts = $this->parseStmts($v->stmts);
                if (!$this->isReturnStmtInLastLine($v->stmts)) {
                    $stmts .= $this->genReturnCode();
                }
            } catch (Skip) {
                $this->climate->cyan('Skip function ' . $name);
            }
            $this->indentLevel--;
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
        $code .= $this->genLocalVarDecl();
        $code .= "\n";
        // Constructor Property Promotion
        foreach ($this->functionDef->argInfoList as $argInfo) {
            if (!$argInfo->property) {
                continue;
            }
            $code .= $this->genPropertyPromotion($argInfo);
        }
        $this->indentLevel--;
        $code .= $this->genDebugInfo();
        $code .= $stmts;
        $code .= "}\n";

        $this->resetFunction();

        return $code;
    }

    protected function writeLog($msg): void
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

    protected function parseVariable(Variable $expr): string
    {
        if (is_object($expr->name) and $this->isVarExpr($expr->name)) {
            $this->fatalError($expr, 'The `$$` syntax is not supported');
        }
        if ($this->isSuperGlobal($expr->name) and !$this->hasGlobalVar($expr->name)) {
            $this->addGlobalVar($expr->name, $this->superGlobalVars[$expr->name]);
        }
        if ($this->hasStaticVar($expr->name)) {
            return $this->getStaticVarName($expr->name);
        }
        return $this->escapeVarName($expr->name);
    }

    protected function parseIdentifier(NodeAbstract $expr): string
    {
        $type = $expr->getType();
        switch ($type) {
            case self::EXPR_VARIABLE:
                return $this->parseVariable($expr);
            case 'Name':
            case 'Name_FullyQualified':
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

    protected function parseParamDefaultValue(?NodeAbstract $default): ?string
    {
        if (!$default) {
            return null;
        }
        /*
         * 函数参数默认值只能为字面量，无法使用表达式获取值
         */
        if ($default instanceof Node\Expr\ConstFetch) {
            return $this->parseConstFetch($default, true);
        } else {
            return $this->parseIdentifier($default);
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
                } else {
                    $type = $param->type === null ? '' : $param->type;
                    $nullable = false;
                }
                $default = $this->parseParamDefaultValue($param->default);
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
            $name          = $this->parseIdentifier($param->var);
            $type          = $this->parseParameterType($param, $name);
            if ($param->variadic) {
                $list[]        = self::TYPE_ARRAY . ' ' . $name;
            } else {
                $list[]        = $type . ' ' . $name;
            }
            $argInfo       = new ArgInfo();
            $argInfo->name = $name;
            $argInfo->type = $type;
            $argInfo->byRef = $param->byRef;
            $argInfo->variadic = $param->variadic;
            $argInfo->property = $param->isPromoted();
            if ($param->type and $param->type instanceof Node\NullableType) {
                $argInfo->nullable = true;
            }
            if ($param->default) {
                $defaultValueCount++;
                $argInfo->default = $this->parseParamDefaultValue($param->default);
                $argInfo->defaultValue = $param->default;
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
        if ($this->context->beforeStmtLines) {
            $code                  = implode(PHP_EOL, $this->context->beforeStmtLines);
            $this->context->beforeStmtLines = [];

            return $code . PHP_EOL;
        }
        return '';
    }

    protected function parseStmts(array $stmts): string
    {
        $lines     = [];
        $inLoopTop = $this->context->inLoop;
        $last = array_key_last($stmts);
        foreach ($stmts as $i => $v) {
            $class                 = $v->getType();
            $this->context->beforeStmtLines = [];
            $this->context->afterStmtLines  = [];
            $result                = '';
            $this->writeLog('Line ' . $this->getLine($v) . ': ' . $class);
            $lines[] = $this->genDebugInfo($v);
            $lines[] = $this->getComment($v, $class);
            switch ($class) {
                case 'Stmt_Expression':
                    $result = $this->parseExpr($v->expr) . ';';
                    break;
                case 'Stmt_Echo':
                    $result = $this->parseEcho($v);
                    break;
                case 'Stmt_Return':
                    $result = $this->parseReturn($v);
                    break;
                case 'Stmt_For':
                    $this->context->inLoop = true;
                    $result       = $this->parseFor($v);
                    $this->context->inLoop = $inLoopTop;
                    break;
                case 'Stmt_Foreach':
                    $this->context->inLoop = true;
                    $result       = $this->parseForeach($v);
                    $this->context->inLoop = $inLoopTop;
                    break;
                case 'Stmt_Switch':
                    $this->context->inLoop = true;
                    $result       = $this->parseSwitch($v);
                    $this->context->inLoop = $inLoopTop;
                    break;
                case 'Stmt_While':
                    $this->context->inLoop = true;
                    $result       = $this->parseWhile($v);
                    $this->context->inLoop = $inLoopTop;
                    break;
                case 'Stmt_Do':
                    $this->context->inLoop = true;
                    $result       = $this->parseDo($v);
                    $this->context->inLoop = $inLoopTop;
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
                    if ($i === $last) {
                        $result .= self::OP_NOP;
                    }
                    break;
                case 'Stmt_Continue':
                    $result = $this->parseContinue($v);
                    break;
                case 'Stmt_Nop':
                    break;
                case 'Stmt_Global':
                    $result = $this->parseGlobal($v);
                    break;
                case 'Stmt_Enum':
                    $result = $this->parseEnum($v);
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
                    // no break
                default:
                    abort($v);
            }
            $lines                 = array_merge($lines, $this->context->beforeStmtLines);
            $this->context->beforeStmtLines = [];
            if ($result) {
                $lines[] = $result;
            }
            if ($this->context->afterStmtLines) {
                $lines                = array_merge($lines, $this->context->afterStmtLines);
                $this->context->afterStmtLines = [];
            }
        }

        $code = '';
        foreach ($lines as $line) {
            $code .= $this->getIndent() . $line . PHP_EOL;
        }

        return $code;
    }

    protected function parseAssignArrayDim(NodeAbstract $left, NodeAbstract $right): string
    {
        if ($this->isPropertyFetch($left)) {
            return $this->parseAssignPropertyArrayDim($left, $right);
        }
        $oriInAssignExpr    = $this->context->inAssignExpr;
        $this->context->inAssignExpr = true;
        $array              = $this->parseIdentifier($left->var);
        $this->context->inAssignExpr = $oriInAssignExpr;
        $code               = '';
        // 这是 PHP 的初始化+赋值写法，需要先创建数组
        if (!$this->hasVar($array) and $this->isVarExpr($left->var)) {
            $this->addLocalVar($array, self::TYPE_ARRAY);
        }

        $value = $this->trimBrackets($this->parseExpr($right));
        if ($left->dim === null) {
            return $code . "{$array}.offsetSet(" . self::VALUE_NULL . ", {$value})";
        }
        $dim = $this->trimBrackets($this->parseIdentifier($left->dim));

        return $code . "{$array}.offsetSet({$dim}, {$value})";
    }

    protected function parseAssignPropertyFetch(NodeAbstract $left, NodeAbstract $right): string
    {
        $array = $this->parseIdentifier($left->var);
        $propName = $this->identifierToStr($left->name, literal: true);

        return "{$array}.setProperty({$propName}, " . $this->trimBrackets($this->parseExpr($right)) . ')';
    }

    protected function parseRightAssociativeAssign(NodeAbstract $left, Node\Expr\Assign $right): string
    {
        $chain[] = $left;
        $next    = $right;
        while ($next->getType() === 'Expr_Assign') {
            $var = $next->var;
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
            $list[] = $this->getIndent() . $this->parseAssignFinally($var, $right);
        }

        return implode(";\n" . $this->getIndent(), $list);
    }

    protected function parseAssignStaticProperty($left, $right): string
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
        return $this->parseAssignFinally($left, $right);
    }

    protected function parseAssignFinally($left, $right): string
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
                    $oriInAssignExpr = $this->context->inAssignExpr;
                    $this->context->inAssignExpr = true;
                    $var = $this->parseIdentifier($item->value);
                    $this->context->inAssignExpr = $oriInAssignExpr;
                    if ($this->isVarExpr($item->value) and !$this->hasVar($var)) {
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

        $oriInAssignExpr = $this->context->inAssignExpr;
        $this->context->inAssignExpr = true;
        $var = $this->parseIdentifier($left);
        $this->context->inAssignExpr = $oriInAssignExpr;
        if ($var === 'this_') {
            $this->fatalError($left, 'Cannot re-assign $this');
        }

        $expr = $this->parseExpr($right);
        $type = $this->detectExprType($right);

        if ($this->isVarExpr($left)) {
            // 类型推断，获取对象的类名
            if ($this->isNewExpr($right) and $this->isNameExpr($right->class)) {
                $class = $this->parseIdentifier($right->class);
                $this->addObject($var, $this->getNamespacedClassName($class));
                $type = self::TYPE_OBJECT;
            } elseif ($this->isFuncCallExpr($right) and $this->isNameExpr($right->name)) {
                $fn = $this->parseIdentifier($right->name);
                if (count($right->args) === 2 and $fn === 'objval' and $this->isScalarString($right->args[1]->value)) {
                    $this->addObject($var, $this->getNamespacedClassName($this->parseIdentifier($right->args[1]->value)));
                    $type = self::TYPE_OBJECT;
                } elseif (count($right->args) === 1 and $fn === 'any') {
                    $type = self::TYPE_VAR;
                    if (!$this->hasVar($var)) {
                        $this->addLocalVar($var, $type);
                    }
                    return $var . ' = ' . $this->parseIdentifier($right->args[0]->value);
                } else {
                    $type = $type === self::TYPE_VOID ? self::TYPE_VAR : $type;
                }
            } elseif ($this->isStaticCall($right) and $this->isIdExpr($right->name)) {
                $class = $this->parseIdentifier($right->class);
                if ($class === 'std') {
                    $func = $this->parseIdentifier($right->name);
                    $type = match ($func) {
                        'int' => self::TYPE_INT,
                        'float' => self::TYPE_FLOAT,
                        'bool' => self::TYPE_BOOL,
                        default => '',
                    };
                    // Native 类型
                    if ($type) {
                        if (!$this->hasVar($var)) {
                            $this->addLocalVar($var, $type);
                        }
                        $expr = $this->parseExpr($right->args[0]->value);
                        return $var . ' = ' . $this->convertExprFromType($type, $expr);
                    }
                }
            }

            if (!$this->hasVar($var)) {
                $this->addLocalVar($var, $type);
            }
        } elseif ($this->isPropertyFetch($left) and !$left->getAttribute('nativeProperty')) {
            return $this->parseAssignPropertyFetch($left, $right);
        } elseif ($this->isArrayDimFetch($left)) {
            $tmp = $this->parseIdentifier($left->var);
            if ($this->isVarExpr($left->var) and $this->getVarType($tmp) === self::TYPE_STR) {
                if ($left->dim === null) {
                    $this->fatalError($left, 'Cannot use [] for strings');
                }
            }
        }

        return $var . ' = ' . $this->convertExprType($expr, $this->detectExprType($left), $this->detectExprType($right));
    }

    protected function parseEcho(mixed $v): string
    {
        $lines = [];
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

    protected function isNativeType(string $type): bool
    {
        return in_array($type, [self::TYPE_INT, self::TYPE_FLOAT, self::TYPE_BOOL]);
    }

    protected function isNativeTypeVar(string $var): bool
    {
        return $this->isNativeType($this->getVarType($var));
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
     */
    protected function parseNumericIdentifier(NodeAbstract $expr): float|int|string
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

    protected function parseBinaryOpPlus(Node\Expr\BinaryOp\Plus $expr): string
    {
        return $this->parseBinaryOp($expr->left, $expr->right, '+');
    }

    protected function parseReturn(Node\Stmt\Return_ $v): string
    {
        if ($v->expr === null) {
            if ($this->functionDef->returnType === self::TYPE_VOID and !$this->context->inClosure) {
                return 'return;';
            } else {
                return 'return ' . self::VALUE_NULL . ';';
            }
        }
        // 实际函数的返回值
        $type = $this->detectExprType($v->expr);
        $expr = $this->parseExpr($v->expr);

        // 匿名函数的返回值一定是 var
        if (!$this->context->inClosure) {
            // 函数定义时没有声明返回值，但函数体中有返回值，修改为实际的返回值类型
            if ($this->getReturnType() === 'void') {
                $this->resetReturnType($v, $type);
            } elseif ($this->isNativeType($type) and $this->getReturnType() !== self::TYPE_VAR and $this->getReturnType() !== $type) {
                // 返回值类型不一致，说明存在多种类型的返回值，修改为 var 表示 any
                $this->resetReturnType($v, self::TYPE_VAR);
            }
            $returnType = $this->getReturnType();
        } else {
            $returnType = self::TYPE_VAR;
        }

        $exprCode = $this->convertExprType($expr, $returnType, $type);
        // return 如果使用了 Indirect 语句，可能会导致变量提前析构，出现悬空指针
        // 将 Indirect 赋值给临时变量后，使用 Ctor::Copy 解除了 Indirect，保证内存安全
        if (!$this->isVarExpr($v->expr) and !$this->isScalar($v->expr)) {
            $tmpVar = $this->genTmpVarName();
            // 必须提前声明变量，否则在末尾声明并 return 可能会被 gcc 优化掉
            $this->addLocalVar($tmpVar, $returnType);
            $code = $tmpVar . ' = ' . $exprCode . ';' . PHP_EOL;
            // 解析表达式后可能会插入语句，因此需要在末尾添加 return 语句，而不是直接返回
            $this->context->afterStmtLines[] = $this->getIndent() . 'return ' . $tmpVar . ';';
        } else {
            $code = 'return ' . $exprCode . ';';
        }

        return $code;
    }

    protected function parseBinaryOpMul(Node\Expr\BinaryOp\Mul $expr): string
    {
        return $this->parseBinaryOp($expr->left, $expr->right, '*');
    }

    protected function addLocalVar(string $name, string $type): void
    {
        $this->context->localVars[$name] = $type;
    }

    protected function addTmpVar(string $type): string
    {
        $var = $this->genTmpVarName();
        $this->addLocalVar($var, $type);
        return $var;
    }

    protected function addStaticVar(string $name, string $type): void
    {
        $this->context->staticVars[$name] = $type;
        $this->addGlobalVar($this->getStaticVarName($name), $type);
    }

    protected function addArgument(string $name, string $type): void
    {
        $this->context->arguments[$name] = $type;
        $this->addLocalVar($name, $type);
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

    protected function addClass(string $name, ClassDef $classDef): void
    {
        $this->classes[$this->escapeClass($name)] = $classDef;
    }

    protected function addObject(string $name, string $class): void
    {
        $this->context->objects[$name] = $class;
    }

    protected function addFunction(string $name, FunctionDef $functionDef): void
    {
        $this->functions[$this->escapeFunction($name)] = $functionDef;
    }

    protected function hasVar(string $name): bool
    {
        return $this->hasLocalVar($name) || $this->hasGlobalVar($name) || $this->hasStaticVar($name);
    }

    protected function hasLocalVar(string $name): bool
    {
        return isset($this->context->localVars[$name]);
    }

    /**
     * @param string $name 必须传入带有完整命名空间的类名，将会自动转义为 native name
     */
    protected function hasNativeClass(string $name): bool
    {
        return array_key_exists($this->escapeClass($name), $this->classes);
    }

    protected function getNativeClass(string $name): ClassDef
    {
        return $this->classes[$this->escapeClass($name)];
    }

    /**
     * @param string $name 必须传入带有完整命名空间的类名，将会自动转义为 native name
     */
    protected function hasNativeFunction(string $name): bool
    {
        return array_key_exists($this->escapeFunction($name), $this->nativeFunctions);
    }

    protected function getNativeFunction(string $name): FunctionDef
    {
        return $this->nativeFunctions[$this->escapeFunction($name)];
    }

    protected function checkNativeFunction(string $name): void
    {
        // 在预处理阶段检测到函数声明，但是未定义，说明在当前文件，但是顺序错误
        // 跳过，稍后再处理
        if (isset($this->functionDeclInFile[$name])
            and $this->functionDeclInFile[$name] === $this->file
            and !$this->hasNativeFunction($name)) {
            $this->redoAfterDeclare[$name] = true;
            throw new Skip();
        }
    }

    protected function checkNativeCallArgs(CallLike $expr, FunctionDef $funcDef, array $args, string $name): void
    {
        $argc = count($args);
        $type = str_contains($name, '::') ? 'Method' : 'Function';
        if ($argc < $funcDef->argCountRequired) {
            $this->fatalError($expr, $type . ' `' . $name . '()` requires ' . $funcDef->argCountRequired . ' arguments, ' . $argc . ' given');
        } elseif (!$funcDef->hasVariadicArg() and count($expr->args) > count($funcDef->argInfoList)) {
            $this->fatalError($expr, $type . ' `' . $name . '()` accepts ' . count($funcDef->argInfoList) . ' arguments, ' . $argc . ' given');
        }
    }

    protected function getNativeMethod(CallLike $expr, string $class, string $method): string|false
    {
        if (!$this->hasNativeClass($class)) {
            return false;
        }

        $classDef = $this->getClassDef($class);
        $methodDef = null;
        // 递归查找，若子类中未定义方法，则尝试查找父类是否存在此方法
        while ($classDef) {
            if (!$classDef->hasMethod($method)) {
                if (!$classDef->extends) {
                    return false;
                }
                if (!$this->hasNativeClass($classDef->extends)) {
                    if ($classDef->inheritedFromInternalClass) {
                        if (!Reflection::hasMethod($classDef->extends, $method) and !Reflection::hasMethod($classDef->extends, $method . '__call')) {
                            $this->fatalError($expr, 'Class `' . $classDef->getNamespacedName() . '` inherits from a internal class, but the class `' .
                                $classDef->extends . '` does not have a `' . $method . '` method or a `__call` magic method');
                        } else {
                            $this->climate->cyan('Dynamically calling internal class method `' . $classDef->extends . '::' . $method . '()`');
                            throw new DynamicCall;
                        }
                    }
                    return false;
                }
                $classDef = $this->getClassDef($classDef->extends);
            } else {
                $methodDef = $classDef->getMethod($method);
                break;
            }
        }

        if (!$this->checkAccessible($classDef, $methodDef)) {
            $this->fatalError($expr, 'Method `' . $classDef->getNamespacedName() . '::' . $method . '()` is not accessible');
        }
        // 函数调用占位符，不是真实的函数调用
        if (count($expr->args) === 1 and $this->isPlaceholderExpr($expr->args[0])) {
            return false;
        }
        $this->checkNativeCallArgs($expr, $methodDef->functionDef, $expr->args, $classDef->getNamespacedName() . '::' . $method);
        return $this->getNativeName($method, $classDef->namespace, $classDef->name);
    }

    protected function getClassDef(string $name): ClassDef
    {
        return $this->classes[$this->escapeClass($name)];
    }

    protected function resetReturnType(Node\Stmt\Return_ $node, string $type): void
    {
        $this->functionDef->returnType = $type;
        // 返回值变更，需要重新解析
        $this->climate->cyan('Return type changed at line ' . $node->getLine() . ', retrying...');
        throw new Redo();
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
                return $this->getNativeType(self::TYPE_INT);
            case 'Expr_Cast_Float':
            case 'Expr_Cast_Double':
            case 'Scalar_Float':
                return $this->getNativeType(self::TYPE_FLOAT);
            case 'Expr_Cast_Bool':
            case 'Scalar_Bool':
                return $this->getNativeType(self::TYPE_BOOL);
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
                    // 除法存在特殊性，若未能整除，会返回浮点数，其他则一律视为整数
                    if ($exprType === 'Expr_BinaryOp_Div') {
                        if ($leftType === self::TYPE_INT && $rightType === self::TYPE_INT) {
                            return self::TYPE_INT;
                        } else {
                            return self::TYPE_VAR;
                        }
                    }
                    return self::TYPE_INT;
                }
                break;
            case 'Expr_FuncCall':
                $name = $this->parseIdentifier($expr->name);
                if ($this->hasNativeFunction($name)) {
                    return $this->getNativeFunction($name)->returnType;
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

    protected function parseArray(Node\Expr\Array_ $node): string
    {
        $items = $node->items;
        // 优化代码风格，空数组直接返回{}，否则会产生一些空洞内容
        if (count($items) === 0) {
            return self::TYPE_ARRAY . '{}';
        }

        $hasKey = false;
        $hasIntKey = false;
        $hasStrKey = false;
        $hasUnpack = false;
        $hasVarKey = false;
        $hasNextInsert = false;
        foreach ($items as $item) {
            if ($item->unpack) {
                $hasUnpack = true;
            }
            if ($item->key) {
                if ($item->key instanceof Node\Scalar\LNumber) {
                    $hasIntKey = true;
                } elseif ($item->key instanceof Node\Scalar\String_) {
                    $hasStrKey = true;
                } else {
                    $hasVarKey = true;
                }
                $hasKey = true;
            } else {
                $hasNextInsert = true;
            }
        }

        // 存在混合键，则需要拆分为多行插入
        if ($hasUnpack or $hasVarKey or ($hasNextInsert && $hasKey) or ($hasIntKey and $hasStrKey)) {
            return $this->parseArrayMixed($node);
        }

        $list = [];
        $this->indentLevel++;
        foreach ($items as $item) {
            $value = $this->parseIdentifier($item->value);
            if ($item->key) {
                $key = $this->parseIdentifier($item->key);
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
        if ($param->byRef) {
            return self::TYPE_REF;
        }
        if ($param->type === null or $param->type instanceof Node\NullableType or $param->type instanceof UnionType) {
            return self::TYPE_VAR;
        }
        $type = $param->type;
        $typeName = $type->name;
        if ($typeName === 'void' or $typeName === 'never') {
            $this->fatalError($param, 'Cannot use `void`/`never` as a parameter type.');
        } elseif ($typeName === 'self') {
            $this->addObject($var, $this->classDef->getNamespacedName(false));
            return self::TYPE_OBJECT;
        } elseif (isset($this->zendTypeMap[$typeName])) {
            return $this->getTypeFromZendType($typeName);
        } else {
            $this->addObject($var, $this->getNamespacedClassName($typeName));
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
                $cmd .= ' -fPIC';
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

    protected function parseBinaryOpConcat(Node\Expr\BinaryOp\Concat $expr): string
    {
        $left  = $this->parseIdentifier($expr->left);
        $right = $this->parseIdentifier($expr->right);

        return 'php::concat(' . $left . ', ' . $right . ')';
    }

    protected function parseFor(Node\Stmt\For_ $v): string
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

    protected function parseBinaryOpSmaller(Node\Expr\BinaryOp\Smaller $expr): string
    {
        return $this->convertBoolExpr($this->parseBinaryOp($expr->left, $expr->right, '<'));
    }

    protected function parsePreInc(Node\Expr\PreInc $expr): string
    {
        return '++' . $this->parseIdentifier($expr->var);
    }

    protected function removeAssignOp(string $op): string
    {
        return str_replace('=', '', $op);
    }

    protected function parseAssignOp(Node\Expr\AssignOp $node, string $op): string
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
                $this->context->beforeStmtLines[] = "{$tmpVar} = php::concat(" .
                    $this->convertVarType($tmpVar, $var) . ', ' .
                    $this->convertExprType($expr, $type, $rightType) . ');';
            } else {
                $this->context->beforeStmtLines[] = "{$tmpVar} = " .
                    $this->convertVarType($tmpVar, $var) . ' ' .
                    $binaryOp . ' ' .
                    $this->convertExprType($expr, $type, $rightType) . ';';
            }

            return $this->parseArrayDimStore($node->var->var, $dim, $tmpVar);
        }

        if ($this->isAssignOpConcat($op)) {
            return $var . '.append(' . $expr . ')';
        }
        return $var . ' ' . $op . ' (' . $expr . ')';
    }

    protected function parseAssignOpConcat(Node\Expr\AssignOp\Concat $expr): string
    {
        return $this->parseAssignOp($expr, '.=');
    }

    protected function parseAssignOpPlus(Node\Expr\AssignOp\Plus $expr): string
    {
        return $this->parseAssignOp($expr, '+=');
    }

    protected function parseAssignOpMinus(Node\Expr\AssignOp\Minus $expr): string
    {
        return $this->parseAssignOp($expr, '-=');
    }

    protected function parseAssignOpMod(Node\Expr\AssignOp\Mod $expr): string
    {
        return $this->parseAssignOp($expr, '%=');
    }

    protected function parseAssignOpMul(Node\Expr\AssignOp\Mul $expr): string
    {
        return $this->parseAssignOp($expr, '*=');
    }

    protected function parseAssignOpDiv(Node\Expr\AssignOp\Div $expr): string
    {
        return $this->parseAssignOp($expr, '/=');
    }

    protected function parseAssignOpBitwiseAnd(Node\Expr\AssignOp\BitwiseAnd $expr): string
    {
        return $this->parseAssignOp($expr, '&=');
    }

    protected function parseAssignOpPow(Node\Expr\AssignOp\Pow $expr): string
    {
        return $this->parseAssignOp($expr, '**=');
    }

    protected function error(string $msg): never
    {
        $this->climate->red("Fatal error: {$msg}");
        debug_print_backtrace();
        exit(255);
    }

    protected function fatalError(Node $node, string $msg): never
    {
        $this->error("{$msg} in {$this->file}:{$node->getStartLine()}");
    }

    protected function errorUndefinedVariable(Variable $node): never
    {
        $this->fatalError($node, "The variable `\${$node->name}` is undefined");
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
        if ($this->isVarExpr($node->var)) {
            if ($var === 'GLOBALS') {
                if ($node->dim === null) {
                    $this->fatalError($node, 'Cannot use [] for GLOBALS');
                }
                return 'php::global(' . $this->parseIdentifier($node->dim) . ')';
            }
            if (!$this->hasVar($var)) {
                if ($write) {
                    $this->addLocalVar($var, self::TYPE_ARRAY);
                } else {
                    $this->errorUndefinedVariable($node->var);
                }
            } else {
                $type = $this->getVarType($var);
                if ($type === self::TYPE_BOOL || $type === self::TYPE_INT || $type === self::TYPE_FLOAT) {
                    $this->fatalError($node, 'Cannot use [] for numbers');
                }
            }
            if ($this->getVarType($var) === self::TYPE_STR) {
                if ($node->dim === null) {
                    $this->fatalError($node, 'Cannot use [] for strings');
                }
            }
        }

        if ($node->dim === null) {
            if (!$write) {
                $this->fatalError($node, 'Cannot use [] for reading');
            } else {
                return $var . '.newItem()';
            }
        } else {
            $oriInAssignExpr = $this->context->inAssignExpr;
            $this->context->inAssignExpr = false;
            $dim = $this->parseIdentifier($node->dim);
            $this->context->inAssignExpr = $oriInAssignExpr;
            return $var . '.item(' . $this->trimBrackets($dim) . ', ' . $this->escapeBool($write) . ')';
        }
    }

    protected function parseArrayDimStore($array, $dim, $var): string
    {
        $oriInAssignExpr = $this->context->inAssignExpr;
        $this->context->inAssignExpr = true;
        $id = $this->parseIdentifier($array);
        $this->context->inAssignExpr = $oriInAssignExpr;

        return $id . '.offsetSet(' . $this->trimBrackets($dim) . ', ' . $this->trimBrackets($var) . ')';
    }

    protected function parseBinaryOpShiftLeft(Node\Expr\BinaryOp\ShiftLeft $expr): string
    {
        return $this->parseBinaryOp($expr->left, $expr->right, '<<');
    }

    protected function parseBinaryOpShiftRight(Node\Expr\BinaryOp\ShiftRight $expr): string
    {
        return $this->parseBinaryOp($expr->left, $expr->right, '>>');
    }

    protected function parseBinaryOpMod(Node\Expr\BinaryOp\Mod $expr): string
    {
        return $this->parseBinaryOp($expr->left, $expr->right, '%');
    }

    /**
     * 查找原生函数.
     */
    protected function findNativeFunction(string $funcName): string|false
    {
        // 绝对命名空间的函数
        if ($funcName[0] == '\\') {
            $funcName = ltrim($funcName, '\\');
            $possibleFunctionNames = [$this->escapeName($funcName)];
        } else {
            $possibleFunctionNames = [$this->escapeName($funcName)];
            if (isset($this->useAliases[$funcName])) {
                $possibleFunctionNames[] = $this->escapeName($this->escapeNamespace($this->useAliases[$funcName]));
            }
            if ($this->namespace) {
                $possibleFunctionNames[] = $this->escapeNamespace($this->namespace) . self::NAMESPACE_SEPARATOR . $funcName;
            }
            if (isset($this->useFunctions[$funcName])) {
                $possibleFunctionNames[] = $this->escapeNamespace($this->useFunctions[$funcName]) . self::NAMESPACE_SEPARATOR . $funcName;
            }
            // 复杂命名空间规则，组合命名空间
            // 例子：use foo\bar;  bar\fn();
            foreach ($this->useNamespaces as $use) {
                $ns1 = explode('\\', $use);
                $ns2 = explode('\\', $funcName);
                if ($ns1[array_key_last($ns1)] === $ns2[array_key_first($ns2)]) {
                    $ns = array_merge($ns1, $ns2);
                    array_splice($ns, array_key_last($ns1) + 1);
                    $possibleFunctionNames[] = $this->escapeNamespace(implode('\\', $ns));
                    break;
                }
            }
        }

        foreach ($possibleFunctionNames as $nativeFunc) {
            if (str_contains($nativeFunc, '\\')) {
                $nativeFunc = $this->escapeNamespace($nativeFunc);
            }
            $this->checkNativeFunction($nativeFunc);
            if ($this->hasNativeFunction($nativeFunc)) {
                return $nativeFunc;
            }
        }

        return false;
    }

    protected function foundStrayCode(Node $node): never
    {
        $this->fatalError($node, 'All execution code must be within a function, found stray code');
    }

    protected function parseFuncCall(Node\Expr\FuncCall $expr): string
    {
        if ($this->isVarExpr($expr->name)) {
            $fn   = $this->parseIdentifier($expr->name);
            $placeHolder = $fn;
            $name = '';
        } elseif ($expr->name->getType() === 'Name' or $expr->name->getType() === 'Name_FullyQualified') {
            $name = $this->parseIdentifier($expr->name);
            if (in_array($name, Constants::UNSUPPORTED_FUNCTIONS)) {
                $this->fatalError($expr, 'Unsupported function: `' . $name . '`');
            }
            $nativeFn = $this->findNativeFunction($name);
            if ($nativeFn) {
                $expr->setAttribute('nativeCall', $nativeFn);
                $this->checkNativeCallArgs($expr, $this->getNativeFunction($nativeFn), $expr->args, $name);
                try {
                    return self::PREFIX . $nativeFn . '(' . $this->parseNativeCallArgs($expr->args, $nativeFn) . ')';
                } catch (PlaceHolder) {
                    return $this->genPlaceHolder($this->identifierToStr($expr->name));
                }
            }
            $code = $this->parseFuncCallWithOptimizer($name, $expr);
            if ($code) {
                return $code;
            }
            $placeHolder = $this->identifierToStr($expr->name);
            $fn = $this->getFuncPtr($name);
            $this->context->beforeStmtLines[] = '// Func Call: ' . $name . '()';
        } else {
            $tmpVar = $this->genTmpVarName();
            $this->addLocalVar($tmpVar, self::TYPE_VAR);
            $this->context->beforeStmtLines[] = $tmpVar . ' = ' . $this->parseExpr($expr->name) . ';';
            $placeHolder = $fn = $tmpVar;
            $name = '';
        }
        if (empty($expr->args)) {
            return 'php::call(' . $fn . ')';
        }
        try {
            return 'php::call(' . $fn . ', ' . $this->parseCallArgs($expr->args, $name) . ')';
        } catch (PlaceHolder) {
            return $this->genPlaceHolder($placeHolder);
        }
    }

    /**
     * @param array<Node\Arg|Node\VariadicPlaceholder> $callArgs
     */
    protected function parseNativeCallArgs(array $callArgs, string $nativeFunc): string
    {
        $argList = [];
        $functionDef = $this->nativeFunctions[$nativeFunc];
        $args = [];
        $hasNamedArg = false;
        // 对命名参数进行重排
        foreach ($callArgs as $i => $arg) {
            if ($this->isPlaceholderExpr($arg)) {
                throw new PlaceHolder();
            }
            if ($arg->name) {
                foreach ($functionDef->argInfoList as $k => $argInfo) {
                    if ($argInfo->name === $arg->name->name) {
                        $args[$k] = $arg;
                        $hasNamedArg = true;
                        break;
                    }
                }
            } else {
                $args[$i] = $arg;
            }
        }
        // 对 key 进行排序，确保参数顺序正确
        if ($hasNamedArg) {
            // 命名参数中间存在空洞，需要使用默认参数填充
            foreach ($functionDef->argInfoList as $k => $argInfo) {
                if (!isset($args[$k])) {
                    $args[$k] = new Node\Arg($argInfo->defaultValue);
                }
            }
            ksort($args);
        }

        // 函数只接受一个变长参数，且调用参数为空，直接传入空数组
        if (count($args) === 0 and count($functionDef->argInfoList) === 1 and $functionDef->argInfoList[0]->variadic) {
            return '{}';
        }

        foreach ($args as $i => $arg) {
            $argInfo = $this->getArgInfo($arg, $nativeFunc, $i);
            if ($argInfo->variadic) {
                $argsSlice = array_slice($args, $i);
                if (count($argsSlice) === 1 and $argsSlice[0]->unpack) {
                    if ($this->isVarExpr($arg->value)) {
                        $var =$this->parseIdentifier($arg->value);
                        if ($this->getVarType($var) === self::TYPE_ARRAY) {
                            $argList[] = $var;
                            break;
                        }
                    }
                    $argList[] = $this->convertArrayExpr($this->parseExpr($arg->value));
                } else {
                    $tmpVar = $this->addTmpVar(self::TYPE_ARRAY);
                    foreach ($argsSlice as $item) {
                        if ($item->unpack) {
                            $this->context->beforeStmtLines[] = $tmpVar . '.merge(' . $this->parseArg($item) . ');';
                        } else {
                            $this->context->beforeStmtLines[] = $tmpVar . '.append(' . $this->parseArg($item) . ');';
                        }
                    }
                    $argList[] = $tmpVar;
                    break;
                }
            } else {
                $argList[] = $this->getTypeConvertedArg($arg, $argInfo);
            }
        }

        return implode(', ', $argList);
    }

    protected function parseCallArgs(array $args, string $funcName = '', string $className = ''): string
    {
        $list_args = [];
        $last = array_key_last($args);
        foreach ($args as $i => $arg) {
            if ($this->isPlaceholderExpr($arg)) {
                throw new PlaceHolder();
            }
            if ($arg->name !== null) {
                $this->fatalError($arg, 'Named arguments are not supported');
            }
            $byRef = $funcName && Reflection::isReferenceArg($funcName, $className, $i);
            if ($this->isVarExpr($arg->value)) {
                $name = $this->parseIdentifier($arg->value);
                if ($byRef) {
                    if (!$this->hasVar($name)) {
                        // 若参数是引用类型，可以传入未定义变量，将立即创建变量作为引用
                        $this->addLocalVar($name, self::TYPE_REF);
                    } else {
                        // 本地变量，且是原生类型，则转为普通变量
                        if ($this->hasLocalVar($name) and $this->isNativeType($this->getVarType($name))) {
                            $this->context->localVars[$name] = self::TYPE_VAR;
                        }
                        // 需要引用类型的参数，使用临时变量作为引用，并替换掉实际的参数
                        $tmpVar = $this->genTmpVarName();
                        $this->addLocalVar($tmpVar, self::TYPE_REF);
                        $this->context->beforeStmtLines[] = $tmpVar . ' = ' . $this->parseExpr($arg->value) . '.toReference();';
                        $name = $tmpVar;
                    }
                    $list_args[] = '&' . $name;
                    continue;
                }
                if (!$this->hasVar($name)) {
                    $this->fatalError($arg, 'Undefined variable `$' . $name . '`');
                }
            } elseif ($this->isPropertyFetch($arg->value) and $this->isVarExpr($arg->value->var)) {
                $obj = $this->parseIdentifier($arg->value->var);
                if (!$this->hasVar($obj)) {
                    $this->fatalError($arg, 'Undefined variable `$' . $obj . '`');
                }
                if ($byRef) {
                    $list_args[] = $obj . '.attrRef(' . $this->identifierToStr($arg->value->name) . ')';
                    continue;
                }
            } elseif ($this->isArrayDimFetch($arg->value) and $this->isVarExpr($arg->value->var)) {
                $array = $this->parseIdentifier($arg->value->var);
                if ($array === 'GLOBALS') {
                    if ($arg->value->dim === null) {
                        $this->fatalError($arg, 'GLOBALS array dimension must be a constant expression');
                    }
                    // $GLOBALS['var'] 等价于 global $var; $var ，将字符串常量转为变量名称即可
                    // 仅限于字面量字符串可以转为变量名称，其他则使用 php::global 函数获取
                    if ($arg->value->dim instanceof Node\Scalar\String_) {
                        $globalVar = $arg->value->dim->value;
                        if (!$this->hasGlobalVar($globalVar)) {
                            $this->addGlobalVar($globalVar, self::TYPE_VAR);
                        }
                    } else {
                        $globalVar = 'php::global(' . $this->parseExpr($arg->value->dim) . ')';
                    }
                    // 全局变量作为引用参数
                    if ($byRef) {
                        $ref = $this->addTmpVar(self::TYPE_REF);
                        $this->context->beforeStmtLines[] = $ref . ' = ' . $globalVar . '.toReference();';
                        $list_args[] = '&' . $ref;
                    } else {
                        $list_args[] = $globalVar;
                    }
                    continue;
                }
                if ($this->isVarExpr($arg->value->var) and !$this->hasVar($array)) {
                    $this->fatalError($arg, 'Undefined variable `$' . $array . '`');
                }
                if ($byRef) {
                    if ($arg->value->dim === null) {
                        $this->fatalError($arg, 'Array dimension must be a constant expression');
                    }
                    $list_args[] = $array . '.itemRef(' . $this->identifierToStr($arg->value->dim) . ')';
                    continue;
                }
            } else {
                if ($byRef) {
                    $list_args[] = $this->parseChainedExpr($arg->value, self::OP_REFVAL);
                    continue;
                }
            }
            // 变长参数展开的语法，例如：array_merge(...$arr)
            if ($arg->unpack) {
                if ($i !== $last) {
                    $this->fatalError($arg, 'The unpack expression for variadic arguments must be the last');
                }
                $tmpVar = $this->genTmpVarName();
                $this->context->beforeStmtLines[] = self::TYPE_ARRAY . ' ' . $tmpVar . '{' . implode(', ', $list_args) . '};';
                $this->context->beforeStmtLines[] = $tmpVar . '.merge(' . $this->parseArrayArg($arg) . ');';
                return $tmpVar;
            }
            $list_args[] = $this->parseArg($arg);
        }

        return '{' . implode(', ', $list_args) . '}';
    }

    protected function parseArg(Node\Arg $arg): string
    {
        return $this->parseIdentifier($arg->value);
    }

    protected function parseArrayArg(Node\Arg $expr): string
    {
        $value = $expr->value;
        if ($this->isVarExpr($value)) {
            $var = $this->parseIdentifier($value);
            if (!$this->hasVar($var)) {
                $this->errorUndefinedVariable($value);
            }
            if ($this->getVarType($var) === self::TYPE_ARRAY) {
                return $var;
            }
        }
        return $this->parseExpr($value);
    }

    protected function parsePostOp(Node\Expr\PostDec|Node\Expr\PostInc $expr, string $op): string
    {
        if ($this->isVarExpr($expr->var) or $this->isPropertyFetch($expr->var) or $this->isArrayDimFetch($expr->var)) {
            $var = $this->parseIdentifier($expr->var);
            if ($this->isVarExpr($expr->var) and !$this->hasVar($var)) {
                $this->errorUndefinedVariable($expr->var);
            }
            return $var . str_repeat($op, 2);
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
            $this->context->beforeStmtLines[] = $tmpVar . ' = php::getStaticProperty(' . $class . ', ' . $prop . ');';
            $this->context->afterStmtLines[]  = 'php::setStaticProperty(' . $class . ', ' . $prop . ', ' . $tmpVar . ' ' . $op . ' 1);';

            return $tmpVar;
        }
        $this->fatalError($expr, 'Post-increment operator is not supported for non-variable expressions');
    }

    protected function parsePostDec(Node\Expr\PostDec $expr): string
    {
        return $this->parsePostOp($expr, '-');
    }

    protected function parsePostInc(Node\Expr\PostInc $expr): string
    {
        return $this->parsePostOp($expr, '+');
    }

    protected function parseTernary(Node\Expr\Ternary $expr): string
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

    protected function parseMatch(Node\Expr\Match_ $expr): string
    {
        $var = $this->parseIdentifier($expr->cond);
        if ($this->isVarExpr($expr->cond) and !$this->hasVar($var)) {
            $this->errorUndefinedVariable($expr->cond);
        }
        $code = '[&]() -> ' . self::TYPE_VAR . '{';
        $default = null;
        foreach ($expr->arms as $i => $arm) {
            if ($arm->conds === null) {
                $default = $arm->body;
                continue;
            }
            $prefix = $i === 0 ? 'if' : 'else if';
            $condList = [];
            foreach ($arm->conds as $cond) {
                if ($this->isMatchExpr($cond)) {
                    $this->fatalError($arm, 'Match expression cannot be used as a condition');
                }
                $condList[] = 'php::same(' . $var . ', ' . $this->parseExpr($cond) . ')';
            }
            $code .= $prefix . '(' . implode(' || ', $condList) . ') {';
            $code .= 'return ' . $this->parseExpr($arm->body) . ';';
            $code .= '}';
        }

        $else = count($expr->arms) === 0 ? '' : 'else ';
        if ($default) {
            $code .= $else . ' { return ' . $this->parseExpr($default) . '; }';
        } else {
            $code .= $else . ' { php::throwException("UnhandledMatchError", "Unhandled match case");' . PHP_EOL .
                'return ' . self::VALUE_NULL . '; }';
        }
        $code .= '}()';

        return $code;
    }

    protected function parseBinaryOpGreater(Node\Expr\BinaryOp\Greater $expr): string
    {
        return $this->convertBoolExpr($this->parseBinaryOp($expr->left, $expr->right, '>'));
    }

    protected function parseBinaryOpPow(Node\Expr\BinaryOp\Pow $expr): string
    {
        $left  = $this->parseIdentifier($expr->left);
        $right = $this->parseIdentifier($expr->right);

        return 'php::pow(' . $left . ', ' . $right . ')';
    }

    protected function parsePreDec(Node\Expr\PreDec $expr): string
    {
        return '--' . $this->parseIdentifier($expr->var);
    }

    protected function parseBinaryOpBitwiseAnd(Node\Expr\BinaryOp\BitwiseAnd $expr): string
    {
        return $this->parseBinaryOp($expr->left, $expr->right, '&');
    }

    protected function parseBinaryOpBitwiseOr(Node\Expr\BinaryOp\BitwiseOr $expr): string
    {
        return $this->parseBinaryOp($expr->left, $expr->right, '|');
    }

    protected function parseBinaryOpBitwiseXor(Node\Expr\BinaryOp\BitwiseXor $expr): string
    {
        return $this->parseBinaryOp($expr->left, $expr->right, '^');
    }

    protected function parseBitwiseNot(Node\Expr\BitwiseNot $expr): string
    {
        $var = $this->parseIdentifier($expr->expr);

        return '~' . $var;
    }

    protected function parseIf(Node\Stmt\If_ $v): string
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

    protected function parseBinaryOpEqual(Node\Expr\BinaryOp\Equal $expr): string
    {
        return 'php::equals(' . $this->parseExpr($expr->left) . ', ' . $this->parseExpr($expr->right) . ')';
    }

    protected function parseBinaryOpNotEqual(Node\Expr\BinaryOp\NotEqual $expr): string
    {
        return '!php::equals(' . $this->parseExpr($expr->left) . ', ' . $this->parseExpr($expr->right) . ')';
    }

    /**
     * 逻辑比较的运算，必须返回 bool 类型.
     */
    protected function parseBinaryOpLogicalAnd(Node\Expr\BinaryOp\LogicalAnd|Node\Expr\BinaryOp\BooleanAnd $expr): string
    {
        return $this->convertBoolExpr($this->parseBinaryOp($expr->left, $expr->right, '&&'));
    }

    protected function parseBinaryOpLogicalOr(Node\Expr\BinaryOp\LogicalOr|Node\Expr\BinaryOp\BooleanOr $expr): string
    {
        return $this->convertBoolExpr($this->parseBinaryOp($expr->left, $expr->right, '||'));
    }

    protected function parseBinaryOpLogicalXor(Node\Expr\BinaryOp\LogicalXor $expr): string
    {
        return $this->convertBoolExpr($this->parseBinaryOp($expr->left, $expr->right, '^'));
    }

    protected function parseBooleanNot(Node\Expr\BooleanNot $expr): string
    {
        $expr = $this->parseExpr($expr->expr);

        return '!' . $expr;
    }

    protected function parseWhile(Node\Stmt\While_ $v): string
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

    protected function convertIntExpr(string $expr): string
    {
        if (!$this->isClosedExpr($expr, 'php::toInt')) {
            return 'php::toInt(' . $this->trimBrackets($expr) . ')';
        }

        return $expr;
    }

    protected function convertFloatExpr(string $expr): string
    {
        if (!$this->isClosedExpr($expr, 'php::toFloat')) {
            return 'php::toFloat(' . $this->trimBrackets($expr) . ')';
        }

        return $expr;
    }

    protected function convertStringExpr(string $expr): string
    {
        if (!$this->isClosedExpr($expr, 'php::toString')) {
            return 'php::toString(' . $this->trimBrackets($expr) . ')';
        }

        return $expr;
    }

    protected function convertObjectExpr(string $expr, string $class = ''): string
    {
        if (!$this->isClosedExpr($expr, 'php::toObject')) {
            if ($class === '') {
                return 'php::toObject(' . $this->trimBrackets($expr) . ')';
            }
            return 'php::toObject(' . $this->trimBrackets($expr) . ', ' . $class . ')';
        }

        return $expr;
    }

    protected function convertArrayExpr(string $expr): string
    {
        if (!$this->isClosedExpr($expr, 'php::toArray')) {
            return 'php::toArray(' . $this->trimBrackets($expr) . ')';
        }

        return $expr;
    }

    protected function convertBoolExpr(string $expr): string
    {
        if (!$this->isClosedExpr($expr, 'php::toBool')) {
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

    protected function parseBinaryOpCoalesce(Node\Expr\BinaryOp\Coalesce $expr): string
    {
        $left = $this->parseIdentifier($expr->left);
        $right = $this->parseIdentifier($expr->right);

        return $this->parseChainedExpr($expr->left, 'isset') . ' ? ' . $left . ' : ' . $right;
    }

    protected function parseBinaryOpNotIdentical(Node\Expr\BinaryOp $expr): string
    {
        return '!(' . $this->parseBinaryOpIdentical($expr) . ')';
    }

    protected function packData(string $bytes): string
    {
        $out = '';
        for ($i = 0; $i < strlen($bytes); $i++) {
            $out .= ord($bytes[$i]) . ', ';
            if ($i % 32 == 0) {
                $out .= "\n\t";
            }
        }
        $out .= '0,';
        return $out;
    }

    protected function addConstData(string $name, string $bytes): void
    {
        $this->constData[$name] = $this->packData($bytes);
    }

    protected function parseNew(Node\Expr\New_ $expr): string
    {
        // 匿名类
        if ($expr->class instanceof Node\Stmt\Class_) {
            if ($expr->class->name === null) {
                $classDef = $expr->class;
                $className = $this->genAnonClassName();
                $classDef->name = new Node\Identifier($className);
                $this->context->beforeStmtLines[] = 'static THREAD_LOCAL bool ' . $className . '_defined = false;';
                $classCode = $this->genEmbeddedCode($classDef);
                $this->addConstData($className . '_code', $classCode);
                $this->context->beforeStmtLines[] = 'if (!' . $className . '_defined) {'
                    . $className . '_defined = true; php::eval((const char *)' . $className . '_code);}';
                $className = '\\' . $className;
                $cePtr     = $this->getClassEntryPtr($className);
            } else {
                $this->fatalError($expr, 'must be anonymous class');
            }
        } else {
            $className = $this->parseIdentifier($expr->class);
            if ($this->isNameExpr($expr->class)) {
                if ($className === 'static') {
                    $cePtr = 'php_get_called_ce(this_)';
                } else {
                    $className = $this->getNamespacedClassName($className);
                    $cePtr = $this->getClassEntryPtr($className);
                }
            } else {
                $cePtr = $className;
            }
        }

        $args = $expr->args;
        if (empty($args)) {
            return 'php::newObject(' . $cePtr . ')';
        }
        return 'php::newObject(' . $cePtr . ', ' . $this->parseCallArgs($args) . ')';
    }

    protected function parseClone(Node\Expr\Clone_ $expr): string
    {
        return $this->convertToObject($expr->expr) . '.clone()';
    }

    protected function parseInstanceof(Node\Expr\Instanceof_ $expr): string
    {
        return $this->convertToObject($expr->expr) . '.instanceOf(' . $this->identifierToStr($expr->class) . ')';
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

    protected function parseConstFetch(Node\Expr\ConstFetch $expr, bool $scalar = false): string
    {
        if ($expr->name->getType() != 'Name' and !($expr->name instanceof Node\Name\FullyQualified)) {
            abort($expr);
        }
        $name = $this->parseIdentifier($expr->name);
        if ($this->isNameExpr($expr->name) and $this->hasConstant($name)) {
            return $this->getConstant($name);
        }
        if ($name === 'null') {
            return self::VALUE_NULL;
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
        if ($scalar) {
            return constant($expr->name);
        }
        if ($this->isNameExpr($expr->name)) {
            if (str_contains($name, '::')) {
                $ns = explode('::', $name)[0];
                $fullName = $this->getNamespacedClassName($ns[0]);
                $ce = $this->getClassEntryPtr($fullName);
                return 'php::constant(' . $ce . ', ' . $this->getLiteralString($ns[1]) . ')';
            }
            return 'php::constant(nullptr, ' . $this->getLiteralString($name) . ')';
        }
        return 'php::constant("' . $this->escapeString($name) . '")';
    }

    protected function parseUnaryMinus(Node\Expr\UnaryMinus $expr): string
    {
        $code = $this->parseExpr($expr->expr);

        return '-' . $code;
    }

    protected function parseUnaryPlus(Node\Expr\UnaryPlus $expr)
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

    protected function parseInterpolatedStringPart(Node\InterpolatedStringPart $expr): string
    {
        return '"' . $this->escapeString($expr->value) . '"';
    }

    protected function parseGlobal(Node\Stmt\Global_ $v): string
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

    protected function getTypeConvertedArg($arg, ArgInfo $argInfo): string
    {
        $expr = $this->parseArg($arg);
        $type = $this->detectExprType($arg->value);

        if ($argInfo->byRef) {
            return $this->convertToRef($arg->value);
        }

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
        $vars = $node->vars;
        $lines = [];
        foreach ($vars as $var) {
            $type = $var->getType();
            if ($type === self::EXPR_ARRAY_DIM_FETCH) {
                $array = $this->parseIdentifier($var->var);
                $dim = $this->parseIdentifier($var->dim);
                $lines[] = $array . '.offsetUnset(' . $dim . ');';
            } elseif ($type === 'Expr_PropertyFetch') {
                $object = $this->parseIdentifier($var->var);
                $propName = $this->identifierToStr($var->name, literal: true);
                $lines[] = $object . '.unsetProperty(' . $propName . ');';
            } elseif ($type === self::EXPR_VARIABLE) {
                $name = $this->parseIdentifier($var);
                $lines[] = "{$name}.unset();";
            } else {
                abort($var);
            }
        }

        return implode(PHP_EOL . $this->getIndent(), $lines);
    }

    protected function getPropertyIdentifier(Node\Expr\PropertyFetch $expr, NodeAbstract $object, NodeAbstract $property): ?string
    {
        if ($this->isVarExpr($object) and $this->isIdExpr($property)) {
            $objectName = $this->parseIdentifier($object);
            $propertyName = $this->parseIdentifier($property);
            $nativeProperty = null;
            if ($objectName === 'this_') {
                $nativeProperty = $this->findNativeProperty($object, $propertyName, $this->class, $this->namespace);
            } elseif ($this->isTypedObject($objectName)) {
                $className = $this->getObjectType($objectName);
                $nativeProperty = $this->findNativeProperty($object, $propertyName, $className);
            }
            if ($nativeProperty) {
                $expr->setAttribute('nativeProperty', $nativeProperty);
                return $nativeProperty;
            }
        }
        return $this->identifierToStr($property, literal: true);
    }

    protected function parsePropertyFetch(Node\Expr\PropertyFetch $expr, bool $update = false): string
    {
        $object = $expr->var;
        $property = $expr->name;
        $id = $this->getPropertyIdentifier($expr, $object, $property);
        return $this->parseIdentifier($object) . '.attr(' . $id . ', ' . $this->escapeBool($update) . ')';
    }

    protected function parseAssignOpShiftRight(Node\Expr\AssignOp\ShiftRight $node): string
    {
        return $this->parseAssignOp($node, '>>=');
    }

    protected function parseAssignOpBitwiseXor(Node\Expr\AssignOp\BitwiseXor $node): string
    {
        return $this->parseAssignOp($node, '^=');
    }

    protected function parseMagicConst(MagicConst $expr): string
    {
        $class = ($this->namespace ? $this->namespace . '\\' : '') . $this->class;
        $function = ($this->namespace ? $this->namespace . '\\' : '') . $this->function;
        switch ($expr->getType()) {
            case 'Scalar_MagicConst_Dir':
                return '"' . $this->escapeString($this->dir) . '"';
            case 'Scalar_MagicConst_File':
                return '"' . $this->escapeString($this->file) . '"';
            case 'Scalar_MagicConst_Line':
                return (string) $expr->getStartLine();
            case 'Scalar_MagicConst_Function':
                return '"' . $this->escapeString($function) . '"';
            case 'Scalar_MagicConst_Class':
                return '"' . $this->escapeString($class) . '"';
            case 'Scalar_MagicConst_Method':
                return '"' . $this->escapeString($class) . '::' . $this->escapeString($this->method) . '"';
            default:
                abort($expr);
        }
    }

    protected function parseForeachArray(Foreach_ $node, string $iteratorVar): string
    {
        $code = 'for (auto iter = ' . $iteratorVar . '.begin(); iter != ' . $iteratorVar . '.end(); ++iter) {' . PHP_EOL;
        $this->indentLevel++;
        if ($node->keyVar) {
            $keyVar = $this->parseIdentifier($node->keyVar);
            $this->checkVar($node, $keyVar);
            $code .= $this->getIndent() . ' ' . $keyVar . ' = iter.key();' . PHP_EOL;
        }

        if ($node->byRef and !$this->isVarExpr($node->valueVar)) {
            $this->fatalError($node, 'Foreach by reference only supports variable as value');
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
            if ($node->byRef) {
                if (!$this->hasVar($valueVar)) {
                    $this->addLocalVar($valueVar, self::TYPE_REF);
                } elseif ($this->getVarType($valueVar) !== self::TYPE_REF) {
                    $this->fatalError($node, 'Cannot assign value to reference of type');
                }
                $code .= $this->getIndent() . ' ' . $valueVar . ' = iter.valueRef();' . PHP_EOL;
            } else {
                $this->checkVar($node, $valueVar);
                $code .= $this->getIndent() . ' ' . $valueVar . ' = iter.value();' . PHP_EOL;
            }
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
        if ($this->isVarExpr($node->expr)) {
            $name = $this->parseIdentifier($node->expr);
            if ($this->hasVar($name)) {
                $type = $this->getVarType($name);
                if ($type === self::TYPE_OBJECT) {
                    if ($node->byRef) {
                        $this->fatalError($node, 'Cannot use & with foreach');
                    }
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

    /**
     * 为了兼容已有代码，默认不使用原生类型，而是将整数和浮点数作为 php 变量处理
     * 原生 int/float/bool 类型，是不支持自动转换的，例如如果 int 计算超过最大值后，会自动转为 float，除法若不能除尽，则会转为 float
     * 某些情况下高性能计算，可能需要使用原生类型，使用 $a = std::int(0) 来显式地使用原生类型
     */
    protected function getNativeType(string $type): string
    {
        return $this->defaultNativeType ? $type : self::TYPE_VAR;
    }

    protected function detectConstType($expr): string
    {
        $name = $this->parseIdentifier($expr->name);
        if ($this->hasConstant($name)) {
            return $this->getConstantType($name);
        }
        if ($name === 'true') {
            return $this->getNativeType(self::TYPE_BOOL);
        }
        if ($name === 'false') {
            return $this->getNativeType(self::TYPE_BOOL);
        }
        if ($name === 'NAN' or $name === 'INF') {
            return $this->getNativeType(self::TYPE_FLOAT);
        }
        return self::TYPE_VAR;
    }

    protected function parseSwitch(Node\Stmt\Switch_ $v): string
    {
        $cond    = $v->cond;
        $tmp_var = $this->genTmpVarName();
        $type    = $this->detectExprType($cond);
        if ($this->isVarExpr($cond)) {
            $this->requireVar($v, $this->parseIdentifier($cond));
        }
        $var_def = $type . ' ' . $tmp_var . ' = ' . $this->parseExpr($cond) . ';' . PHP_EOL;

        // 保存作用域，switch 可能会解析失败，在这个过程中会增加变量，需重置
        $localVars = $this->context->localVars;
        $code      = $this->parseBeforeStmtLines() . PHP_EOL;

        if ($type === self::TYPE_INT or $type === self::TYPE_BOOL) {
            $code .= 'switch (' . $tmp_var . ') {' . PHP_EOL;
            $this->indentLevel++;
            foreach ($v->cases as $case) {
                if (empty($case->cond)) {
                    $code .= $this->getIndent() . 'default: {' . PHP_EOL;
                } else {
                    $condType = $case->cond->getType();
                    if ($condType !== 'Scalar_Int' and $condType !== 'Scalar_Float') {
                        $this->context->localVars = $localVars;
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

        if (count($v->cases) == 1) {
            if (!empty($v->cases[0]->cond)) {
                $code = 'if (' . $tmp_var . '==' . $this->parseIdentifier($v->cases[0]->cond) . ') {' . PHP_EOL;
            } else {
                $code = 'if (1) {' . PHP_EOL;
            }
            $code .= $this->parseStmts($v->cases[0]->stmts);
            $code .= $this->getIndent() . '}';
            return $var_def . $code;
        }

        $code = 'do {' . PHP_EOL;
        $this->indentLevel++;
        $condList = [];
        foreach ($v->cases as $case) {
            if (empty($case->cond)) {
                if ($condList) {
                    $this->fatalError($case, 'switch case must be first');
                }
            } else {
                $condList[] = $tmp_var . '==' . $this->parseIdentifier($case->cond);
            }
            $this->indentLevel++;
            $stmts = $case->stmts;
            if (empty($stmts)) {
                continue;
            }
            if (count($stmts) === 1 and $stmts[0] instanceof Node\Stmt\Block) {
                $stmts = $stmts[0]->stmts;
            }
            $lastExpr = end($stmts);
            if (!$this->isReturnExpr($lastExpr) and !$this->isExitExpr($lastExpr) and !$this->isBreakExpr($lastExpr)) {
                $this->fatalError($case, 'switch case must end with return or break or exit, ' . $lastExpr->getType() . ' given');
            }

            if ($condList) {
                $code .= $this->getIndent() . 'if (' . implode(' || ', $condList) . ') {' . PHP_EOL;
                $condList = [];
            } else {
                $code .= $this->getIndent() . 'else {' . PHP_EOL;
            }

            $code .= $this->parseStmts($stmts);
            $this->indentLevel--;
            $code .= $this->getIndent() . '}' . PHP_EOL;
        }
        $this->indentLevel--;
        $code .= $this->getIndent() . '} while (0);';

        return $var_def . $code;
    }

    protected function parseStatic(Node\Stmt\Static_ $v): string
    {
        $list = [];
        foreach ($v->vars as $var) {
            $varName = $var->var->name;
            $type = $var->default ? $this->detectExprType($var->default) : self::TYPE_VAR;
            $this->addStaticVar($varName, $type);
            if ($var->default) {
                $initState = self::STATIC_VAR . $varName . '_initialized';
                $initCode = $this->getIndent() . 'static bool ' . $initState . ' = false;';
                $initCode .= $this->getIndent() . "if (!{$initState}) { \n";
                $this->indentLevel++;
                $initCode .= $this->getIndent() . "{$initState} = true;\n";
                $initCode .= $this->getIndent() . $this->getStaticVarName($varName) . ' = ' . $this->parseIdentifier($var->default) . ';';
                $this->indentLevel--;
                $initCode .= $this->getIndent() . '}';
                $list[] = $initCode;
            }
        }

        return implode(PHP_EOL . $this->getIndent(), $list);
    }

    protected function parseEnum(Node\Stmt\Enum_ $v): string
    {
        return 'php::eval("' . $this->escapeString($this->genEmbeddedCode($v)) . '");';
    }

    protected function parseEval(Node\Expr\Eval_ $expr): string
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

    protected function parseBreak(Node\Stmt\Break_ $v): string
    {
        if (!$this->context->inLoop) {
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

    protected function parseContinue(Node\Stmt\Continue_ $v): string
    {
        if (!$this->context->inLoop) {
            $this->fatalError($v, 'Cannot continue outside loop');
        }
        if ($v->num and $v->num->value > 1) {
            $this->fatalError($v, 'Cannot continue more than 1 level');
        }
        return 'continue;';
    }

    protected function parseScalarFloat(Node\Scalar\Float_ $expr): string
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

    protected function parseIsset(Node\Expr\Isset_ $expr): string
    {
        $vars = $expr->vars;
        if (count($vars) > 1) {
            $list = [];
            foreach ($vars as $var) {
                $list[] = $this->parseChainedExpr($var, self::OP_ISSET);
            }
            return '(' . implode(' && ', $list) . ')';
        }
        return $this->parseChainedExpr($vars[0], self::OP_ISSET);
    }

    protected function parseEmpty(Node\Expr\Empty_ $expr): string
    {
        return $this->parseChainedExpr($expr->expr, self::OP_EMPTY);
    }

    /**
     * 左值只能为变量、数组、对象属性、对象静态属性
     */
    protected function checkLeftValue(NodeAbstract $expr): void
    {
        if (!$this->isVarExpr($expr) && !$this->isArrayDimFetch($expr) && !$this->isPropertyFetch($expr) && !$this->isStaticPropertyFetch($expr)) {
            $this->fatalError($expr, 'The left value of assignment operation can only be variable, array item, object property, class static property');
        }
    }

    protected function parseChainedExpr(NodeAbstract $expr, string $op): string
    {
        if ($this->isVarExpr($expr)) {
            if ($op === 'isset') {
                $var = $this->parseIdentifier($expr);
                return $this->hasVar($var) ? 'php::exists(' . $var . ')' : 'false';
            }
            return 'php::' . $op . '(' . $this->parseExpr($expr) . ')';
        }

        $list = [];
        while (true) {
            if ($this->isArrayDimFetch($expr)) {
                if ($expr->dim === null) {
                    $this->fatalError($expr, 'Cannot use [] for reading');
                }
                $dim = $this->parseIdentifier($expr->dim);
                $list[] = '{php::ArrayDimFetch, ' . self::TYPE_VAR . '(' . $dim . ')}';
            } elseif ($this->isPropertyFetch($expr)) {
                $name = $this->identifierToStr($expr->name, literal: true);
                $list[] = '{php::PropertyFetch, ' . self::TYPE_VAR . '(' . $name . ')}';
            } elseif ($this->isVarExpr($expr)) {
                $var = $this->parseIdentifier($expr);
                break;
            } else {
                $var = $this->genTmpVarName();
                $this->addLocalVar($var, self::TYPE_VAR);
                $this->context->beforeStmtLines[] = $var . '=' . $this->parseExpr($expr) . ';';
                break;
            }
            $expr = $expr->var;
        }
        $list = array_reverse($list);
        $fn = $op === 'isset' ? 'exists' : $op;
        return 'php::' . $fn . '(' . $var . ', {' . implode(', ', $list) . '})';
    }

    protected function parseCastArray(Node\Expr\Cast\Array_ $expr): string
    {
        return $this->convertArrayExpr($this->parseIdentifier($expr->expr));
    }

    protected function hasGlobalVar(string $name): bool
    {
        return array_key_exists($name, $this->globalVars);
    }

    protected function hasStaticVar(string $name): bool
    {
        return array_key_exists($name, $this->context->staticVars);
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

        if (isset($this->context->objectWrappers[$id])) {
            return $this->context->objectWrappers[$id];
        }

        $tmpVar = $this->genTmpVarName();
        $this->addLocalVar($tmpVar, self::TYPE_OBJECT);
        $this->context->beforeStmtLines[]   = $this->getIndent() . $tmpVar . ' = ' . $id . ';';
        $this->context->objectWrappers[$id] = $tmpVar;

        return $tmpVar;
    }

    protected function convertToRef(NodeAbstract $expr): string
    {
        $this->checkLeftValue($expr);
        $var = $this->parseIdentifier($expr);
        if ($this->isVarExpr($expr) and $this->isNativeTypeVar($var)) {
            $this->context->localVars[$var] = self::TYPE_VAR;
        }
        return $this->parseIdentifier($expr) . '.toReference()';
    }

    protected function parseAssignRef(Node\Expr\AssignRef $expr): string
    {
        if ($this->isVarExpr($expr->var)) {
            $this->context->inAssignExpr = true;
            $left               = $this->parseIdentifier($expr->var);
            $this->context->inAssignExpr = false;
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
        if ($this->isVarExpr($expr->var)) {
            $var = $this->parseIdentifier($expr->var);
            if (!$this->hasVar($var)) {
                $this->errorUndefinedVariable($expr->var);
            }
        }

        $object = $this->convertToObject($expr->var);
        if ($this->isTypedObject($object)) {
            $class = $this->getObjectType($object);
        } else {
            $class = '';
        }

        $dynamicCall = false;
        $method = $this->identifierToStr($expr->name, literal: true);

        // 可转为原生调用的 MethodCall
        if ($this->isVarExpr($expr->var) and $this->isNamedMethod($expr->name)) {
            $this->context->beforeStmtLines[] = '// Method Call: ' . $object . '->' . $this->parseIdentifier($expr->name) . '()';
            try {
                $nativeFunc = $this->findNativeMethod($expr, $object, $this->parseIdentifier($expr->name));
                if ($nativeFunc) {
                    $expr->setAttribute('nativeCall', $nativeFunc);
                    try {
                        return $this->parseNativeMethodCall($object, $nativeFunc, $expr->args);
                    } catch (PlaceHolder) {
                        return $this->genPlaceHolder($this->genArray([$object, $method]));
                    }
                }
            } catch (DynamicCall) {
                $dynamicCall = true;
            }
        }

        if ($this->isNamedMethod($expr->name)) {
            $funcName = $this->parseIdentifier($expr->name);
        } else {
            $funcName = '';
        }

        if ($class and $funcName and !$dynamicCall) {
            $methodPtr = $this->getMethodPtr($class, $funcName);
        } else {
            $methodPtr = $method;
        }

        if (empty($expr->args)) {
            return $object . '.exec(' . $methodPtr . ')';
        }
        try {
            return $object . '.exec(' . $methodPtr . ', ' . $this->parseCallArgs($expr->args, $funcName, $class) . ')';
        } catch (PlaceHolder) {
            return $this->genPlaceHolder($this->genArray([$object, $method]));
        }
    }

    protected function identifierToStr(NodeAbstract $node, bool $require = true, bool $literal = false): string
    {
        $id = $this->parseIdentifier($node);
        if ($this->isVarExpr($node)) {
            if ($require) {
                $this->requireVar($node, $id);
            }
            return $id;
        }
        /*
         * 对 static 的支持存在问题，静态编译时无法获得实际运行时的子类名，所以只能使用 self
         * self 是在编译期确定的，而 static 是运行时确定的，但使用 AOT 编译为可执行文件后，运行时类的名称是无法确定的
         */
        if ($id === 'self') {
            $id = $this->getNamespacedClassName($this->class);
        } elseif ($id === 'static') {
            return 'php_get_called_class(this_)';
        }
        if ($this->isNameExpr($node) or $this->isIdExpr($node)) {
            return $literal ? $this->getLiteralString($id) : $this->genCharPtr($id, true);
        }
        return $id;
    }

    protected function requireVar($node, string $var): void
    {
        if (!$this->hasVar($var)) {
            $this->fatalError($node, 'The variable `' . $var . '` is not defined');
        }
    }

    protected function parseStaticCall(Node\Expr\StaticCall $expr): string
    {
        $self = false;
        $callScope = [];
        $class = $this->parseIdentifier($expr->class);

        if ($this->isVarExpr($expr->class) or $this->isVarExpr($expr->name)) {
            $var = $class;
            if ($this->isTypedObject($var)) {
                $class = $this->getObjectType($var);
                goto _do_call;
            }
            if ($this->getVarType($var) == self::TYPE_OBJECT) {
                $fn = 'php::concat({' . $var . '.getClassName(), "::", ' . $this->identifierToStr($expr->name) . '})';
            } else {
                $fn = 'php::concat({' . $this->identifierToStr($expr->class) . ', "::", ' . $this->identifierToStr($expr->name) . '})';
            }
            $placeHolder = $fn;
        } elseif ($class === 'static') {
            $methodPtr = $this->identifierToStr($expr->name, literal: true);
            $fn = 'php_get_called_ce(this_), php::getMethod(php_get_called_ce(this_), ' . $methodPtr . ')';
            $this->context->beforeStmtLines[] = '// Static Method Call: static::' . $this->parseIdentifier($expr->name) . '()';
            $placeHolder = $this->genArray(['php_get_called_class(this_)', $methodPtr]);
        } elseif ($this->isNameExpr($expr->class)) {
            if ($class === 'self') {
                $class = $this->class;
                $self = true;
            } elseif ($class === 'parent') {
                return $this->parseParentMethodCall($expr);
            }
            $class = $this->getNamespacedClassName($class);

            _do_call:
            $method = $this->parseIdentifier($expr->name);
            $dynamicCall = false;
            $this->context->beforeStmtLines[] = '// Static Method Call: ' . $class . '::' . $method . '()';

            if ($this->isNameExpr($expr->class) and $this->isIdExpr($expr->name)) {
                $callScope = [$this->genCharPtr($class, true), $this->genCharPtr($method)];
            }

            if ($callScope) {
                $nativeFunc = $this->getNativeMethod($expr, $class, $method);
                // 存在 Native 类，但是没有找到方法，可能是动态调用
                if (!$nativeFunc and $this->hasNativeClass($class) and $this->getNativeMethod($expr, $class, '__callStatic')) {
                    $dynamicCall = true;
                }

                if ($nativeFunc) {
                    try {
                        $args = $this->parseNativeCallArgs($expr->args, $nativeFunc);
                        $expr->setAttribute('nativeCall', $nativeFunc);
                    } catch (PlaceHolder) {
                        return $this->genPlaceHolder($this->genArray($callScope));
                    }
                    // 在方法定义中使用了当前类的方法 self::method()，依然应该传递 this_ 指针
                    if ($this->methodDef and $self) {
                        $object = 'this_';
                    } else {
                        $object = $this->getCeWrapper($class);
                    }
                    if ($args) {
                        return self::PREFIX . $nativeFunc . '(' . $object . ', ' . $args . ')';
                    } else {
                        return self::PREFIX . $nativeFunc . '(' . $object . ')';
                    }
                }
            }

            if ($dynamicCall) {
                $fn = $this->getLiteralString($class . '::' . $method);
            } else {
                $ce = $this->getClassEntryPtr($class);
                $fn = $ce . ', ' . $this->getFuncPtr($class . '::' . $method);
            }
            $placeHolder = $this->genArray($callScope);
        } else {
            $fn = 'php::concat({' . $this->identifierToStr($expr->class) . ', "::", ' . $this->identifierToStr($expr->name) . '})';
            $placeHolder = $fn;
        }

        $call = 'php::call';
        if (empty($expr->args)) {
            return $call . '(' . $fn . ')';
        }
        try {
            $callArgs = $this->parseCallArgs($expr->args);
            return $call . '(' . $fn . ', ' . $callArgs . ')';
        } catch (PlaceHolder) {
            return $this->genPlaceHolder($placeHolder);
        }
    }

    protected function findNativeStaticProperty(Node\Expr\StaticPropertyFetch $expr, ?string &$class): ?PropertyDef
    {
        if ($this->isNameExpr($expr->class) and $this->isIdExpr($expr->name)) {
            $class = $this->parseIdentifier($expr->class);
            $prop = $this->parseIdentifier($expr->name);
            if ($class === 'self') {
                $class = $this->class;
            }

            $fullName = $this->getNamespacedClassName($class);
            if (!$this->hasNativeClass($fullName)) {
                return null;
            }

            $classDef = $this->getClassDef($fullName);
            if ($classDef->hasProperty($prop)) {
                $propDef = $classDef->getProperty($prop);
                if ($propDef->isStatic()) {
                    $class = $fullName;
                    return $propDef;
                }
            }
        }
        return null;
    }

    protected function findNativeProperty(NodeAbstract $object, string $property, string $class, string $namespace = ''): ?string
    {
        $findClass = $class;
        if ($namespace) {
            $findClass = $namespace . '\\' . $class;
        }
        $scope = $this->class ? ltrim($namespace . '\\' . $class, '\\') : '';

        while (true) {
            if ($this->hasNativeClass($findClass)) {
                $classDef = $this->getClassDef($findClass);
                if ($classDef->hasProperty($property)) {
                    $propertyDef = $classDef->getProperty($property);
                    if ($propertyDef->isPublic()) {
                        return $this->getPropertyOffset($classDef->getNamespacedName(false), $property);
                    }
                    if ($propertyDef->isProtected()) {
                        if ($scope) {
                            return $this->getPropertyOffset($classDef->getNamespacedName(false), $property);
                        }
                        $this->fatalError($object, "Cannot access protected property `{$property}` of class `{$class}`");
                    } else {
                        if ($scope === $findClass) {
                            return $this->getPropertyOffset($classDef->getNamespacedName(false), $property);
                        }
                        $this->fatalError($object, "Cannot access private property `{$property}` of class `{$class}`");
                    }
                } elseif ($classDef->extends) {
                    $findClass = $classDef->extends;
                    continue;
                }
            }
            break;
        }
        return null;
    }

    protected function parseNativeStaticPropertyFetch(Node\Expr\StaticPropertyFetch $expr): string|bool
    {
        $nativeProp = $this->findNativeStaticProperty($expr, $class);
        if ($nativeProp) {
            $classPtr = $this->getClassEntryPtr($class);
            $propOffset = $this->getPropertyOffset($class, $nativeProp->name);
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
        if ($class === 'static') {
            if (!$this->methodDef) {
                $this->fatalError($expr, "The 'static' keyword can only be used as the class name in class methods");
            }
            if ($const === 'class') {
                return 'php_get_called_class(this_)';
            } else {
                return 'php::constant(php_get_called_ce(this_), ' . $this->getLiteralString($const) . ')';
            }
        }

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
                if ($classDef->enum) {
                    $ce = $this->getClassEntryPtr($class);
                    return 'php::getEnumCase(' . $ce . ', ' . $this->getLiteralString($const) . ')';
                }
            }
            $ce = $this->getClassEntryPtr($class);
            return 'php::constant(' . $ce . ', ' . $this->getLiteralString($const) . ')';
        }
        $name = $class . '::' . $const;
        $name = $this->getLiteralString($name);
        return 'php::constant(' . $name . ')';
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
        $code .= $this->getIndent() . $exVar . ' = php::catchException();' . PHP_EOL;
        if ($catches) {
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

    protected function parseCatch(Node\Stmt\Catch_ $catch, string $exVar): string
    {
        $types = $catch->types;
        $var = $catch->var ? $this->parseIdentifier($catch->var) : $this->genTmpVarName();
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

    protected function parseGoto(Node\Stmt\Goto_ $v): string
    {
        return 'goto ' . $v->name->name . ';';
    }

    protected function parseLabel(Node\Stmt\Label $v): string
    {
        return $v->name->name . ':';
    }

    protected function parseConstDef(mixed $v2): string
    {
        foreach ($v2->consts as $const) {
            $name  = $this->parseIdentifier($const->name);
            $value = $this->parseIdentifier($const->value);
            if ($this->namespace) {
                $name = $this->namespace . '\\' . $name;
            }
            $this->addConstant($name, $value);
        }

        return '';
    }

    protected function addConstant(string $name, string $value): void
    {
        $constInfo                    = new \stdClass();
        $constInfo->value             = $value;
        $constInfo->type              = $this->detectStrValueType($value);
        $constInfo->namespace = $this->namespace;
        $constInfo->name = $name;
        $this->nativeConstants[$this->escapeNamespace($name)] = $constInfo;
    }

    protected function hasConstant(string $name): bool
    {
        return isset($this->nativeConstants[$this->escapeNamespace($name)]);
    }

    protected function getConstant(string $name): string
    {
        return $this->escapeNamespace($name);
    }

    protected function getConstantType(string $name): string
    {
        return $this->nativeConstants[$this->escapeNamespace($name)]->type;
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
        return $code;
    }

    protected function parseErrorSuppress(Node\Expr\ErrorSuppress $expr): string
    {
        $tmpVar = $this->genTmpVarName();
        $this->context->beforeStmtLines[] = 'auto ' . $tmpVar . ' = EG(error_reporting);';
        $this->context->beforeStmtLines[] = 'php::call(' . $this->getFuncPtr('error_reporting') . ', {E_FATAL_ERRORS});';
        $code = $this->parseExpr($expr->expr);
        $this->context->afterStmtLines[] = 'php::call(' . $this->getFuncPtr('error_reporting') . ', {' . $tmpVar . '});';
        return $code;
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

    protected function findNativeMethod(CallLike $expr, string $object, string $method): string|false
    {
        $nativeFunc = '';
        $classDef = null;
        if ($object === 'this_') {
            $nativeFunc = $this->getNativeName($method, $this->namespace, $this->class);
            $classDef = $this->classDef;
        } elseif (isset($this->context->objects[$object])) {
            $class = $this->context->objects[$object];
            $nativeFunc = $this->getNativeMethod($expr, $class, $method);
            // 存在 Native 类，但是没有找到方法，可能是动态调用
            if (!$nativeFunc and $this->hasNativeClass($class) and $this->getNativeMethod($expr, $class, '__call')) {
                throw new DynamicCall;
            }
        }
        if ($classDef) {
            $fullMethodName = $classDef->getNamespacedName(false) . '::' . $method;
        } else {
            $fullMethodName = $object . '::' . $method;
        }

        // 存在子类同名方法，需要转为动态调用
        if (isset($this->classMethodOverride[$fullMethodName]) and $this->classMethodOverride[$fullMethodName]) {
            return false;
        }
        if ($nativeFunc) {
            $this->checkNativeFunction($nativeFunc);
            if ($this->hasNativeFunction($nativeFunc)) {
                return $nativeFunc;
            }
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

    protected function parseAssignPropertyArrayDim(NodeAbstract $left, NodeAbstract $right): string
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

    protected function parseParentMethodCall(Node\Expr\StaticCall $expr): string
    {
        if (!$this->classDef->extends) {
            $this->fatalError($expr, 'Cannot call parent method `' . $this->classDef->name . '::' . $this->parseIdentifier($expr->name) . '()` because class `' . $this->classDef->name . '` does not extend any class');
        }
        $method = $this->identifierToStr($expr->name);
        if (empty($expr->args)) {
            return 'this_.callParentMethod(' . $method . ')';
        }
        return 'this_.callParentMethod(' . $method . ', ' . $this->parseCallArgs($expr->args) . ')';
    }

    protected function genDebugInfo(?NodeAbstract $stmt = null): string
    {
        $code = '';
        if ($this->debugInfo) {
            if ($stmt) {
                $code .= 'php::traceDebugInfo("' . $this->escapeString($this->file) . '", ' . $stmt->getLine() . ');' . PHP_EOL;
            } else {
                $code .= 'php::enableDebugInfo();' . PHP_EOL;
            }
        }
        return $code;
    }

    protected function genLocalVarDecl(): string
    {
        $code = '';
        foreach ($this->context->localVars as $name => $type) {
            if (isset($this->context->arguments[$name])) {
                continue;
            }
            $code .= $this->getIndent() . $type . ' ' . $name;
            if ($type === self::TYPE_INT or $type === self::TYPE_FLOAT or $type === self::TYPE_BOOL) {
                $code .= ' = 0';
            }
            $code .= ';' . PHP_EOL;
        }
        return $code;
    }

    protected function genReturnCode(): string
    {
        if ($this->functionDef->returnType === self::TYPE_VOID) {
            return '';
        }
        if ($this->functionDef->returnType === self::TYPE_INT
            or $this->functionDef->returnType === self::TYPE_FLOAT
            or $this->functionDef->returnType === self::TYPE_BOOL) {
            return $this->getIndent() . 'return 0;';
        } else {
            return $this->getIndent() . 'return ' . self::VALUE_NULL . ';';
        }
    }

    protected function genEmbeddedCode(NodeAbstract $stmt): string
    {
        return $this->printer->prettyPrint([$stmt]);
    }

    protected function parseArrowFunction(Node\Expr\ArrowFunction $expr): string
    {
        $cb = function () use ($expr) {
            $code = $this->parseExpr($expr->expr);
            if ($this->context->beforeStmtLines) {
                $beforeCode = implode(PHP_EOL, $this->context->beforeStmtLines);
            } else {
                $beforeCode = '';
            }
            if ($this->isCallExpr($expr->expr)) {
                $nativeCall = $expr->expr->getAttribute('nativeCall');
                if ($nativeCall and $this->nativeFunctions[$nativeCall]->returnType === self::TYPE_VOID) {
                    return $beforeCode . PHP_EOL . $code . ';' . PHP_EOL . 'return ' . self::VALUE_NULL . ';';
                }
            }
            return $beforeCode . PHP_EOL . 'return ' . $code . ';';
        };
        return $this->genClosure($expr, $expr->params, $cb, [], true);
    }

    protected function parseClosure(Node\Expr\Closure $expr): string
    {
        $cb = function () use ($expr) {
            $fnCode = $this->parseStmts($expr->stmts);
            $fnCode = $this->genLocalVarDecl() . $fnCode;
            if (!$this->isReturnStmtInLastLine($expr->stmts)) {
                $fnCode .= 'return ' . self::VALUE_NULL . ';' . PHP_EOL;
            }
            return $fnCode;
        };
        return $this->genClosure($expr, $expr->params, $cb, $expr->uses);
    }

    protected function parseAssignOpCoalesce(Node\Expr\AssignOp\Coalesce $expr): string
    {
        $this->checkLeftValue($expr->var);
        $isset = $this->parseChainedExpr($expr->var, self::OP_ISSET);

        $inAssignExpr = $this->context->inAssignExpr;
        $this->context->inAssignExpr = true;
        $var = $this->parseIdentifier($expr->var);
        $this->context->inAssignExpr = $inAssignExpr;

        $right = $this->parseExpr($expr->expr);
        if ($this->isVarExpr($expr->expr) and !$this->hasVar($right)) {
            $this->errorUndefinedVariable($expr->expr);
        }
        if ($this->isVarExpr($expr->var) and !$this->hasVar($var)) {
            $this->addLocalVar($var, $this->detectExprType($expr->expr));
        }
        return '(' . $isset . '?' . $var . ':(' . $var . ' = ' . $right . '))';
    }

    protected function isReturnStmtInLastLine(array $stmts): bool
    {
        if (count($stmts) === 0) {
            return false;
        }
        return $stmts[array_key_last($stmts)] instanceof Node\Stmt\Return_;
    }

    protected function parseNullsafePropertyFetch(Node\Expr\NullsafePropertyFetch $expr): string
    {
        return $this->parseNullsafeExpr($expr);
    }

    protected function parseNullsafeMethodCall(Node\Expr\NullsafeMethodCall $expr): string
    {
        return $this->parseNullsafeExpr($expr);
    }

    protected function parseNullsafeExpr(Node\Expr\NullsafePropertyFetch|Node\Expr\NullsafeMethodCall $expr): string
    {
        $list = [];
        $comment = '// Nullsafe Operator: ' . $this->printer->prettyPrint([$expr]);

        while (1) {
            if ($expr instanceof Node\Expr\NullsafePropertyFetch) {
                $list[] = ['property', $this->identifierToStr($expr->name, literal: true)];
                $expr = $expr->var;
            } elseif ($expr instanceof Node\Expr\NullsafeMethodCall) {
                $list[] = ['method', $this->identifierToStr($expr->name, literal: true), $expr->args];
                $expr = $expr->var;
            } else {
                if ($this->isVarExpr($expr)) {
                    $object = $this->parseIdentifier($expr);
                    if (!$this->hasVar($object)) {
                        $this->errorUndefinedVariable($expr);
                    }
                    $type = $this->getVarType($object);
                    if ($type === self::TYPE_OBJECT) {
                        break;
                    }
                }
                $object = $this->addTmpVar(self::TYPE_OBJECT);
                $this->context->beforeStmtLines[] = $this->getIndent() . $object . ' = ' . $this->parseIdentifier($expr) . ';';
                break;
            }
        }

        $list = array_reverse($list);
        $last = array_key_last($list);
        $tmpFn = $this->genTmpVarName();

        $code = $comment . PHP_EOL . 'auto ' . $tmpFn . ' = [&]() -> ' . self::TYPE_VAR . '{' . PHP_EOL;
        $update = $this->escapeBool($this->context->inAssignExpr);

        foreach ($list as $key => $item) {
            $tmpVar = $this->addTmpVar($key !== $last ? self::TYPE_OBJECT : self::TYPE_VAR);
            $code .= "if ({$object}.isNull()) { return " . self::VALUE_NULL . '; }';
            if ($item[0] == 'property') {
                $code .= $this->getIndent() . "{$tmpVar} = {$object}.attr({$item[1]}, {$update});";
            } else {
                $args = $this->parseCallArgs($item[2]);
                $code .= $this->getIndent() . "{$tmpVar} = {$object}.exec({$item[1]}, {$args});";
            }
            $object = $tmpVar;
        }
        $code .= $this->getIndent() . "return {$object}; };";
        $this->context->beforeStmtLines[] = $code;
        return "{$tmpFn}()";
    }

    protected function parseFullyQualifiedName(Node\Name\FullyQualified $expr): string
    {
        return $expr->name;
    }

    /**
     * 混杂数组赋值，需要拆分为多行插入
     */
    private function parseArrayMixed(Node\Expr\Array_ $node): string
    {
        $tmpVar = $this->genTmpVarName();
        $this->addLocalVar($tmpVar, self::TYPE_ARRAY);

        $items = $node->items;
        foreach ($items as $item) {
            $value = $this->parseIdentifier($item->value);
            if ($item->unpack) {
                $this->context->beforeStmtLines[] = $this->getIndent() . $tmpVar . '.merge(' . $value . ');';
            } elseif ($item->key) {
                $key = $this->parseIdentifier($item->key);
                if (str_starts_with($key, self::LITERAL_STRINGS)) {
                    $key = "{$key}.toStdString()";
                } elseif ($key === '0L') {
                    $key = 'php::zero';
                }
                $this->context->beforeStmtLines[] = $this->getIndent() . $tmpVar . '.set(' . $key . ', ' . $value . ');';
            } else {
                $this->context->beforeStmtLines[] = $this->getIndent() . $tmpVar . '.append(' . $value . ');';
            }
        }

        // 释放临时变量，避免修改数组产生数组复制操作
        $this->context->afterStmtLines[] = $this->getIndent() . $tmpVar . '.unset();';

        return $tmpVar;
    }
}
