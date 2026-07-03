<?php
/**
 * This file is part of Swoole-Compiler(AOT).
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace PhpAot\Php;

use League\CLImate\CLImate;
use PhpAot\Php\Backend\CompilerBackend;
use PhpAot\Php\Backend\CompilerFactory;
use PhpAot\Php\Context\FunctionContext;
use PhpAot\Php\Entity\ClassDef;
use PhpAot\Php\Entity\ConstantDef;
use PhpAot\Php\Entity\FunctionDef;
use PhpAot\Php\Entity\InterfaceDef;
use PhpAot\Php\Entity\MethodDef;
use PhpAot\Php\Entity\PropertyDef;
use PhpAot\Php\Exception\DynamicCall;
use PhpAot\Php\Exception\PlaceHolder;
use PhpAot\Php\Exception\Redo;
use PhpAot\Php\Exception\Skip;
use PhpAot\Php\Exception\TestError;
use PhpAot\Php\Generator\AnonClassGenerator;
use PhpAot\Php\Generator\ClosureGenerator;
use PhpAot\Php\Generator\PlaceHolderGenerator;
use PhpAot\Php\Generator\PropertyPromotion;
use PhpAot\Php\Generator\Utils;
use PhpAot\Php\Generator\TypeCheckGenerator;
use PhpAot\Php\Optimizer\SsaPropOptimizer;
use PhpAot\Php\Optimizer\SsaTypeOptimizer;
use PhpAot\Php\Optimizer\LoopVarOptimizer;
use PhpAot\Php\Parser\StdContainerTrait;
use PhpAot\Php\Parser\AssignOpTrait;
use PhpAot\Php\Parser\BinaryOpTrait;
use PhpAot\Php\Parser\TypeConversionTrait;
use PhpAot\Php\Parser\TypeDetectionTrait;
use PhpAot\Php\Optimizer\FuncCallOptimizer;
use PhpAot\Php\Platform\Linux;
use PhpAot\Php\Platform\Macos;
use PhpAot\Php\Platform\PlatformBase;
use PhpAot\Php\Platform\PlatformFactory;
use PhpAot\Php\Platform\Windows;
use PhpAot\Php\Resolver\InstancePropertyFetchTarget;
use PhpAot\Php\Resolver\PropertyAccessContext;
use PhpAot\Php\Resolver\NativePropertyAccess;
use PhpAot\Php\Resolver\PropertyAccessResult;
use PhpAot\Php\Resolver\PropertyAccessResolver;
use PhpAot\Php\Resolver\PropertyAssignTypeInfo;
use PhpAot\Php\Resolver\PropertyWriteTarget;
use PhpAot\Php\Resolver\StaticPropertyFetchResolution;
use PhpAot\Php\Resolver\StaticPropertyFetchTarget;
use PhpParser\Modifiers;
use PhpParser\Node;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\IntersectionType;
use PhpParser\Node\NullableType;
use PhpParser\Node\Scalar\MagicConst;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\UnionType;
use PhpParser\NodeAbstract;
use PhpParser\NodeFinder;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PhpParser\PhpVersion;
use PhpParser\PrettyPrinter;

class CompilerBase extends \PhpAot\Core\Translator implements PropertyAccessContext
{
    use AstNodeType;
    use FuncCallOptimizer;
    use AnonClassGenerator;
    use ClosureGenerator;
    use PlaceHolderGenerator;
    use PropertyPromotion;
    use MagicMethodDetector;
    use StdContainerTrait;
    use BinaryOpTrait;
    use TypeConversionTrait;
    use TypeDetectionTrait;
    use AssignOpTrait;
    use UniversalMethodCall;
    use Utils;
    use TypeCheckGenerator;
    use SsaTypeOptimizer;
    use LoopVarOptimizer;
    use SsaPropOptimizer;

    public const string TYPE_VAR = 'php::Var';
    public const string TYPE_BOOL = 'php::Bool';
    public const string TYPE_INT = 'php::Int';
    public const string TYPE_FLOAT = 'php::Float';
    public const string TYPE_OBJECT = 'php::Object';
    public const string TYPE_ARRAY = 'php::Array';
    public const string TYPE_RESOURCE = 'php::Resource';
    public const string TYPE_STREAM = 'php::Stream';
    public const string TYPE_BIGINT = 'php::BigInt';
    public const string TYPE_DECIMAL = 'php::Decimal';
    public const string TYPE_BIGFLOAT = 'php::BigFloat';
    public const string TYPE_BOX = 'php::Box';

    protected const string NATIVE_PROPERTY_VALUE_VAR = 'var';
    protected const string NATIVE_PROPERTY_VALUE_DYNAMIC = 'dynamic';

    /**
     * Keyword methods (to* builtins) with mandated return types.
     * Use findKeywordMethod() for unified lookup including keyword extension methods.
     */
    public const array KEYWORD_METHOD_MAP = [
        'toInt'      => self::TYPE_INT,
        'toFloat'    => self::TYPE_FLOAT,
        'toString'   => self::TYPE_STR,
        'toBool'     => self::TYPE_BOOL,
        'toArray'    => self::TYPE_ARRAY,
        'toStream'   => self::TYPE_STREAM,
        'toBigInt'   => self::TYPE_BIGINT,
        'toBigFloat' => self::TYPE_BIGFLOAT,
        'toDecimal'  => self::TYPE_DECIMAL,
        'toObject'   => self::TYPE_OBJECT,
    ];

    private const array STREAM_FUNCTIONS = [
        'fopen',
        'tmpfile',
        'fsockopen',
        'stream_socket_client',
        'stream_socket_accept',
        'popen',
    ];
    public const string TYPE_STD_ARRAY = 'php::StdArray';
    public const string TYPE_STD_VECTOR = 'php::StdVector';
    public const string TYPE_STD_MAP = 'php::StdMap';
    public const string TYPE_STD_ORDERED_MAP = 'php::StdOrderedMap';
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
    public const string VALUE_ZERO = 'php::zero';
    public const string VALUE_FALSE = 'php::false_';
    public const string VALUE_TRUE = 'php::true_';
    public const string LITERAL_STRINGS = '_literal_strings';
    public const string ANON_CLASS = '_anon_class_';
    public const string DYNAMIC_CALLED_CLASS = '__dynamic_called_class__';
    public const string STATIC_VAR = '_static_var_';
    public const string GLOBAL_VAR = '_global_var_';
    public const string CONST_VAR = '_const_var_';
    public const string OBJECT_PROP = '_object_prop_';
    public const string CLASS_MAP = 'class_map';
    public const string FUNC_MAP = 'func_map';
    public const string PROP_MAP = 'property_map';
    public const string NAMESPACE_SEPARATOR = '__';

    public const string PREFIX = 'php_';
    public const string OP_ISSET = 'isset';
    public const string OP_EMPTY = 'empty';
    public const string OP_NOT_EMPTY = 'notEmpty';
    public const string OP_REFVAL = 'toReference';
    public const string OP_NOP = "if (0) {}\n";
    public const string BUILD_MODE_BIN = 'bin';
    public const string BUILD_MODE_EXT = 'ext';
    public const string ENTRY_FUNCTION = 'main';
    public const string PHPX_VENDOR_DIR = '/vendor/swoole/phpx';
    protected const string PHASE_IDLE = 'idle';
    protected const string PHASE_PREPARE = 'prepare';
    protected const string PHASE_CONVERT = 'convert';

    protected string $lang = 'PHP';
    protected string $compilerPhase = self::PHASE_IDLE;
    protected string $cppCompiler = '';
    protected array $literalStrings = [];
    protected int $literalStringIndex = 0;
    protected int $anonClassIndex = 0;
    protected int $classIndex = 0;

    /**
     * @var array<string, int>
     */
    protected array $classMap = [];
    /**
     * @var array<string, int>
     */
    protected array $stdTypeMap = [];
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
        'double' => self::TYPE_FLOAT,
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
        'any' => self::TYPE_VAR,
        // callable 类型，可以是字符串、数组、对象
        // 1) 'foo' 函数名称字符串, 2) [ $obj, 'bar' ] 对象方法数组, 3) Closure 对象， 4) [ 'class', 'staticMethod'] 类名+静态方法数组
        'callable' => self::TYPE_VAR,
        // iterable 类型，可以是数组或者对象
        'iterable' => self::TYPE_VAR,
        'stream' => self::TYPE_STREAM,
        'bigint' => self::TYPE_BIGINT,
        'bigfloat' => self::TYPE_BIGFLOAT,
        'decimal' => self::TYPE_DECIMAL,
        'box' => self::TYPE_BOX,
    ];
    protected array $globalHeaders = [
        'phpx.h',
        'phpx_helper.h',
        'phpx_big_int.h',
        'phpx_big_float.h',
        'phpx_decimal.h',
        'php_aot_helper.h',
        'phpx_std.h',
    ];
    protected array $localHeaders = [];
    protected array $internalFunctions = [];
    protected array $internalConstants = [];

    /**
     * 存储所有函数、类方法的声明，key 是 符号名称，Value 是函数、类方法所在的文件名称
     * @var array<string, string>
     */
    protected array $symbolDeclInFile = [];

    /**
     * 存储所有函数、类方法的调用，key 是 文件名称，Value 是函数、类方法调用的列表数组
     * @var array<string, array<string>>
     */
    protected array $symbolCallInFile = [];
    protected array $redoAfterDeclare = [];
    protected array $constData = [];
    protected int $optimizeLevel = 0;
    protected int $maxJob = 4;
    protected string $buildMode = self::BUILD_MODE_BIN;
    protected string $cxxFlags = '';
    protected string $cxxStd = 'c++17';
    protected string $march = '';            // --march: target CPU instruction set (e.g. native, x86-64-v3)
    protected string $targetPlatform = '';   // --target-platform: cross-compilation target triple (e.g. aarch64-linux-gnu)
    protected string $ldflags = '';
    protected array $linkLibs = [];    // --link-lib / -l: user-specified libraries to link
    protected array $linkPaths = [];   // --link-path / -L: user-specified library search paths
    protected int $floatPrecision = 17;
    protected bool $debug = false;
    protected bool $formatCode = false;   // --format: enable clang-format (disabled by default)
    protected bool $printBacktraceOnError = true;
    protected bool $noLiteralStrings = false;
    protected bool $noConsole = false;  // Windows: hide console window
    protected string $sanitize = '';    // Sanitizer type (address, undefined, etc.)
    protected bool $dryRun = false;     // Dry run: only generate C++ code, skip compile & link
    protected array $userIncludePaths = [];  // --include-path / -I: user-provided C++ include dirs
    protected array $userDefines = [];       // --define / -D: user-provided preprocessor macros
    protected bool $enableLto = false;       // --lto: enable Link Time Optimization (-flto)
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
    protected array $useConstants = [];

    /**
     * 原始类名，不包含命名空间.
     */
    protected string $class = '';
    protected string $parentClass = '';
    protected string $interface = '';

    /**
     * @var array<string, InterfaceDef>
     */
    protected array $interfaces = [];

    /**
     * 存储所有函数、类方法的定义，key 是 native name，命名空间需要转为 `_`，并且必须为小写
     * @var array<string, FunctionDef>
     */
    protected array $functions = [];

    /**
     * key 类名，包含命名空间
     * @var array<string, ClassDef>
     */
    protected array $classes = [];

    /**
     * @var array<string, ConstantDef>
     */
    protected array $constants = [];

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
        '_ENV'     => self::TYPE_ARRAY,
        'GLOBALS'  => self::TYPE_ARRAY,
    ];
    protected array $globalVars = [];
    protected bool $nativeTypes = false;
    protected bool $decimalTypes = false;
    protected bool $bigintTypes = false;
    protected string $rootPath;
    protected string $buildDir;
    protected string $outputDir = '';    // -o 参数指定的输出目录
    protected int $debugLine = 0;
    protected CLImate $climate;
    protected bool $stubFile = false;
    protected bool $enableProfiler = false;
    protected bool $noProgress = false;
    protected bool $forTest = false;
    protected Parser $parser;
    protected PrettyPrinter $printer;
    protected bool $isPhpZts = false;  // PHP 是否为线程安全版本

    // Windows 平台：保存检测到的 PHP lib 文件路径
    protected string $windowsPhpEmbedLib = '';  // php8embed.lib 路径
    protected string $windowsPhpCoreLib = '';   // php8ts.lib 或 php8.lib 路径
    
    // 新的平台和编译器抽象层（可选使用）
    protected ?PlatformBase $platform = null;
    protected ?CompilerBackend $compilerBackend = null;

    /**
     * 在预处理阶段获取所有类的方法名称，检测子类和父类中存在的同名方法，解决动态绑定方法调用的问题
     * `static::methodCall()`
     * `$this->methodCall()` 子类和父类中存在同名方法
     * @var array<string, bool>
     */
    protected array $classMethodOverride = [];

    /**
     * 存储所有类继承关系，类名必须全部为小写
     * @var array<string, string>
     */
    protected array $classExtends = [];

    /**
     * Reverse class hierarchy: parent class (lowercase) => list of child classes (lowercase)
     * @var array<string, string[]>
     */
    protected array $classSubClasses = [];

    public function __construct(string $rootPath)
    {
        parent::__construct();
        if (version_compare(PHP_VERSION, '8.2.0', '<')) {
            $this->error('PHP 8.2.0 or later is required');
        }
        if (version_compare(PHP_VERSION, '8.6.0', '>=')) {
            $this->error('PHP 8.6.0 or later is not supported');
        }
        $this->rootPath = $rootPath;
        $this->parser = (new ParserFactory())->createForVersion(PhpVersion::fromString(PHP_VERSION));
        $this->printer = new PrettyPrinter\Standard();
        $this->setBuildDir($rootPath . '/build');
        $climate = new CLImate();
        $this->climate = $climate;
    }

    protected function getPhpxDir(): string
    {
        // 优先使用环境变量 PHPX_HOME
        $phpxDir = getenv('PHPX_HOME');
        if ($phpxDir && is_dir($phpxDir)) {
            return rtrim($phpxDir, '\/');
        }

        // 尝试使用 Composer 安装的 phpx
        $composerPhpxDir = $this->rootPath . self::PHPX_VENDOR_DIR;
        if (is_dir($composerPhpxDir)) {
            return $composerPhpxDir;
        }

        if (defined('ROOT_PATH')) {
            $rootPhpxDir = ROOT_PATH . self::PHPX_VENDOR_DIR;
            if (is_dir($rootPhpxDir)) {
                return $rootPhpxDir;
            }
        }

        // 两个路径都不存在，报错
        $this->error(
            'phpx directory not found. Please either:\n' .
            '1. Set PHPX_HOME environment variable to your phpx installation path\n' .
            '2. Install phpx via Composer: composer require swoole/phpx'
        );
    }

    protected function getPlatform(): PlatformBase
    {
        if ($this->platform === null) {
            $this->platform = PlatformFactory::create();
        }

        return $this->platform;
    }

    protected function getCompilerBackend(): CompilerBackend
    {
        if ($this->compilerBackend === null) {
            $this->cppCompiler = CompilerFactory::detectCompilerName($this->getPlatform(), $this->cppCompiler);
            $this->compilerBackend = CompilerFactory::createByName($this->cppCompiler, $this->getPlatform());
        }

        return $this->compilerBackend;
    }

    public function isWindows(): bool
    {
        return $this->getPlatform() instanceof Windows;
    }

    public function isLinux(): bool
    {
        return $this->getPlatform() instanceof Linux;
    }

    public function isMacos(): bool
    {
        return $this->getPlatform() instanceof Macos;
    }

    public function isBuildModeBin(): bool
    {
        return $this->buildMode === self::BUILD_MODE_BIN;
    }

    public function isBuildModeExt(): bool
    {
        return $this->buildMode === self::BUILD_MODE_EXT;
    }

    public function getPhpDir(): string
    {
        try {
            return $this->getPlatform()->getPhpDir();
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());
        }
    }

    public function isScalarInt(Expr $expr): bool
    {
        return $expr instanceof Node\Scalar\LNumber;
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

    public function getObjectType(string $object): string
    {
        if (isset($this->context->stableObjects[$object])) {
            return $this->context->stableObjects[$object];
        }
        return $this->context->objects[$object] ?? 'stdClass';
    }

    public function parseExpr(NodeAbstract $expr): string
    {
        if ($expr->hasAttribute('replace')) {
            return $expr->getAttribute('replace');
        }
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
            case 'Expr_AssignOp_ShiftLeft':
                return $this->parseAssignOpShiftLeft($expr);
            case 'Expr_AssignOp_ShiftRight':
                return $this->parseAssignOpShiftRight($expr);
            case 'Expr_AssignOp_BitwiseAnd':
                return $this->parseAssignOpBitwiseAnd($expr);
            case 'Expr_AssignOp_BitwiseOr':
                return $this->parseAssignOpBitwiseOr($expr);
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
            case 'Expr_ArrayDimFetch':
                return $this->parseArrayDimFetch($expr, $this->context->inAssignExpr);
            case 'Expr_PropertyFetch':
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
                return $this->parseIdentifier($expr);
            case 'Expr_Variable':
                $varName = $this->parseIdentifier($expr);
                $this->requireVar($expr, $varName);
                if ($this->isStdContainer($varName)) {
                    return $varName . '_ref';
                }
                // $GLOBALS is an INDIRECT to &EG(symbol_table),
                // whose refcount MUST NOT be directly manipulated.
                // Use php_globals_array() to create a separated copy.
                if ($varName === 'GLOBALS') {
                    return 'php_globals_array()';
                }
                return $varName;
            case 'Scalar_MagicConst_File':
            case 'Scalar_MagicConst_Dir':
            case 'Scalar_MagicConst_Line':
            case 'Scalar_MagicConst_Function':
            case 'Scalar_MagicConst_Method':
            case 'Scalar_MagicConst_Class':
            case 'Scalar_MagicConst_Trait':
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
                break;
            default:
                abort($expr);
                break;
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

    public function getUserIncludePaths(): array
    {
        return $this->userIncludePaths;
    }

    public function getUserDefines(): array
    {
        return $this->userDefines;
    }

    public function isLtoEnabled(): bool
    {
        return $this->enableLto;
    }

    public function getLinkLibs(): array
    {
        return $this->linkLibs;
    }

    public function getLinkPaths(): array
    {
        return $this->linkPaths;
    }

    public function getMarch(): string
    {
        return $this->march;
    }

    public function getRelativePath($path, $cwd = ''): string
    {
        $cwd = $cwd ?: getcwd();
        return ltrim($this->removeCommonPrefix($cwd, $path), '/');
    }

    protected function removeCommonPrefix(string $short, string $long): string
    {
        return $this->getPlatform()->removeCommonPrefix($short, $long);
    }

    protected function getVarType(string $name): string
    {
        if ($this->hasLocalVar($name)) {
            return $this->context->localVars[$name];
        }
        if ($this->hasScopeGlobalVar($name)) {
            return $this->context->globalVars[$name];
        }

        return self::TYPE_VAR;
    }

    /**
     * Resolve the ClassDef for an object expression (variable or $this).
     */
    private function resolveObjectClassDef(Node\Expr $expr): ?ClassDef
    {
        if ($expr instanceof Expr\Variable) {
            $name = $this->parseIdentifier($expr);
            if ($name === 'this_' && $this->classDef) {
                return $this->classDef;
            }
            if ($this->isTypedObject($name)) {
                $className = $this->getObjectType($name);
                if ($this->hasClass($className)) {
                    return $this->getClass($className);
                }
            }
        }
        return null;
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

    protected function enterCompilerPhase(string $phase): string
    {
        $previous = $this->compilerPhase;
        $this->compilerPhase = $phase;
        return $previous;
    }

    protected function restoreCompilerPhase(string $phase): void
    {
        $this->compilerPhase = $phase;
    }

    protected function assertCompilerPhase(string $expected, string $feature): void
    {
        if ($this->compilerPhase !== $expected) {
            $this->error("Internal compiler error: {$feature} can only be used during {$expected} phase, current phase is {$this->compilerPhase}");
        }
    }

    protected function resetClass(): void
    {
        $this->class     = '';
        $this->interface = '';
        $this->classDef  = null;
    }

    protected function resetFile(): void
    {
        $this->indentLevel = 0;
        $this->nativeTypes = false;
        $this->decimalTypes = false;
        $this->bigintTypes = false;
        $this->classesDefineInFile = [];
        $this->interfacesDefineInFile = [];
        $this->functionDefineInFile = [];
    }

    protected function resetNamespace(): void
    {
        $this->useNamespaces = [];
        $this->useAliases = [];
        $this->useFunctions = [];
        $this->useConstants = [];
        $this->namespace = '';
    }

    protected function getFunctionName(FunctionLike $v): string
    {
        return $this->getNativeName($this->parseIdentifier($v->name), $this->namespace, $this->class);
    }

    protected function getFullClassName(): string
    {
        return ltrim($this->namespace . '\\' . $this->class, '\\');
    }

    protected function getFullMethodName(string $fullClassName, string $method): string
    {
        return strtolower($fullClassName . '::' . $method);
    }

    protected function isCurrentConstructor(): bool
    {
        return $this->method === '__construct';
    }

    protected function getCurrentMethodDisplayName(): string
    {
        return $this->getFullClassName() . '::' . $this->method;
    }

    protected function assertExprCanBeUsedAsValue(NodeAbstract $expr, string $context = 'value'): void
    {
        // PHP permits using a void/never call as an expression; the expression
        // result is null after the call side effect has run.
    }

    protected function assertExprCanBeUsedAsCondition(NodeAbstract $expr, string $context = 'condition'): void
    {
        // Conditions are value contexts in PHP. A void/never expression is
        // evaluated for side effects and then coerced from null.
    }

    protected function isVoidValueExpr(NodeAbstract $expr): bool
    {
        return $this->detectTypeOfExpr($expr) === self::TYPE_VOID;
    }

    protected function wrapVoidExprAsNull(NodeAbstract $expr, string $exprCode): string
    {
        if (!$this->isVoidValueExpr($expr)) {
            return $exprCode;
        }

        return '((void) (' . $exprCode . '), ' . self::VALUE_NULL . ')';
    }

    protected function parseExprAsValue(NodeAbstract $expr): string
    {
        return $this->wrapVoidExprAsNull($expr, $this->parseExpr($expr));
    }

    public function getNamespacedClassName(string $class, string $currentNamespace = ''): string
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
            if (strcasecmp($ns1[array_key_last($ns1)], $ns2[0]) === 0) {
                $ns = '\\' . implode('\\', $ns1);
                goto _return;
            }
        }

        // Handle qualified names that exactly match a use import (e.g. the extends
        // of an anonymous class may already be a qualified name like "A\B\C" when the
        // use import is also "A\B\C").
        if (count($ns2) > 1) {
            foreach ($this->useNamespaces as $useNamespace) {
                if (strcasecmp(trim($useNamespace, '\\'), $class) === 0) {
                    return $class;
                }
            }
        }

        if (!$currentNamespace) {
            $currentNamespace = $this->namespace;
        }
        if (!empty($currentNamespace)) {
            return trim($currentNamespace, '\\') . '\\' . $class;
        }

        return $class;
    }

    /**
     * 将 trait 方法参数中的类名 Name 节点升级为 Name\FullyQualified。
     * 对于已由 parseTypeDecl() 解析的限定名（含 \），直接升级节点类型；
     * 对于尚未解析的非限定名（如 NullableType 内层，parseTypeDecl 返回 TYPE_VAR 跳过了解析），
     * 先通过 useAliases/useNamespaces 解析再升级。
     * gen_stub.php 的 SimpleType::fromNode() 依赖 isFullyQualified() 判断是否需要再次解析，
     * 若不升级为 FullyQualified，在上下文丢失后会被错误地追加当前 namespace 前缀。
     */
    protected function upgradeToFullyQualifiedName(?NodeAbstract $type): ?NodeAbstract
    {
        if ($type === null) {
            return null;
        }
        if ($type instanceof Node\NullableType) {
            return new Node\NullableType($this->upgradeToFullyQualifiedName($type->type));
        }
        if ($type instanceof Node\UnionType) {
            foreach ($type->types as $i => $subType) {
                $type->types[$i] = $this->upgradeToFullyQualifiedName($subType);
            }
            return $type;
        }
        if ($type instanceof Node\IntersectionType) {
            foreach ($type->types as $i => $subType) {
                $type->types[$i] = $this->upgradeToFullyQualifiedName($subType);
            }
            return $type;
        }
        if ($type instanceof Node\Name\FullyQualified) {
            return $type;
        }
        if ($type instanceof Node\Name) {
            $typeName = $type->toString();
            if (isset($this->zendTypeMap[strtolower($typeName)]) || in_array(strtolower($typeName), ['self', 'static', 'parent'], true)) {
                return $type;
            }
            if ($type->isQualified()) {
                return new Node\Name\FullyQualified($typeName, $type->getAttributes());
            }
            $resolved = $this->getNamespacedClassName($typeName);
            return new Node\Name\FullyQualified($resolved, $type->getAttributes());
        }
        return $type;
    }

    /**
     * 函数名称处理，补齐 namespace
     */
    public function getNamespacedFuncName(string $funcName): string
    {
        if ($funcName[0] == '\\') {
            return ltrim($funcName, '\\');
        }
        if (isset($this->useFunctions[$funcName])) {
            return $this->useFunctions[$funcName] . '\\' . $funcName;
        }
        return $funcName;
    }

    protected function getObjectPropVarName(string $object, string $prop): string
    {
        return self::OBJECT_PROP . $object . self::NAMESPACE_SEPARATOR . $prop;
    }

    protected function getObjectPropVarInfo(string $object, string $prop): array
    {
        return $this->context->objectProps[$this->getObjectPropVarName($object, $prop)];
    }

    protected function getObjectPropInfoByVar(string $var): ?array
    {
        return $this->context->objectProps[$var] ?? null;
    }

    protected function registerObjectPropVar(string $var, array $info): void
    {
        if (isset($this->context->objectProps[$var])) {
            return;
        }
        $this->context->objectProps[$var] = $info;
    }

    protected function registerHoistedObjectPropVar(string $var, string $type, string $getter): void
    {
        $info = $this->getHoistedObjectPropInfo($type);
        $this->registerObjectPropVar($var, [
            'type' => $info['type'],
            'getter' => $getter,
            'kind' => $info['kind'],
        ]);
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

    /**
     * @param string $class 一定是带有命名空间的完整类名
     */
    protected function parseTypeDecl(?NodeAbstract $type, int $what, string &$class): string
    {
        // 未定义类型视为 var (mixed, any)
        if ($type === null) {
            return self::TYPE_VAR;
        }
        if ($type instanceof UnionType || $type instanceof NullableType || $type instanceof IntersectionType) {
            // 复杂类型静态阶段统一按 mixed/var 处理，运行时再由 typeCheck 兜底。
            return self::TYPE_VAR;
        } else {
            $typeName = $this->parseIdentifier($type);
            $typeNameLower = strtolower($typeName);
            // 属性和类常量的类型不能声明为 void/never ，只有返回值可以
            if ($what !== self::DECL_TYPE_OF_RETURN and ($typeNameLower === 'void' or $typeNameLower === 'never')) {
                $this->fatalError($type, 'The type `void`/`never` is allowed only for return type');
            } elseif (isset($this->zendTypeMap[$typeNameLower])) {
                return $this->getTypeFromZendType($typeNameLower);
            } else {
                if ($typeName === 'self') {
                    $class = $this->getFullClassName();
                } elseif ($typeName === 'parent') {
                    if (!$this->classDef) {
                        $this->fatalError($type, 'Cannot use "parent" type declaration outside a class');
                    }
                    $class = $this->classDef->extends;
                } elseif ($typeName === 'static') {
                    // static 类无法在编译期获取
                    $class = '';
                } else {
                    $class = $this->getNamespacedClassName($typeName);
                }
                // Trait 在注入 class 需要使用完整类名
                if ($class and $this->classDef and $this->classDef->trait) {
                    $type->name = $class;
                }
                return self::TYPE_OBJECT;
            }
        }
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

    protected function parseScalar(Node\Scalar $expr): string
    {
        $type = $expr->getType();
        switch ($type) {
            case 'Scalar_Int':
                if ($this->bigintTypes) {
                    return 'php::toBigInt(' . $expr->value . ')';
                }
                return $expr->value . $this->getPlatform()->getIntegerLiteralSuffix();
            case 'Scalar_Float':
                if ($this->isBigIntLiteral($expr)) {
                    return 'php::toBigInt(' . $this->getLiteralString($this->getBigIntLiteralString($expr)) . ')';
                }
                if ($this->isDecimalLiteral($expr) || $this->decimalTypes) {
                    $rawValue = $expr->getAttribute('rawValue');
                    $clean = $rawValue !== null ? $this->stripNumericUnderscores($rawValue) : (string) $expr->value;
                    return 'php::toDecimal(' . $this->getLiteralString($clean) . ')';
                }
                return $this->parseScalarFloat($expr);
            case 'Scalar_String':
                return $expr->hasAttribute('noLiteralString') ? $this->genCharPtr($expr->value, true) : $this->getLiteralString($expr->value);
            default:
                abort($expr);
                break;
        }
    }

    /**
     * Check if a numeric literal's rawValue represents an integer that exceeds int64 range.
     * PHP's parser converts such literals to float (Scalar_Float) when they overflow.
     */
    /**
     * Check if a Scalar_Float literal should be treated as Decimal.
     * Only "long" floats (>= 16 significant digits) that would lose precision
     * as native PHP float (double) are auto-converted.
     */
    private function getBigIntLiteralString(Node\Scalar $expr): string
    {
        return $this->stripNumericUnderscores($expr->getAttribute('rawValue'));
    }

    private function getDecimalLiteralString(Node\Scalar $expr): string
    {
        return $this->stripNumericUnderscores($expr->getAttribute('rawValue'));
    }

    protected function parseSuperGlobalVar(string $name): string
    {
        if (!$this->hasGlobalVar($name)) {
            $this->addGlobalVar($name, $this->superGlobalVars[$name]);
        }
        if (!$this->hasScopeGlobalVar($name)) {
            $this->addScopeGlobalVar($name, $this->superGlobalVars[$name]);
        }
        return $name;
    }

    protected function parseVariable(Variable $expr): string
    {
        if (is_object($expr->name) and $this->isVarExpr($expr->name)) {
            $this->fatalError($expr, 'The `$$` syntax is not supported');
        }
        if ($this->isSuperGlobal($expr->name)) {
            return $this->parseSuperGlobalVar($expr->name);
        }
        return $this->escapeVarName($expr->name);
    }

    protected function parseImplements(array $implements): array
    {
        $list = [];
        foreach ($implements as $implement) {
            $interfaceName = $this->getNamespacedClassName($this->parseIdentifier($implement));
            $list[] = $interfaceName;
            if (!$this->isInternalInterface($interfaceName)) {
                $this->symbolCallInFile[$this->file][] = strtolower($interfaceName);
            }
        }
        return $list;
    }

    protected function parseArrayKey(NodeAbstract $expr): string
    {
        $key = $this->parseIdentifier($expr);
        if (str_starts_with($key, self::LITERAL_STRINGS)) {
            $key = "{$key}.str()";
        } elseif ($this->isZeroLiteral($expr)) {
            $key = self::VALUE_ZERO;
        }
        return $key;
    }

    /**
     * Check if a node is a literal zero value.
     *
     * Detects compile-time zero for two purposes:
     *  - Division-by-zero guard (any zero form: int, float, negated, numeric string)
     *  - C++ null pointer ambiguity guard: Scalar_Int(0) → 0L → nullptr → segfault
     *    when passed to functions with zend_string* overloads (setProperty, getProperty, etc.)
     */
    protected function isZeroLiteral(NodeAbstract $expr): bool
    {
        if ($expr instanceof Node\Scalar\Int_) {
            return $expr->value === 0;
        }
        if ($expr instanceof Node\Scalar\Float_) {
            return $expr->value == 0.0;
        }
        if ($expr instanceof Expr\UnaryMinus || $expr instanceof Expr\UnaryPlus) {
            return $this->isZeroLiteral($expr->expr);
        }
        if ($expr instanceof Node\Scalar\String_) {
            $value = trim($expr->value);
            return $value !== '' && is_numeric($value) && (float)$value == 0.0;
        }
        if ($expr instanceof Node\Expr\ConstFetch or $expr instanceof Node\Expr\ClassConstFetch) {
            return in_array($this->parseExpr($expr), ['0L', '0LL']);
        }
        return false;
    }

    protected function parseIdentifier(NodeAbstract $expr): string
    {
        $type = $expr->getType();
        switch ($type) {
            case 'Expr_Variable':
                return $this->parseVariable($expr);
            case 'Name_FullyQualified':
                return '\\' . $expr->name;
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
                return $this->parseExprAsValue($expr);
            default:
                return $this->parseExprAsValue($expr);
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
        if ($default instanceof Expr\ConstFetch) {
            return $this->parseConstFetch($default, true);
        } else {
            return $this->parseIdentifier($default);
        }
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
            $code = implode(PHP_EOL, $this->context->beforeStmtLines);
            $this->context->beforeStmtLines = [];
            return $code . PHP_EOL;
        }
        return '';
    }

    protected function parseAfterStmtLines(): string
    {
        if ($this->context->afterStmtLines) {
            $code = implode(PHP_EOL, $this->context->afterStmtLines);
            $this->context->afterStmtLines = [];
            return $code . PHP_EOL;
        }
        return '';
    }

    protected function parseExprWithCapturedStmts(NodeAbstract $expr): array
    {
        $beforeStmtCount = count($this->context->beforeStmtLines);
        $afterStmtCount = count($this->context->afterStmtLines);
        $value = $this->parseExprAsValue($expr);
        $beforeStmts = array_slice($this->context->beforeStmtLines, $beforeStmtCount);
        $afterStmts = array_slice($this->context->afterStmtLines, $afterStmtCount);
        $this->context->beforeStmtLines = array_slice($this->context->beforeStmtLines, 0, $beforeStmtCount);
        $this->context->afterStmtLines = array_slice($this->context->afterStmtLines, 0, $afterStmtCount);
        return [$value, $beforeStmts, $afterStmts];
    }

    protected function stringifyParsedExpr(mixed $expr): string
    {
        if (is_string($expr)) {
            return $expr;
        }
        if (is_int($expr) || is_float($expr)) {
            return (string) $expr;
        }
        if (is_object($expr)) {
            if (method_exists($expr, 'toString')) {
                return $expr->toString();
            }
            if (method_exists($expr, '__toString')) {
                return $expr->__toString();
            }
        }
        throw new \LogicException('Parsed expression must be stringable');
    }

    protected function appendCapturedStmtLines(string &$code, array $stmts): void
    {
        if ($stmts) {
            $code .= $this->formatCapturedStmtLines($stmts);
        }
    }

    protected function formatCapturedStmtLines(array $stmts): string
    {
        if (!$stmts) {
            return '';
        }
        return $this->getIndent() . implode(PHP_EOL . $this->getIndent(), $stmts) . PHP_EOL;
    }

    protected function genConditionWithCapturedStmts(NodeAbstract $cond, string $openPrefix): string
    {
        $this->assertExprCanBeUsedAsCondition($cond);
        [$condExpr, $beforeStmts, $afterStmts] = $this->parseExprWithCapturedStmts($cond);
        $code = '';
        $this->appendCapturedStmtLines($code, $beforeStmts);
        if ($afterStmts) {
            $tmpVar = $this->addTmpVar(self::TYPE_VAR);
            $code .= $this->getIndent() . $tmpVar . ' = ' . $condExpr . ';' . PHP_EOL;
            $this->appendCapturedStmtLines($code, $afterStmts);
            $condExpr = $tmpVar;
        }

        if ($cond instanceof Expr\Assign) {
            $condExpr = '(' . $condExpr . ')';
        }
        $code .= $openPrefix . '(' . $condExpr . ') {' . PHP_EOL;
        return $code;
    }

    protected function parseBlockStmts(array $stmts): string
    {
        $this->indentLevel++;
        $code = $this->parseStmts($stmts);
        $this->indentLevel--;
        return $code;
    }

    protected function parseStmts(array $stmts): string
    {
        $this->context->enterScope();
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
                case 'Stmt_Block':
                    $result = $this->parseStmts($v->stmts);
                    break;
                case 'Stmt_Class':
                    $this->fatalError($v, 'Cannot declare class in function');
                    break;
                case 'Stmt_Function':
                    $this->fatalError($v, 'Cannot declare function in function');
                    break;
                default:
                    abort($v);
                    break;
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
        $this->context->leaveScope();

        return $code;
    }

    protected function parseEcho(mixed $v): string
    {
        $lines = [];
        foreach ($v->exprs as $expr) {
            if ($expr instanceof Expr\Assign) {
                $this->fatalError($expr, 'Cannot echo assign expression');
            } else {
                $type = $this->detectTypeOfExpr($expr);
                $parsed = $this->convertExprToStringByType($this->parseExprAsValue($expr), $type);
                $lines[] = 'php::echo(' . $parsed . ');';
            }
        }

        return implode("\n" . $this->getIndent(), $lines);
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

    protected function detectClassOfExpr(NodeAbstract $expr): string
    {
        if ($this->isNewExpr($expr) and $this->isNameExpr($expr->class)) {
            $class = $this->parseIdentifier($expr->class);
            if ($class === 'self') {
                return $this->getFullClassName();
            }
            if ($class === 'static') {
                // 无法在编译期获得 static 类的准确类名
                return '';
            } else {
                return $this->getNamespacedClassName($class);
            }
        }
        if ($this->isVarExpr($expr)) {
            $object = $this->parseVariable($expr);
            if ($object === 'this_') {
                return $this->getFullClassName();
            }
            if ($this->isTypedObject($object)) {
                return $this->getObjectType($object);
            }
        }
        if ($this->isArrayDimFetch($expr) and $this->isStdContainerExpr($expr)) {
            if ($this->isStdArrayExpr($expr)) {
                if (!$expr->hasAttribute('stdArrayDimFetch')) {
                    $this->parseStdArrayDimFetch($expr);
                }
                $attr = $expr->getAttribute('stdArrayDimFetch');
                if ($attr['accessLevel'] === $attr['totalLevel']) {
                    return $this->context->stdArrays[$attr['var']]['class'] ?? '';
                }
                return '';
            }
            if (!$expr->hasAttribute('stdContainerDimFetch')) {
                $this->parseStdContainerDimFetch($expr);
            }
            $attr = $expr->getAttribute('stdContainerDimFetch');
            return $this->context->stdContainers[$attr['var']]['class'] ?? '';
        }
        if ($this->isFuncCallExpr($expr) and $this->isNameExpr($expr->name)) {
            $fn = $this->parseIdentifier($expr->name);
            if (count($expr->args) === 2 and $fn === 'objval') {
                return $this->resolveClassNameArg($expr->args[1]->value);
            }
            if ($this->hasFunction($fn)) {
                return $this->getFunction($fn)->returnClass;
            }
        }
        if ($this->isMethodCall($expr) and $this->isNamedMethod($expr->name)) {
            $method = $this->parseIdentifier($expr->name);
            if ($method === 'toObject' and !empty($expr->args)) {
                return $this->resolveClassNameArg($expr->args[0]->value);
            }
            $classDef = $this->resolveObjectClassDef($expr->var);
            if ($classDef !== null && $classDef->hasMethod($method)) {
                return $classDef->getMethod($method)->functionDef->returnClass;
            }
            if ($this->isVarExpr($expr->var)) {
                $object = $this->parseVariable($expr->var);
                try {
                    $nativeFunc = $this->findNativeMethod($expr, $object, $method);
                    if ($nativeFunc) {
                        return $this->getFunction($nativeFunc)->returnClass;
                    }
                } catch (DynamicCall) {
                }
            }
        }
        if ($this->isStaticCall($expr) and $this->isNameExpr($expr->class) and $this->isNamedMethod($expr->name)) {
            $class = $this->parseIdentifier($expr->class);
            if ($class === 'self') {
                $class = $this->class;
            } elseif ($class === 'static' or $class === 'parent') {
                return '';
            }
            $class = $this->getNamespacedClassName($class);
            $method = $this->parseIdentifier($expr->name);
            if ($this->hasClass($class)) {
                $classDef = $this->getClass($class);
                if ($classDef->hasMethod($method)) {
                    return $classDef->getMethod($method)->functionDef->returnClass;
                }
            }
            $nativeFunc = $this->getNativeMethod($expr, $class, $method);
            if ($nativeFunc) {
                return $this->getFunction($nativeFunc)->returnClass;
            }
        }
        return '';
    }

    protected function resolveClassNameArg(NodeAbstract $arg): string
    {
        if ($this->isScalarString($arg)) {
            return $this->getNamespacedClassName($arg->value);
        }
        if ($this->isClassConstFetch($arg)) {
            if ($this->isNameExpr($arg->class) and $this->isIdExpr($arg->name) and $this->parseIdentifier($arg->name) === 'class') {
                $class = $this->parseIdentifier($arg->class);
                if ($class === 'self') {
                    $class = $this->class;
                } elseif ($class === 'parent') {
                    if (!$this->classDef || !$this->classDef->extends) {
                        $this->fatalError($arg, 'Cannot use "parent" outside a class or class does not extend any class');
                    }
                    return $this->classDef->extends;
                } elseif ($class === 'static') {
                    $this->fatalError($arg, "'static::class' cannot be resolved at compile time, use a concrete class name or 'self::class'");
                }
                return $this->getNamespacedClassName($class);
            }
        }
        $this->fatalError($arg, 'Only string literals or `ClassName::class` constant are supported');
    }

    protected function parseReturn(Node\Stmt\Return_ $v): string
    {
        if ($v->expr === null) {
            if ($this->functionDef->returnType === self::TYPE_VOID and !$this->context->inClosure) {
                return 'return;';
            } elseif ($this->shouldCheckClosureReturnType()) {
                return $this->genClosureCheckedReturn(self::VALUE_NULL);
            } elseif ($this->functionDef->returnTypeCheck && !$this->context->inClosure) {
                return $this->genUnionCheckedReturn(self::VALUE_NULL);
            } else {
                return 'return ' . self::VALUE_NULL . ';';
            }
        }
        // 实际函数的返回值
        $type = $this->detectTypeOfExpr($v->expr);
        if ($this->isCurrentConstructor() && !$this->context->inClosure) {
            $this->fatalError($v, 'Method `' . $this->getCurrentMethodDisplayName() . '()` cannot return a value');
        }
        $expr = $this->parseExprAsValue($v->expr);
        $returnType = $this->getReturnType();

        // 匿名函数的返回值一定是 var
        if (!$this->context->inClosure) {
            if ($returnType === 'void') {
                $this->fatalError($v, 'The return type is void, cannot return any value');
            }
        } else {
            $returnType = self::TYPE_VAR;
        }

        // 返回值的表达式是一个类的对象
        $objectClass = $this->detectClassOfExpr($v->expr);
        $returnClass = $this->getReturnClass();
        if ($returnClass) {
            if (!$objectClass or $this->hasInterface($objectClass)) {
                // TODO 返回值的类型无法确定，或者是一个接口，无法继承关系，需要插入动态类型检测代码
            } elseif (!$this->isInheritedFrom($objectClass, $returnClass)) {
                $this->fatalError($v, 'The return type is `' . $returnClass . '`, cannot return an instance of `' . $objectClass . '`');
            }
            // 把子类当做父类返回时，父类必须是抽象类或者接口
            // 仅原生类进行静态检查，若类不存在，说明该类是动态类，无法进行编译期验证
            if ($objectClass and $objectClass !== $returnClass
                and $this->hasClass($returnClass)
                and !$this->isAbstractClass($returnClass)
                and !$this->hasInterface($returnClass)
                and !$this->isInternalInterface($returnClass)) {
                $this->fatalError($v, "When returning a subclass `$objectClass` instance as parent type, the parent class `$returnClass` must be abstract/interface");
            }
        }

        $exprCode = $this->convertExprType($expr, $returnType, $type);
        // Union/nullable return type: always use tmpVar for runtime check
        if ($this->shouldCheckClosureReturnType()) {
            [$code, $tmpVar] = $this->genClosureCheckedReturnAssignment($exprCode);
            $this->context->afterStmtLines[] = $this->getIndent() . 'return ' . $tmpVar . ';';
        } elseif ($this->functionDef->returnTypeCheck && !$this->context->inClosure) {
            [$code, $tmpVar] = $this->genUnionCheckedReturnAssignment($exprCode);
            $this->context->afterStmtLines[] = $this->getIndent() . 'return ' . $tmpVar . ';';
        } elseif (!$this->isVarExpr($v->expr) and !$this->isScalar($v->expr)) {
            // return 如果使用了 Indirect 语句，可能会导致变量提前析构，出现悬空指针
            // 将 Indirect 赋值给临时变量后，使用 Ctor::Copy 解除了 Indirect，保证内存安全
            $tmpVar = $this->genTmpVarName();
            // 必须提前声明变量，否则在末尾声明并 return 可能会被 gcc 优化掉
            $this->addLocalVar($tmpVar, $returnType);
            $code = $tmpVar . ' = (' . $exprCode . ');' . PHP_EOL;
            // 解析表达式后可能会插入语句，因此需要在末尾添加 return 语句，而不是直接返回
            $this->context->afterStmtLines[] = $this->getIndent() . 'return ' . $tmpVar . ';';
        } else {
            $code = 'return ' . $exprCode . ';';
        }

        return $code;
    }

    protected function genClosureCheckedReturn(string $exprCode): string
    {
        [$code, $tmpVar] = $this->genClosureCheckedReturnAssignment($exprCode);
        return $code . $this->getIndent() . 'return ' . $tmpVar . ';';
    }

    protected function genClosureReturnValue(string $exprCode): string
    {
        if ($this->context->closureReturnTypeCheck) {
            return $this->genClosureCheckedReturn($exprCode);
        }

        return 'return ' . $exprCode . ';';
    }

    protected function genClosureReturnNull(): string
    {
        return $this->genClosureReturnValue(self::VALUE_NULL);
    }

    protected function genUnionCheckedReturn(string $exprCode): string
    {
        [$code, $tmpVar] = $this->genUnionCheckedReturnAssignment($exprCode);
        return $code . $this->getIndent() . 'return ' . $tmpVar . ';';
    }

    protected function genClosureCheckedReturnAssignment(string $exprCode): array
    {
        return $this->genCheckedReturnAssignment($exprCode, true);
    }

    protected function genUnionCheckedReturnAssignment(string $exprCode): array
    {
        return $this->genCheckedReturnAssignment($exprCode, false);
    }

    protected function genCheckedReturnAssignment(string $exprCode, bool $closure): array
    {
        $tmpVar = $this->genTmpVarName();
        $this->addLocalVar($tmpVar, self::TYPE_VAR);
        $code = $tmpVar . ' = ' . $exprCode . ';' . PHP_EOL;
        $code .= $closure ? $this->genClosureReturnCheck($tmpVar) : $this->genUnionReturnCheck($tmpVar);

        return [$code, $tmpVar];
    }

    protected function shouldCheckClosureReturnType(): bool
    {
        return $this->context->inClosure && $this->context->closureReturnTypeCheck;
    }

    protected function addLocalVar(string $name, string $type): void
    {
        $this->context->localVars[$name] = $type;
    }

    protected function registerStdType(string $key): int
    {
        if (isset($this->stdTypeMap[$key])) {
            return $this->stdTypeMap[$key];
        }
        $typeId = count($this->stdTypeMap) + 1;
        $this->stdTypeMap[$key] = $typeId;
        return $typeId;
    }

    protected function addTmpVar(string $type): string
    {
        $var = $this->genTmpVarName();
        $this->addLocalVar($var, $type);
        return $var;
    }

    protected function addStaticVar(Variable $var, string $name, string $type): string
    {
        if ($this->hasVar($name)) {
            $this->fatalError($var, 'Duplicate variable `$' . $var->name . '`');
        }
        $this->context->staticVars[$name] = $type;
        // 静态变量实际上是一个全局变量的引用
        $globalVar = $this->escapeStaticVar($name);
        $this->addGlobalVar($globalVar, $type);
        return $globalVar;
    }

    protected function hasArgument(string $name): bool
    {
        return isset($this->context->arguments[$name]);
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

    protected function addScopeGlobalVar(string $name, string $type): void
    {
        $this->context->globalVars[$name] = $type;
    }

    protected function addObject(string $name, string $class): void
    {
        // 接口、抽象类、非原生类，无法作为 TypedObject 使用
        if (!$this->isInterface($class) and !$this->isAbstractClass($class) and
            ($this->isNativeClass($class) or $this->isInternalClass($class))) {
            $this->context->objects[$name] = $class;
        }
    }

    protected function hasVar(string $name): bool
    {
        return $this->hasLocalVar($name) || $this->hasStaticVar($name) || $this->hasScopeGlobalVar($name) || $this->isSuperGlobal($name);
    }

    protected function hasLocalVar(string $name): bool
    {
        return isset($this->context->localVars[$name]);
    }

    protected function hasObjectPropVar(string $name): bool
    {
        return isset($this->context->objectProps[$name]);
    }

    protected function addFunction(string $name, FunctionDef $functionDef): void
    {
        $this->functions[$this->escapeFunction($name)] = $functionDef;
    }

    /**
     * @param string $name 必须传入带有完整命名空间的类名，将会自动转义为 native name
     */
    protected function hasFunction(string $name): bool
    {
        return array_key_exists($this->escapeFunction($name), $this->functions);
    }

    protected function getFunction(string $name): FunctionDef
    {
        return $this->functions[$this->escapeFunction($name)];
    }

    protected function addClass(string $name, ClassDef $classDef): void
    {
        $this->classes[$this->escapeClass($name)] = $classDef;
    }

    protected function getClass(string $name): ClassDef
    {
        return $this->classes[$this->escapeClass($name)];
    }

    public function getClassDef(string $name): ?ClassDef
    {
        return $this->classes[$this->escapeClass($name)] ?? null;
    }

    public function getParentClass(string $class): string
    {
        return $this->classExtends[strtolower(ltrim($class, '\\'))] ?? '';
    }

    protected function hasClass(string $name): bool
    {
        return array_key_exists($this->escapeClass($name), $this->classes);
    }

    protected function hasInterface(string $name): bool
    {
        return array_key_exists($this->escapeClass($name), $this->interfaces);
    }

    protected function getInterface(string $name): InterfaceDef
    {
        return $this->interfaces[$this->escapeClass($name)];
    }

    protected function checkFunction(string $name): void
    {
        // 在预处理阶段检测到函数声明，但是未定义，说明在当前文件，但是顺序错误
        // 跳过，稍后再处理
        if (isset($this->symbolDeclInFile[$name])
            and $this->symbolDeclInFile[$name] === $this->file
            and !$this->hasFunction($name)) {
            $this->redoAfterDeclare[$name] = true;
            throw new Skip();
        }
    }

    protected function checkNativeCallArgs(CallLike $expr, FunctionDef $funcDef, array $args, string $name): void
    {
        $this->validateNativeNamedCallArgs($funcDef, $args);

        if ($this->hasUnpackCallArg($args)) {
            return;
        }

        $argc = count($args);
        $type = str_contains($name, '::') ? 'Method' : 'Function';
        if ($argc < $funcDef->argCountRequired) {
            $this->fatalError($expr, $type . ' `' . $name . '()` requires ' . $funcDef->argCountRequired . ' arguments, ' . $argc . ' given');
        } elseif (!$funcDef->hasVariadicArg() and count($expr->args) > count($funcDef->argInfoList)) {
            $this->fatalError($expr, $type . ' `' . $name . '()` accepts ' . count($funcDef->argInfoList) . ' arguments, ' . $argc . ' given');
        }
    }

    protected function getFunctionArgNameIndex(FunctionDef $functionDef): array
    {
        $argNameIndex = [];
        foreach ($functionDef->argInfoList as $k => $argInfo) {
            $argNameIndex[$argInfo->phpName ?: $this->unescapeVarName($argInfo->name)] = $k;
        }
        return $argNameIndex;
    }

    protected function getVariadicArgIndex(FunctionDef $functionDef): ?int
    {
        $lastIndex = count($functionDef->argInfoList) - 1;
        if ($lastIndex >= 0 and $functionDef->argInfoList[$lastIndex]->variadic) {
            return $lastIndex;
        }
        return null;
    }

    protected function validateNativeNamedCallArgs(FunctionDef $functionDef, array $callArgs): void
    {
        $hasNamedArg = false;
        $hasUnpack = false;
        $seenNamedArgs = [];
        $providedArgIndexes = [];
        $argNameIndex = $this->getFunctionArgNameIndex($functionDef);
        $variadicArgIndex = $this->getVariadicArgIndex($functionDef);

        foreach ($callArgs as $i => $arg) {
            if ($this->isPlaceholderExpr($arg)) {
                continue;
            }
            if ($arg instanceof Node\Arg && $arg->unpack) {
                if ($hasNamedArg) {
                    $this->fatalError($arg, 'Cannot use argument unpacking after named arguments');
                }
                $hasUnpack = true;
                $providedArgIndexes[$i] = true;
                continue;
            }
            if ($arg->name === null) {
                if ($hasUnpack) {
                    $this->fatalError($arg, 'Cannot use positional argument after argument unpacking');
                }
                if ($hasNamedArg) {
                    $this->fatalError($arg, 'Cannot use positional argument after named argument');
                }
                $providedArgIndexes[$i] = true;
                continue;
            }
            if (!$this->isIdExpr($arg->name)) {
                $this->fatalError($arg, 'Named argument must be a string');
            }

            $argName = $arg->name->name;
            if (isset($seenNamedArgs[$argName])) {
                $this->fatalError($arg, "Duplicate named argument `{$argName}`");
            }
            if (!array_key_exists($argName, $argNameIndex)) {
                if ($variadicArgIndex === null) {
                    $this->fatalError($arg, "Unknown named argument `{$argName}`");
                }
                $seenNamedArgs[$argName] = true;
                $hasNamedArg = true;
                continue;
            }

            $argIndex = $argNameIndex[$argName];
            if ($variadicArgIndex !== null and $argIndex === $variadicArgIndex) {
                $seenNamedArgs[$argName] = true;
                $hasNamedArg = true;
                continue;
            }
            if (isset($providedArgIndexes[$argIndex])) {
                $this->fatalError($arg, "Named argument `{$argName}` overwrites previous argument");
            }

            $seenNamedArgs[$argName] = true;
            $providedArgIndexes[$argIndex] = true;
            $hasNamedArg = true;
        }
    }

    protected function getNativeMethod(CallLike $expr, string $class, string $method, bool $checkArgs = true): string|false
    {
        if (!$this->hasClass($class)) {
            return false;
        }

        $classDef = $this->getClass($class);
        $methodDef = null;
        // 递归查找，若子类中未定义方法，则尝试查找父类是否存在此方法
        while (true) {
            if (!$classDef->hasMethod($method)) {
                if (!$classDef->extends) {
                    return false;
                }
                if (!$this->hasClass($classDef->extends)) {
                    if ($classDef->inheritedFromInternalClass) {
                        if (!Reflection::hasMethod($classDef->extends, $method) and !Reflection::hasMethod($classDef->extends, $method . '__call')) {
                            $this->fatalError($expr, 'Class `' . $classDef->getNamespacedName() . '` inherits from a internal class, but the class `' .
                                $classDef->extends . '` does not have a `' . $method . '` method or a `__call` magic method');
                        } else {
                            $this->climate->cyan('Dynamically calling internal class method `' . $classDef->extends . '::' . $method . '()`');
                            throw new DynamicCall();
                        }
                    }
                    return false;
                }
                $classDef = $this->getClass($classDef->extends);
            } else {
                $methodDef = $classDef->getMethod($method);
                break;
            }
        }

        if (!$this->checkAccessible($classDef, $methodDef->flags)) {
            $this->fatalError($expr, 'Method `' . $classDef->getNamespacedName() . '::' . $method . '()` is not accessible');
        }
        // 函数调用占位符，不是真实的函数调用
        if (count($expr->args) === 1 and $this->isPlaceholderExpr($expr->args[0])) {
            return false;
        }
        if ($checkArgs) {
            $this->checkNativeCallArgs($expr, $methodDef->functionDef, $expr->args, $classDef->getNamespacedName() . '::' . $method);
        }
        return $this->getNativeName($method, $classDef->namespace, $classDef->name);
    }

    protected function findNativeClassConst(NodeAbstract $expr, string $class, string $const): string|false
    {
        if (!$this->hasClass($class)) {
            return false;
        }

        $classDef = $this->getClass($class);
        $originClassDef = $classDef;
        $constDef = null;
        // 递归查找，若子类中未定义方法，则尝试查找父类是否存在此方法
        while (true) {
            if (!$classDef->hasConstant($const)) {
                if (!$classDef->extends) {
                    break;
                }
                if (!$this->hasClass($classDef->extends)) {
                    break;
                }
                $classDef = $this->getClass($classDef->extends);
            } else {
                $constDef = $classDef->getConstant($const);
                break;
            }
        }
        if ($constDef === null) {
            foreach ($this->getClassImplementedInterfaces($originClassDef) as $interfaceName) {
                if (!$this->hasInterface($interfaceName)) {
                    continue;
                }
                $interfaceDef = $this->getInterface($interfaceName);
                if (!$interfaceDef->hasConstant($const)) {
                    continue;
                }
                $interfaceConstDef = $interfaceDef->constants[$const];
                if ($interfaceConstDef->type === self::TYPE_ARRAY) {
                    return self::PREFIX . $this->getNativeName($interfaceConstDef->name, $interfaceDef->namespace, $interfaceDef->name);
                }
                $expr->setAttribute('nativeConst', $interfaceConstDef);
                return $interfaceConstDef->value;
            }
        }
        if ($constDef === null) {
            return false;
        }
        if ($classDef instanceof ClassDef && !$this->checkAccessible($classDef, $constDef->flags)) {
            $this->fatalError($expr, 'Constant `' . $classDef->getNamespacedName() . '::' . $const . '` is not accessible');
        }
        if ($constDef->type === self::TYPE_ARRAY) {
            return self::PREFIX . $this->getNativeName($constDef->name, $classDef->namespace, $classDef->name);
        } else {
            $expr->setAttribute('nativeConst', $constDef);
            return $constDef->value;
        }
    }

    /**
     * @return array<string>
     */
    protected function getClassImplementedInterfaces(ClassDef $classDef): array
    {
        $interfaces = [];
        $current = $classDef;
        while (true) {
            foreach ($current->implements as $interfaceName) {
                $this->collectInterfaceAndParents($interfaceName, $interfaces);
            }
            if (!$current->extends || !$this->hasClass($current->extends)) {
                break;
            }
            $current = $this->getClass($current->extends);
        }

        return array_values($interfaces);
    }

    /**
     * @param array<string, string> $interfaces
     */
    private function collectInterfaceAndParents(string $interfaceName, array &$interfaces): void
    {
        if (isset($interfaces[$interfaceName])) {
            return;
        }
        $interfaces[$interfaceName] = $interfaceName;
        if (!$this->hasInterface($interfaceName)) {
            return;
        }
        $interfaceDef = $this->getInterface($interfaceName);
        foreach ($interfaceDef->extendsList ?: ($interfaceDef->extends ? [$interfaceDef->extends] : []) as $parentInterface) {
            $this->collectInterfaceAndParents($parentInterface, $interfaces);
        }
    }

    protected function resetReturnType(Node\Stmt\Return_ $node, string $type): void
    {
        $oriType = $this->functionDef->returnType;
        $this->functionDef->returnType = $type;
        // 返回值变更，需要重新解析
        $this->climate->cyan("Return type changed ({$oriType} -> {$type}) at line {$node->getLine()} retrying...");
        throw new Redo();
    }

    protected function detectVarType($var): string
    {
        $name = $this->parseIdentifier($var);
        if ($this->isStdContainer($name)) {
            return self::TYPE_ARRAY;
        }
        return $this->getVarType($name);
    }

    protected function detectTypeOfExpr($expr): string
    {
        $exprType = $expr->getType();
        switch ($exprType) {
            case 'Expr_UnaryMinus':
                return $this->detectTypeOfExpr($expr->expr);
            case 'Expr_BitwiseNot':
                $inner = $this->detectTypeOfExpr($expr->expr);
                return $inner === self::TYPE_BIGINT ? self::TYPE_BIGINT : self::TYPE_INT;
            case 'Expr_Print':
            case 'Expr_Cast_Int':
                return self::TYPE_INT;
            case 'Scalar_Int':
                return $this->bigintTypes ? self::TYPE_BIGINT : self::TYPE_INT;
            case 'Expr_Cast_Float':
            case 'Expr_Cast_Double':
                return self::TYPE_FLOAT;
            case 'Scalar_Float':
                if ($this->isBigIntLiteral($expr)) {
                    return self::TYPE_BIGINT;
                }
                if ($this->isDecimalLiteral($expr) || $this->decimalTypes) {
                    return self::TYPE_DECIMAL;
                }
                return self::TYPE_FLOAT;
            case 'Expr_Cast_Bool':
            case 'Scalar_Bool':
                return self::TYPE_BOOL;
            case 'Expr_Array':
            case 'Expr_Cast_Array':
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
                $leftType  = $this->detectTypeOfExpr($expr->left);
                $rightType = $this->detectTypeOfExpr($expr->right);
                if ($leftType === self::TYPE_BIGFLOAT || $rightType === self::TYPE_BIGFLOAT) {
                    return self::TYPE_BIGFLOAT;
                }
                if ($leftType === self::TYPE_DECIMAL || $rightType === self::TYPE_DECIMAL) {
                    return self::TYPE_DECIMAL;
                }
                if ($leftType === self::TYPE_BIGINT || $rightType === self::TYPE_BIGINT) {
                    if ($exprType === 'Expr_BinaryOp_Div') {
                        // BigInt division produces BigInt (integer division); BigDecimal in future
                        return self::TYPE_BIGINT;
                    }
                    return self::TYPE_BIGINT;
                }
                if ($leftType === self::TYPE_FLOAT || $rightType === self::TYPE_FLOAT) {
                    return self::TYPE_FLOAT;
                }
                if ($leftType === self::TYPE_INT || $rightType === self::TYPE_INT) {
                    return self::TYPE_INT;
                }
                break;
            case 'Expr_FuncCall':
                if ($this->isNameExpr($expr->name)) {
                    $name = $this->parseIdentifier($expr->name);
                    // Math function optimization: propagate Big* return types
                    if (in_array($name, ['abs', 'pow', 'sqrt', 'floor', 'ceil', 'round'], true) && !empty($expr->args)) {
                        $argType = $this->detectTypeOfExpr($expr->args[0]->value);
                        if (
                            $argType === self::TYPE_BIGINT
                            && in_array($name, ['abs', 'pow', 'sqrt'], true)
                        ) {
                            return self::TYPE_BIGINT;
                        }
                        if (
                            $argType === self::TYPE_DECIMAL
                            && in_array($name, ['abs', 'pow', 'sqrt', 'floor', 'ceil', 'round'], true)
                        ) {
                            return self::TYPE_DECIMAL;
                        }
                        if (
                            $argType === self::TYPE_BIGFLOAT
                            && in_array($name, ['abs', 'sqrt'], true)
                        ) {
                            return self::TYPE_BIGFLOAT;
                        }
                    }
                    if (in_array($name, self::STREAM_FUNCTIONS)) {
                        return self::TYPE_STREAM;
                    }
                    if (count($expr->args) === 1 and $this->isPlaceholderExpr($expr->args[0])) {
                        return self::TYPE_OBJECT;
                    }
                    if ($this->hasFunction($name)) {
                        return $this->getFunction($name)->returnType;
                    }
                    return $this->detectFuncCallReturnType($name);
                }
                break;
            case 'Expr_MethodCall':
                if ($this->isNamedMethod($expr->name)) {
                    $method = $this->parseIdentifier($expr->name);
                    // keyword methods (to* builtins + __ extensions) — return type is known regardless of receiver
                    $kwType = $this->findKeywordMethod($method);
                    if ($kwType !== null) {
                        return $kwType;
                    }
                    // Class definition resolution (handles this_, typed VarExpr)
                    $classDef = $this->resolveObjectClassDef($expr->var);
                    if ($classDef !== null && $classDef->hasMethod($method)) {
                        if (count($expr->args) === 1 and $this->isPlaceholderExpr($expr->args[0])) {
                            return self::TYPE_OBJECT;
                        }
                        return $classDef->getMethod($method)->getReturnType();
                    }
                    if ($this->isVarExpr($expr->var)) {
                        $object = $this->parseIdentifier($expr->var);
                        try {
                            $nativeFunc = $this->findNativeMethod($expr, $object, $method);
                            if ($nativeFunc) {
                                $funcDef = $this->getFunction($nativeFunc);
                                return $funcDef->returnType;
                            }
                        } catch (DynamicCall) {
                            // Method inherited from internal class, can't resolve type statically
                        }
                        if ($this->isTypedObject($object)) {
                            return $this->detectMethodCallReturnType($this->getObjectType($object), $method);
                        }
                        $type = $this->getVarType($object);
                    } else {
                        $type = $this->detectTypeOfExpr($expr->var);
                    }
                    if ($type !== self::TYPE_VAR && !$this->checkArgType($type, self::TYPE_OBJECT)) {
                        $retType = $this->detectUniversalMethodReturnType($type, $method);
                        if ($retType !== null) {
                            return $retType;
                        }
                    }
                }
                break;
            case 'Expr_StaticCall':
                if ($this->isNameExpr($expr->class) && $this->isIdExpr($expr->name)) {
                    // First-class callable syntax creates a Closure, not a method return value
                    if (count($expr->args) === 1 and $this->isPlaceholderExpr($expr->args[0])) {
                        return self::TYPE_OBJECT;
                    }
                    $className = $this->parseIdentifier($expr->class);
                    if (strtolower($className) === 'std') {
                        $method = strtolower($this->parseIdentifier($expr->name));
                        return match ($method) {
                            'int' => self::TYPE_INT,
                            'float' => self::TYPE_FLOAT,
                            'bool' => self::TYPE_BOOL,
                            'bigint' => self::TYPE_BIGINT,
                            'decimal' => self::TYPE_DECIMAL,
                            'bigfloat' => self::TYPE_BIGFLOAT,
                            default => self::TYPE_VAR,
                        };
                    }
                    if ($className === 'self') {
                        $className = $this->getFullClassName();
                    } elseif ($className === 'parent') {
                        if ($this->classDef->extends) {
                            $className = $this->classDef->extends;
                        } else {
                            break;
                        }
                    } elseif ($className === 'static') {
                        break;
                    } else {
                        $className = $this->getNamespacedClassName($className);
                    }
                    if ($this->hasClass($className)) {
                        $classDef = $this->getClass($className);
                        $methodName = $this->parseIdentifier($expr->name);
                        if ($classDef->hasMethod($methodName)) {
                            return $classDef->getMethod($methodName)->getReturnType();
                        }
                    }
                }
                break;
            case 'Expr_PropertyFetch':
                if ($this->isIdExpr($expr->name)) {
                    // Class definition property type
                    $propName = $this->parseIdentifier($expr->name);
                    $classDef = $this->resolveObjectClassDef($expr->var);
                    if ($classDef !== null && $classDef->hasProperty($propName)) {
                        return $classDef->getProperty($propName)->type;
                    }
                    // Native property var type
                    if ($this->isVarExpr($expr->var)) {
                        $this->parsePropertyFetch($expr);
                        $propVar = $this->getNativePropertyVar($expr);
                        if ($propVar !== null) {
                            $info = $this->getObjectPropInfoByVar($propVar);
                            if ($info !== null) {
                                return $info['type'];
                            }
                        }
                    }
                }
                break;
            case 'Expr_StaticPropertyFetch':
                if ($this->isIdExpr($expr->name)) {
                    if (!$this->getNativePropertyDef($expr)) {
                        $this->resolveNativeStaticPropertyFetch($expr);
                    }
                    $def = $this->getNativePropertyDef($expr);
                    if ($def) {
                        return $def->type;
                    }
                }
                break;
            case 'Expr_ArrayDimFetch':
                if ($this->isStdArrayExpr($expr)) {
                    if (!$expr->hasAttribute('stdArrayDimFetch')) {
                        $this->parseStdArrayDimFetch($expr);
                    }
                    $attr = $expr->getAttribute('stdArrayDimFetch');
                    if ($attr['accessLevel'] === $attr['totalLevel']) {
                        return $this->context->stdArrays[$attr['var']]['type'];
                    } else {
                        return self::TYPE_ARRAY;
                    }
                }
                if ($this->isStdContainerExpr($expr)) {
                    if (!$expr->hasAttribute('stdContainerDimFetch')) {
                        $this->parseStdContainerDimFetch($expr);
                    }
                    $attr = $expr->getAttribute('stdContainerDimFetch');
                    return $this->context->stdContainers[$attr['var']]['type'];
                }
                break;
            case 'Expr_New':
                return self::TYPE_OBJECT;
            case 'Expr_Assign':
            case 'Expr_AssignOp_BitwiseAnd':
            case 'Expr_AssignOp_BitwiseOr':
            case 'Expr_AssignOp_BitwiseXor':
                return $this->detectVarType($expr->var);
            case 'Expr_Variable':
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

    protected function parseArray(Expr\Array_ $node): string
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
            $this->assertExprCanBeUsedAsValue($item->value, 'array value');
            $value = $this->parseIdentifier($item->value);
            if ($item->key) {
                $this->assertExprCanBeUsedAsValue($item->key, 'array key');
                $key = $this->parseArrayKey($item->key);
                $list[] = $this->getIndent() . '{ ' . $key . ', ' . self::TYPE_VAR . '(' . $value . ') }';
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

    /**
     * 获取包含路径
     */
    protected function getIncludePaths(): array
    {
        $platform = $this->getPlatform();
        $includePaths = [
            $this->getPhpxDir() . '/include',
            $this->getBuildDir() . '/include',
            $this->getPhpxDir() . '/src/misc',
        ];

        // 根据平台添加 PHP 包含路径
        if ($platform instanceof Windows) {
            $phpSdkPaths = $platform->buildPhpSdkIncludePaths($this->getPhpDir());
            $includePaths = array_merge($includePaths, $phpSdkPaths);
        } else {
            // Linux/macOS
            $phpPaths = $platform->buildPhpIncludePaths($this->getPhpDir());
            $includePaths = array_merge($includePaths, $phpPaths);
            // 内置 mpdecimal 头文件目录
            $includePaths[] = $this->getPhpxDir() . '/thirdparty/mpdecimal/libmpdec';
            $includePaths[] = $this->getPhpxDir() . '/thirdparty/mpdecimal/libmpdec++';
        }

        return $includePaths;
    }

    /**
     * 解析包含路径
     */
    protected function parseIncludes(): string
    {
        return $this->getPlatform()->getIncludeFlags($this->getIncludePaths());
    }

    protected function getLibraryPaths(): array
    {
        $platform = $this->getPlatform();
        $libraryPaths = [
            $this->getPhpxDir() . '/lib',
        ];

        // 根据平台添加 PHP 库路径
        if ($platform instanceof Windows) {
            $phpLibPaths = $platform->buildPhpSdkLibPaths($this->getPhpDir());
            $libraryPaths = array_merge($libraryPaths, $phpLibPaths);
        } else {
            // Linux/macOS
            $phpLibPaths = $platform->buildPhpLibPaths($this->getPhpDir());
            $libraryPaths = array_merge($libraryPaths, $phpLibPaths);
        }

        return $libraryPaths;
    }

    protected function parseLdflags(): string
    {
        $flags = $this->getPlatform()->getLibraryPathFlags($this->getLibraryPaths());
        
        // 添加用户自定义的 ldflags
        if (!empty($this->ldflags)) {
            $flags .= ' ' . $this->ldflags;
        }
        
        return $flags;
    }

    /**
     * 获取库文件
     */
    protected function getLibraries(): array
    {
        $platform = $this->getPlatform();
        $libraries = [];

        // phpx 库（根据平台使用不同的文件名格式）
        if ($platform instanceof Windows) {
            // Windows: phpx.lib (无 lib 前缀)
            $phpxLibPath = $this->getPhpxDir() . '\\lib\\phpx.lib';
            if (file_exists($phpxLibPath)) {
                $libraries[] = $phpxLibPath;  // 不添加引号，由 getLibraryFlags() 统一处理
            } else {
                $this->error('phpx.lib not found at: ' . $phpxLibPath);
            }
        } else {
            // Linux/macOS: libphpx.so 或 libphpx.a
            $sharedLibExt = $platform->getSharedLibraryExtension();
            // getSharedLibraryExtension() 返回的值可能带点或不带点，需要统一处理
            $extWithoutDot = ltrim($sharedLibExt, '.');
            $phpxLibPath = $this->getPhpxDir() . '/lib/libphpx.' . $extWithoutDot;
            if (file_exists($phpxLibPath)) {
                $libraries[] = $phpxLibPath;
            } else {
                // 尝试静态库
                $phpxStaticPath = $this->getPhpxDir() . '/lib/libphpx.a';
                if (file_exists($phpxStaticPath)) {
                    $libraries[] = $phpxStaticPath;
                } else {
                    $this->error('libphpx library not found');
                }
            }
        }

        // extension 和 bin 模式都需要链接 PHP 库
        if ($platform instanceof Windows) {
            // Windows: 根据构建模式选择不同的库
            if ($this->isBuildModeBin()) {
                // bin 模式：需要同时链接 php8ts.lib 和 php8embed.lib
                // 注意：php8ts.lib 必须在 php8embed.lib 之前，因为 embed 依赖 core
                // php8ts.lib 提供 PHP 核心全局符号（executor_globals, compiler_globals, sapi_globals）
                if (!empty($this->windowsPhpCoreLib)) {
                    $libraries[] = $this->windowsPhpCoreLib;  // 不添加引号
                }
                // php8embed.lib 提供嵌入 API
                if (!empty($this->windowsPhpEmbedLib)) {
                    $libraries[] = $this->windowsPhpEmbedLib;  // 不添加引号
                }
            } else {
                // ext 模式：只使用 php8ts.lib 或 php8.lib（PHP 扩展）
                if (!empty($this->windowsPhpCoreLib)) {
                    $libraries[] = $this->windowsPhpCoreLib;  // 不添加引号
                }
            }
            
            // 添加 Windows API 库（Win32 GUI 程序需要）
            $libraries[] = 'user32.lib';   // Windows UI 函数（CreateWindow, MessageBox 等）
            $libraries[] = 'gdi32.lib';    // GDI 图形函数
            $libraries[] = 'kernel32.lib'; // 核心 Windows API
            $libraries[] = 'gmp.lib';
            $libraries[] = 'gmpxx.lib';
            $libraries[] = 'mpfr.lib';
            $libraries[] = 'libmpdec-4.0.1.dll.lib';
            $libraries[] = 'libmpdec++-4.0.1.dll.lib';
        } else {
            // Linux/macOS: extension 和 bin 模式都需要添加 php 库
            $libraries[] = 'php';
            $libraries[] = 'gmp';
            $libraries[] = 'gmpxx';
            $libraries[] = 'mpfr';
        }

        return $libraries;
    }

    /**
     * 解析库文件
     */
    protected function parseLibs(): string
    {
        return $this->getPlatform()->getLibraryFlags($this->getLibraries());
    }

    protected function getTargetFileName(): string
    {
        $targetFile = $this->targetName;
        $extension = $this->getPlatform()->getTargetExtension($this->buildMode);

        if ($extension !== '' && !str_ends_with($targetFile, $extension)) {
            $targetFile .= $extension;
        }

        if ($this->outputDir !== '') {
            $targetFile = rtrim($this->outputDir, '/\\') . '/' . $targetFile;
        }

        return $targetFile;
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
            [$initExpr, $beforeStmts, $afterStmts] = $this->parseExprWithCapturedStmts($expr);
            $initExpr = $this->stringifyParsedExpr($initExpr);
            $this->appendCapturedStmtLines($code, $beforeStmts);
            $list_expr[] = $initExpr;
            if ($afterStmts) {
                $list_expr[] = implode(";\n" . $this->getIndent(), $afterStmts);
            }
        }
        $list_expr[] = '';
        $code .= implode(";\n" . $this->getIndent(), $list_expr);

        $list_cond = [];
        $list_cond_expr = [];
        $hasCondStmts = false;
        foreach ($cond as $expr) {
            $this->assertExprCanBeUsedAsCondition($expr, 'for condition');
            [$condExpr, $beforeStmts, $afterStmts] = $this->parseExprWithCapturedStmts($expr);
            $condExpr = $this->stringifyParsedExpr($condExpr);
            $hasCondStmts = $hasCondStmts || $beforeStmts || $afterStmts;
            $list_cond[] = [$condExpr, $beforeStmts, $afterStmts];
            $list_cond_expr[] = $condExpr;
        }

        $code .= $this->parseBeforeStmtLines() . PHP_EOL;
        $code .= 'for (;';
        if ($hasCondStmts) {
            $condCode = '[&]() -> bool {';
            if (empty($list_cond)) {
                $condCode .= $this->getIndent() . 'return true;';
            } else {
                $condResult = $this->genTmpVarName();
                $condCode .= $this->getIndent() . 'bool ' . $condResult . ' = true;' . PHP_EOL;
                foreach ($list_cond as [$condExpr, $beforeStmts, $afterStmts]) {
                    $this->appendCapturedStmtLines($condCode, $beforeStmts);
                    if ($afterStmts) {
                        $tmpVar = $this->addTmpVar(self::TYPE_VAR);
                        $condCode .= $this->getIndent() . $tmpVar . ' = ' . $condExpr . ';' . PHP_EOL;
                        $this->appendCapturedStmtLines($condCode, $afterStmts);
                        $condExpr = $tmpVar;
                    }
                    $condCode .= $this->getIndent() . $condResult . ' = ' . $this->convertBoolExpr($condExpr) . ';' . PHP_EOL;
                }
                $condCode .= $this->getIndent() . 'return ' . $condResult . ';';
            }
            $condCode .= $this->getIndent() . '}()';
            $code .= $condCode;
        } else {
            $code .= implode(', ', $list_cond_expr);
        }
        $code .= '; ';

        $list_loop = [];
        foreach ($loop as $expr) {
            [$loopExpr, $beforeStmts, $afterStmts] = $this->parseExprWithCapturedStmts($expr);
            $loopExpr = $this->stringifyParsedExpr($loopExpr);
            if ($beforeStmts || $afterStmts) {
                $loopCode = '[&]() {';
                $this->appendCapturedStmtLines($loopCode, $beforeStmts);
                $loopCode .= $this->getIndent() . $loopExpr . ';' . PHP_EOL;
                $this->appendCapturedStmtLines($loopCode, $afterStmts);
                $loopCode .= $this->getIndent() . '}()';
                $list_loop[] = $loopCode;
            } else {
                $list_loop[] = $loopExpr;
            }
        }
        $code .= implode(', ', $list_loop);
        $code .= ') {' . PHP_EOL;

        $code .= $this->parseBlockStmts($stmts);
        $code .= $this->genLoopEndFlagCheck();
        $code .= $this->getIndent() . '}' . PHP_EOL;

        return $code;
    }

    /**
     * Generate C++ code for dynamic property ++/-- operations.
     *
     * Returns null if $var is not a dynamic property fetch, so callers can
     * fall through to their normal codegen path.
     */
    protected function genDynamicPropIncDec($var, string $op, bool $isPre): ?string
    {
        if (!$this->isPropertyFetch($var) || $this->isNativePropertyAccess($var)) {
            return null;
        }

        $target = $this->preparePropertyWriteTarget($var);
        $tmpVar = $this->genTmpVarName();
        $this->addLocalVar($tmpVar, self::TYPE_VAR);
        if ($isPre) {
            $this->context->beforeStmtLines[] = "{$tmpVar} = " . $this->emitDynamicPropertyFetchRead($var, $target) . " {$op} 1; " . $this->emitDynamicPropertyFetchWrite($var, $tmpVar, $target) . ';';
        } else {
            $this->context->beforeStmtLines[] = "{$tmpVar} = " . $this->emitDynamicPropertyFetchRead($var, $target) . ';';
            $this->context->afterStmtLines[] = $this->emitDynamicPropertyFetchWrite($var, "{$tmpVar} {$op} 1", $target) . ';';
        }

        return $tmpVar;
    }

    protected function parsePreInc(Expr\PreInc $expr): string
    {
        $this->assertNotNullsafeWriteContext($expr->var);
        $oriInAssignExpr = $this->context->inAssignExpr;
        $this->context->inAssignExpr = true;

        $result = $this->genDynamicPropIncDec($expr->var, '+', true);
        if ($result !== null) {
            $this->context->inAssignExpr = $oriInAssignExpr;
            return $result;
        }

        $type = $this->detectVarType($expr->var);
        if ($type === self::TYPE_BIGINT || $type === self::TYPE_DECIMAL || $type === self::TYPE_BIGFLOAT) {
            $this->fatalError($expr, 'Cannot use ++ on ' . $type . '. Use += 1 instead (Big* types are immutable).');
        }
        $result = '++' . $this->parseIdentifier($expr->var);
        $this->context->inAssignExpr = $oriInAssignExpr;
        return $result;
    }

    /**
     * Report a compiler fatal error.
     */
    public function error(string $msg): never
    {
        if ($this->forTest) {
            throw new TestError($msg);
        } else {
            $this->climate->red("Fatal error: {$msg}");
            if ($this->printBacktraceOnError) {
                debug_print_backtrace();
            }
            exit(255);
        }
    }

    public function fatalError(NodeAbstract $node, string $msg): never
    {
        $this->error("{$msg} in {$this->file}:{$node->getStartLine()}");
    }

    protected function warning(Node $node, string $msg): void
    {
        $this->climate->magenta("{$msg} in {$this->file}:{$node->getStartLine()}");
    }

    protected function errorUndefinedVariable(Variable $node): never
    {
        $this->fatalError($node, "The variable `\${$node->name}` is undefined");
    }

    protected function warningUndefinedBehavior(NodeAbstract $expr): void
    {
        $this->warning($expr, 'Use this expression carefully, which may be inconsistent with the dynamic execution behavior');
    }

    protected function dump(NodeAbstract $v): void
    {
        if ($this->debugLine == $v->getStartLine()) {
            var_dump($v);
        }
    }

    /**
     * $GLOBALS['var'] 等价于 global $var; $var ，将字符串常量转为变量名称即可
     * 仅限于字面量字符串可以转为变量名称，其他则使用 php::global() 函数获取
     */
    protected function parseGlobalsArrayDimFetch(Expr\ArrayDimFetch $node): string
    {
        if ($node->dim === null) {
            $this->fatalError($node, 'Cannot use [] for GLOBALS');
        }
        if ($this->isScalarString($node->dim)) {
            $name = $node->dim->value;
            if (!$this->hasGlobalVar($name)) {
                $this->addGlobalVar($name, self::TYPE_VAR);
            }
            if (!$this->hasScopeGlobalVar($name)) {
                $this->addScopeGlobalVar($name, self::TYPE_VAR);
            }
            return $name;
        }
        return 'php::global(' . $this->parseIdentifier($node->dim) . ')';
    }

    protected function parseArrayDimFetch(Expr\ArrayDimFetch $node, bool $write): string
    {
        if ($this->isStdContainerExpr($node)) {
            if ($write && $node->dim === null) {
                return $this->parseIdentifier($node->var);
            }
            return $this->parseStdContainerDimFetch($node);
        }

        $var = $this->parseIdentifier($node->var);
        if ($this->isVarExpr($node->var)) {
            if ($var === 'GLOBALS') {
                return $this->parseGlobalsArrayDimFetch($node);
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
            return $var . '.item(' . $dim . ', ' . $this->escapeBool($write) . ')';
        }
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
                $possibleFunctionNames[] = $this->escapeNamespace($this->namespace) . self::NAMESPACE_SEPARATOR . $this->escapeName($funcName);
            }
            if (isset($this->useFunctions[$funcName])) {
                $possibleFunctionNames[] = $this->escapeNamespace($this->useFunctions[$funcName]) . self::NAMESPACE_SEPARATOR . $this->escapeName($funcName);
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
            $this->checkFunction($nativeFunc);
            if ($this->hasFunction($nativeFunc)) {
                return $nativeFunc;
            }
        }

        return false;
    }

    protected function foundStrayCode(Node $node): never
    {
        $this->fatalError($node, 'All execution code must be within a function, found stray code');
    }

    protected function checkInternalFunctionArgCount(string $funcName, Node\Expr\FuncCall $expr): void
    {
        $ref = Reflection::getFunction($funcName);
        if (!$ref) {
            return;
        }
        $this->validateInternalNamedCallArgs($ref, $expr->args);
        if ($this->hasUnpackCallArg($expr->args)) {
            return;
        }
        $minArgs = $ref->getNumberOfRequiredParameters();
        $maxArgs = $ref->getNumberOfParameters();
        $actualArgCount = count($expr->args);
        if ($minArgs > 0 && $actualArgCount < $minArgs) {
            $this->fatalError($expr, "{$funcName}() expects at least {$minArgs} argument(s), {$actualArgCount} given");
        }
        if (!$ref->isVariadic() && $maxArgs > 0 && $actualArgCount > $maxArgs) {
            $this->fatalError($expr, "{$funcName}() expects at most {$maxArgs} argument(s), {$actualArgCount} given");
        }
    }

    protected function hasNamedCallArg(array $args): bool
    {
        foreach ($args as $arg) {
            if ($arg instanceof Node\Arg && $arg->name !== null) {
                return true;
            }
        }
        return false;
    }

    protected function hasUnpackBeforeNamedArg(array $args): bool
    {
        $hasUnpack = false;
        foreach ($args as $arg) {
            if (!$arg instanceof Node\Arg) {
                continue;
            }
            if ($arg->unpack) {
                $hasUnpack = true;
            } elseif ($hasUnpack && $arg->name !== null) {
                return true;
            }
        }
        return false;
    }

    protected function hasUnpackCallArg(array $args): bool
    {
        foreach ($args as $arg) {
            if ($arg instanceof Node\Arg && $arg->unpack) {
                return true;
            }
        }
        return false;
    }

    protected function shouldUseDynamicCallForNativeArgs(string $nativeFunc, array $args): bool
    {
        if (!$this->hasUnpackCallArg($args)) {
            return false;
        }
        if ($this->hasUnpackBeforeNamedArg($args)) {
            return true;
        }

        $variadicArgIndex = $this->getVariadicArgIndex($this->getFunction($nativeFunc));
        foreach ($args as $i => $arg) {
            if (!$arg instanceof Node\Arg || !$arg->unpack) {
                continue;
            }
            if ($variadicArgIndex === null || $i < $variadicArgIndex) {
                return true;
            }
        }
        return false;
    }

    protected function genCallUserFuncArray(string $callback, array $args, string $funcName = '', string $className = ''): string
    {
        return 'php::call(' . $this->getFuncPtr('call_user_func_array') . ', '
            . Symbol::argList() . '{' . $callback . ', ' . $this->parseCallArgs($args, $funcName, $className, false, true) . '})';
    }

    protected function getFunctionCallbackExpr(string $nativeFn): string
    {
        $functionDef = $this->getFunction($nativeFn);
        return $this->getLiteralString($functionDef->getNamespacedName());
    }

    protected function validateInternalNamedCallArgs(\ReflectionFunctionAbstract $ref, array $callArgs): void
    {
        $hasNamedArg = false;
        $hasUnpack = false;
        $seenNamedArgs = [];
        $providedArgIndexes = [];
        $argNameIndex = [];
        $requiredArgIndexes = [];
        $variadicArgIndex = null;

        foreach ($ref->getParameters() as $i => $param) {
            $argNameIndex[$param->getName()] = $i;
            if (!$param->isOptional() && !$param->isVariadic()) {
                $requiredArgIndexes[$i] = $param->getName();
            }
            if ($param->isVariadic()) {
                $variadicArgIndex = $i;
            }
        }

        foreach ($callArgs as $i => $arg) {
            if ($this->isPlaceholderExpr($arg)) {
                continue;
            }
            if ($arg instanceof Node\Arg && $arg->unpack) {
                if ($hasNamedArg) {
                    $this->fatalError($arg, 'Cannot use argument unpacking after named arguments');
                }
                $hasUnpack = true;
                $providedArgIndexes[$i] = true;
                continue;
            }
            if ($arg->name === null) {
                if ($hasUnpack) {
                    $this->fatalError($arg, 'Cannot use positional argument after argument unpacking');
                }
                if ($hasNamedArg) {
                    $this->fatalError($arg, 'Cannot use positional argument after named argument');
                }
                $providedArgIndexes[$i] = true;
                continue;
            }
            if (!$this->isIdExpr($arg->name)) {
                $this->fatalError($arg, 'Named argument must be a string');
            }

            $argName = $arg->name->name;
            if (isset($seenNamedArgs[$argName])) {
                $this->fatalError($arg, "Duplicate named argument `{$argName}`");
            }
            if (!array_key_exists($argName, $argNameIndex)) {
                if ($variadicArgIndex === null) {
                    $this->fatalError($arg, "Unknown named argument `{$argName}`");
                }
                $seenNamedArgs[$argName] = true;
                $hasNamedArg = true;
                continue;
            }

            $argIndex = $argNameIndex[$argName];
            if ($variadicArgIndex !== null && $argIndex === $variadicArgIndex) {
                $seenNamedArgs[$argName] = true;
                $hasNamedArg = true;
                continue;
            }
            if (isset($providedArgIndexes[$argIndex])) {
                $this->fatalError($arg, "Named argument `{$argName}` overwrites previous argument");
            }

            $seenNamedArgs[$argName] = true;
            $providedArgIndexes[$argIndex] = true;
            $hasNamedArg = true;
        }

        if ($hasNamedArg && !$hasUnpack) {
            foreach ($requiredArgIndexes as $index => $name) {
                if (!isset($providedArgIndexes[$index])) {
                    $this->fatalError($callArgs[array_key_last($callArgs)] ?? null, "Named argument `{$name}` is missing default value");
                }
            }
        }
    }

    protected function parseFuncCall(Expr\FuncCall $expr): string
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
            if ($name === 'objval') {
                return $this->genObjvalCall($expr);
            }
            $nativeFn = $this->findNativeFunction($name);
            if ($nativeFn) {
                $expr->setAttribute('nativeCall', $nativeFn);
                // 函数调用占位符，不是真实的函数调用
                if (count($expr->args) === 1 and $this->isPlaceholderExpr($expr->args[0])) {
                    return $this->genPlaceHolder($this->identifierToStr($expr->name));
                }
                $this->checkNativeCallArgs($expr, $this->getFunction($nativeFn), $expr->args, $name);
                if ($this->shouldUseDynamicCallForNativeArgs($nativeFn, $expr->args)) {
                    return $this->genCallUserFuncArray($this->getFunctionCallbackExpr($nativeFn), $expr->args, $name);
                }
                try {
                    return self::PREFIX . $nativeFn . '(' . $this->parseNativeCallArgs($expr->args, $nativeFn) . ')';
                } catch (PlaceHolder) {
                    return $this->genPlaceHolder($this->identifierToStr($expr->name));
                }
            }
            // 动态调用的函数，转换函数名为带有命名空间的全限定名称
            $name = $this->getNamespacedFuncName($name);
            $this->checkInternalFunctionArgCount($name, $expr);
            $code = $this->parseFuncCallWithOptimizer($name, $expr);
            if ($code !== false) {
                return $code;
            }
            $placeHolder = $this->identifierToStr($expr->name);
            $fn = $this->getFuncPtr($name);
            $this->context->beforeStmtLines[] = '// Func Call: ' . $name . '()';
        } else {
            $tmpVar = $this->addTmpVar(self::TYPE_VAR);
            $this->context->beforeStmtLines[] = $tmpVar . ' = ' . $this->parseExpr($expr->name) . ';';
            $placeHolder = $fn = $tmpVar;
            $name = '';
        }
        if (empty($expr->args)) {
            return 'php::call(' . $fn . ')';
        }
        if ($this->hasNamedCallArg($expr->args)) {
            $callback = $name !== '' ? $this->getLiteralString($name) : $fn;
            return $this->genCallUserFuncArray($callback, $expr->args, $name);
        }
        try {
            return 'php::call(' . $fn . ', ' . $this->parseCallArgs($expr->args, $name, '', $name !== '') . ')';
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
        $functionDef = $this->getFunction($nativeFunc);
        $args = [];
        $variadicArgs = [];
        $hasNamedArg = false;
        $argNameIndex = $this->getFunctionArgNameIndex($functionDef);
        $variadicArgIndex = $this->getVariadicArgIndex($functionDef);
        // 对命名参数进行重排
        foreach ($callArgs as $i => $arg) {
            if ($this->isPlaceholderExpr($arg)) {
                throw new PlaceHolder();
            }
            if ($arg->name) {
                $argName = $arg->name->name;
                $k = $argNameIndex[$argName] ?? null;
                if ($k !== null and ($variadicArgIndex === null or $k < $variadicArgIndex)) {
                    $args[$k] = $arg;
                } else {
                    $variadicArgs[] = [$argName, $arg];
                }
                $hasNamedArg = true;
            } elseif ($variadicArgIndex !== null and $i >= $variadicArgIndex) {
                $variadicArgs[] = [null, $arg];
            } else {
                $args[$i] = $arg;
            }
        }
        // 对 key 进行排序，确保参数顺序正确
        if ($hasNamedArg) {
            // 命名参数中间存在空洞，需要使用默认参数填充
            foreach ($functionDef->argInfoList as $k => $argInfo) {
                if ($variadicArgIndex !== null and $k === $variadicArgIndex) {
                    continue;
                }
                if (!isset($args[$k])) {
                    if ($argInfo->defaultValue === null) {
                        $errorNode = null;
                        foreach ($callArgs as $a) {
                            if ($a instanceof Node\Arg && $a->name) {
                                $errorNode = $a;
                                break;
                            }
                        }
                        $argName = $argInfo->phpName ?: $this->unescapeVarName($argInfo->name);
                        $this->fatalError($errorNode ?? reset($callArgs), 'Named argument `' . $argName . '` is missing default value');
                    }
                    $args[$k] = new Node\Arg($argInfo->defaultValue);
                }
            }
            ksort($args);
        }

        if ($variadicArgIndex !== null and $variadicArgs) {
            $args[$variadicArgIndex] = $this->buildNativeVariadicArg($variadicArgs, $functionDef->argInfoList[$variadicArgIndex]);
            ksort($args);
        }

        // 函数只接受一个变长参数，且调用参数为空，直接传入空数组
        if (count($args) === 0 and count($functionDef->argInfoList) === 1 and $functionDef->argInfoList[0]->variadic) {
            return '{}';
        }

        foreach ($args as $i => $arg) {
            if (is_string($arg)) {
                $argList[] = $arg;
                continue;
            }
            $argInfo = $this->getArgInfo($arg, $nativeFunc, $i);
            $argList[] = $this->getTypeConvertedArg($arg, $argInfo);
        }

        return implode(', ', $argList);
    }

    protected function buildNativeVariadicArg(array $variadicArgs, ArgInfo $argInfo): string
    {
        if (count($variadicArgs) === 1 and $variadicArgs[0][0] === null and $variadicArgs[0][1]->unpack) {
            $arg = $variadicArgs[0][1];
            if ($this->isVarExpr($arg->value)) {
                $var = $this->parseIdentifier($arg->value);
                if ($this->getVarType($var) === self::TYPE_ARRAY) {
                    return $var;
                }
            }
            return $this->convertArrayExpr($this->parseExpr($arg->value));
        }

        $tmpVar = $this->addTmpVar(self::TYPE_ARRAY);
        foreach ($variadicArgs as [$name, $arg]) {
            if ($arg->unpack) {
                $this->context->beforeStmtLines[] = $tmpVar . '.merge(' . $this->parseArrayArg($arg) . ');';
            } elseif ($name !== null) {
                $this->context->beforeStmtLines[] = $tmpVar . '.set(' . $this->getLiteralString($name) . ', ' . $this->getTypeConvertedArg($arg, $argInfo) . ');';
            } else {
                $this->context->beforeStmtLines[] = $tmpVar . '.append(' . $this->getTypeConvertedArg($arg, $argInfo) . ');';
            }
        }
        return $tmpVar;
    }

    protected function parseNamedCallArgs(array $args, int $firstIndex, array $listArgs): string
    {
        $namedArgs = [];
        foreach ($args as $i => $arg) {
            if ($i < $firstIndex) {
                continue;
            }
            if ($arg->name === null) {
                $this->fatalError($arg, 'Named argument must follow positional argument');
            }
            if (!$this->isIdExpr($arg->name)) {
                $this->fatalError($arg, 'Named argument must be a string');
            }
            if (array_key_exists($arg->name->name, $namedArgs)) {
                $this->fatalError($arg, "Duplicate named argument `{$arg->name->name}`");
            }
            $namedArgs[$arg->name->name] = $this->parseCallArgValue($arg);
        }

        $tmpVar = $this->genTmpVarName();

        $array = self::TYPE_ARRAY . ' ' . $tmpVar . ';';
        foreach ($namedArgs as $k => $v) {
            $array .= $tmpVar . '.set(' . $this->getLiteralString($k) . ', ' . $v . ');' . PHP_EOL;
        }
        $this->context->beforeStmtLines[] = $array;
        $this->context->afterStmtLines[] = $tmpVar . '.unset();';

        return Symbol::argList() . '{' . implode(', ', $listArgs) . '}, ' . $tmpVar . '.array()';
    }

    protected function isReferenceArgument($funcName, $className, $argIndex): bool
    {
        $argInfo = $this->getAotCallArgInfo($funcName, $className, $argIndex);
        if ($argInfo !== null) {
            return $argInfo->byRef;
        }

        if ($className) {
            // 动态调用类方法，无法判断参数是否为引用
            if ($className === self::DYNAMIC_CALLED_CLASS) {
                return false;
            }
            $param = Reflection::getClassMethodParameter($className, $funcName, $argIndex);
        } else {
            $param = Reflection::getFunctionParameter($funcName, $argIndex);
        }

        if ($param) {
            return $param->isPassedByReference();
        }

        // 参数索引超出声明范围，检查最后一个参数是否为变长引用参数（如 &...$rest）
        $variadicParam = Reflection::getVariadicParameter($funcName, $className);
        return $variadicParam !== null && $variadicParam->isPassedByReference();
    }

    protected function getAotCallArgInfo(string $funcName, string $className, int $argIndex): ?ArgInfo
    {
        if ($className !== '') {
            if ($className === self::DYNAMIC_CALLED_CLASS || !$this->hasClass($className)) {
                return null;
            }
            $classDef = $this->getClass($className);
            while (true) {
                if ($classDef->hasMethod($funcName)) {
                    return $this->getArgInfoByIndex($classDef->getMethod($funcName)->functionDef, $argIndex);
                }
                if (!$classDef->extends || !$this->hasClass($classDef->extends)) {
                    return null;
                }
                $classDef = $this->getClass($classDef->extends);
            }
        }

        if (!$this->hasFunction($funcName)) {
            return null;
        }
        return $this->getArgInfoByIndex($this->getFunction($funcName), $argIndex);
    }

    protected function getAotCallArgInfoByName(string $funcName, string $className, string $argName): ?ArgInfo
    {
        $functionDef = null;
        if ($className !== '') {
            if ($className === self::DYNAMIC_CALLED_CLASS || !$this->hasClass($className)) {
                return null;
            }
            $classDef = $this->getClass($className);
            while (true) {
                if ($classDef->hasMethod($funcName)) {
                    $functionDef = $classDef->getMethod($funcName)->functionDef;
                    break;
                }
                if (!$classDef->extends || !$this->hasClass($classDef->extends)) {
                    return null;
                }
                $classDef = $this->getClass($classDef->extends);
            }
        } elseif ($this->hasFunction($funcName)) {
            $functionDef = $this->getFunction($funcName);
        }

        if ($functionDef === null) {
            return null;
        }

        $variadicArgInfo = null;
        foreach ($functionDef->argInfoList as $argInfo) {
            if ($argInfo->variadic) {
                $variadicArgInfo = $argInfo;
            }
            if (($argInfo->phpName ?: $this->unescapeVarName($argInfo->name)) === $argName) {
                return $argInfo;
            }
        }
        return $variadicArgInfo;
    }

    protected function getArgInfoByIndex(FunctionDef $functionDef, int $argIndex): ?ArgInfo
    {
        if (array_key_exists($argIndex, $functionDef->argInfoList)) {
            return $functionDef->argInfoList[$argIndex];
        }
        if ($functionDef->hasVariadicArg()) {
            return $functionDef->argInfoList[array_key_last($functionDef->argInfoList)];
        }
        return null;
    }

    protected function isReferenceNamedArgument(string $funcName, string $className, string $argName): bool
    {
        $argInfo = $this->getAotCallArgInfoByName($funcName, $className, $argName);
        if ($argInfo !== null) {
            return $argInfo->byRef;
        }

        if ($className) {
            if ($className === self::DYNAMIC_CALLED_CLASS) {
                return false;
            }
            $ref = Reflection::getClass($className);
            if (!$ref) {
                return false;
            }
            try {
                $params = $ref->getMethod($funcName)->getParameters();
            } catch (\ReflectionException) {
                return false;
            }
        } else {
            $ref = Reflection::getFunction($funcName);
            if (!$ref) {
                return false;
            }
            $params = $ref->getParameters();
        }

        $variadicParam = null;
        foreach ($params as $param) {
            if ($param->isVariadic()) {
                $variadicParam = $param;
            }
            if ($param->getName() === $argName) {
                return $param->isPassedByReference();
            }
        }
        return $variadicParam !== null && $variadicParam->isPassedByReference();
    }

    protected function parseCallArgs(
        array $args,
        string $funcName = '',
        string $className = '',
        bool $separateNamedArgs = true,
        bool $forceArrayArgs = false
    ): string
    {
        $list_args = [];
        $arrayArgsVar = null;
        $namedArgsVar = null;
        $namedArgs = [];
        $hasNamedArg = false;
        $hasUnpack = false;

        if ($forceArrayArgs) {
            $this->ensureCallArrayArgs($arrayArgsVar, $list_args);
        }

        foreach ($args as $i => $arg) {
            if ($this->isPlaceholderExpr($arg)) {
                throw new PlaceHolder();
            }
            if ($arg->unpack) {
                if ($hasNamedArg) {
                    $this->fatalError($arg, 'Cannot use argument unpacking after named arguments');
                }
                $hasUnpack = true;
                $arrayArgs = $this->ensureCallArrayArgs($arrayArgsVar, $list_args);
                $this->context->beforeStmtLines[] = $arrayArgs . '.merge(' . $this->parseArrayArg($arg) . ');';
                continue;
            }
            if ($arg->name !== null) {
                $hasNamedArg = true;
                if (!$this->isIdExpr($arg->name)) {
                    $this->fatalError($arg, 'Named argument must be a string');
                }
                if (array_key_exists($arg->name->name, $namedArgs)) {
                    $this->fatalError($arg, "Duplicate named argument `{$arg->name->name}`");
                }
                $namedArgs[$arg->name->name] = true;
                $byRef = $funcName && $this->isReferenceNamedArgument($funcName, $className, $arg->name->name);
                $value = ($byRef || $this->isRefvalCall($arg->value))
                    ? $this->parseReferenceCallArgValue($arg)
                    : $this->parseCallArgValue($arg);
                if ($separateNamedArgs) {
                    $namedArgsArray = $this->ensureCallNamedArgs($namedArgsVar);
                    $this->context->beforeStmtLines[] = $namedArgsArray . '.set(' . $this->getLiteralString($arg->name->name) . ', ' . $value . ');';
                } else {
                    $arrayArgs = $this->ensureCallArrayArgs($arrayArgsVar, $list_args);
                    $this->context->beforeStmtLines[] = $arrayArgs . '.set(' . $this->getLiteralString($arg->name->name) . ', ' . $value . ');';
                }
                continue;
            }
            if ($hasNamedArg) {
                $this->fatalError($arg, 'Cannot use positional argument after named argument');
            }
            if ($hasUnpack) {
                $this->fatalError($arg, 'Cannot use positional argument after argument unpacking');
            }
            $byRef = $funcName && $this->isReferenceArgument($funcName, $className, $i);
            if ($this->isVarExpr($arg->value)) {
                $name = $this->parseIdentifier($arg->value);
                if ($byRef) {
                    $this->addPositionalCallArg($this->parseArgRefVar($arg, $name), $arrayArgsVar, $list_args);
                    continue;
                }
                if (!$this->hasVar($name)) {
                    $this->fatalError($arg, 'Undefined variable `$' . $name . '`');
                }
            } elseif ($this->isPropertyFetch($arg->value) and $this->isVarExpr($arg->value->var)) {
                if ($byRef) {
                    $this->addPositionalCallArg($this->emitDynamicPropertyFetchRef($arg->value, $arg), $arrayArgsVar, $list_args);
                    continue;
                }
                $objectExpr = $this->parseIdentifier($arg->value->var);
                if (!$this->hasVar($objectExpr)) {
                    $this->fatalError($arg, 'Undefined variable `$' . $objectExpr . '`');
                }
            } elseif ($this->isArrayDimFetch($arg->value) and $this->isVarExpr($arg->value->var)) {
                $array = $this->parseIdentifier($arg->value->var);
                if ($array === 'GLOBALS') {
                    $globalVar = $this->parseGlobalsArrayDimFetch($arg->value);
                    // 全局变量作为引用参数
                    if ($byRef) {
                        $ref = $this->addTmpVar(self::TYPE_REF);
                        $this->context->beforeStmtLines[] = $ref . ' = ' . $globalVar . '.toReference();';
                        $this->addPositionalCallArg('&' . $ref, $arrayArgsVar, $list_args);
                    } else {
                        $this->addPositionalCallArg($globalVar, $arrayArgsVar, $list_args);
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
                    $this->addPositionalCallArg($array . '.itemRef(' . $this->identifierToStr($arg->value->dim) . ')', $arrayArgsVar, $list_args);
                    continue;
                }
            } elseif ($this->isFuncCallExpr($arg->value)) {
                if ($this->isNameExpr($arg->value->name) and $arg->value->name->toString() === 'refval') {
                    if (count($arg->value->args) !== 1) {
                        $this->fatalError($arg, 'The refval function only accepts one parameter');
                    }
                    $inner = $arg->value->args[0]->value;
                    if ($this->isVarExpr($inner)) {
                        $name = $this->parseVariable($inner);
                        // 消除 refval() 函数调用，直接使用变量
                        $arg->value = $inner;
                        $this->addPositionalCallArg($this->parseArgRefVar($arg, $name), $arrayArgsVar, $list_args);
                        continue;
                    }
                    $expr = $this->expandRefvalExpr($inner, $arg);
                    if ($expr !== null) {
                        $this->addPositionalCallArg($expr, $arrayArgsVar, $list_args);
                        continue;
                    }
                    $this->fatalError($arg, 'The refval function only accepts a variable, array element, or object property');
                }
            } else {
                if ($byRef) {
                    if ($this->isScalar($arg->value)) {
                        $this->fatalError($arg, 'The constants cannot be used as an argument for a reference-type parameter');
                    }
                    $tmpRef = $this->genTmpVarName();
                    $this->addLocalVar($tmpRef, self::TYPE_REF);
                    $this->context->beforeStmtLines[] = $tmpRef . ' = ' . $this->parseChainedExpr($arg->value, self::OP_REFVAL) . ';';
                    $this->addPositionalCallArg('&' . $tmpRef, $arrayArgsVar, $list_args);
                    continue;
                }
            }
            $value = $this->parseCallArgValue($arg);
            $this->addPositionalCallArg($value, $arrayArgsVar, $list_args);
        }

        if ($arrayArgsVar !== null) {
            return $namedArgsVar !== null ? $arrayArgsVar . ', ' . $namedArgsVar . '.array()' : $arrayArgsVar;
        }
        $callArgs = Symbol::argList() . '{' . implode(', ', $list_args) . '}';
        return $namedArgsVar !== null ? $callArgs . ', ' . $namedArgsVar . '.array()' : $callArgs;
    }

    protected function ensureCallArrayArgs(?string &$arrayArgsVar, array &$listArgs): string
    {
        if ($arrayArgsVar === null) {
            $arrayArgsVar = $this->genTmpVarName();
            $this->context->beforeStmtLines[] = self::TYPE_ARRAY . ' ' . $arrayArgsVar . '{' . implode(', ', $listArgs) . '};';
            $listArgs = [];
        }
        return $arrayArgsVar;
    }

    protected function ensureCallNamedArgs(?string &$namedArgsVar): string
    {
        if ($namedArgsVar === null) {
            $namedArgsVar = $this->genTmpVarName();
            $this->context->beforeStmtLines[] = self::TYPE_ARRAY . ' ' . $namedArgsVar . ';';
            $this->context->afterStmtLines[] = $namedArgsVar . '.unset();';
        }
        return $namedArgsVar;
    }

    protected function addPositionalCallArg(string $value, ?string $arrayArgsVar, array &$listArgs): void
    {
        if ($arrayArgsVar !== null) {
            $this->context->beforeStmtLines[] = $arrayArgsVar . '.append(' . $value . ');';
        } else {
            $listArgs[] = $value;
        }
    }

    protected function parseCallArgValue(Node\Arg $arg): string
    {
        $this->assertExprCanBeUsedAsValue($arg->value, 'function argument');
        return $this->materializeCallArgValue($arg->value, $this->parseArg($arg));
    }

    protected function materializeCallArgValue(NodeAbstract $value, string $expr): string
    {
        if (!$this->shouldMaterializeCallArg($value)) {
            return $expr;
        }
        return 'php_deindirect(' . $expr . ')';
    }

    protected function shouldMaterializeCallArg(NodeAbstract $value): bool
    {
        if ($value instanceof Expr\ArrayDimFetch) {
            return !$this->isStdContainerExpr($value);
        }

        return $value instanceof Expr\PropertyFetch;
    }

    protected function parseReferenceCallArgValue(Node\Arg $arg): string
    {
        if ($this->isRefvalCall($arg->value)) {
            if (count($arg->value->args) !== 1) {
                $this->fatalError($arg, 'The refval function only accepts one parameter');
            }
            $arg->value = $arg->value->args[0]->value;
        }

        if ($this->isVarExpr($arg->value)) {
            return $this->parseArgRefVar($arg, $this->parseIdentifier($arg->value));
        }

        if ($this->isPropertyFetch($arg->value) and $this->isVarExpr($arg->value->var)) {
            return $this->emitDynamicPropertyFetchRef($arg->value, $arg);
        }

        if ($this->isArrayDimFetch($arg->value) and $this->isVarExpr($arg->value->var)) {
            $array = $this->parseIdentifier($arg->value->var);
            if ($array === 'GLOBALS') {
                $globalVar = $this->parseGlobalsArrayDimFetch($arg->value);
                $ref = $this->addTmpVar(self::TYPE_REF);
                $this->context->beforeStmtLines[] = $ref . ' = ' . $globalVar . '.toReference();';
                return '&' . $ref;
            }
            if (!$this->hasVar($array)) {
                $this->fatalError($arg, 'Undefined variable `$' . $array . '`');
            }
            if ($arg->value->dim === null) {
                $this->fatalError($arg, 'Array dimension must be a constant expression');
            }
            return $array . '.itemRef(' . $this->identifierToStr($arg->value->dim) . ')';
        }

        if ($this->isScalar($arg->value)) {
            $this->fatalError($arg, 'The constants cannot be used as an argument for a reference-type parameter');
        }

        $tmpRef = $this->genTmpVarName();
        $this->addLocalVar($tmpRef, self::TYPE_REF);
        $this->context->beforeStmtLines[] = $tmpRef . ' = ' . $this->parseChainedExpr($arg->value, self::OP_REFVAL) . ';';
        return '&' . $tmpRef;
    }

    /**
     * 展开 refval() 调用中的数组元素或对象属性，返回对应的 C++ 引用表达式。
     * 若为普通变量则返回 null，由调用方自行处理。
     */
    protected function expandRefvalExpr(NodeAbstract $inner, Node\Arg $arg): ?string
    {
        if ($this->isPropertyFetch($inner) and $this->isVarExpr($inner->var)) {
            return $this->emitDynamicPropertyFetchRef($inner, $arg);
        }
        if ($this->isArrayDimFetch($inner) and $this->isVarExpr($inner->var)) {
            $array = $this->parseIdentifier($inner->var);
            if ($array === 'GLOBALS') {
                $globalVar = $this->parseGlobalsArrayDimFetch($inner);
                $ref = $this->addTmpVar(self::TYPE_REF);
                $this->context->beforeStmtLines[] = $ref . ' = ' . $globalVar . '.toReference();';
                return '&' . $ref;
            }
            if (!$this->hasVar($array)) {
                $this->fatalError($arg, 'Undefined variable `$' . $array . '`');
            }
            if ($inner->dim === null) {
                $this->fatalError($arg, 'Array dimension must be a constant expression');
            }
            return $array . '.itemRef(' . $this->identifierToStr($inner->dim) . ')';
        }
        return null;
    }

    /**
     * 仅用于动态调用的参数解析
     */
    protected function parseArgRefVar(Node\Arg $arg, string $name): string
    {
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
        // 动态调用，参数列表是 Variant 类型而不是 Reference，必须使用 & 符号取地址，传递指针，以保持引用传递
        return '&' . $name;
    }

    protected function parseArg(Node\Arg $arg): string
    {
        if ($this->isArrayDimFetch($arg->value) and $this->isStdContainerExpr($arg->value)) {
            if ($this->isStdArrayExpr($arg->value)) {
                $valueExpr = $this->parseStdArrayDimFetch($arg->value);
                $attr = $arg->value->getAttribute('stdArrayDimFetch');
                if ($attr['accessLevel'] === $attr['totalLevel']) {
                    return $this->convertExprFromType($this->context->stdArrays[$attr['var']]['type'], $valueExpr);
                } else {
                    return $this->convertArrayExpr($valueExpr);
                }
            } else {
                $valueExpr = $this->parseStdContainerDimFetch($arg->value);
                $attr = $arg->value->getAttribute('stdContainerDimFetch');
                return $this->convertExprFromType($this->context->stdContainers[$attr['var']]['type'], $valueExpr);
            }
        }
        $expr = $this->parseIdentifier($arg->value);
        if ($this->isVarExpr($arg->value) and $arg->value->name === 'GLOBALS') {
            return 'php_globals_array()';
        }
        if ($this->isVarExpr($arg->value) and $this->isStdContainer($arg->value->name)) {
            return $this->convertArrayExpr($expr . '_ref');
        }
        return $expr;
    }

    protected function parseOrderedArg(Node\Arg $arg): string
    {
        if ($this->isArrayDimFetch($arg->value) and $this->isStdContainerExpr($arg->value)) {
            return $this->parseArg($arg);
        }
        if ($this->isVarExpr($arg->value) and $arg->value->name === 'GLOBALS') {
            return 'php_globals_array()';
        }
        if ($this->isVarExpr($arg->value) and $this->isStdContainer($arg->value->name)) {
            return $this->convertArrayExpr($this->parseIdentifier($arg->value) . '_ref');
        }
        return $this->parseOrderedOperand($arg->value, false);
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

    protected function parsePostOp(Expr\PostDec|Expr\PostInc $expr, string $op): string
    {
        $this->assertNotNullsafeWriteContext($expr->var);
        $result = $this->genDynamicPropIncDec($expr->var, $op, false);
        if ($result !== null) {
            return $result;
        }

        if ($this->isVarExpr($expr->var) or $this->isPropertyFetch($expr->var) or $this->isArrayDimFetch($expr->var)) {
            $oriInAssignExpr = $this->context->inAssignExpr;
            $this->context->inAssignExpr = true;
            $var = $this->parseIdentifier($expr->var);
            $this->context->inAssignExpr = $oriInAssignExpr;
            if ($this->isVarExpr($expr->var) and !$this->hasVar($var)) {
                $this->errorUndefinedVariable($expr->var);
            }
            $type = $this->detectVarType($expr->var);
            if ($type === self::TYPE_BIGINT || $type === self::TYPE_DECIMAL || $type === self::TYPE_BIGFLOAT) {
                $opName = $op === '+' ? '++' : '--';
                $this->fatalError($expr, "Cannot use {$opName} on {$type}. Use " . ($op === '+' ? '+= 1' : '-= 1') . ' instead (Big* types are immutable).');
            }
            return $var . str_repeat($op, 2);
        }
        if ($this->isStaticPropertyFetch($expr->var)) {
            $native = $this->parseNativeStaticPropertyFetch($expr->var);
            if ($native !== null) {
                return $native . str_repeat($op, 2);
            }

            $class = $this->identifierToStr($expr->var->class);
            $prop = $this->identifierToStr($expr->var->name);
            $tmpVar = $this->genTmpVarName();
            $this->addLocalVar($tmpVar, self::TYPE_VAR);
            $this->context->beforeStmtLines[] = $tmpVar . ' = ' . Symbol::getStaticProperty() . '(' . $class . ', ' . $prop . ');';
            $this->context->afterStmtLines[] = Symbol::setStaticProperty() . '(' . $class . ', ' . $prop . ', ' . $tmpVar . ' ' . $op . ' 1);';

            return $tmpVar;
        }
        $this->fatalError($expr, 'Post-increment operator is not supported for non-variable expressions');
    }

    protected function parsePostDec(Expr\PostDec $expr): string
    {
        return $this->parsePostOp($expr, '-');
    }

    protected function parsePostInc(Expr\PostInc $expr): string
    {
        return $this->parsePostOp($expr, '+');
    }

    protected function parseTernary(Expr\Ternary $expr): string
    {
        if ($expr->if === null) {
            return $this->parseValueSelection($expr, $expr->cond, $expr->else, self::OP_NOT_EMPTY);
        }
        $this->assertExprCanBeUsedAsCondition($expr->cond, 'ternary condition');
        $this->assertExprCanBeUsedAsValue($expr->if, 'ternary branch');
        $this->assertExprCanBeUsedAsValue($expr->else, 'ternary branch');
        [$cond, $condBeforeStmts, $condAfterStmts] = $this->parseExprWithCapturedStmts($expr->cond);
        $ifBeforeStmtCount = count($this->context->beforeStmtLines);
        $ifAfterStmtCount = count($this->context->afterStmtLines);
        $if = $this->parseExpr($expr->if);
        $ifBeforeStmts = array_slice($this->context->beforeStmtLines, $ifBeforeStmtCount);
        $ifAfterStmts = array_slice($this->context->afterStmtLines, $ifAfterStmtCount);
        $this->context->beforeStmtLines = array_slice($this->context->beforeStmtLines, 0, $ifBeforeStmtCount);
        $this->context->afterStmtLines = array_slice($this->context->afterStmtLines, 0, $ifAfterStmtCount);

        $elseBeforeStmtCount = count($this->context->beforeStmtLines);
        $elseAfterStmtCount = count($this->context->afterStmtLines);
        $else = $this->parseExpr($expr->else);
        $elseBeforeStmts = array_slice($this->context->beforeStmtLines, $elseBeforeStmtCount);
        $elseAfterStmts = array_slice($this->context->afterStmtLines, $elseAfterStmtCount);
        $this->context->beforeStmtLines = array_slice($this->context->beforeStmtLines, 0, $elseBeforeStmtCount);
        $this->context->afterStmtLines = array_slice($this->context->afterStmtLines, 0, $elseAfterStmtCount);

        $hasBranchStmts = $condBeforeStmts || $condAfterStmts || $ifBeforeStmts || $ifAfterStmts || $elseBeforeStmts || $elseAfterStmts;
        $typeChanged = $this->detectTypeOfExpr($expr->if) !== $this->detectTypeOfExpr($expr->else);
        if (!$hasBranchStmts && $typeChanged) {
            $if = 'php::Var(' . $if . ')';
            $else = 'php::Var(' . $else . ')';
        }
        if ($hasBranchStmts) {
            $code = '[&]() -> ' . self::TYPE_VAR . '{';
            $code .= $this->formatCapturedStmtLines($condBeforeStmts);
            if ($condAfterStmts) {
                $condTmpVar = $this->addTmpVar(self::TYPE_VAR);
                $code .= $this->getIndent() . "{$condTmpVar} = {$cond};";
                $code .= $this->formatCapturedStmtLines($condAfterStmts);
                $cond = $condTmpVar;
            }
            $code .= $this->getIndent() . 'if (' . $cond . ') {';
            $code .= $this->formatTernaryReturn($if, $ifBeforeStmts, $ifAfterStmts);
            $code .= $this->getIndent() . '} else {';
            $code .= $this->formatTernaryReturn($else, $elseBeforeStmts, $elseAfterStmts);
            $code .= $this->getIndent() . '}';
            $code .= $this->getIndent() . '}()';
            return $code;
        }
        return '(' . $cond . ') ? (' . $if . ') : (' . $else . ')';
    }

    protected function formatTernaryReturn(string $value, array $beforeStmts, array $afterStmts): string
    {
        $code = $this->formatCapturedStmtLines($beforeStmts);
        if ($afterStmts) {
            $tmpVar = $this->addTmpVar(self::TYPE_VAR);
            $code .= $this->getIndent() . "{$tmpVar} = {$value};";
            $code .= $this->formatCapturedStmtLines($afterStmts);
            $code .= $this->getIndent() . 'return ' . $tmpVar . ';';
        } else {
            $code .= $this->getIndent() . 'return php::Var(' . $value . ');';
        }
        return $code;
    }

    protected function parseMatch(Expr\Match_ $expr): string
    {
        $this->assertExprCanBeUsedAsValue($expr->cond, 'match condition');
        $var = $this->parseIdentifier($expr->cond);
        if ($this->isVarExpr($expr->cond)) {
            if (!$this->hasVar($var)) {
                $this->errorUndefinedVariable($expr->cond);
            }
        } else {
            $tmpVar = $this->addTmpVar(self::TYPE_VAR);
            $this->context->beforeStmtLines[] = $tmpVar . ' = ' . $var . ';';
            $var = $tmpVar;
        }

        $code = '[&]() -> ' . self::TYPE_VAR . '{';
        $default = null;
        foreach ($expr->arms as $arm) {
            if ($arm->conds === null) {
                $default = $arm->body;
                continue;
            }
            $matched = $this->genTmpVarName();
            $code .= $this->getIndent() . 'bool ' . $matched . ' = false;';
            foreach ($arm->conds as $cond) {
                if ($this->isMatchExpr($cond)) {
                    $this->fatalError($arm, 'Match expression cannot be used as a condition');
                }
                $this->assertExprCanBeUsedAsValue($cond, 'match arm condition');
                [$condValue, $beforeStmts, $afterStmts] = $this->parseExprWithCapturedStmts($cond);
                $code .= $this->getIndent() . 'if (!' . $matched . ') {';
                $code .= $this->formatCapturedStmtLines($beforeStmts);
                if ($afterStmts) {
                    $condTmpVar = $this->addTmpVar(self::TYPE_VAR);
                    $code .= $this->getIndent() . "{$condTmpVar} = {$condValue};";
                    $code .= $this->formatCapturedStmtLines($afterStmts);
                    $condValue = $condTmpVar;
                }
                $code .= $this->getIndent() . $matched . ' = php::same(' . $var . ', ' . $condValue . ');';
                $code .= $this->getIndent() . '}';
            }
            $code .= $this->getIndent() . 'if (' . $matched . ') {';
            $code .= $this->formatMatchReturn($arm->body);
            $code .= $this->getIndent() . '}';
        }

        if ($default) {
            $code .= $this->getIndent() . '{';
            $code .= $this->formatMatchReturn($default);
            $code .= $this->getIndent() . '}';
        } else {
            $code .= $this->getIndent() . '{ return php::throwException("UnhandledMatchError", "Unhandled match case"); }';
        }
        $code .= '}()';

        return $code;
    }

    protected function formatMatchReturn(NodeAbstract $body): string
    {
        $this->assertExprCanBeUsedAsValue($body, 'match arm');
        [$value, $beforeStmts, $afterStmts] = $this->parseExprWithCapturedStmts($body);
        $code = $this->formatCapturedStmtLines($beforeStmts);
        if ($afterStmts) {
            $tmpVar = $this->addTmpVar(self::TYPE_VAR);
            $code .= $this->getIndent() . "{$tmpVar} = {$value};";
            $code .= $this->formatCapturedStmtLines($afterStmts);
            $code .= $this->getIndent() . 'return ' . $tmpVar . ';';
        } else {
            $code .= $this->getIndent() . 'return ' . $value . ';';
        }
        return $code;
    }

    protected function parsePreDec(Expr\PreDec $expr): string
    {
        $this->assertNotNullsafeWriteContext($expr->var);
        $oriInAssignExpr = $this->context->inAssignExpr;
        $this->context->inAssignExpr = true;

        $result = $this->genDynamicPropIncDec($expr->var, '-', true);
        if ($result !== null) {
            $this->context->inAssignExpr = $oriInAssignExpr;
            return $result;
        }

        $type = $this->detectVarType($expr->var);
        if ($type === self::TYPE_BIGINT || $type === self::TYPE_DECIMAL || $type === self::TYPE_BIGFLOAT) {
            $this->fatalError($expr, 'Cannot use -- on ' . $type . '. Use -= 1 instead (Big* types are immutable).');
        }
        $result = '--' . $this->parseIdentifier($expr->var);
        $this->context->inAssignExpr = $oriInAssignExpr;
        return $result;
    }

    protected function parseBitwiseNot(Expr\BitwiseNot $expr): string
    {
        $type = $this->detectTypeOfExpr($expr->expr);
        $this->assertExprCanBeUsedAsValue($expr->expr, 'bitwise operand');
        if ($type === self::TYPE_BIGINT) {
            return 'php::BigInt::bitNot(' . $this->parseExpr($expr->expr) . ')';
        }
        $var = $this->parseIdentifier($expr->expr);
        return '~' . $var;
    }

    protected function parseIf(Node\Stmt\If_ $v): string
    {
        $arms = [[$v->cond, $v->stmts]];
        foreach ($v->elseifs as $elseif) {
            $arms[] = [$elseif->cond, $elseif->stmts];
        }

        return $this->parseBeforeStmtLines() . PHP_EOL . $this->parseIfChain($arms, $v->else, 0) . PHP_EOL;
    }

    protected function parseIfChain(array $arms, ?Node\Stmt\Else_ $else, int $index): string
    {
        if (!isset($arms[$index])) {
            if (!$else) {
                return '';
            }
            return $this->parseBlockStmts($else->stmts);
        }

        [$cond, $stmts] = $arms[$index];
        $code = $this->genConditionWithCapturedStmts($cond, 'if ');
        $code .= $this->parseBlockStmts($stmts);
        $tail = $this->parseIfChain($arms, $else, $index + 1);
        if ($tail !== '') {
            $code .= $this->getIndent() . '} else {' . PHP_EOL;
            $code .= $tail;
        }
        $code .= $this->getIndent() . '}';
        return $code;
    }

    /**
     * 逻辑比较的运算，必须返回 bool 类型.
     */
    protected function parseBooleanNot(Expr\BooleanNot $expr): string
    {
        $this->assertExprCanBeUsedAsCondition($expr->expr, 'boolean operand');
        return '!(' . $this->parseExprAsValue($expr->expr) . ')';
    }

    protected function parseWhile(Node\Stmt\While_ $v): string
    {
        $stmts = $v->stmts;
        $this->assertExprCanBeUsedAsCondition($v->cond, 'while condition');
        [$cond, $beforeStmts, $afterStmts] = $this->parseExprWithCapturedStmts($v->cond);

        $code = $this->parseBeforeStmtLines() . PHP_EOL;
        if ($beforeStmts || $afterStmts) {
            $code .= 'while (true) {' . PHP_EOL;
            $this->appendCapturedStmtLines($code, $beforeStmts);
            if ($afterStmts) {
                $tmpVar = $this->addTmpVar(self::TYPE_VAR);
                $code .= $this->getIndent() . $tmpVar . ' = ' . $cond . ';' . PHP_EOL;
                $this->appendCapturedStmtLines($code, $afterStmts);
                $cond = $tmpVar;
            }
            $code .= $this->getIndent() . 'if (!(' . $cond . ')) { break; }' . PHP_EOL;
        } else {
            $code .= 'while (' . $cond . ') {' . PHP_EOL;
        }
        $code .= $this->parseBlockStmts($stmts);
        $code .= $this->genLoopEndFlagCheck();
        $code .= $this->getIndent() . '}' . PHP_EOL;

        return $code;
    }

    protected function parsePrint(Expr\Print_ $expr): string
    {
        $this->assertExprCanBeUsedAsValue($expr->expr, 'print operand');
        return 'php::print(' . $this->parseExprAsValue($expr->expr) . ')';
    }

    protected function parseDo(Node\Stmt\Do_ $v): string
    {
        $stmts = $v->stmts;
        $this->assertExprCanBeUsedAsCondition($v->cond, 'do-while condition');
        [$cond, $beforeStmts, $afterStmts] = $this->parseExprWithCapturedStmts($v->cond);
        if ($beforeStmts || $afterStmts) {
            $condCode = '[&]() -> bool {';
            $this->appendCapturedStmtLines($condCode, $beforeStmts);
            if ($afterStmts) {
                $tmpVar = $this->addTmpVar(self::TYPE_VAR);
                $condCode .= $this->getIndent() . $tmpVar . ' = ' . $cond . ';' . PHP_EOL;
                $this->appendCapturedStmtLines($condCode, $afterStmts);
                $cond = $tmpVar;
            }
            $condCode .= $this->getIndent() . 'return ' . $this->convertBoolExpr($cond) . ';';
            $condCode .= $this->getIndent() . '}()';
            $cond = $condCode;
        }
        $code  = $this->parseBeforeStmtLines() . PHP_EOL;
        $code .= 'do {' . PHP_EOL;
        $code .= $this->parseBlockStmts($stmts);
        $code .= $this->genLoopEndFlagCheck();
        $code .= $this->getIndent() . '} while (' . $cond . ');' . PHP_EOL;

        return $code;
    }

    /**
     * 值选择，如 ?: 或者 ??
     */
    protected function parseValueSelection(NodeAbstract $expr, Expr $left, Expr $right, string $op): string
    {
        $this->assertExprCanBeUsedAsValue($left, 'selection value');
        $this->assertExprCanBeUsedAsValue($right, 'selection value');
        $leftExpr = $this->parseIdentifier($left);
        if ($this->isVarExpr($left)) {
            $this->checkVarMustExist($left, $leftExpr);
        }

        $condExpr = $this->parseChainedExpr($left, $op, true);
        $chainOpResult = $left->getAttribute('chainOpResult');
        if ($chainOpResult) {
            $leftExpr = $chainOpResult;
        }

        $rightBeforeStmtCount = count($this->context->beforeStmtLines);
        $rightAfterStmtCount = count($this->context->afterStmtLines);
        $rightExpr = $this->parseIdentifier($right);
        $rightBeforeStmts = array_slice($this->context->beforeStmtLines, $rightBeforeStmtCount);
        $rightAfterStmts = array_slice($this->context->afterStmtLines, $rightAfterStmtCount);
        $this->context->beforeStmtLines = array_slice($this->context->beforeStmtLines, 0, $rightBeforeStmtCount);
        $this->context->afterStmtLines = array_slice($this->context->afterStmtLines, 0, $rightAfterStmtCount);
        $this->checkVarMustExist($right, $rightExpr);

        $tmpVar = $this->addTmpVar(self::TYPE_VAR);
        if ($rightBeforeStmts || $rightAfterStmts) {
            $code = '// Expr: ' . $this->printer->prettyPrintExpr($expr) . PHP_EOL .
                'if (' . $condExpr . ') {' . PHP_EOL .
                $this->getIndent() . $tmpVar . ' = ' . $leftExpr . ';' . PHP_EOL .
                '} else {' . PHP_EOL;
            if ($rightBeforeStmts) {
                $code .= $this->getIndent() . implode(PHP_EOL . $this->getIndent(), $rightBeforeStmts) . PHP_EOL;
            }
            if ($rightAfterStmts) {
                $rightTmpVar = $this->addTmpVar(self::TYPE_VAR);
                $code .= $this->getIndent() . $rightTmpVar . ' = ' . $rightExpr . ';' . PHP_EOL;
                $code .= $this->getIndent() . implode(PHP_EOL . $this->getIndent(), $rightAfterStmts) . PHP_EOL;
                $code .= $this->getIndent() . $tmpVar . ' = ' . $rightTmpVar . ';' . PHP_EOL;
            } else {
                $code .= $this->getIndent() . $tmpVar . ' = ' . $rightExpr . ';' . PHP_EOL;
            }
            $code .= '}';
            $this->context->beforeStmtLines[] = $code;
        } else {
            $this->context->beforeStmtLines[] = '// Expr: ' . $this->printer->prettyPrintExpr($expr) . PHP_EOL .
                $tmpVar . ' = ' . $condExpr . ' ? ' . $leftExpr . ' : ' . $rightExpr . ';';
        }
        $expr->setAttribute('replace', $tmpVar);

        return $tmpVar;
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

    protected function parseNew(Expr\New_ $expr): string
    {
        $ctorClassName = '';
        // 匿名类
        if ($expr->class instanceof Node\Stmt\Class_) {
            if ($expr->class->name === null) {
                $classDef = $expr->class;
                $className = $this->genAnonClassName();
                $classDef->name = new Node\Identifier($className);
                // 继承父类和接口可能是 use 的名称，需要转换成全限定名称
                if ($classDef->extends !== null) {
                    $parentClass = $this->getNamespacedClassName($classDef->extends->toString());
                    $classDef->extends = new Node\Name\FullyQualified($parentClass);
                }
                if (!empty($classDef->implements)) {
                    foreach ($classDef->implements as $i => $iface) {
                        $ifaceName = $this->getNamespacedClassName($iface->toString());
                        $classDef->implements[$i] = new Node\Name\FullyQualified($ifaceName);
                    }
                }
                // 将匿名类内部的类型引用（方法参数、返回值、属性等）转为全限定名称
                $this->resolveAnonClassTypeNames($classDef);
                $this->context->beforeStmtLines[] = 'static THREAD_LOCAL bool ' . $className . '_defined = false;';
                $classCode = $this->genEmbeddedCode($classDef);
                $this->addConstData($className . '_code', $classCode);
                $this->context->beforeStmtLines[] = 'if (!' . $className . '_defined) {'
                    . $className . '_defined = true; php::eval((const char *)' . $className . '_code);}';
                $className = '\\' . $className;
                $cePtr     = $this->getClassEntryPtr($className);
                $ctorClassName = $className;
            } else {
                $this->fatalError($expr, 'must be anonymous class');
            }
        } else {
            $className = $this->parseIdentifier($expr->class);
            if ($this->isNameExpr($expr->class)) {
                if ($className === 'static') {
                    $cePtr = Symbol::getCalledCe();
                } else {
                    if ($className === 'self') {
                        $className = $this->getFullClassName();
                    } elseif ($className === 'parent') {
                        if (!$this->classDef) {
                            $this->fatalError($expr, 'Cannot use "parent" outside a class');
                        }
                        $className = $this->classDef->extends;
                    } else {
                        $className = $this->getNamespacedClassName($className);
                    }
                    $ctorClassName = $className;
                    if ($this->hasClass($className)) {
                        $classDef = $this->getClass($className);
                        if ($classDef->flags & Modifiers::ABSTRACT) {
                            $this->fatalError($expr, "abstract class `{$className}` cannot be instantiated");
                        }
                    }
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
        return 'php::newObject(' . $cePtr . ', ' . $this->parseCallArgs($args, '__construct', $ctorClassName) . ')';
    }

    protected function parseClone(Expr\Clone_ $expr): string
    {
        $this->assertExprCanBeUsedAsValue($expr->expr, 'clone operand');
        return 'php::clone(' . $this->parseExprAsValue($expr->expr) . ')';
    }

    protected function parseInstanceof(Expr\Instanceof_ $expr): string
    {
        $this->assertExprCanBeUsedAsValue($expr->expr, 'instanceof operand');
        $value = $this->parseExprAsValue($expr->expr);
        if ($this->isNameExpr($expr->class)) {
            $className = $this->getNamespacedClassName($this->parseIdentifier($expr->class));
            $className = $this->getClassEntryPtr($className);
            return 'php::instanceOf(' . $value . ', ' . $className . ')';
        } else {
            return 'php::instanceOf(' . $value . ', ' . $this->identifierToStr($expr->class) . ')';
        }
    }

    protected function parseCastInt(Expr\Cast\Int_ $node): string
    {
        $this->assertExprCanBeUsedAsValue($node->expr, 'cast operand');
        return $this->convertIntExpr($this->parseExprAsValue($node->expr));
    }

    protected function parseCastString(Expr\Cast\String_ $node): string
    {
        $this->assertExprCanBeUsedAsValue($node->expr, 'cast operand');
        return $this->convertExprToStringByType(
            $this->parseExprAsValue($node->expr),
            $this->detectTypeOfExpr($node->expr)
        );
    }

    protected function parseCastBool(Expr\Cast\Bool_ $node): string
    {
        $this->assertExprCanBeUsedAsValue($node->expr, 'cast operand');
        return $this->convertBoolExpr($this->parseExprAsValue($node->expr));
    }

    protected function parseCastObject(Expr\Cast\Object_ $node): string
    {
        $this->assertExprCanBeUsedAsValue($node->expr, 'cast operand');
        return $this->convertObjectExpr($this->parseExprAsValue($node->expr));
    }

    protected function parseConstFetch(Expr\ConstFetch $expr, bool $scalar = false): string
    {
        if ($expr->name->getType() != 'Name' and !($expr->name instanceof Node\Name\FullyQualified)) {
            abort($expr);
        }
        $name = $this->parseIdentifier($expr->name);
        $name = ltrim($name, '\\');
        if ($this->isNameExpr($expr->name) and $this->hasConstant($name)) {
            return $this->getConstant($name);
        }
        if ($this->namespace and $this->isNameExpr($expr->name) and !$expr->name instanceof Node\Name\FullyQualified) {
            $nsName = $this->namespace . '\\' . $name;
            if ($this->hasConstant($nsName)) {
                return $this->getConstant($nsName);
            }
        }
        if ($this->isNameExpr($expr->name) and isset($this->useConstants[$name])) {
            $importedName = $this->useConstants[$name];
            if ($this->hasConstant($importedName)) {
                return $this->getConstant($importedName);
            }
        }
        if (strcasecmp($name, 'null') === 0) {
            return self::VALUE_NULL;
        }
        if (strcasecmp($name, 'true') === 0) {
            return 'true';
        }
        if (strcasecmp($name, 'false') === 0) {
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
                return Symbol::constant() . '(' . $ce . ', ' . $this->getLiteralString($ns[1]) . ')';
            }
            if ($this->isInternalConstant($name)) {
                return Symbol::constant() . '(' . $this->getLiteralString($name) . ')';
            }
            if (isset($this->useAliases[$name])) {
                $name = $this->useAliases[$name];
            } elseif (isset($this->useConstants[$name])) {
                $name = $this->useConstants[$name];
            } else {
                $fullName = $this->getNamespacedClassName($name);
                if ($fullName) {
                    $name = $fullName;
                }
            }
            return Symbol::constant() . '(nullptr, ' . $this->getLiteralString($name) . ')';
        }
        return Symbol::constant() . '("' . $this->escapeString($name) . '")';
    }

    protected function parseUnaryMinus(Expr\UnaryMinus $expr): string
    {
        $type = $this->detectTypeOfExpr($expr->expr);
        $this->assertExprCanBeUsedAsValue($expr->expr, 'unary operand');
        if ($type === self::TYPE_BIGFLOAT) {
                        return 'php::BigFloat::neg(' . $this->parseExprAsValue($expr->expr) . ')';
        }
        if ($type === self::TYPE_BIGINT) {
                        return 'php::BigInt::neg(' . $this->parseExprAsValue($expr->expr) . ')';
        }
        if ($type === self::TYPE_DECIMAL) {
                        return 'php::Decimal::neg(' . $this->parseExprAsValue($expr->expr) . ')';
        }
        $code = $this->parseExprAsValue($expr->expr);

        return '-' . $code;
    }

    protected function parseUnaryPlus(Expr\UnaryPlus $expr): string
    {
        $this->assertExprCanBeUsedAsValue($expr->expr, 'unary operand');
        return $this->parseExprAsValue($expr->expr);
    }

    protected function parseInterpolatedString(Node\Scalar\InterpolatedString $expr): string
    {
        $parts = $expr->parts;
        $list  = [];
        foreach ($parts as $part) {
            if (!$part instanceof Node\InterpolatedStringPart) {
                $this->assertExprCanBeUsedAsValue($part, 'string interpolation value');
            }
            $list[] = $this->parseExpr($part);
        }

        return 'php::concat({' . implode(', ', $list) . '})';
    }

    protected function parseInterpolatedStringPart(Node\InterpolatedStringPart $expr): string
    {
        return '"' . $this->escapeString($expr->value) . '"';
    }

    protected function parseGlobal(Node\Stmt\Global_ $expr): string
    {
        foreach ($expr->vars as $v) {
            $name = $this->parseVariable($v);
            if (!$this->hasGlobalVar($name)) {
                $this->addGlobalVar($name, self::TYPE_VAR);
            }
            if (!$this->hasScopeGlobalVar($name)) {
                $this->addScopeGlobalVar($name, self::TYPE_VAR);
            }
        }
        return '';
    }

    protected function getArgInfo(Node $arg, string $funcName, int $index): ArgInfo
    {
        if (!$this->hasFunction($funcName)) {
            $this->fatalError($arg, "Function `{$funcName}` is undefined, you must adjust the order of function definition");
        }
        $funcDef = $this->getFunction($funcName);
        if (!array_key_exists($index, $funcDef->argInfoList)) {
            $this->fatalError($arg, "Argument `{$index}` of function `{$funcName}` not found");
        }

        return $funcDef->argInfoList[$index];
    }

    protected function getReturnType(): string
    {
        $type = $this->functionDef->returnType;
        if ($type === self::TYPE_STREAM) {
            return self::TYPE_VAR;
        }
        return $type;
    }

    protected function getReturnClass(): string
    {
        return $this->functionDef->returnClass;
    }

    protected function isInheritedFrom(string $class, string $expected): bool
    {
        $internal = ($this->isInternalClass($expected) or $this->isInternalInterface($expected));
        $isInterface = ($this->hasInterface($expected) or $this->isInternalInterface($expected));
        // 类不存在，说明这是一个动态类，跳过静态检查，需要运行时检查
        if (!$this->hasClass($class)) {
            return true;
        }
        $classDef = $this->getClass($class);
        while (true) {
            if ($isInterface) {
                if ($classDef->implements and in_array($expected, $classDef->implements)) {
                    return true;
                }
                // Check transitive interface inheritance (e.g., Iterator extends Traversable)
                foreach ($classDef->implements as $iface) {
                    $stack = [$iface];
                    while ($stack) {
                        $check = array_pop($stack);
                        if (strcasecmp($check, $expected) === 0) {
                            return true;
                        }
                        if (!$this->hasInterface($check)) {
                            continue;
                        }
                        $interfaceDef = $this->getInterface($check);
                        foreach ($interfaceDef->extendsList ?: ($interfaceDef->extends ? [$interfaceDef->extends] : []) as $parentIface) {
                            $stack[] = $parentIface;
                        }
                    }
                    if (is_subclass_of($iface, $expected)) {
                        return true;
                    }
                }
            } else {
                if (strcasecmp($class, $expected) === 0) {
                    return true;
                }
                if (!$this->hasClass($class)) {
                    // 原生类继承自一个内置类，例如: UserError extends Exception ，然后 $expected 预期是 Throwable
                    // 这种情况，需要使用 ZendVM 获取继承关系
                    if ($this->isInternalClass($class) and $internal) {
                        return $class === $expected or is_subclass_of($class, $expected);
                    }
                    return false;
                }
            }
            if (!$classDef->extends) {
                return false;
            }
            $class = $classDef->extends;
            $classDef = $this->getClass($class);
        }
    }

    protected function getTypeConvertedArg(Node\Arg $arg, ArgInfo $argInfo): string
    {
        $type = $this->detectTypeOfExpr($arg->value);
        $this->assertExprCanBeUsedAsValue($arg->value, 'function argument');

        if ($argInfo->byRef) {
            if ($this->isRefvalCall($arg->value)) {
                if (count($arg->value->args) !== 1) {
                    $this->fatalError($arg, 'The refval function only accepts one parameter');
                }
                $inner = $arg->value->args[0]->value;
                if ($this->isVarExpr($inner)) {
                    // 消除 refval() 函数调用，直接使用变量
                    $arg->value = $inner;
                } else {
                    $expr = $this->expandRefvalExpr($inner, $arg);
                    if ($expr !== null) {
                        return $expr;
                    }
                    $this->fatalError($arg, 'The refval function only accepts a variable, array element, or object property');
                }
            }
            if ($this->isVarExpr($arg->value)) {
                $var = $this->parseVariable($arg->value);
                // 若参数是引用类型，可以传入未定义变量，将立即创建变量作为引用
                if (!$this->hasLocalVar($var)) {
                    $this->addLocalVar($var, self::TYPE_VAR);
                }
            }
            return $this->convertToRef($arg->value);
        }

        $expr = $this->parseOrderedArg($arg);
        $expr = $this->materializeCallArgValue($arg->value, $expr);

        $this->checkVarAssignExpr($arg, $argInfo->type, $type);

        if ($argInfo->type === self::TYPE_VAR && $this->isVarExpr($arg->value)) {
            $varName = $this->parseIdentifier($arg->value);
            if ($this->isStdContainer($varName)) {
                return $varName;
            }
        }

        if ($argInfo->type === self::TYPE_OBJECT) {
            if ($this->isVarExpr($arg->value)) {
                $object = $this->parseVariable($arg->value);
                if ($this->isTypedObject($object)) {
                    $class = $this->getObjectType($object);
                    if ($class and $argInfo->class and !$this->isInheritedFrom($class, $argInfo->class)) {
                        $argName = $argInfo->phpName ?: $this->unescapeVarName($argInfo->name);
                        $this->fatalError($arg, "Argument `{$argName}` must be an instance of `{$argInfo->class}`, `{$class}` given");
                    }
                }
            }
            return $this->convertObjectExpr($expr);
        }

        return $this->convertExprType($expr, $argInfo->type, $type);
    }

    protected function parseExit(Expr\Exit_ $node): string
    {
        if (!$node->expr) {
            return 'php::exit(0)';
        }
        return 'php::exit(' . $this->parseIdentifier($node->expr) . ')';
    }

    protected function getFixedObjectPropDefaultValue(PropertyDef $def): ?string
    {
        return (new PropertyAssignTypeInfo())->getFixedDefaultValue($def);
    }

    protected function isFixedObjectProp(PropertyDef $def): bool
    {
        return (new PropertyAssignTypeInfo())->isFixed($def);
    }

    protected function assertCanAssignObjectProp(Expr\PropertyFetch $left, Expr $right): void
    {
        $this->assertCanAssignObjectProperty($left, $right, 'object property');
    }

    protected function assertCanAssignStaticProp(Expr\StaticPropertyFetch $left, Expr $right): void
    {
        $this->assertCanAssignObjectProperty($left, $right, 'static property');
    }

    protected function preparePropertyWriteTarget(NodeAbstract $left): ?PropertyWriteTarget
    {
        if ($left instanceof Expr\PropertyFetch) {
            $objectExpr = null;
            $propertyExpr = null;
            if (!$this->isNativePropertyAccess($left) && $this->isVarExpr($left->var)) {
                $objectExpr = $this->parseIdentifier($left->var);
                $propertyExpr = $this->identifierToStr($left->name, literal: true);
            }
            if ($this->isIdExpr($left->name)) {
                $this->getPropertyIdentifier($left, $left->var, $left->name);
            }
            return new PropertyWriteTarget($left, 'object property', $objectExpr, $propertyExpr);
        }

        if ($left instanceof Expr\StaticPropertyFetch) {
            if ($this->isIdExpr($left->name)) {
                $this->resolveNativeStaticPropertyFetch($left);
            }
            return new PropertyWriteTarget($left, 'static property');
        }

        return null;
    }

    protected function assertCanAssignPropertyWrite(PropertyWriteTarget $target, Expr $right): void
    {
        $this->assertCanAssignObjectProperty($target->node, $right, $target->label);
    }

    protected function wrapPropertyWriteTypeCheck(PropertyWriteTarget $target, Expr $right, string $rightExpr): string
    {
        return $this->wrapObjectPropertyAssignTypeCheck($target->node, $right, $rightExpr);
    }

    private function assertCanAssignObjectProperty(NodeAbstract $left, Expr $right, string $label): void
    {
        $def = $this->getNativePropertyDef($left);
        if (!$def) {
            return;
        }

        $propName = $this->parseIdentifier($left->name);

        if ($this->isNull($right)) {
            if ($this->isFixedObjectProp($def)) {
                $this->fatalError(
                    $left,
                    "Cannot assign null to {$label} `{$propName}` of fixed type `{$def->type}`"
                );
            }
            return;
        }

        if ($def->type !== self::TYPE_OBJECT) {
            return;
        }

        $rightType = $this->detectTypeOfExpr($right);
        if ($rightType !== self::TYPE_VAR && $rightType !== self::TYPE_OBJECT) {
            $this->fatalError(
                $left,
                "Cannot assign value of type `{$rightType}` to {$label} `{$propName}` of type `{$def->type}`"
            );
        }

        if ($def->class === '' or $this->isAbstractClass($def->class) or $this->isInterface($def->class) or !$this->hasClass($def->class)) {
            return;
        }

        $rightClass = $this->detectClassOfExpr($right);
        // TODO 静态编译阶段无法获得准确的类型，需要在运行时检查
        if ($rightClass === '') {
            return;
        }
        if ($rightClass !== $def->class) {
            $this->fatalError(
                $left,
                "Cannot assign object of class `{$rightClass}` to {$label} `{$propName}` of class `{$def->class}`"
            );
        }
    }

    protected function wrapObjectPropertyAssignTypeCheck(NodeAbstract $left, Expr $right, string $rightExpr): string
    {
        $def = $this->getNativePropertyDef($left);
        if (!$def) {
            return $rightExpr;
        }

        $typeCheck = $this->getObjectPropertyAssignTypeCheck($def);
        if (empty($typeCheck)) {
            return $rightExpr;
        }

        $rightClass = $this->detectClassOfExpr($right);
        if ($rightClass !== '') {
            return $rightExpr;
        }

        $tmpVar = $this->addTmpVar(self::TYPE_VAR);
        $conditions = [];
        foreach ($typeCheck as $entry) {
            $cond = $this->genSingleTypeCondition($tmpVar, $entry);
            if ($cond !== '') {
                $conditions[] = $cond;
            }
        }
        if (empty($conditions)) {
            return $rightExpr;
        }

        $propDisplay = $this->getObjectPropertyTypeCheckDisplayName($left);
        $typeStr = $this->getObjectPropertyTypeCheckTypeString($def);
        $msgExpr = 'php::concat(php::concat(php::Str(' . $this->genCharPtr($propDisplay, true) . ' " must be of type " '
            . $this->genCharPtr($typeStr, true) . ' ", "), ' . $tmpVar . '.typeStr()), php::Str(" given"))';

        return '([&]() -> ' . self::TYPE_VAR . ' { '
            . $tmpVar . ' = ' . $rightExpr . '; '
            . 'if (UNEXPECTED(!(' . implode(' || ', $conditions) . '))) { '
            . 'php::throwException(zend_ce_type_error, (' . $msgExpr . ').toCString()); '
            . '} '
            . 'return ' . $tmpVar . '; '
            . '}())';
    }

    private function getObjectPropertyAssignTypeCheck(PropertyDef $def): array
    {
        return (new PropertyAssignTypeInfo())->getRuntimeTypeCheck($def);
    }

    private function getObjectPropertyTypeCheckDisplayName(NodeAbstract $left): string
    {
        $propName = $this->parseIdentifier($left->name);
        $classDef = $this->getNativePropertyClassDef($left);
        if ($classDef) {
            $class = $classDef->getNamespacedName(false);
            return $class . '::$' . $propName;
        }

        if ($left instanceof Expr\StaticPropertyFetch) {
            return $this->identifierToStr($left->class, literal: true) . '::$' . $propName;
        }

        return '$' . $propName;
    }

    private function getObjectPropertyTypeCheckTypeString(PropertyDef $def): string
    {
        return (new PropertyAssignTypeInfo())->getTypeString($def);
    }

    protected function parseUnset(Node\Stmt\Unset_ $node): string
    {
        $vars = $node->vars;
        $lines = [];
        foreach ($vars as $var) {
            $this->assertNotNullsafeWriteContext($var);
            if ($this->isArrayDimFetch($var)) {
                if ($var->dim === null) {
                    $this->fatalError($var, 'Cannot use [] for array unset');
                }
                $array = $this->parseIdentifier($var->var);
                if (($this->isStdMap($array) or $this->isStdOrderedMap($array))
                    and !empty($this->context->stdContainers[$array]['locking'])) {
                    $this->fatalError($var, 'Cannot delete element in std container in foreach loop');
                }
                $dim = $this->parseIdentifier($var->dim);
                if ($this->isStdContainer($array)) {
                    $lines[] = $array . '_ref.offsetUnset(' . $dim . ');';
                } else {
                    $lines[] = $array . '.offsetUnset(' . $dim . ');';
                }
            } elseif ($this->isPropertyFetch($var)) {
                $propertyWriteTarget = $this->preparePropertyWriteTarget($var);
                $object = $this->getDynamicPropertyFetchObjectExpr($var, $propertyWriteTarget);
                $restoreDefault = null;
                if ($this->isIdExpr($var->name)) {
                    $propertyId = $this->getPropertyIdentifier($var, $var->var, $var->name);
                    $def = $this->getNativePropertyDef($var);
                    if ($def) {
                        if ($this->isFixedObjectProp($def)) {
                            $restoreDefault = $this->getFixedObjectPropDefaultValue($def);
                            if ($restoreDefault === null) {
                                $this->fatalError($var, "Cannot unset object property `{$this->parseIdentifier($var->name)}` of fixed type `{$def->type}` without default value");
                            }
                            $this->warning($var, "Object property `{$this->parseIdentifier($var->name)}` of fixed type cannot be unset; restoring its default value");
                            $propName = $this->parseIdentifier($var->name);
                            $propVar = $this->getObjectPropVarName($object, $propName);
                            if ($this->hasObjectPropVar($propVar)) {
                                $lines[] = $propVar . ' = ' . $restoreDefault . ';';
                            } else {
                                $lines[] = $object . '.attr(' . $propertyId . ', true) = ' . $restoreDefault . ';';
                            }
                        }
                    }
                }
                if ($restoreDefault === null) {
                    $lines[] = $this->emitDynamicPropertyFetchUnset($var, $propertyWriteTarget) . ';';
                }
            } elseif ($this->isStaticPropertyFetch($var)) {
                $this->fatalError($var, 'Attempt to unset static property ' . $this->parseIdentifier($var->class) . '::$' . $this->parseIdentifier($var->name));
            } elseif ($this->isVarExpr($var)) {
                $name = $this->parseIdentifier($var);
                if (!$this->hasVar($name)) {
                    $this->errorUndefinedVariable($var);
                }
                $type = $this->getVarType($name);
                if ($this->isNativeType($type)) {
                    $this->warning($var, "Variable of native type `\${$name}` cannot be unset");
                } else {
                    $lines[] = "{$name}.unset();";
                }
            } else {
                $this->fatalError($var, "Unsupported unset type `{$var->getType()}`");
            }
        }

        return implode(PHP_EOL . $this->getIndent(), $lines);
    }

    protected function getPropertyIdentifier(Expr\PropertyFetch $expr, NodeAbstract $object, NodeAbstract $property): ?string
    {
        $target = $this->resolveInstancePropertyFetchTarget($object, $property);
        if ($target !== null) {
            $result = $this->resolveNativeInstanceProperty($expr, $target->property, $target->class);
            if ($result !== null) {
                return $this->applyNativePropertyAccessResult($expr, $result);
            }
        }

        return $this->identifierToStr($property, literal: true);
    }

    private function resolveInstancePropertyFetchTarget(
        NodeAbstract $object,
        NodeAbstract $property,
    ): ?InstancePropertyFetchTarget {
        if (!$this->isVarExpr($object) or !$this->isIdExpr($property)) {
            return null;
        }

        $objectName = $this->parseIdentifier($object);
        $propertyName = $this->parseIdentifier($property);
        if ($objectName === 'this_') {
            if ($this->classDef->trait) {
                return null;
            }
            return new InstancePropertyFetchTarget($propertyName, $this->getFullClassName());
        }
        if ($this->isTypedObject($objectName)) {
            return new InstancePropertyFetchTarget($propertyName, $this->getObjectType($objectName));
        }

        return null;
    }

    protected function parsePropertyFetch(Expr\PropertyFetch $expr, bool $update = false): string
    {
        $object = $expr->var;
        $property = $expr->name;
        $id = $this->getPropertyIdentifier($expr, $object, $property);
        $objectName = $this->parseIdentifier($object);
        if ($this->isVarExpr($object) and !$this->hasVar($objectName)) {
            $this->errorUndefinedVariable($object);
        }
        $objectVar = $objectName;
        $getProperty = $objectVar . '.attr(' . $id . ', ' . $this->escapeBool($update) . ')';
        $def = $this->getNativePropertyDef($expr);
        if ($def and $this->nativeTypes) {
            $propName = $this->parseIdentifier($property);
            $typedFetch = $this->emitNativeInstancePropertyTypedFetch(
                $expr,
                $objectVar,
                $propName,
                $id,
                $def,
                $getProperty,
            );
            if ($typedFetch !== null) {
                return $typedFetch;
            }
        }
        if ($def) {
            $this->setNativePropertyValueSource($expr, self::NATIVE_PROPERTY_VALUE_DYNAMIC);
        }
        return $getProperty;
    }

    private function emitNativeInstancePropertyTypedFetch(
        Expr\PropertyFetch $expr,
        string $objectVar,
        string $propName,
        string $propertyId,
        PropertyDef $def,
        string $getter,
    ): ?string {
        $propVar = $this->getObjectPropVarName($objectVar, $propName);
        if ($objectVar === 'this_') {
            if (!$this->canHoistObjectProp($objectVar, $propName)) {
                return null;
            }
            $this->registerHoistedObjectPropVar($propVar, $def->type, $getter);
            $this->setNativePropertyVar($expr, $propVar);
            $this->setNativePropertyValueSource($expr, self::NATIVE_PROPERTY_VALUE_VAR);
            return $propVar;
        }

        if (!$this->canHoistStableObjectProp($objectVar, $propName)) {
            return null;
        }

        // SSA-stable object: lazily create reference at first access point.
        $result = $this->hoistStableObjectProp($objectVar, $propName, $propertyId, $def->type);
        $this->setNativePropertyVar($expr, $result);
        $this->setNativePropertyValueSource($expr, self::NATIVE_PROPERTY_VALUE_VAR);
        return $result;
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
                if (!$this->classDef) {
                    $this->fatalError($expr, 'The magic constant `__CLASS__` is not allowed in global scope');
                }
                if ($this->classDef->trait) {
                    return Symbol::getCalledClass();
                }
                return '"' . $this->escapeString($class) . '"';
            case 'Scalar_MagicConst_Trait':
                if (!$this->classDef or !$this->classDef->trait) {
                    $this->fatalError($expr, 'The magic constant `__TRAIT__` is not allowed in global scope');
                }
                return '"' . $this->escapeString($class) . '"';
            case 'Scalar_MagicConst_Method':
                return '"' . $this->escapeString($class) . '::' . $this->escapeString($this->method) . '"';
            default:
                abort($expr);
                break;
        }
    }

    protected function parseForeachItemAsList(string $listTmpVar, array $listItems): string
    {
        $code = '';
        foreach ($listItems as $k => $item) {
            if (!$item) {
                continue;
            }
            if ($item instanceof ArrayItem) {
                $oriInAssignExpr = $this->context->inAssignExpr;
                $this->context->inAssignExpr = true;
                $var = $this->parseIdentifier($item->value);
                $this->context->inAssignExpr = $oriInAssignExpr;
                if ($this->isVarExpr($item->value) and !$this->hasVar($var)) {
                    $this->addLocalVar($var, self::TYPE_VAR);
                }
                $code .= $this->getIndent() . ' ' . $var . ' = ' . $listTmpVar . '.item(' . $k . ');' . PHP_EOL;
            } else {
                $this->fatalError($item, 'Unsupported foreach item type');
            }
        }
        return $code;
    }

    protected function parseForeachArray(Foreach_ $node, string $iteratorVar): string
    {
        $tmpVar = $this->genTmpVarName();
        $code = "for (auto $tmpVar = $iteratorVar.begin(); $tmpVar != $iteratorVar.end(); ++$tmpVar) {" . PHP_EOL;
        $this->indentLevel++;
        if ($node->keyVar) {
            $keyVar = $this->parseIdentifier($node->keyVar);
            $this->checkVar($node, $keyVar);
            $code .= $this->getIndent() . ' ' . $keyVar . ' = ' . $tmpVar . '.key();' . PHP_EOL;
        }

        if ($node->byRef and !$this->isVarExpr($node->valueVar)) {
            $this->fatalError($node, 'Foreach by reference only supports variable as value');
        }

        if ($node->valueVar instanceof Expr\List_) {
            if ($node->byRef) {
                $this->fatalError($node, 'Foreach by reference cannot use list destructuring');
            }
            $listTmpVar = $this->genTmpVarName();
            $this->addLocalVar($listTmpVar, self::TYPE_VAR);
            $code .= $this->getIndent() . ' ' . $listTmpVar . ' = ' . $tmpVar . '.value();' . PHP_EOL;
            $code .= $this->parseForeachItemAsList($listTmpVar, $node->valueVar->items);
        } elseif ($this->isArrayDimFetch($node->valueVar)) {
            $array = $this->parseIdentifier($node->valueVar->var);
            if (!$this->hasVar($array) or $node->valueVar->dim === null) {
                abort($node->valueVar);
            }
            $dim = $this->parseIdentifier($node->valueVar->dim);
            $code .= $this->getIndent() . "{$array}.offsetSet({$dim}, {$tmpVar}.value());";
        } else {
            $valueVar = $this->parseIdentifier($node->valueVar);
            if ($node->byRef) {
                if (!$this->hasVar($valueVar)) {
                    $this->addLocalVar($valueVar, self::TYPE_REF);
                } elseif ($this->getVarType($valueVar) !== self::TYPE_REF) {
                    $this->fatalError($node, 'Cannot assign value to reference of type');
                }
                $code .= $this->getIndent() . ' ' . $valueVar . ' = ' . $tmpVar . '.valueRef();' . PHP_EOL;
            } else {
                $this->checkVar($node, $valueVar);
                $code .= $this->getIndent() . ' ' . $valueVar . ' = ' . $tmpVar . '.value();' . PHP_EOL;
            }
        }

        $body = $this->parseStmts($node->stmts);
        $body .= $this->genLoopEndFlagCheck();
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
                } elseif ($this->isStdContainerType($type)) {
                    return $this->parseForeachStdContainer($node);
                }
            }
        }

        $iteratorVar = $this->genTmpVarName();

        $code = '';
        $expr = $this->parseIdentifier($node->expr);
        $code .= $this->parseBeforeStmtLines() . PHP_EOL;
        $code .= self::TYPE_ARRAY . " {$iteratorVar} = " . $expr . ';' . PHP_EOL;
        $code .= $this->parseForeachArray($node, $iteratorVar);

        return $code;
    }

    /**
     * 为了兼容已有代码，默认不使用原生类型，而是将整数和浮点数作为 php 变量处理
     * 原生 int/float/bool 类型，是不支持自动转换的，例如如果 int 计算超过最大值后，会自动转为 float，除法若不能除尽，则会转为 float
     * 某些情况下高性能计算，可能需要使用原生类型，使用 $a = std::int(0) 来显式地使用原生类型
     */
    protected function detectConstType($expr): string
    {
        $name = $this->parseIdentifier($expr->name);
        if ($this->hasConstant($name)) {
            return $this->getConstantType($name);
        }
        if ($this->isInternalConstant($name)) {
            return $this->getTypeFromZendType(gettype($this->internalConstants[$name]));
        }
        if (strcasecmp($name, 'true') === 0) {
            return self::TYPE_BOOL;
        }
        if (strcasecmp($name, 'false') === 0) {
            return self::TYPE_BOOL;
        }
        if ($name === 'NAN' or $name === 'INF') {
            return self::TYPE_FLOAT;
        }
        return self::TYPE_VAR;
    }

    protected function parseSwitch(Node\Stmt\Switch_ $v): string
    {
        $cond    = $v->cond;
        $tmp_var = $this->genTmpVarName();
        $type    = $this->detectTypeOfExpr($cond);
        $this->assertExprCanBeUsedAsValue($cond, 'switch condition');
        if ($this->isVarExpr($cond)) {
            $this->requireVar($v, $this->parseIdentifier($cond));
        }
        [$condExpr, $condBeforeStmts, $condAfterStmts] = $this->parseExprWithCapturedStmts($cond);
        $var_def = '';
        $this->appendCapturedStmtLines($var_def, $condBeforeStmts);
        $var_def .= $type . ' ' . $tmp_var . ' = ' . $condExpr . ';' . PHP_EOL;
        $this->appendCapturedStmtLines($var_def, $condAfterStmts);

        // 保存作用域，switch 可能会解析失败，在这个过程中会增加变量，需重置
        $localVars = $this->context->localVars;
        $code      = $this->parseBeforeStmtLines() . PHP_EOL;

        if ($type === self::TYPE_INT or $type === self::TYPE_BOOL) {
            $code .= 'do {' . PHP_EOL;
            $this->indentLevel++;
            $code .= $this->getIndent() . 'switch (' . $tmp_var . ') {' . PHP_EOL;
            $this->indentLevel++;
            foreach ($v->cases as $case) {
                if (empty($case->cond)) {
                    $code .= $this->getIndent() . 'default: {' . PHP_EOL;
                } else {
                    $condType = $case->cond->getType();
                    if ($condType !== 'Scalar_Int' and $condType !== 'Scalar_Float') {
                        $this->context->localVars = $localVars;
                        $this->indentLevel -= 2;
                        goto _fail;
                    }
                    $code .= $this->getIndent() . 'case ' . $this->parseScalar($case->cond) . ': {' . PHP_EOL;
                }
                $code .= $this->parseBlockStmts($case->stmts);
                $code .= $this->getIndent() . '}' . PHP_EOL;
            }
            $this->indentLevel--;
            $code .= $this->getIndent() . '}' . PHP_EOL;
            $code .= $this->genLoopEndFlagCheck();
            $this->indentLevel--;
            $code .= $this->getIndent() . '} while(0);' . PHP_EOL;

            return $var_def . $code;
        }

        _fail:

        $code = 'do {' . PHP_EOL;
        $this->indentLevel++;
        $switchMatched = $this->genTmpVarName();
        $code .= $this->getIndent() . 'bool ' . $switchMatched . ' = false;' . PHP_EOL;
        $caseConds = [];
        foreach ($v->cases as $case) {
            if (empty($case->cond)) {
                $isDefault = true;
            } else {
                $isDefault = false;
                $caseConds[] = $case->cond;
            }
            $stmts = $case->stmts;
            if (empty($stmts)) {
                continue;
            }
            $this->indentLevel++;
            if (count($stmts) === 1 and $stmts[0] instanceof Node\Stmt\Block) {
                $stmts = $stmts[0]->stmts;
            }
            $lastExpr = end($stmts);
            if (!$this->isReturnExpr($lastExpr)
                and !$this->isExitExpr($lastExpr)
                and !$this->isBreakExpr($lastExpr)
                and !$this->isThrowExpr($lastExpr)
            ) {
                $this->fatalError($case, 'switch case must end with return/break/exit/throw, ' . $lastExpr->getType() . ' given');
            }

            if ($isDefault) {
                $code .= $this->getIndent() . 'if (!' . $switchMatched . ') {' . PHP_EOL;
            } else {
                $groupMatched = $this->genTmpVarName();
                $code .= $this->getIndent() . 'bool ' . $groupMatched . ' = false;' . PHP_EOL;
                foreach ($caseConds as $caseCond) {
                    $this->assertExprCanBeUsedAsValue($caseCond, 'switch case condition');
                    $caseBeforeStmtCount = count($this->context->beforeStmtLines);
                    $caseAfterStmtCount = count($this->context->afterStmtLines);
                    $caseCondExpr = $this->parseIdentifier($caseCond);
                    $caseBeforeStmts = array_slice($this->context->beforeStmtLines, $caseBeforeStmtCount);
                    $caseAfterStmts = array_slice($this->context->afterStmtLines, $caseAfterStmtCount);
                    $this->context->beforeStmtLines = array_slice($this->context->beforeStmtLines, 0, $caseBeforeStmtCount);
                    $this->context->afterStmtLines = array_slice($this->context->afterStmtLines, 0, $caseAfterStmtCount);

                    $code .= $this->getIndent() . 'if (!' . $switchMatched . ' && !' . $groupMatched . ') {' . PHP_EOL;
                    $this->appendCapturedStmtLines($code, $caseBeforeStmts);
                    if ($caseAfterStmts) {
                        $caseTmpVar = $this->addTmpVar(self::TYPE_VAR);
                        $code .= $this->getIndent() . $caseTmpVar . ' = ' . $caseCondExpr . ';' . PHP_EOL;
                        $this->appendCapturedStmtLines($code, $caseAfterStmts);
                        $caseCondExpr = $caseTmpVar;
                    }
                    $code .= $this->getIndent() . $groupMatched . ' = php::equals(' . $tmp_var . ', ' . $caseCondExpr . ');' . PHP_EOL;
                    $code .= $this->getIndent() . '}' . PHP_EOL;
                }
                $code .= $this->getIndent() . 'if (' . $groupMatched . ') {' . PHP_EOL;
                $code .= $this->getIndent() . $switchMatched . ' = true;' . PHP_EOL;
                $caseConds = [];
            }

            $code .= $this->parseStmts($stmts);
            $this->indentLevel--;
            $code .= $this->getIndent() . '}' . PHP_EOL;
        }
        $code .= $this->genLoopEndFlagCheck();
        $this->indentLevel--;
        $code .= $this->getIndent() . '} while (0);';

        return $var_def . $code;
    }

    protected function parseStatic(Node\Stmt\Static_ $v): string
    {
        $list = [];
        foreach ($v->vars as $var) {
            $varName = $this->escapeVarName($var->var->name);
            $type = $var->default ? $this->detectTypeOfExpr($var->default) : self::TYPE_VAR;
            if ($var->default) {
                $this->assertExprCanBeUsedAsValue($var->default, 'static variable default value');
            }
            $globalVar = $this->addStaticVar($var->var, $varName, $type);

            $list[] = self::TYPE_VAR . ' &' . $varName . ' = ' . $this->escapeGlobalVar($globalVar) . ';';
            if ($var->default) {
                $initState = self::STATIC_VAR . $varName . '_initialized';
                $initCode = $this->getIndent() . 'static bool ' . $initState . ' = false;';
                $initCode .= $this->getIndent() . "if (!{$initState}) { \n";
                $this->indentLevel++;
                $initCode .= $this->getIndent() . "{$initState} = true;\n";
                $initCode .= $this->genStaticVarInitLambda($var, $varName);
                $this->indentLevel--;
                $initCode .= $this->getIndent() . '}';
                $list[] = $initCode;
            }
        }

        return implode(PHP_EOL . $this->getIndent(), $list);
    }

    protected function genStaticVarInitLambda(Node\Stmt\StaticVar $var, string $varName): string
    {
        $oriCtx = $this->context;

        $this->context = new FunctionContext();
        $this->context->arguments = $oriCtx->localVars;

        $code = '([&](){' . PHP_EOL;
        $body = $this->getIndent() . $varName . ' = ' . $this->parseExpr($var->default) . ';';
        $code .= $this->genScopeVarDecl();
        $code .= $this->parseBeforeStmtLines();
        $code .= $body;
        $code .= $this->parseAfterStmtLines();
        $code .= '})();' . PHP_EOL;

        $this->context = $oriCtx;

        return $code;
    }

    protected function parseEnum(Node\Stmt\Enum_ $v): string
    {
        return 'php::eval("' . $this->escapeString($this->genEmbeddedCode($v)) . '");';
    }

    protected function parseEval(Expr\Eval_ $expr): string
    {
        $this->assertExprCanBeUsedAsValue($expr->expr, 'eval operand');
        // 对 eval() 指令的 PHP 代码段禁止字面量优化
        $expr->expr->setAttribute('noLiteralString', true);
        return 'php::eval(' . $this->identifierToStr($expr->expr) . ')';
    }

    protected function parseInclude(Expr\Include_ $expr): string
    {
        $this->assertExprCanBeUsedAsValue($expr->expr, 'include operand');
        switch ($expr->type) {
            case Expr\Include_::TYPE_INCLUDE:
                $type = 'php::INCLUDE';
                break;
            case Expr\Include_::TYPE_INCLUDE_ONCE:
                $type = 'php::INCLUDE_ONCE';
                break;
            case Expr\Include_::TYPE_REQUIRE:
                $type = 'php::REQUIRE';
                break;
            case Expr\Include_::TYPE_REQUIRE_ONCE:
                $type = 'php::REQUIRE_ONCE';
                break;
            default:
                $this->fatalError($expr, 'Invalid include type');
                break;
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
            if ($num->value > 1) {
                $this->context->hasMultiLevelBreak = true;
                return '_brk_flag = ' . ($num->value - 1) . '; break;';
            }
        }

        return 'break;';
    }

    protected function parseContinue(Node\Stmt\Continue_ $v): string
    {
        if (!$this->context->inLoop) {
            $this->fatalError($v, 'Cannot continue outside loop');
        }
        $num = $v->num;
        if ($num) {
            if ($num->value > 1) {
                $this->context->hasMultiLevelContinue = true;
                return '_cnt_flag = ' . ($num->value - 1) . '; break;';
            }
        }
        return 'continue;';
    }

    /**
     * Emit flag-propagation checks at the end of a loop body.
     *
     * Translates multi-level break / continue into plain break / continue
     * by decrementing a counter at each loop boundary until it reaches zero.
     */
    protected function genLoopEndFlagCheck(): string
    {
        $code = '';
        $indent = $this->getIndent();
        if ($this->context->hasMultiLevelBreak) {
            $code .= "{$indent}if (_brk_flag > 0) { _brk_flag--; break; }" . PHP_EOL;
        }
        if ($this->context->hasMultiLevelContinue) {
            $code .= "{$indent}if (_cnt_flag > 0) { _cnt_flag--; if (_cnt_flag == 0) continue; else break; }" . PHP_EOL;
        }
        return $code;
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

    protected function parseIsset(Expr\Isset_ $expr): string
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

    protected function parseEmpty(Expr\Empty_ $expr): string
    {
        return $this->parseChainedExpr($expr->expr, self::OP_EMPTY);
    }

    /**
     * 左值只能为变量、数组、对象属性、对象静态属性
     */
    protected function checkLeftValue(NodeAbstract $expr): void
    {
        $this->assertNotNullsafeWriteContext($expr);
        if (!$this->isVarExpr($expr) && !$this->isArrayDimFetch($expr) && !$this->isPropertyFetch($expr) && !$this->isStaticPropertyFetch($expr)) {
            $this->fatalError($expr, 'The left value of assignment operation can only be variable, array item, object property, class static property');
        }
    }

    protected function assertNotNullsafeWriteContext(NodeAbstract $expr): void
    {
        if ($expr instanceof Expr\NullsafePropertyFetch) {
            $this->fatalError($expr, "Can't use nullsafe operator in write context");
        }
    }

    protected function emitDynamicPropertyRead(string $object, string $property): string
    {
        return "{$object}.getProperty({$property})";
    }

    protected function emitDynamicPropertyWrite(string $object, string $property, string $value): string
    {
        return "{$object}.setProperty({$property}, {$value})";
    }

    protected function emitDynamicPropertyTargetRead(PropertyWriteTarget $target): string
    {
        $this->assertDynamicPropertyTarget($target);

        return $this->emitDynamicPropertyRead($target->getDynamicObjectExpr(), $target->getDynamicPropertyExpr());
    }

    protected function emitDynamicPropertyTargetWrite(PropertyWriteTarget $target, string $value): string
    {
        $this->assertDynamicPropertyTarget($target);

        return $this->emitDynamicPropertyWrite($target->getDynamicObjectExpr(), $target->getDynamicPropertyExpr(), $value);
    }

    protected function emitDynamicPropertyTargetUnset(PropertyWriteTarget $target): string
    {
        $this->assertDynamicPropertyTarget($target);

        return $target->getDynamicObjectExpr() . '.unsetProperty(' . $target->getDynamicPropertyExpr() . ')';
    }

    protected function emitDynamicPropertyTargetRef(PropertyWriteTarget $target): string
    {
        $this->assertDynamicPropertyTarget($target);

        return $target->getDynamicObjectExpr() . '.attrRef(' . $target->getDynamicPropertyExpr() . ')';
    }

    protected function emitDynamicPropertyTargetAppendArray(PropertyWriteTarget $target, string $value): string
    {
        $this->assertDynamicPropertyTarget($target);

        return $target->getDynamicObjectExpr() . '.appendArrayProperty(' . $target->getDynamicPropertyExpr() . ', ' . $value . ')';
    }

    protected function emitDynamicPropertyTargetUpdateArray(PropertyWriteTarget $target, string $dim, string $value): string
    {
        $this->assertDynamicPropertyTarget($target);

        return $target->getDynamicObjectExpr() . '.updateArrayProperty(' . $target->getDynamicPropertyExpr() . ', ' . $dim . ', ' . $value . ')';
    }

    protected function canEmitDynamicPropertyTarget(?PropertyWriteTarget $target): bool
    {
        return $target !== null && $target->isDynamicObjectProperty();
    }

    protected function emitDynamicPropertyFetchRead(Expr\PropertyFetch $expr, ?PropertyWriteTarget $target = null): string
    {
        if ($this->canEmitDynamicPropertyTarget($target)) {
            return $this->emitDynamicPropertyTargetRead($target);
        }

        return $this->emitDynamicPropertyRead(
            $this->parseIdentifier($expr->var),
            $this->identifierToStr($expr->name, literal: true)
        );
    }

    protected function emitDynamicPropertyFetchWrite(Expr\PropertyFetch $expr, string $value, ?PropertyWriteTarget $target = null): string
    {
        if ($this->canEmitDynamicPropertyTarget($target)) {
            return $this->emitDynamicPropertyTargetWrite($target, $value);
        }

        return $this->emitDynamicPropertyWrite(
            $this->parseIdentifier($expr->var),
            $this->identifierToStr($expr->name, literal: true),
            $value
        );
    }

    protected function getDynamicPropertyFetchObjectExpr(Expr\PropertyFetch $expr, ?PropertyWriteTarget $target = null): string
    {
        if ($this->canEmitDynamicPropertyTarget($target)) {
            return $target->getDynamicObjectExpr();
        }

        return $this->parseIdentifier($expr->var);
    }

    protected function emitDynamicPropertyFetchUnset(Expr\PropertyFetch $expr, ?PropertyWriteTarget $target = null): string
    {
        if ($this->canEmitDynamicPropertyTarget($target)) {
            return $this->emitDynamicPropertyTargetUnset($target);
        }

        return $this->parseIdentifier($expr->var) . '.unsetProperty(' . $this->identifierToStr($expr->name, literal: true) . ')';
    }

    protected function emitDynamicPropertyFetchAppendArray(Expr\PropertyFetch $expr, string $value, ?PropertyWriteTarget $target = null): string
    {
        if ($this->canEmitDynamicPropertyTarget($target)) {
            return $this->emitDynamicPropertyTargetAppendArray($target, $value);
        }

        return $this->parseIdentifier($expr->var) . '.appendArrayProperty(' . $this->identifierToStr($expr->name) . ', ' . $value . ')';
    }

    protected function emitDynamicPropertyFetchUpdateArray(Expr\PropertyFetch $expr, string $dim, string $value, ?PropertyWriteTarget $target = null): string
    {
        if ($this->canEmitDynamicPropertyTarget($target)) {
            return $this->emitDynamicPropertyTargetUpdateArray($target, $dim, $value);
        }

        return $this->parseIdentifier($expr->var) . '.updateArrayProperty(' . $this->identifierToStr($expr->name) . ', ' . $dim . ', ' . $value . ')';
    }

    protected function assertDynamicPropertyTarget(PropertyWriteTarget $target): void
    {
        if (!$target->isDynamicObjectProperty()) {
            $this->fatalError($target->node, 'Internal error: property write target is not a dynamic object property');
        }
    }

    protected function emitDynamicPropertyFetchRef(Expr\PropertyFetch $expr, NodeAbstract $errorNode): string
    {
        $target = $this->preparePropertyWriteTarget($expr);
        if ($this->canEmitDynamicPropertyTarget($target)) {
            $objectExpr = $target->getDynamicObjectExpr();
            if (!$this->hasVar($objectExpr)) {
                $this->fatalError($errorNode, 'Undefined variable `$' . $objectExpr . '`');
            }
            return $this->emitDynamicPropertyTargetRef($target);
        }

        if (!$this->isVarExpr($expr->var)) {
            return $this->parseExpr($expr->var) . '.attrRef(' . $this->identifierToStr($expr->name) . ')';
        }

        $objectExpr = $this->parseIdentifier($expr->var);
        if (!$this->hasVar($objectExpr)) {
            $this->fatalError($errorNode, 'Undefined variable `$' . $objectExpr . '`');
        }

        return $objectExpr . '.attrRef(' . $this->identifierToStr($expr->name) . ')';
    }

    protected function getChainedFunc(string $op): string
    {
        return match ($op) {
            self::OP_ISSET => 'php::exists',
            self::OP_NOT_EMPTY => 'php::notEmpty',
            default => 'php::' . $op,
        };
    }

    protected function parseChainedExpr(NodeAbstract $node, string $op, bool $getValue = false): string
    {
        // AOT 编译器不允许操作未定义的变量，PHP 的 isset($var) 可能 $var 未定义
        $this->checkVarMustExist($node, $this->parseIdentifier($node));
        $fn = $this->getChainedFunc($op);
        $expr = $node;
        if ($this->isVarExpr($expr)) {
            return $fn . '(' . $this->parseExpr($expr) . ')';
        }
        // 单属性读取（非链式）
        if ($this->isPropertyFetch($expr) and $this->isVarExpr($expr->var) and $this->isIdExpr($expr->name)) {
            $prop = $this->parsePropertyFetch($expr);
            if ($this->isNativePropertyAccess($expr)) {
                if ($op === self::OP_REFVAL) {
                    return $prop . '.toReference()';
                }
                return $fn . '(' . $prop . ')';
            }
        }
        if ($this->isStaticPropertyFetch($expr) and $this->isNameExpr($expr->class) and $this->isIdExpr($expr->name)) {
            $prop = $this->parseStaticPropertyFetch($expr);
            if ($this->isNativePropertyAccess($expr)) {
                if ($op === self::OP_REFVAL) {
                    return $prop . '.toReference()';
                }
                return $fn . '(' . $prop . ')';
            }
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

        if ($getValue) {
            $result = $this->addTmpVar(self::TYPE_VAR);
            $node->setAttribute('chainOpResult', $result);
            return $fn . '(' . $var . ', {' . implode(', ', $list) . '}, ' . $result . ')';
        } else {
            // toReference(var, {}) 返回空引用，空链时改用成员函数形式
            if ($op === self::OP_REFVAL && empty($list)) {
                return $var . '.toReference()';
            }
            return $fn . '(' . $var . ', {' . implode(', ', $list) . '})';
        }
    }

    protected function parseCastArray(Expr\Cast\Array_ $expr): string
    {
        $this->assertExprCanBeUsedAsValue($expr->expr, 'cast operand');
        return $this->convertArrayExpr($this->parseExprAsValue($expr->expr));
    }

    protected function hasGlobalVar(string $name): bool
    {
        return array_key_exists($name, $this->globalVars);
    }

    protected function hasScopeGlobalVar(string $name): bool
    {
        return array_key_exists($name, $this->context->globalVars);
    }

    protected function hasStaticVar(string $name): bool
    {
        return array_key_exists($name, $this->context->staticVars);
    }

    protected function parseCastDouble(mixed $expr): string
    {
        $this->assertExprCanBeUsedAsValue($expr->expr, 'cast operand');
        return $this->convertFloatExpr($this->parseIdentifier($expr->expr));
    }

    protected function detectFuncCallReturnType(string $name): string
    {
        $name = ltrim($name, '\\');
        $returnType = Reflection::getFunctionReturnType($name);
        if ($returnType !== null) {
            return $this->getTypeFromZendType($returnType);
        }

        return self::TYPE_VAR;
    }

    protected function detectMethodCallReturnType(string $class, string $method): string
    {
        $returnType = Reflection::getMethodReturnType($class, $method);
        if ($returnType) {
            return $this->getTypeFromZendType($returnType);
        }
        return self::TYPE_VAR;
    }

    protected function genObjvalCall(Expr\FuncCall $expr): string
    {
        if (count($expr->args) !== 2) {
            $this->fatalError($expr, 'objval() requires exactly 2 arguments');
        }
        $receiver = $this->parseExpr($expr->args[0]->value);
        $className = $this->resolveClassNameArg($expr->args[1]->value);
        return 'php::toObject(' . $receiver . ', ' . $this->getClassEntryPtr($className) . ', true)';
    }

    protected function genToObjectCall(Expr\MethodCall $expr, string $receiver): string
    {
        if (empty($expr->args)) {
            return 'php::toObject(' . $receiver . ')';
        }
        $className = $this->resolveClassNameArg($expr->args[0]->value);
        return 'php::toObject(' . $receiver . ', ' . $this->getClassEntryPtr($className) . ', true)';
    }

    protected function parseMethodCall(Expr\MethodCall $expr): string
    {
        $class = '';
        $object = $this->parseIdentifier($expr->var);
        if ($this->isVarExpr($expr->var)) {
            if (!$this->hasVar($object)) {
                $this->errorUndefinedVariable($expr->var);
            }
            if ($this->isTypedObject($object)) {
                $class = $this->getObjectType($object);
            }
        }

        $magicMethod = false;
        $method = $this->identifierToStr($expr->name, literal: true);

        // keyword methods (to* builtins + __ extensions) — dispatched before type-specific logic
        if ($this->isNamedMethod($expr->name)) {
            $methodName = $expr->name->toString();
            $receiverType = $this->isVarExpr($expr->var) ? $this->getVarType($object) : $this->detectTypeOfExpr($expr->var);
            if ($receiverType === self::TYPE_VOID) {
                $receiverType = self::TYPE_VAR;
            }
            // to* builtins
            if (isset(self::KEYWORD_METHOD_MAP[$methodName])) {
                if ($methodName === 'toObject') {
                    return $this->genToObjectCall($expr, $object);
                }
                return $this->genToConvertCall($object, $methodName, $receiverType);
            }
            // __ keyword extensions
            $kwExt = $this->findKeywordExtensionMethod($methodName);
            if ($kwExt) {
                return $this->parseUniversalMethodCall($expr, $object, $methodName, $kwExt, $this->isVarExpr($expr->var));
            }
        }

        // 可转为原生调用的 MethodCall
        if ($this->isVarExpr($expr->var) and $this->isNamedMethod($expr->name)) {
            $type = $this->getVarType($object);
            if (!$this->checkArgType($type, self::TYPE_OBJECT)) {
                $methodName = $expr->name->toString();
                // 非对象类型可使用内置方法
                $fn = $this->findUniversalMethodAnyType($type, $methodName);
                if ($fn) {
                    if ($type === self::TYPE_STREAM) {
                        return $this->genStreamNullGuard($expr, $object, $methodName, $fn);
                    }
                    return $this->parseUniversalMethodCall($expr, $object, $methodName, $fn);
                }
                $this->fatalError($expr, "Cannot call method `{$methodName}()` on variable of type {$type}");
            }
            $this->context->beforeStmtLines[] = '// Method Call: ' . $object . '->' . $this->parseIdentifier($expr->name) . '()';
            try {
                $nativeFunc = $this->findNativeMethod($expr, $object, $this->parseIdentifier($expr->name));
                if ($nativeFunc) {
                    $expr->setAttribute('nativeCall', $nativeFunc);
                    try {
                        if ($this->shouldUseDynamicCallForNativeArgs($nativeFunc, $expr->args)) {
                            return $this->genCallUserFuncArray($this->genArray([$object, $method]), $expr->args, $methodName, $class);
                        }
                        return $this->parseNativeMethodCall($object, $nativeFunc, $expr->args);
                    } catch (PlaceHolder) {
                        return $this->genPlaceHolder($this->genArray([$object, $method]));
                    }
                }
            } catch (DynamicCall) {
                $magicMethod = true;
            }
        }

        // 表达式返回值也可使用内置方法：fn()->method(), $obj->fn()->method(), Foo::fn()->method(), $obj->prop->method()
        if (!$this->isVarExpr($expr->var) and $this->isNamedMethod($expr->name)) {
            $type = $this->detectTypeOfExpr($expr->var);
            if ($type === self::TYPE_VOID) {
                $type = self::TYPE_VAR;
            }
            if ($type !== self::TYPE_VAR && !$this->checkArgType($type, self::TYPE_OBJECT)) {
                $methodName = $expr->name->toString();
                $fn = $this->findUniversalMethodAnyType($type, $methodName);
                if ($fn) {
                    // Wrap receiver in type conversion for direct_method handlers
                    // since the raw expression (often from php::call()) is php::Variant
                    $receiver = $object;
                    if ($fn['handler'] === 'direct_method') {
                        $receiver = $this->wrapUniversalReceiver($type, $object);
                    }
                    if ($type === self::TYPE_STREAM) {
                        return $this->genStreamNullGuard($expr, $receiver, $methodName, $fn);
                    }
                    return $this->parseUniversalMethodCall($expr, $receiver, $methodName, $fn, false);
                }
            }
        }

        if ($this->isNamedMethod($expr->name)) {
            $funcName = $this->parseIdentifier($expr->name);
        } else {
            $funcName = '';
        }

        if ($class && $funcName && !$magicMethod && $this->isInternalClass($class)) {
            $methodPtr = $this->getMethodPtr($class, $funcName);
        } else {
            $methodPtr = $method;
        }

        if ($object === 'this_' or $object === 'self' or $object === 'static') {
            $this->methodDef->hasDynamicCall = true;
        }

        if (empty($expr->args)) {
            return $object . '.call(' . $methodPtr . ')';
        }
        if ($this->hasNamedCallArg($expr->args)) {
            return $this->genCallUserFuncArray($this->genArray([$object, $method]), $expr->args, $funcName, $class);
        }
        try {
            $class = empty($class) ? self::DYNAMIC_CALLED_CLASS : $class;
            return $object . '.call(' . $methodPtr . ', ' . $this->parseCallArgs($expr->args, $funcName, $class, false) . ')';
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
        if ($id === 'self') {
            $id = $this->getNamespacedClassName($this->class);
        } elseif ($id === 'static') {
            return Symbol::getCalledClass();
        }
        if ($this->isNameExpr($node) or $this->isIdExpr($node)) {
            return $literal ? $this->getLiteralString($id) : $this->genCharPtr($id, true);
        }
        if ($this->isZeroLiteral($node)) {
            return self::VALUE_ZERO;
        }
        return $id;
    }

    protected function requireVar($node, string $var): void
    {
        if (!$this->hasVar($var)) {
            $this->fatalError($node, 'The variable `' . $var . '` is not defined');
        }
    }

    protected function parseStaticCall(Expr\StaticCall $expr): string
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
        } elseif ($this->isNameExpr($expr->class) and $class === 'static') {
            $methodPtr = $this->identifierToStr($expr->name, literal: true);
            $fn = Symbol::getCalledCe() . ', php::getMethod(' . Symbol::getCalledCe() . ', ' . $methodPtr . ')';
            $this->context->beforeStmtLines[] = '// Static Method Call: static::' . $this->parseIdentifier($expr->name) . '()';
            $placeHolder = $this->genArray([Symbol::getCalledClass(), $methodPtr]);
        } elseif ($this->isNameExpr($expr->class)) {
            if ($class === 'self') {
                $class = $this->class;
                $self = true;
            } elseif ($class === 'parent') {
                return $this->parseParentMethodCall($expr);
            } elseif ($class === 'std') {
                return $this->parseStdCall($expr);
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
                if (!$nativeFunc and $this->hasClass($class) and $this->getNativeMethod($expr, $class, '__callStatic', false)) {
                    $dynamicCall = true;
                }

                if ($nativeFunc) {
                    try {
                        if ($this->shouldUseDynamicCallForNativeArgs($nativeFunc, $expr->args)) {
                            return $this->genCallUserFuncArray($this->genArray($callScope), $expr->args, $method, $class);
                        }
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
        if ($this->hasNamedCallArg($expr->args)) {
            return $this->genCallUserFuncArray($placeHolder, $expr->args);
        }
        try {
            $callArgs = $this->parseCallArgs($expr->args);
            return $call . '(' . $fn . ', ' . $callArgs . ')';
        } catch (PlaceHolder) {
            return $this->genPlaceHolder($placeHolder);
        }
    }

    protected function resolveNativeStaticPropertyFetch(Expr\StaticPropertyFetch $expr): ?StaticPropertyFetchResolution
    {
        $target = $this->resolveStaticPropertyFetchTarget($expr);
        if ($target === null) {
            return null;
        }
        if ($target->isDynamic()) {
            return new StaticPropertyFetchResolution(null, $target->dynamicExpression, false);
        }
        $class = $target->class;
        if ($class === null) {
            return null;
        }
        $result = $this->resolveNativeStaticProperty($expr, $target->property, $class);
        if ($result !== null) {
            $expression = $this->applyNativePropertyAccessResult($expr, $result);
            return new StaticPropertyFetchResolution($class, $expression, true);
        }
        return null;
    }

    private function resolveStaticPropertyFetchTarget(Expr\StaticPropertyFetch $expr): ?StaticPropertyFetchTarget
    {
        if (!$this->isNameExpr($expr->class) or !$this->isIdExpr($expr->name)) {
            return null;
        }

        $class = $this->parseIdentifier($expr->class);
        $propertyName = $this->parseIdentifier($expr->name);
        if ($class === 'static') {
            return null;
        }
        if ($class === 'self') {
            if ($this->classDef->trait) {
                $expression = Symbol::getStaticProperty() . '(' . Symbol::getCalledCe() . ', ' . $this->getLiteralString($propertyName) . ')';
                return new StaticPropertyFetchTarget($propertyName, null, $expression);
            }
            return new StaticPropertyFetchTarget($propertyName, $this->getFullClassName(), null);
        }
        if ($class === 'parent') {
            if (!$this->classDef->extends) {
                $this->fatalError($expr, 'Cannot access parent:: when current class does not extend any class');
            }
            return new StaticPropertyFetchTarget($propertyName, $this->classDef->extends, null);
        }

        return new StaticPropertyFetchTarget($propertyName, $this->getNamespacedClassName($class), null);
    }

    private function createPropertyAccessResolver(): PropertyAccessResolver
    {
        $this->assertCompilerPhase(self::PHASE_CONVERT, 'PropertyAccessResolver');
        return new PropertyAccessResolver($this);
    }

    protected function isSameClassName(string $classA, string $classB): bool
    {
        return strcasecmp(ltrim($classA, '\\'), ltrim($classB, '\\')) === 0;
    }

    protected function isSameOrSubclassOf(string $class, string $parent): bool
    {
        $class = strtolower(ltrim($class, '\\'));
        $parent = strtolower(ltrim($parent, '\\'));
        while ($class !== '') {
            if ($class === $parent) {
                return true;
            }
            $class = $this->getParentClass($class);
        }
        return false;
    }

    protected function canAccessProtectedProperty(string $scope, string $declaringClass): bool
    {
        if ($scope === '') {
            return false;
        }
        return $this->isSameOrSubclassOf($scope, $declaringClass)
            || $this->isSameOrSubclassOf($declaringClass, $scope);
    }

    private function resolveNativeInstanceProperty(NodeAbstract $expr, string $property, string $class): ?PropertyAccessResult
    {
        $scope = $this->class ? $this->getFullClassName() : '';
        return $this->createPropertyAccessResolver()->resolveNativeInstanceProperty($expr, $property, $class, $scope);
    }

    private function resolveNativeStaticProperty(NodeAbstract $expr, string $property, string $class): ?PropertyAccessResult
    {
        $scope = $this->class ? $this->getFullClassName() : '';
        return $this->createPropertyAccessResolver()->resolveNativeStaticProperty($expr, $property, $class, $scope);
    }

    private function applyNativePropertyAccessResult(NodeAbstract $expr, PropertyAccessResult $result): string
    {
        $offset = $this->getPropertyOffset($result->classDef->getNamespacedName(false), $result->property);
        $expr->setAttribute('nativePropertyAccess', new NativePropertyAccess($offset, $result));
        return $offset;
    }

    protected function isNativePropertyAccess(NodeAbstract $expr): bool
    {
        return $this->getNativePropertyAccess($expr) !== null;
    }

    protected function getNativePropertyDef(NodeAbstract $expr): ?PropertyDef
    {
        return $this->getNativePropertyAccess($expr)?->getPropertyDef();
    }

    protected function getNativePropertyClassDef(NodeAbstract $expr): ?ClassDef
    {
        return $this->getNativePropertyAccess($expr)?->getClassDef();
    }

    private function getNativePropertyAccess(NodeAbstract $expr): ?NativePropertyAccess
    {
        $access = $expr->getAttribute('nativePropertyAccess');
        return $access instanceof NativePropertyAccess ? $access : null;
    }

    protected function setNativePropertyVar(NodeAbstract $expr, string $var): void
    {
        $expr->setAttribute('nativePropertyVar', $var);
    }

    protected function getNativePropertyVar(NodeAbstract $expr): ?string
    {
        $var = $expr->getAttribute('nativePropertyVar');
        return is_string($var) ? $var : null;
    }

    protected function setNativePropertyValueSource(NodeAbstract $expr, string $source): void
    {
        $expr->setAttribute('nativePropertyValueSource', $source);
    }

    protected function isNativePropertyTypedValue(NodeAbstract $expr): bool
    {
        return $expr->getAttribute('nativePropertyValueSource') === self::NATIVE_PROPERTY_VALUE_VAR;
    }

    protected function parseNativeStaticPropertyFetch(Expr\StaticPropertyFetch $expr): ?string
    {
        $resolution = $this->resolveNativeStaticPropertyFetch($expr);
        if ($resolution !== null) {
            $nativeProp = $resolution->expression;
            $def = $this->getNativePropertyDef($expr);
            $class = $resolution->class;
            if ($this->nativeTypes && $def && $class !== null) {
                $this->setNativePropertyValueSource($expr, self::NATIVE_PROPERTY_VALUE_VAR);
                return $this->emitNativeStaticPropertyTypedFetch($expr, $class, $def, $nativeProp);
            }

            if ($resolution->nativeProperty && $class !== null) {
                $classPtr = $this->getClassEntryPtr($class);
                $this->setNativePropertyValueSource($expr, self::NATIVE_PROPERTY_VALUE_DYNAMIC);
                return Symbol::getStaticProperty() . '(' . $classPtr . ', ' . $nativeProp . ')';
            } else {
                $this->setNativePropertyValueSource($expr, self::NATIVE_PROPERTY_VALUE_DYNAMIC);
                return $nativeProp;
            }
        }
        return null;
    }

    private function emitNativeStaticPropertyTypedFetch(
        Expr\StaticPropertyFetch $expr,
        string $class,
        PropertyDef $def,
        string $nativeProp,
    ): string {
        $info = $this->getHoistedObjectPropInfo($def->type);
        $propName = $this->parseIdentifier($expr->name);
        $refVar = '_static_' . str_replace('\\', '_', $class) . '_' . $propName;
        $this->registerStaticPropertyRef($refVar, $class, $nativeProp, $info);

        if ($info['kind'] === 'zval') {
            $helper = $def->type === self::TYPE_FLOAT ? 'php_aot_static_float_ref' : 'php_aot_static_int_ref';
            return $helper . '(' . $refVar . ')';
        }

        return $refVar;
    }

    private function registerStaticPropertyRef(string $refVar, string $class, string $offsetExpr, array $info): void
    {
        if (isset($this->context->staticPropRefs[$refVar])) {
            return;
        }

        $this->context->staticPropRefs[$refVar] = [
            'type' => $info['type'],
            'classPtr' => $this->getClassEntryPtr($class),
            'offsetExpr' => $offsetExpr,
            'kind' => $info['kind'],
        ];
    }

    protected function parseStaticPropertyFetch(Expr\StaticPropertyFetch $expr): string
    {
        $native = $this->parseNativeStaticPropertyFetch($expr);
        if ($native !== null) {
            return $native;
        }
        return Symbol::getStaticProperty() . '(' . $this->identifierToStr($expr->class) . ', ' . $this->identifierToStr($expr->name) . ')';
    }

    protected function parseClassConstFetch(Expr\ClassConstFetch $expr): string
    {
        $class = $this->parseIdentifier($expr->class);
        $self = false;
        if ($class === 'self' or $class === 'this_') {
            // Trait 读取常量，必须动态获取类名
            if ($this->classDef->trait) {
                $class = 'static';
            } else {
                $self = true;
                $class = $this->class;
            }
        }

        $const = $this->escapeString($this->parseIdentifier($expr->name));
        if ($class === 'static') {
            if (!$this->methodDef) {
                $this->fatalError($expr, "The 'static' keyword can only be used as the class name in class methods");
            }
            if ($const === 'class') {
                return Symbol::getCalledClass();
            } else {
                return Symbol::constant() . '(' . Symbol::getCalledCe() . ', ' . $this->getLiteralString($const) . ')';
            }
        }

        $class = $this->getNamespacedClassName($class);
        if ($const === 'class') {
            return $this->getLiteralString($class);
        }
        if (($self or $this->isNameExpr($expr->class)) and $this->isIdExpr($expr->name)) {
            if ($this->hasClass($class)) {
                $classDef = $this->getClass($class);
                if ($classDef->enum) {
                    $ce = $this->getClassEntryPtr($class);
                    return 'php::getEnumCase(' . $ce . ', ' . $this->getLiteralString($const) . ')';
                }
                $nativeConst = $this->findNativeClassConst($expr, $class, $const);
                if ($nativeConst) {
                    return $nativeConst;
                }
            }
            $ce = $this->getClassEntryPtr($class);
            return Symbol::constant() . '(' . $ce . ', ' . $this->getLiteralString($const) . ')';
        }
        $name = $class . '::' . $const;
        $name = $this->getLiteralString($name);
        return Symbol::constant() . '(' . $name . ')';
    }

    protected function parseThrow(mixed $expr): string
    {
        if ($this->method === '__destruct') {
            $this->warning($expr, "Throwing exception in {$this->getFullClassName()}::__destruct() may cause memory leak");
        }
        $type = $this->detectTypeOfExpr($expr->expr);
        if ($this->isNewExpr($expr->expr)) {
            $ex = $this->parseExpr($expr->expr);
            return 'php::throwException(' . $ex . ')';
        } elseif ($this->isVarExpr($expr->expr)) {
            $ex = $this->parseIdentifier($expr->expr);
            if ($type == self::TYPE_OBJECT) {
                return 'php::throwException(' . $ex . ')';
            }
        } else {
            $ex = $this->parseExpr($expr->expr);
        }
        if ($type != self::TYPE_VAR) {
            $this->fatalError($expr, 'Can only throw objects');
        }
        $tmp = $this->genTmpVarName();
        $this->addLocalVar($tmp, self::TYPE_VAR);
        return '([&]() -> php::Var { ' . $tmp . ' = ' . $ex . '; if (!' . $tmp . '.isObject()) { php::throwError("Can only throw objects"); } return php::throwException(php::Object(' . $tmp . ')); })()';
    }

    protected function parseTryCatch(mixed $v): string
    {
        $code = $this->parseBeforeStmtLines() . PHP_EOL;
        $code .= 'try {';
        $stmts = $v->stmts;

        $code .= PHP_EOL;
        $code .= $this->parseBlockStmts($stmts);
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
        $conditions = [];
        foreach ($types as $type) {
            if ($this->isNameExpr($type) or $this->isFullNameExpr($type)) {
                $class = $this->getNamespacedClassName($this->parseIdentifier($type));
                $ce = $this->getClassEntryPtr($class);
                $conditions[] = Symbol::instanceOf() . '(' . $var . ', ' . $ce . ')';
            } else {
                $this->fatalError($type, 'Unsupported catch type');
            }
        }

        $code .= implode(' || ', $conditions);
        $code .= ') {' . PHP_EOL;
        $this->indentLevel++;
        $code .= $this->parseStmts($catch->stmts);
        $code .= $this->getIndent() . "{$exVar}.unset();" . PHP_EOL;
        $this->indentLevel--;
        $code .= $this->getIndent() . '}';

        return $code;
    }

    protected function parseShellExec(Expr\ShellExec $expr): string
    {
        $list = [];
        foreach ($expr->parts as $part) {
            $list[] = $this->identifierToStr($part);
        }
        return 'php::fn::shell_exec(php::concat({' . implode(', ', $list) . '}))';
    }

    protected function parseGoto(Node\Stmt\Goto_ $v): string
    {
        return 'goto ' . $v->name->name . ';';
    }

    protected function parseLabel(Node\Stmt\Label $v): string
    {
        return $v->name->name . ':';
    }

    protected function parseModifiers(int $flags): int
    {
        if (!($flags & Modifiers::PRIVATE) and !($flags & Modifiers::PROTECTED)) {
            $flags |= Modifiers::PUBLIC;
        }
        return $flags;
    }

    protected function parseConstDef(mixed $v2): void
    {
        foreach ($v2->consts as $const) {
            $name  = $this->parseIdentifier($const->name);
            $value = $this->parseIdentifier($const->value);
            if ($this->namespace) {
                $name = $this->namespace . '\\' . $name;
            }
            $this->addConstant($name, $value);
        }
    }

    protected function addConstant(string $name, string $value): void
    {
        $constInfo                    = new \stdClass();
        $constInfo->value             = $value;
        $constInfo->type              = $this->detectStrValueType($value);
        $constInfo->namespace = $this->namespace;
        $constInfo->name = $name;
        $this->constants[$this->escapeConstVar($name)] = $constInfo;
    }

    protected function hasConstant(string $name): bool
    {
        return isset($this->constants[$this->escapeConstVar($name)]);
    }

    protected function getConstant(string $name): string
    {
        return $this->escapeConstVar($name);
    }

    protected function getConstantType(string $name): string
    {
        return $this->constants[$this->escapeConstVar($name)]->type;
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

    protected function setBuildDir(string $string): void
    {
        if (!is_dir($string)) {
            mkdir($string, 0777, true);
        }
        $resolved = realpath($string);
        if ($resolved === false) {
            throw new \RuntimeException('Failed to resolve build path: ' . $string);
        }
        $this->buildDir = $resolved;
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
        if (!mb_check_encoding($phpCode, 'UTF-8')) {
            throw new \Exception('File encoding must be UTF-8, got: ' . mb_detect_encoding($phpCode, ['UTF-8', 'ISO-8859-1', 'GBK', 'Shift_JIS'], true) . ' in ' . $file);
        }
        $this->file     = realpath($file);
        $this->dir      = dirname($this->file);
        $this->stubFile = $this->isStubFile($file);

        return $phpCode;
    }

    protected function parseUse(Node\Stmt\Use_ $v2): string
    {
        $code = '';
        foreach ($v2->uses as $use) {
            $id = $this->parseIdentifier($use->name);
            $type = $use->type !== Node\Stmt\Use_::TYPE_UNKNOWN ? $use->type : $v2->type;
            if ($type === Node\Stmt\Use_::TYPE_FUNCTION) {
                $lastIndex = strrpos($id, '\\');
                $fn = substr($id, $lastIndex + 1);
                $ns = substr($id, 0, $lastIndex);
                if ($use->alias) {
                    $this->useFunctions[$use->alias->toString()] = $ns;
                } else {
                    $this->useFunctions[$fn] = $ns;
                }
            } elseif ($type === Node\Stmt\Use_::TYPE_CONSTANT) {
                $lastIndex = strrpos($id, '\\');
                $cn = substr($id, $lastIndex + 1);
                $ns = substr($id, 0, $lastIndex);
                $fullName = $ns . '\\' . $cn;
                if ($use->alias) {
                    $this->useConstants[$use->alias->toString()] = $fullName;
                } else {
                    $this->useConstants[$cn] = $fullName;
                }
            } else {
                $idLower = strtolower($id);
                if ($idLower === 'native_types') {
                    $this->nativeTypes = true;
                } elseif ($idLower === 'decimal_types') {
                    $this->decimalTypes = true;
                } elseif ($idLower === 'bigint_types') {
                    $this->bigintTypes = true;
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

    protected function parseGroupUse(Node\Stmt\GroupUse $node): void
    {
        $prefix = $node->prefix;
        $uses = [];
        foreach ($node->uses as $use) {
            $fullName = Node\Name::concat($prefix, $use->name);
            $uses[] = new Node\UseItem($fullName, $use->alias, $use->type);
        }
        $syntheticUse = new Node\Stmt\Use_($uses, $node->type);
        $this->parseUse($syntheticUse);
    }

    protected function parseErrorSuppress(Expr\ErrorSuppress $expr): string
    {
        $tmpVar = $this->genTmpVarName();
        $this->context->beforeStmtLines[] = 'auto ' . $tmpVar . ' = EG(error_reporting);';
        $this->context->beforeStmtLines[] = 'php::call(' . $this->getFuncPtr('error_reporting') . ', {E_FATAL_ERRORS});';
        $code = $this->parseExpr($expr->expr);
        $this->context->afterStmtLines[] = 'php::call(' . $this->getFuncPtr('error_reporting') . ', {' . $tmpVar . '});';
        return $code;
    }

    protected function checkVar(NodeAbstract $node, string $name, string $defaultType = self::TYPE_VAR): void
    {
        if (!$this->hasVar($name)) {
            $this->addLocalVar($name, $defaultType);
        } else {
            if ($this->getVarType($name) !== $defaultType) {
                $this->fatalError($node, 'Cannot assign value to variable $' . $name . ' of type ' . $this->getVarType($name) . ' with type ' . $defaultType);
            }
        }
    }

    protected function checkVarMustExist(NodeAbstract $node, string $name): void
    {
        if ($this->isVarExpr($node) and !$this->hasVar($name)) {
            $this->errorUndefinedVariable($node);
        }
    }

    protected function checkVarAssignExpr(NodeAbstract $left, string $toType, string $fromType): bool
    {
        if ($toType === self::TYPE_VAR or $fromType === self::TYPE_VAR) {
            return true;
        }
        // 引用当前没有类型信息，按照 var 处理
        if ($toType === self::TYPE_REF or $fromType === self::TYPE_REF) {
            return true;
        }
        // 类型一致，可以互相赋值
        if ($toType === $fromType) {
            return true;
        }
        // 原生类型可以互相转换，由 C++ 底层完成
        if ($this->isNativeType($toType) and $this->isNativeType($fromType)) {
            return true;
        }
        // BigInt/BigFloat/Decimal 与原生类型之间可能发生隐式转换，允许重新赋值
        $bigTypes = [self::TYPE_BIGINT, self::TYPE_DECIMAL, self::TYPE_BIGFLOAT];
        if (in_array($toType, $bigTypes, true) or in_array($fromType, $bigTypes, true)) {
            return true;
        }
        $varName = 'variable';
        if ($this->isVarExpr($left)) {
            $varName = '`$' . $this->parseIdentifier($left) . '`';
        }
        $this->fatalError($left, "Cannot re-assign $varName from `{$fromType}` to `{$toType}`");
    }

    protected function mustNoCall(NodeAbstract $node): void
    {
        $nodeFinder = new NodeFinder();
        $r1 = $nodeFinder->findInstanceOf($node, Expr\StaticCall::class);
        $r2 = $nodeFinder->findInstanceOf($node, Expr\MethodCall::class);
        $r3 = $nodeFinder->findInstanceOf($node, Expr\FuncCall::class);
        if (count($r1) + count($r2) + count($r3) > 0) {
            $this->fatalError($node, 'Calling function or method is not allowed');
        }
    }

    protected function checkAccessible(ClassDef $classDef, int $flags): bool
    {
        // 私有方法，只能当前的类使用
        if ($flags & Modifiers::PRIVATE) {
            return $classDef->namespace === $this->namespace and $classDef->name == $this->class;
        }
        // 保护方法，只能当前类和子类使用
        if ($flags & Modifiers::PROTECTED) {
            if (!$this->classDef) {
                return false;
            }
            return $this->canAccessProtectedProperty(
                $this->classDef->getNamespacedName(false),
                $classDef->getNamespacedName(false)
            );
        }
        // 类外部调用，只允许调用 public 方法
        return true;
    }

    protected function isOverrideMethod(string $fullMethodName): bool
    {
        $fullMethodNameLower = strtolower($fullMethodName);
        return isset($this->classMethodOverride[$fullMethodNameLower]) and $this->classMethodOverride[$fullMethodNameLower];
    }

    protected function getOverrideMethodName(string $class, string $method): string
    {
        if (!$this->hasClass($class) && !$this->hasInterface($class)
            && !$this->isInternalClass($class) && !$this->isInternalInterface($class)) {
            $class = $this->getNamespacedClassName($class);
        }
        return $class . '::' . $method;
    }

    protected function hasSubClasses(string $classNameLower): bool
    {
        return !empty($this->classSubClasses[$classNameLower]);
    }

    protected function isCurrentClassFinal(): bool
    {
        return $this->classDef && ($this->classDef->flags & Modifiers::FINAL) !== 0;
    }

    protected function isFinalClass(string $class): bool
    {
        return $this->hasClass($class) && ($this->getClass($class)->flags & Modifiers::FINAL) !== 0;
    }

    protected function getMethodFlags(string $class, string $method): int
    {
        if (!$this->hasClass($class)) {
            return 0;
        }
        $classDef = $this->getClass($class);
        while (true) {
            $flags = $classDef->getMethodFlags($method);
            if ($flags !== 0) {
                return $flags;
            }
            if (!$classDef->extends || !$this->hasClass($classDef->extends)) {
                return 0;
            }
            $classDef = $this->getClass($classDef->extends);
        }
    }

    protected function guardAbstractMethod(string $class, string $method, Node $expr): void
    {
        $flags = $this->getMethodFlags($class, $method);
        if ($flags & Modifiers::ABSTRACT) {
            $this->fatalError($expr, "Cannot call abstract method `{$class}::{$method}()`");
        }
    }

    /**
     * Determine whether a method call can be devirtualized to a direct native call.
     *
     * Returns true when the exact class is known at compile time:
     *  1. $this->m() in a final class (no subclass possible)
     *  2. $this->m() where m is final (can't be overridden)
     *  3. $this->m() where m is private (not virtual)
     *  4. $obj->m() where obj's class has no known subclasses
     *  5. $obj->m() where obj is SSA-stable and its class is final
     */
    protected function canDevirtualize(string $object, string $class, string $method): bool
    {
        // Case 1: Calling on 'this_' in a final class
        if ($object === 'this_' && $this->isCurrentClassFinal()) {
            return true;
        }

        // Case 2 & 3: Method is final or private
        $flags = $this->getMethodFlags($class, $method);
        if ($flags & (Modifiers::FINAL | Modifiers::PRIVATE)) {
            return true;
        }

        // Case 4: Typed object whose class has no known subclasses
        if ($object !== 'this_' && $this->hasClass($class)) {
            $classLower = strtolower($class);
            if (!$this->hasSubClasses($classLower) && !$this->isInterface($class) && !$this->isAbstractClass($class)) {
                return true;
            }
        }

        // Case 5: SSA stability proves the variable identity, not necessarily
        // the runtime class. Only final classes are exact enough here.
        if ($object !== 'this_' && isset($this->context->stableObjects[$object])) {
            $stableClass = $this->context->stableObjects[$object];
            if ($this->isFinalClass($stableClass)) {
                return true;
            }
        }

        return false;
    }

    protected function findNativeMethod(CallLike $expr, string $object, string $method): string|false
    {
        $classDef = null;
        if ($object === 'this_') {
            $class = $this->getFullClassName();
            $classDef = $this->classDef;
        } elseif (isset($this->context->objects[$object])) {
            $class = $this->context->objects[$object];
            // SSA-stable: use exact type from stableObjects (more specific than declared type)
            if (isset($this->context->stableObjects[$object])) {
                $class = $this->context->stableObjects[$object];
            }
        } else {
            return false;
        }

        $nativeFunc = $this->getNativeMethod($expr, $class, $method);
        // 存在 Native 类，但是没有找到方法，可能是动态调用
        if (!$nativeFunc) {
            if ($this->hasClass($class) and $this->getNativeMethod($expr, $class, '__call', false)) {
                throw new DynamicCall();
            }
        }

        $fullMethodName = $this->getOverrideMethodName($class, $method);

        // 存在子类同名方法，尝试去虚化
        if ($this->isOverrideMethod($fullMethodName)) {
            if (!$this->canDevirtualize($object, $class, $method)) {
                return false;
            }
        }
        if ($nativeFunc) {
            if ($this->hasClass($class) && $this->getMethodFlags($class, $method) & Modifiers::ABSTRACT) {
                return false;
            }
            if ($object !== 'this_' && !isset($this->context->stableObjects[$object])
                && ($this->isAbstractClass($class) || $this->isInterface($class))) {
                return false;
            }
            $this->checkFunction($nativeFunc);
            if ($this->hasFunction($nativeFunc)) {
                return $nativeFunc;
            }
        }
        return false;
    }

    protected function parseNativeMethodCall(string $object, string $nativeFunc, array $args): string
    {
        if ($this->getVarType($object) != self::TYPE_OBJECT) {
            $tmpVar = $this->genTmpVarName();
            $this->context->beforeStmtLines[] = self::TYPE_OBJECT . ' ' . $tmpVar . ' = ' . $object . ';';
            $object = $tmpVar;
        }
        if (count($args) === 0) {
            return self::PREFIX . $nativeFunc . '(' . $object . ')';
        }
        return self::PREFIX . $nativeFunc . '(' . $object . ', ' . $this->parseNativeCallArgs($args, $nativeFunc) . ')';
    }

    protected function parseStdCall(Expr\StaticCall $expr): string
    {
        $func = strtolower($this->parseIdentifier($expr->name));
        $type = match ($func) {
            'int' => self::TYPE_INT,
            'float' => self::TYPE_FLOAT,
            'bool' => self::TYPE_BOOL,
            'bigint' => self::TYPE_BIGINT,
            'decimal' => self::TYPE_DECIMAL,
            'bigfloat' => self::TYPE_BIGFLOAT,
            default => '',
        };
        if ($type) {
            $expr->setAttribute('nativeType', $type);
            $valueExpr = $this->parseExpr($expr->args[0]->value);
            if (in_array($type, [self::TYPE_INT, self::TYPE_FLOAT, self::TYPE_BOOL])) {
                return $this->convertExprFromType($type, $valueExpr);
            }
            $argType = $this->detectTypeOfExpr($expr->args[0]->value);
            if ($argType === $type) {
                return $valueExpr;
            }
            if ($type === self::TYPE_BIGINT) {
                if ($argType === self::TYPE_FLOAT) {
                    $this->fatalError($expr, 'Cannot construct BigInt from float, use string or int instead');
                }
                                if ($argType === self::TYPE_INT) {
                    return 'php::toBigInt(' . $valueExpr . ')';
                }
                return 'php::BigInt::newInstance(' . $valueExpr . ')';
            }
            if ($type === self::TYPE_DECIMAL) {
                if ($argType === self::TYPE_FLOAT) {
                    $argNode = $expr->args[0]->value;
                    if ($argNode instanceof Node\Scalar\Float_) {
                        $rawValue = $argNode->getAttribute('rawValue');
                        $clean = $rawValue !== null ? $this->stripNumericUnderscores($rawValue) : (string)$argNode->value;
                                                return 'php::toDecimal(' . $this->getLiteralString($clean) . ')';
                    }
                    $this->fatalError($expr, 'Cannot construct Decimal from float variable, use string or int instead');
                }
                                if ($argType === self::TYPE_INT) {
                    return 'php::toDecimal(' . $valueExpr . ')';
                }
                return 'php::Decimal::newInstance(' . $valueExpr . ')';
            }
            if ($type === self::TYPE_BIGFLOAT) {
                                if ($argType === self::TYPE_INT) {
                    return 'php::toBigFloat(' . $valueExpr . ')';
                }
                if ($argType === self::TYPE_FLOAT) {
                    return 'php::toBigFloat(' . $valueExpr . ')';
                }
                return 'php::BigFloat::newInstance(' . $valueExpr . ')';
            }
            return $valueExpr;
        } else {
            $this->fatalError($expr, 'Unknown std method: ' . $func);
        }
    }


    protected function parseParentMethodCall(Expr\StaticCall $expr): string
    {
        $methodStr = $this->classDef->name . '::' . $this->parseIdentifier($expr->name);
        if (!$this->classDef->extends) {
            $this->fatalError($expr, 'Cannot call parent method `' . $methodStr . '()` because class `' . $this->classDef->name . '` does not extend any class');
        }
        if (!$this->isIdExpr($expr->name)) {
            $this->fatalError($expr, 'Cannot call parent method `' . $methodStr . '()` because method name is not a literal');
        }
        $parentClass = $this->classDef->extends;
        $method = $this->parseIdentifier($expr->name);
        $this->guardAbstractMethod($parentClass, $method, $expr);
        // TODO 是否转为 native 调用
        if (empty($expr->args)) {
            return 'this_.call(' . $this->getMethodPtr($parentClass, $method) . ')';
        }
        return 'this_.call(' . $this->getMethodPtr($parentClass, $method) . ', ' . $this->parseCallArgs($expr->args) . ')';
    }

    protected function genDebugInfo(?NodeAbstract $stmt = null, string $functionName = '', int $startLine = 0): string
    {
        $code = '';
        if ($this->debug) {
            if ($stmt) {
                $code .= 'php::traceDebugInfo("' . $this->escapeString($this->file) . '", ' . $stmt->getLine() . ');' . PHP_EOL;
            } elseif ($functionName) {
                $code .= 'php::enableDebugInfo();' . PHP_EOL;
                $code .= 'php::pushDebugFrame("' . $this->escapeString($this->file) . '", ' . $startLine . ', "' . $this->escapeString($functionName) . '");' . PHP_EOL;
                $code .= 'ON_SCOPE_EXIT(php::popDebugFrame());' . PHP_EOL;
            } else {
                $code .= 'php::enableDebugInfo();' . PHP_EOL;
            }
        }
        return $code;
    }

    protected function genLocalVarDecl(array $localVars): string
    {
        $code = '';
        foreach ($localVars as $name => $type) {
            if (isset($this->context->arguments[$name])) {
                continue;
            }
            if (isset($this->context->globalVars[$name])) {
                continue;
            }
            $code .= $this->getIndent();
            if ($type === self::TYPE_STD_ARRAY) {
                $info = $this->context->stdArrays[$name];
                if (isset($info['boxExpr'])) {
                    $code .= 'auto &' . $name . '_ref = php::toStdContainer<' . $info['decl'] . '>(' . $info['boxExpr'] . ', ' . $info['typeId'] . ');';
                } else {
                    $containerType = 'php::StdContainerBox<' . $info['decl'] . '>';
                    $code .= 'php::Var ' . $name . ' = php::Var(new ' . $containerType . '(' . $info['typeId'] . '));' . PHP_EOL;
                    $code .= $this->getIndent() . 'auto &' . $name . '_ref = ' . $name . '.toBox<' . $containerType . '>()->container;';
                }
            } elseif ($type === self::TYPE_STD_VECTOR) {
                $info = $this->context->stdContainers[$name];
                if (isset($info['boxExpr'])) {
                    $code .= 'auto &' . $name . '_ref = php::toStdContainer<' . $info['decl'] . '>(' . $info['boxExpr'] . ', ' . $info['typeId'] . ');';
                } else {
                    $containerType = 'php::StdContainerBox<' . $info['decl'] . '>';
                    if ($info['size'] !== null) {
                        $boxCtor = 'new ' . $containerType . '(' . $info['typeId'] . ', ' . $info['size'] . ')';
                    } else {
                        $boxCtor = 'new ' . $containerType . '(' . $info['typeId'] . ')';
                    }
                    $code .= 'php::Var ' . $name . ' = php::Var(' . $boxCtor . ');' . PHP_EOL;
                    $code .= $this->getIndent() . 'auto &' . $name . '_ref = ' . $name . '.toBox<' . $containerType . '>()->container;';
                }
            } elseif ($type === self::TYPE_STD_MAP || $type === self::TYPE_STD_ORDERED_MAP) {
                $info = $this->context->stdContainers[$name];
                if (isset($info['boxExpr'])) {
                    $code .= 'auto &' . $name . '_ref = php::toStdContainer<' . $info['decl'] . '>(' . $info['boxExpr'] . ', ' . $info['typeId'] . ');';
                } else {
                    $containerType = 'php::StdContainerBox<' . $info['decl'] . '>';
                    $code .= 'php::Var ' . $name . ' = php::Var(new ' . $containerType . '(' . $info['typeId'] . '));' . PHP_EOL;
                    $code .= $this->getIndent() . 'auto &' . $name . '_ref = ' . $name . '.toBox<' . $containerType . '>()->container;';
                }
            } elseif ($type === self::TYPE_STREAM || $type === self::TYPE_BIGINT || $type === self::TYPE_DECIMAL || $type === self::TYPE_BIGFLOAT) {
                $code .= self::TYPE_VAR . ' ' . $name . ';';
            } else {
                $code .= $type . ' ' . $name;
                if ($type === self::TYPE_INT or $type === self::TYPE_FLOAT or $type === self::TYPE_BOOL) {
                    $code .= ' = 0';
                }
                $code .= ';';
            }
            $code .= PHP_EOL;
        }
        return $code;
    }

    protected function genScopeVarDecl(): string
    {
        $code = '';
        if ($this->context->hasMultiLevelBreak) {
            $code .= $this->getIndent() . 'int _brk_flag = 0;' . PHP_EOL;
        }
        if ($this->context->hasMultiLevelContinue) {
            $code .= $this->getIndent() . 'int _cnt_flag = 0;' . PHP_EOL;
        }
        $code .= $this->genLocalVarDecl($this->context->localVars);
        foreach ($this->context->globalVars as $name => $type) {
            // $GLOBALS is handled via php_globals_array() at each read site
            if ($name === 'GLOBALS') {
                continue;
            }
            $code .= $this->getIndent() . self::TYPE_VAR . ' &' . $name . ' = ' . $this->escapeGlobalVar($name) . ';' . PHP_EOL;
        }
        foreach ($this->context->objectProps as $name => $info) {
            if (($info['kind'] ?? 'zval') === 'var') {
                $code .= $this->getIndent() . self::TYPE_VAR . ' ' . $name . ' = ' . $info['getter'] . ';' . PHP_EOL;
            } else {
                $zvalMacro = ($info['type'] === self::TYPE_FLOAT) ? 'Z_DVAL_P' : 'Z_LVAL_P';
                $code .= $this->getIndent() . $info['type'] . ' &' . $name . ' = ' . $zvalMacro . '(' . $info['getter'] . '.unwrap_ptr());' . PHP_EOL;
            }
        }
        foreach ($this->context->staticPropRefs as $name => $info) {
            $getter = Symbol::getStaticProperty() . '(' . $info['classPtr'] . ', ' . $info['offsetExpr'] . ')';
            if (($info['kind'] ?? 'zval') === 'var') {
                $code .= $this->getIndent() . self::TYPE_VAR . ' ' . $name . ' = ' . $getter . ';' . PHP_EOL;
            } else {
                $code .= $this->getIndent() . 'zval *' . $name . ' = ' . $getter . '.unwrap_ptr();' . PHP_EOL;
            }
        }
        return $code;
    }

    protected function genReturnCode(): string
    {
        if ($this->shouldCheckClosureReturnType()) {
            return $this->genClosureCheckedReturn(self::VALUE_NULL);
        }
        if ($this->functionDef->returnType === self::TYPE_VOID) {
            return '';
        }
        if ($this->functionDef->returnTypeCheck && !$this->context->inClosure) {
            return $this->genUnionCheckedReturn(self::VALUE_NULL);
        }
        if ($this->functionDef->returnType === self::TYPE_INT
            or $this->functionDef->returnType === self::TYPE_FLOAT
            or $this->functionDef->returnType === self::TYPE_BOOL) {
            return $this->getIndent() . 'return 0;';
        } else {
            return $this->getIndent() . 'return ' . self::VALUE_NULL . ';';
        }
    }

    protected function parseArrowFunction(Expr\ArrowFunction $expr): string
    {
        $nodeFinder = new NodeFinder();
        $vars = $nodeFinder->findInstanceOf($expr->expr, Variable::class);
        $uses = [];
        $params = [];

        foreach ($expr->params as $i => $param) {
            if ($param->byRef) {
                $this->fatalError($expr, 'Closure cannot use reference parameter');
            }
            if ($param->var instanceof Variable) {
                $params[$param->var->name] = $i;
            }
        }

        foreach ($vars as $var) {
            $varName = $this->escapeVarName($this->parseVariable($var));
            if ($varName === 'this_'
                or !$this->hasLocalVar($varName)
                or isset($params[$var->name])
                or isset($uses[$varName])) {
                continue;
            }
            $uses[$varName] = new Node\ClosureUse($var);
        }
        $uses = array_values($uses);

        return $this->genClosure($expr, $expr->params, $uses);
    }

    protected function parseClosure(Expr\Closure $expr): string
    {
        return $this->genClosure($expr, $expr->params, $expr->uses);
    }

    protected function isReturnStmtInLastLine(array $stmts): bool
    {
        if (count($stmts) === 0) {
            return false;
        }
        return $stmts[array_key_last($stmts)] instanceof Node\Stmt\Return_;
    }

    protected function parseNullsafePropertyFetch(Expr\NullsafePropertyFetch $expr): string
    {
        return $this->parseNullsafeExpr($expr);
    }

    protected function parseNullsafeMethodCall(Expr\NullsafeMethodCall $expr): string
    {
        return $this->parseNullsafeExpr($expr);
    }

    protected function parseNullsafeExpr(Expr\NullsafePropertyFetch|Expr\NullsafeMethodCall $expr): string
    {
        $list = [];
        $comment = '// Nullsafe Operator: ' . $this->printer->prettyPrint([$expr]);

        while (1) {
            if ($expr instanceof Expr\NullsafePropertyFetch) {
                $list[] = ['property', $this->identifierToStr($expr->name, literal: true), $expr];
                $expr = $expr->var;
            } elseif ($expr instanceof Expr\NullsafeMethodCall) {
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
        $this->checkNullsafePropertyAccesses($expr, $list);
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
                $beforeStmtCount = count($this->context->beforeStmtLines);
                $afterStmtCount = count($this->context->afterStmtLines);
                $args = $this->parseCallArgs($item[2]);
                $argBeforeStmts = array_slice($this->context->beforeStmtLines, $beforeStmtCount);
                $argAfterStmts = array_slice($this->context->afterStmtLines, $afterStmtCount);
                $this->context->beforeStmtLines = array_slice($this->context->beforeStmtLines, 0, $beforeStmtCount);
                $this->context->afterStmtLines = array_slice($this->context->afterStmtLines, 0, $afterStmtCount);
                if ($argBeforeStmts) {
                    $code .= $this->getIndent() . implode(PHP_EOL . $this->getIndent(), $argBeforeStmts) . PHP_EOL;
                }
                $code .= $this->getIndent() . "{$tmpVar} = {$object}.call({$item[1]}, {$args});";
                if ($argAfterStmts) {
                    $code .= $this->getIndent() . implode(PHP_EOL . $this->getIndent(), $argAfterStmts) . PHP_EOL;
                }
            }
            $object = $tmpVar;
        }
        $code .= $this->getIndent() . "return {$object}; };";
        $this->context->beforeStmtLines[] = $code;
        return "{$tmpFn}()";
    }

    private function checkNullsafePropertyAccesses(NodeAbstract $baseExpr, array $list): void
    {
        $properties = [];
        foreach ($list as $item) {
            if ($item[0] !== 'property') {
                break;
            }

            /** @var Expr\NullsafePropertyFetch $node */
            $node = $item[2];
            if (!$this->isIdExpr($node->name)) {
                break;
            }

            $properties[] = [
                'node' => $node,
                'property' => $this->parseIdentifier($node->name),
            ];
        }

        if (!$properties) {
            return;
        }

        $scope = $this->class ? $this->getFullClassName() : '';
        $results = $this->createPropertyAccessResolver()->resolveNullsafePropertyChain(
            $this->detectClassOfExpr($baseExpr),
            $properties,
            $scope,
            self::TYPE_OBJECT,
        );
        foreach ($results as $index => $result) {
            $this->applyNativePropertyAccessResult($properties[$index]['node'], $result);
        }
    }

    protected function parseFullyQualifiedName(Node\Name\FullyQualified $expr): string
    {
        return $expr->name;
    }

    /**
     * 混杂数组赋值，需要拆分为多行插入
     */
    private function parseArrayMixed(Expr\Array_ $node): string
    {
        $tmpVar = $this->genTmpVarName();
        $this->addLocalVar($tmpVar, self::TYPE_ARRAY);
        // 释放临时变量，避免修改数组产生数组复制操作
        $this->context->beforeStmtLines[] = $this->getIndent() . $tmpVar . '.clean();';

        $items = $node->items;
        foreach ($items as $item) {
            $this->assertExprCanBeUsedAsValue($item->value, $item->unpack ? 'array unpack value' : 'array value');
            $value = $this->parseIdentifier($item->value);
            if ($item->unpack) {
                $this->context->beforeStmtLines[] = $this->getIndent() . $tmpVar . '.merge(' . $value . ');';
            } elseif ($item->key) {
                $this->assertExprCanBeUsedAsValue($item->key, 'array key');
                $key = $this->parseArrayKey($item->key);
                $this->context->beforeStmtLines[] = $this->getIndent() . $tmpVar . '.set(' . $key . ', ' . $value . ');';
            } else {
                $this->context->beforeStmtLines[] = $this->getIndent() . $tmpVar . '.append(' . $value . ');';
            }
        }

        return $tmpVar;
    }
}
