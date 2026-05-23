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
use PhpAot\Php\Generator\ClosureGenerator;
use PhpAot\Php\Generator\PlaceHolderGenerator;
use PhpAot\Php\Generator\PropertyPromotion;
use PhpAot\Php\Generator\Utils;
use PhpAot\Php\Parser\StdContainerParser;
use PhpAot\Php\Platform\Linux;
use PhpAot\Php\Platform\Macos;
use PhpAot\Php\Platform\PlatformBase;
use PhpAot\Php\Platform\PlatformFactory;
use PhpAot\Php\Platform\Windows;
use PhpParser\Modifiers;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\FunctionLike;
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

class CompilerBase extends \PhpAot\Core\Translator
{
    use AstNodeType;
    use FuncCallOptimizer;
    use ClosureGenerator;
    use PlaceHolderGenerator;
    use PropertyPromotion;
    use MagicMethodDetector;
    use StdContainerParser;
    use Utils;

    public const string TYPE_VAR = 'php::Var';

    public const string TYPE_BOOL = 'php::Bool';

    public const string TYPE_INT = 'php::Int';

    public const string TYPE_FLOAT = 'php::Float';
    public const string TYPE_OBJECT = 'php::Object';
    public const string TYPE_ARRAY = 'php::Array';
    public const string TYPE_STD_ARRAY = 'php::StdArray';
    public const string TYPE_STD_VECTOR = 'php::StdVector';
    public const string TYPE_STD_MAP = 'php::StdMap';
    public const string TYPE_STD_UNORDERED_MAP = 'php::StdUnorderedMap';
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
    // 超过65536字节的数组，将从栈上转移到堆
    public const int MAX_BYTES_IN_STACK = 65536;
    protected string $lang = 'PHP';
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
        // 编译器符号类型，C++ 层仍使用 php::Var 承载 null zval 中的 value.ptr
        'UnsafePtr' => self::TYPE_VAR,
    ];
    protected array $globalHeaders = [
        'phpx.h',
        'phpx_helper.h',
        'phpx_func.h',
        'php_aot_helper.h',
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
    protected string $buildMode = 'bin';
    protected string $cxxflags = '';
    protected string $cxxStd = 'c++17';
    protected string $ldflags = '';
    protected int $floatPrecision = 17;
    protected bool $debug = false;
    protected bool $formatCode = true;
    protected bool $printBacktraceOnError = true;
    protected bool $noLiteralStrings = false;
    protected bool $noConsole = false;  // Windows: hide console window
    protected string $sanitize = '';    // Sanitizer type (address, undefined, etc.)
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
    ];
    protected array $globalVars = [];
    protected bool $strictTypes = false;
    protected bool $nativeTypes = false;
    protected string $rootPath;
    protected string $buildDir;
    protected int $debugLine = 0;
    protected CLImate $climate;
    protected bool $stubFile = false;
    protected bool $enableProfiler = false;
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

    /**
     * 检测操作系统、编译器以及 Windows 平台的 PHP lib 文件
     */
    protected function detectPlatform(): void
    {
        try {
            $this->platform = PlatformFactory::create();
            $this->cppCompiler = CompilerFactory::detectCompilerName($this->platform);

            if ($this->platform instanceof Windows) {
                $libInfo = $this->platform->detectPhpLibs($this->getPhpDir());
                $this->windowsPhpEmbedLib = $libInfo['embed'];
                $this->windowsPhpCoreLib = $libInfo['core'];
                $this->isPhpZts = $libInfo['is_zts'];

                $this->platform = new Windows(
                    phpLibs: [$this->windowsPhpCoreLib, $this->windowsPhpEmbedLib],
                    isZts: $this->isPhpZts,
                    phpSdkPath: $this->getPhpDir() . '\\SDK'
                );
            }

            $this->compilerBackend = CompilerFactory::createByName($this->cppCompiler, $this->platform);
            $this->climate->info(
                "Initialized platform/backend: {$this->platform->getName()} + {$this->compilerBackend->getName()} ({$this->compilerBackend->getCompilerCommand()})"
            );
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
        }
    }
    
    /**
     * 设置 C++ 编译器（从配置文件读取）
     */
    public function setCppCompiler(string $compiler): void
    {
        $this->cppCompiler = $compiler;
        $this->climate->info("Using compiler from config: {$this->cppCompiler}");
        
        // 重新初始化 Backend
        $this->initializeNewArchitecture();
    }
    
    /**
     * 初始化新的 Platform 和 Backend 抽象层
     * 这是一个渐进式迁移，保持向后兼容
     */
    protected function initializeNewArchitecture(): void
    {
        try {
            $platform = $this->platform ?? PlatformFactory::create();
            $this->platform = $platform;

            // 自动检测平台和编译器
            $result = CompilerFactory::autoDetect($this->cppCompiler, $platform);
            $this->platform = $result['platform'];
            $this->compilerBackend = $result['compiler'];
            
            $this->climate->info(
                "Initialized new architecture: {$this->platform->getName()} + {$this->compilerBackend->getName()}"
            );
        } catch (\Exception $e) {
            // 如果初始化失败，回退到旧逻辑
            $this->climate->warning(
                "Failed to initialize new architecture: {$e->getMessage()}. Using legacy mode."
            );
            $this->platform = null;
            $this->compilerBackend = null;
        }
    }

    protected function getPhpxDir(): string
    {
        // 优先使用环境变量 PHPX_HOME
        $phpxDir = getenv('PHPX_HOME');
        if ($phpxDir && is_dir($phpxDir)) {
            return rtrim($phpxDir, '\/');
        }

        // 尝试使用 Composer 安装的 phpx
        $composerPhpxDir = $this->rootPath . '/vendor/swoole/phpx';
        if (is_dir($composerPhpxDir)) {
            return $composerPhpxDir;
        }

        $composerPhpxVendorDir = $this->rootPath . '/vendor/swoole/phpx-vendor';
        if (is_dir($composerPhpxVendorDir)) {
            return $composerPhpxVendorDir;
        }

        if (defined('ROOT_PATH')) {
            $rootPhpxDir = ROOT_PATH . '/vendor/swoole/phpx';
            if (is_dir($rootPhpxDir)) {
                return $rootPhpxDir;
            }

            $rootPhpxVendorDir = ROOT_PATH . '/vendor/swoole/phpx-vendor';
            if (is_dir($rootPhpxVendorDir)) {
                return $rootPhpxVendorDir;
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

    public function getPhpDir(): string
    {
        try {
            return $this->getPlatform()->getPhpDir();
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());
        }
    }

    public function save(string $code, string $file): void
    {
        $this->writeFile($file, $code);
        $this->formatCppCode($file);
    }

    public function isScalarInt(Expr $position): bool
    {
        return $position instanceof Node\Scalar\LNumber;
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
            case 'Expr_Variable':
                return $this->parseIdentifier($expr);
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

    public function getRelativePath($path, $cwd = ''): string
    {
        $cwd = $cwd ?: getcwd();
        return ltrim($this->removeCommonPrefix($cwd, $path), '/');
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
        return $this->getPlatform()->removeCommonPrefix($short, $long);
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
        $this->classDef  = null;
    }

    protected function resetFile(): void
    {
        $this->indentLevel = 0;
        $this->strictTypes = false;
        $this->nativeTypes = false;
        $this->classesDefineInFile = [];
        $this->interfacesDefineInFile = [];
        $this->functionDefineInFile = [];
    }

    protected function resetNamespace(): void
    {
        $this->useNamespaces = [];
        $this->useAliases = [];
        $this->useFunctions = [];
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

    public function getNamespacedClassName(string $class): string
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

        $currentNamespace = $this->namespace;
        if (!empty($currentNamespace)) {
            return trim($currentNamespace, '\\') . '\\' . $class;
        }

        return $class;
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
        if ($type instanceof UnionType or $type instanceof NullableType) {
            // 联合类型暂时不支持，使用 var 类型代替
            return self::TYPE_VAR;
        } else {
            $typeName = $this->parseIdentifier($type);
            // 属性和类常量的类型不能声明为 void/never ，只有返回值可以
            if ($what !== self::DECL_TYPE_OF_RETURN and ($typeName === 'void' or $typeName === 'never')) {
                $this->fatalError($type, 'The type `void`/`never` is allowed only for return type');
            } elseif (isset($this->zendTypeMap[$typeName])) {
                return $this->getTypeFromZendType($typeName);
            } else {
                if ($typeName === 'self') {
                    $class = $this->getFullClassName();
                } elseif ($typeName === 'parent') {
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

    /**
     * @throws \Exception
     */
    protected function parseFunction(Node\Stmt\Function_|Node\Stmt\ClassMethod $v): string
    {
        $this->resetFunction();
        $name = $this->getFunctionName($v);
        $this->function = $this->parseIdentifier($v->name);

        if (!$this->hasFunction($name)) {
            $this->fatalError($v, 'Function `' . $name . '` not found');
        }
        $this->functionDef = $this->getFunction($name);

        // 类方法不要保存到 functions 中
        if ($this->methodDef) {
            $this->methodDef->functionDef = $this->functionDef;
        } else {
            $this->functionDefineInFile[$name] = $this->functionDef;
        }

        // stub 函数，没有函数的具体实现，只有声明，实现在 C++ 或者 .so 中定义
        if ($this->functionDef->stub) {
            $this->resetFunction();
            return '';
        }

        if ($this->class) {
            $this->addArgument('this_', self::TYPE_OBJECT);
        }
        foreach ($this->functionDef->argInfoList as $argInfo) {
            $this->addArgument($argInfo->name, $argInfo->type);
            if ($argInfo->class
                and !$this->isAbstractClass($argInfo->class)
                and !$this->hasInterface($argInfo->class)
                and !$this->isInternalClass($argInfo->class)) {
                $this->addObject($argInfo->name, $argInfo->class);
            }
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
        } else {
            $stmts = $this->genReturnCode();
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
        $code .= $this->genScopeVarDecl();
        $code .= "\n";
        // Constructor Property Promotion
        foreach ($this->functionDef->argInfoList as $argInfo) {
            if (!$argInfo->property) {
                continue;
            }
            $code .= $this->genPropertyPromotion($argInfo);
        }
        $this->indentLevel--;
        // 构建 PHP 级别的函数名用于 debug backtrace
        if ($this->class) {
            $debugName = $this->class . '::' . $this->function;
        } else {
            $debugName = $this->function;
            if ($this->namespace) {
                $debugName = $this->namespace . '\\' . $debugName;
            }
        }
        $code .= $this->genDebugInfo(null, $debugName, $v->getStartLine());

        // 函数中存在动态调用的函数，需要在运行时动态切换作用域
        if ($this->methodDef and $this->methodDef->hasDynamicCall) {
            $code .= $this->genScopeSwitchCode();
        }

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

    protected function parseScalar(Node\Scalar $expr): string
    {
        $type = $expr->getType();
        switch ($type) {
            case 'Scalar_Int':
                return $expr->value . $this->getPlatform()->getIntegerLiteralSuffix();
            case 'Scalar_Float':
                return $this->parseScalarFloat($expr);
            case 'Scalar_String':
                return $expr->hasAttribute('noLiteralString') ? $this->genCharPtr($expr->value, true) : $this->getLiteralString($expr->value);
            default:
                abort($expr);
                break;
        }
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
            $list[] = $this->getNamespacedClassName($implement);
        }
        return $list;
    }

    protected function parseArrayKey(NodeAbstract $expr): string
    {
        $key = $this->parseIdentifier($expr);
        if (str_starts_with($key, self::LITERAL_STRINGS)) {
            $key = "{$key}.str()";
        } elseif ($key === '0L') {
            // 0 在 C++ 中是一个特殊的值，存在二义性，既是空指针，也是整数，这会导致产生 ambiguous 错误
            // 必须转为 php::zero 常量，保证作为 key 时正确匹配到 IntKeyMap
            $key = self::VALUE_ZERO;
        }
        return $key;
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
                case 'Stmt_Class':
                    $this->fatalError($v, 'Cannot declare class in function');
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

    protected function parseRightAssociativeAssign(NodeAbstract $left, Expr\Assign $right): string
    {
        $chain[] = $left;
        $next    = $right;
        while ($this->isAssignExpr($next)) {
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
        $rightVar  = new Variable($tmpVar);
        foreach ($chain as $var) {
            $list[] = $this->getIndent() . $this->parseAssignFinally($var, $rightVar);
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
        $class = $this->identifierToStr($left->class);
        $propName = $this->identifierToStr($left->name);
        return Symbol::setStaticProperty() . "({$class}, {$propName}, {$value})";
    }

    protected function parseAssign(Expr\Assign $v): string
    {
        $left  = $v->var;
        $right = $v->expr;
        if ($this->isAssignExpr($right)) {
            return $this->parseRightAssociativeAssign($left, $right);
        }
        return $this->parseAssignFinally($left, $right);
    }

    protected function parseAssignToList(Expr $left, Expr $right): string
    {
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
            if ($item instanceof Expr\ArrayItem) {
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

    protected function parseAssignFinally(Expr $left, Expr $right): string
    {
        if ($left instanceof Expr\List_) {
            return $this->parseAssignToList($left, $right);
        }

        $oriInAssignExpr = $this->context->inAssignExpr;
        $this->context->inAssignExpr = true;
        $var = $this->parseIdentifier($left);
        $this->context->inAssignExpr = $oriInAssignExpr;
        if ($var === 'this_') {
            $this->fatalError($left, 'Cannot re-assign $this');
        }
        if ($this->isVarExpr($left) and $this->isUnsafePtrParameter($var)) {
            $this->fatalError($left, "Cannot re-assign UnsafePtr parameter `\${$var}`");
        }

        $type = $this->detectTypeOfExpr($right);

        if ($this->isVarExpr($left)) {
            if ($this->isStdContainer($var)) {
                $copyAssign = $this->parseStdContainerCopyAssign($var, $right);
                if ($copyAssign !== null) {
                    return $copyAssign;
                }
            }
            // 类型推断，获取对象的类名，如果不是对象则返回空字符串
            $rightClass = $this->detectClassOfExpr($right);
            // 右值是一个对象，已获得类的名称，左值必须与右值的类一致
            if ($rightClass) {
                if (!$this->hasVar($var)) {
                    $this->addLocalVar($var, self::TYPE_OBJECT);
                    // TODO 返回值类型是一个接口，只能作为 var 变量，无法作为 TypedObject
                    if (!$this->hasInterface($rightClass) and !$this->isAbstractClass($rightClass)) {
                        $this->addObject($var, $rightClass);
                    }
                } elseif ($this->isTypedObject($var)) {
                    $leftClass = $this->getObjectType($var);
                    // 对象的类不一致，不能互相赋值，必须使用 objval() 对齐类型
                    // 注意这里必须使用绝对相等比较，即使存在继承关系，类的方法也可能不一致
                    if ($leftClass !== $rightClass) {
                        $this->fatalError($left, "Cannot re-assign typed object `\${$var}` from `{$leftClass}` to `{$rightClass}`");
                    }
                } else {
                    if ($this->getVarType($var) === self::TYPE_ARRAY) {
                        $this->fatalError($left, "Cannot re-assign `\${$var}` from object to array");
                    }
                    // TODO 右值是一个类型对象，但左值是一个 var ，许可，但无法标记对象类型
                }
            } else {
                if ($this->isFuncCallExpr($right) and $this->isNameExpr($right->name)) {
                    $fn = $this->parseIdentifier($right->name);
                    if (count($right->args) === 1 and $fn === 'any') {
                        $type = self::TYPE_VAR;
                        if (!$this->hasVar($var)) {
                            $this->addLocalVar($var, $type);
                        }
                        return $var . ' = ' . $this->parseIdentifier($right->args[0]->value);
                    } else {
                        $type = $type === self::TYPE_VOID ? self::TYPE_VAR : $type;
                    }
                } elseif ($this->isStaticCall($right) and $this->isNameExpr($right->class) and $this->isIdExpr($right->name)) {
                    $class = $this->parseIdentifier($right->class);
                    if ($class === 'std') {
                        if ($right->name->toString() === 'unsafe_cast') {
                            if ($this->hasVar($var)) {
                                $this->fatalError($left, "Cannot re-assign `\${$var}` to std::unsafe_cast()");
                            }
                            if ($this->context->scopeLevel > 1) {
                                $this->fatalError($left, 'Must use std::unsafe_cast() in the top-level scope of the function');
                            }
                            return $this->parseStdUnsafeCastAssign($var, $right);
                        } elseif (in_array($right->name->toString(), ['array', 'vector', 'map', 'unordered_map'], true)) {
                            if ($this->hasVar($var)) {
                                $this->fatalError($left, "Cannot re-assign `\${$var}` to std::{$right->name->toString()}");
                            }
                            if ($this->context->scopeLevel > 1) {
                                $this->fatalError($left, "Must create std::{$right->name->toString()} in the top-level scope of the function");
                            }
                            if ($right->name->toString() === 'array') {
                                $this->addLocalVar($var, self::TYPE_STD_ARRAY);
                                return $this->parseStdArray($var, $right);
                            }
                            if ($right->name->toString() === 'vector') {
                                $this->addLocalVar($var, self::TYPE_STD_VECTOR);
                                return $this->parseStdVector($var, $right);
                            }
                            if ($right->name->toString() === 'map') {
                                $this->addLocalVar($var, self::TYPE_STD_MAP);
                                return $this->parseStdMap($var, $right);
                            }
                            $this->addLocalVar($var, self::TYPE_STD_UNORDERED_MAP);
                            return $this->parseStdUnorderedMap($var, $right);
                        } else {
                            $valueExpr = $this->parseStdCall($right);
                            if (!$this->hasVar($var)) {
                                $this->addLocalVar($var, $right->getAttribute('nativeType'));
                            }
                            return $var . ' = ' . $valueExpr;
                        }
                    }
                } elseif ($this->isVarExpr($right)) {
                    $rightVar = $this->parseIdentifier($right);
                    $type = $this->isStdContainer($rightVar) ? self::TYPE_ARRAY : $this->getVarType($rightVar);
                    if ($this->isTypedObject($rightVar) and $this->isTypedObject($var)) {
                        $leftClass = $this->getObjectType($var);
                        $rightClass = $this->getObjectType($rightVar);
                        $this->fatalError($left, "Cannot re-assign typed object `\${$var}` from `{$leftClass}` to `{$rightClass}`");
                    }
                }
                if (!$this->hasVar($var)) {
                    $this->addLocalVar($var, $type);
                } else {
                    $this->checkVarAssignExpr($left, $this->getVarType($var), $type);
                }
            }
        } elseif ($this->isPropertyFetch($left) and !$left->getAttribute('nativeProperty')) {
            return $this->parseAssignPropertyFetch($left, $right);
        } elseif ($this->isArrayDimFetch($left) and $this->isVarExpr($left->var)) {
            $tmp = $this->parseIdentifier($left->var);
            if ($this->getVarType($tmp) === self::TYPE_STR and $left->dim === null) {
                $this->fatalError($left, 'Cannot use [] for strings');
            }
            if ($this->isStdContainerExpr($left)) {
                return $this->parseStdContainerAssign($left, $right);
            }
            return $this->parseAssignArrayDim($left, $right);
        }

        $rightExpr = $this->parseAssignRightExpr($right);
        return $var . ' = ' . $this->convertExprType($rightExpr, $this->detectTypeOfExpr($left), $this->detectTypeOfExpr($right));
    }

    protected function parseStdContainerCopyAssign(string $leftVar, Expr $right): ?string
    {
        $rightInfo = $this->getStdContainerExprInfo($right);
        if ($rightInfo === null) {
            return null;
        }

        $leftInfo = $this->getStdContainerVarInfo($leftVar);
        if (!$this->isSameStdContainerInfo($leftInfo, $rightInfo)) {
            $this->fatalError($right, 'Cannot copy std container with different type');
        }

        return $leftVar . ' = ' . $this->parseStdContainerCopyExpr($right);
    }

    protected function parseAssignRightExpr(Expr $right): string
    {
        $rightExpr = $this->parseExpr($right);
        if ($this->isVarExpr($right)) {
            $rightVar = $this->parseIdentifier($right);
            if ($this->isStdContainer($rightVar)) {
                return $this->convertArrayExpr($rightExpr);
            }
        }
        return $rightExpr;
    }

    protected function parseEcho(mixed $v): string
    {
        $lines = [];
        foreach ($v->exprs as $expr) {
            if ($expr instanceof Expr\Assign) {
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

    protected function isInternalFunction(string $name): bool
    {
        return array_key_exists($name, $this->internalFunctions);
    }

    protected function isInternalClass(string $name): bool
    {
        return Reflection::isInternalClass($name);
    }

    protected function isAbstractClass(string $name): bool
    {
        if ($this->isInternalClass($name)) {
            return Reflection::isAbstractClass($name);
        }
        if ($this->hasClass($name)) {
            $classDef = $this->getClass($name);
            return $classDef->isAbstract();
        }
        return false;
    }

    protected function isInternalInterface(string $name): bool
    {
        return Reflection::isInternalInterface($name);
    }

    protected function isInternalConstant(string $name): bool
    {
        return array_key_exists($name, $this->internalConstants);
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

    protected function parseBinaryOp(NodeAbstract $left, NodeAbstract $right, string $op): string
    {
        // 运算逻辑，优先转为数字
        $leftExpr  = $this->parseNumericIdentifier($left);
        $rightExpr = $this->parseNumericIdentifier($right);

        $this->checkVarMustExist($left, $leftExpr);
        $this->checkVarMustExist($right, $rightExpr);

        $leftType  = $this->detectTypeOfExpr($left);
        $rightType = $this->detectTypeOfExpr($right);

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

    protected function parseBinaryOpPlus(Expr\BinaryOp\Plus $expr): string
    {
        return $this->parseBinaryOp($expr->left, $expr->right, '+');
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
        if ($this->isFuncCallExpr($expr) and $this->isNameExpr($expr->name)) {
            $fn = $this->parseIdentifier($expr->name);
            if (count($expr->args) === 2 and $fn === 'objval') {
                $argClass = $expr->args[1]->value;
                if ($this->isScalarString($argClass)) {
                    return $this->getNamespacedClassName($argClass->value);
                }
                if ($this->isClassConstFetch($argClass)) {
                    if ($this->isNameExpr($argClass->class) and $this->isIdExpr($argClass->name) and $this->parseIdentifier($argClass->name) === 'class') {
                        return $this->getNamespacedClassName($this->parseIdentifier($argClass->class));
                    }
                } else {
                    $this->fatalError($expr, 'The second parameter of objval() function only supports string literals or `ClassName::class` constant');
                }
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
        if ($this->isMethodCall($expr) and $this->isVarExpr($expr->var) and $this->isNamedMethod($expr->name)) {
            $object = $this->parseVariable($expr->var);
            try {
                $nativeFunc = $this->findNativeMethod($expr, $object, $this->parseIdentifier($expr->name));
                if ($nativeFunc) {
                    return $this->getFunction($nativeFunc)->returnClass;
                }
            } catch (DynamicCall) {
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
            $nativeFunc = $this->getNativeMethod($expr, $class, $method);
            if ($nativeFunc) {
                return $this->getFunction($nativeFunc)->returnClass;
            }
        }
        return '';
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
        $type = $this->detectTypeOfExpr($v->expr);
        $expr = $this->parseExpr($v->expr);
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
            if ($objectClass and $objectClass !== $returnClass and !$this->isAbstractClass($returnClass) and !$this->hasInterface($returnClass)) {
                $this->fatalError($v, "When returning a subclass `$objectClass` instance as parent type, the parent class `$returnClass` must be abstract/interface");
            }
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

    protected function parseBinaryOpMul(Expr\BinaryOp\Mul $expr): string
    {
        return $this->parseBinaryOp($expr->left, $expr->right, '*');
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
        if ($this->hasInterface($class) or $this->isAbstractClass($class)) {
            $this->error("Cannot create an instance of abstract/interface `$class`");
        }
        $this->context->objects[$name] = $class;
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

    protected function hasClass(string $name): bool
    {
        return array_key_exists($this->escapeClass($name), $this->classes);
    }

    protected function hasInterface(string $name): bool
    {
        return array_key_exists($this->escapeClass($name), $this->interfaces);
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
        $argc = count($args);
        $type = str_contains($name, '::') ? 'Method' : 'Function';
        if ($argc < $funcDef->argCountRequired) {
            $this->fatalError($expr, $type . ' `' . $name . '()` requires ' . $funcDef->argCountRequired . ' arguments, ' . $argc . ' given');
        } elseif (!$funcDef->hasVariadicArg() and count($expr->args) > count($funcDef->argInfoList)) {
            $this->fatalError($expr, $type . ' `' . $name . '()` accepts ' . count($funcDef->argInfoList) . ' arguments, ' . $argc . ' given');
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
        $constDef = null;
        // 递归查找，若子类中未定义方法，则尝试查找父类是否存在此方法
        while (true) {
            if (!$classDef->hasConstant($const)) {
                if (!$classDef->extends) {
                    return false;
                }
                if (!$this->hasClass($classDef->extends)) {
                    return false;
                }
                $classDef = $this->getClass($classDef->extends);
            } else {
                $constDef = $classDef->getConstant($const);
                break;
            }
        }
        if (!$this->checkAccessible($classDef, $constDef->flags)) {
            $this->fatalError($expr, 'Constant `' . $classDef->getNamespacedName() . '::' . $const . '` is not accessible');
        }
        if ($constDef->type === self::TYPE_ARRAY) {
            return self::PREFIX . $this->getNativeName($constDef->name, $classDef->namespace, $classDef->name);
        } else {
            $expr->setAttribute('nativeConst', $constDef);
            return $constDef->value;
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
                if ($this->isNameExpr($expr->name)) {
                    $name = $this->parseIdentifier($expr->name);
                    if ($this->hasFunction($name)) {
                        return $this->getFunction($name)->returnType;
                    }
                    return $this->detectFuncCallReturnType($name);
                }
                break;
            case 'Expr_MethodCall':
                if ($this->isVarExpr($expr->var) and $this->isNamedMethod($expr->name)) {
                    $object = $this->parseIdentifier($expr->var);
                    $method = $this->parseIdentifier($expr->name);
                    $nativeFunc = $this->findNativeMethod($expr, $object, $method);
                    if ($nativeFunc) {
                        $funcDef = $this->functions[$nativeFunc];
                        return $funcDef->returnType;
                    }
                    if ($this->isTypedObject($object)) {
                        return $this->detectMethodCallReturnType($this->getObjectType($object), $method);
                    }
                }
                break;
            case 'Expr_PropertyFetch':
                if ($this->isVarExpr($expr->var) and $this->isIdExpr($expr->name)) {
                    $this->parsePropertyFetch($expr);
                    if ($expr->getAttribute('nativePropertyVar')) {
                        $propVar = $expr->getAttribute('nativePropertyVar');
                        $info = $this->context->objectProps[$propVar];
                        return $info['type'];
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
            $value = $this->parseIdentifier($item->value);
            if ($item->key) {
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

    protected function parseParameterType(Node\Param $param, ArgInfo $argInfo, string $var): string
    {
        if ($param->byRef) {
            return self::TYPE_REF;
        }
        if ($this->isUnsafePtrTypeDecl($param->type)) {
            $argInfo->unsafePtr = true;
            return self::TYPE_VAR;
        }
        $class = '';
        $type = $this->parseTypeDecl($param->type, self::DECL_TYPE_OF_PARAM, $class);
        if ($class and !$this->hasInterface($class) and !$this->isAbstractClass($class)) {
            $this->addObject($var, $class);
            $argInfo->class = $class;
        }
        return $type;
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
            if ($this->buildMode === 'bin') {
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
        } else {
            // Linux/macOS: extension 和 bin 模式都需要添加 php 库
            $libraries[] = 'php';
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


    /**
     * 添加编译选项
     */
    protected function addCompilationOption(string &$cmd, bool $link): void
    {
        if (!$link) {
            // 编译时选项
            
            // 先添加包含路径（平台相关）
            $cmd .= ' ' . $this->parseIncludes();
            
            // 再添加编译选项（编译器相关）
            $config = [
                'optimize' => $this->optimizeLevel,
                'debug' => $this->debug,
                'sanitize' => $this->sanitize,
                'cpp_std' => $this->cxxStd,
                'is_zts' => $this->isPhpZts,
                'build_mode' => $this->buildMode,
                'enable_profiler' => $this->enableProfiler,
                'suppressed_warnings' => Constants::MSVC_SUPPRESSED_WARNINGS ?? [],
                'cxxflags' => $this->cxxflags,
            ];
            
            $cmd .= $this->getCompilerBackend()->buildCompileOptions($config);
        } else {
            // 链接时选项
            
            // 先添加库路径（平台相关）
            $cmd .= ' ' . $this->parseLdflags();
            
            // 再添加链接选项（编译器相关）
            $config = [
                'debug' => $this->debug,
                'no_console' => $this->noConsole,
                'build_mode' => $this->buildMode,
                'sanitize' => $this->sanitize,
            ];
            
            // 添加 RPATH（通过 Platform 层获取，仅 macOS 需要）
            $rpaths = $this->getPlatform()->getDefaultRpaths(
                $this->getPhpxDir(),
                $this->getPhpDir()
            );
            if (!empty($rpaths)) {
                $config['rpath'] = $rpaths;
            }
            
            $cmd .= $this->getCompilerBackend()->buildLinkOptions($config);
            
            // 最后添加库文件（平台相关，必须在链接选项之后）
            $cmd .= ' ' . $this->parseLibs();
        }
    }

    protected function getCompileCommandOptions(): array
    {
        return [
            'include_paths' => $this->getIncludePaths(),
            'optimize' => $this->optimizeLevel,
            'debug' => $this->debug,
            'sanitize' => $this->sanitize,
            'cpp_std' => $this->cxxStd,
            'is_zts' => $this->isPhpZts,
            'build_mode' => $this->buildMode,
            'enable_profiler' => $this->enableProfiler,
            'suppressed_warnings' => Constants::MSVC_SUPPRESSED_WARNINGS ?? [],
            'cxxflags' => $this->cxxflags,
        ];
    }

    protected function getCCompileCommandOptions(): array
    {
        return [
            'include_paths' => $this->getIncludePaths(),
            'optimize' => 0,
            'debug' => $this->debug,
            'is_zts' => $this->isPhpZts,
            'suppressed_warnings' => ['4244', '4146'],
        ];
    }

    /**
     * 获取原生源文件（汇编/ObjC 等）的编译选项，不含 C++ 特定标志.
     *
     * @param string $language 语言标识（assembler, objective-c, objective-c++）
     */
    protected function getNativeCompileCommandOptions(string $language = ''): array
    {
        return [
            'include_paths' => $this->getIncludePaths(),
            'optimize' => $this->optimizeLevel,
            'debug' => $this->debug,
            'sanitize' => $this->sanitize,
            'is_zts' => $this->isPhpZts,
            'build_mode' => $this->buildMode,
            'enable_profiler' => $this->enableProfiler,
            'suppressed_warnings' => Constants::MSVC_SUPPRESSED_WARNINGS ?? [],
        ];
    }

    protected function getLinkCommandOptions(): array
    {
        $options = [
            'library_paths' => $this->getLibraryPaths(),
            'libraries' => $this->getLibraries(),
            'ldflags' => $this->ldflags,
            'debug' => $this->debug,
            'no_console' => $this->noConsole,
            'build_mode' => $this->buildMode,
            'sanitize' => $this->sanitize,
        ];

        $rpaths = $this->getPlatform()->getDefaultRpaths(
            $this->getPhpxDir(),
            $this->getPhpDir()
        );
        if (!empty($rpaths)) {
            $options['rpath'] = $rpaths;
        }

        return $options;
    }

    protected function getTargetFileName(): string
    {
        $targetFile = $this->targetName;
        $extension = $this->getPlatform()->getTargetExtension($this->buildMode);

        if ($extension !== '' && !str_ends_with($targetFile, $extension)) {
            $targetFile .= $extension;
        }

        return $targetFile;
    }

    protected function buildLinkCommand(array $objectFiles, string $targetFile): string
    {
        return $this->getCompilerBackend()->buildLinkCommand(
            $objectFiles,
            $targetFile,
            $this->getLinkCommandOptions()
        );
    }


    protected function parseBinaryOpConcat(Expr\BinaryOp\Concat $expr): string
    {
        $left = $this->parseIdentifier($expr->left);
        $right = $this->parseIdentifier($expr->right);

        $leftType = $this->detectTypeOfExpr($expr->left);
        $rightType = $this->detectTypeOfExpr($expr->right);
        if ($leftType === self::TYPE_VOID or $rightType === self::TYPE_VOID) {
            $this->fatalError($expr, 'Cannot concat void');
        }
        return Symbol::concat() . '(' . $this->convertStringExpr($left) . ', ' . $this->convertStringExpr($right) . ')';
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

        $code .= $this->parseBlockStmts($stmts);

        $code .= $this->getIndent() . '}' . PHP_EOL;

        return $code;
    }

    protected function parseBinaryOpSmaller(Expr\BinaryOp\Smaller $expr): string
    {
        return $this->convertBoolExpr($this->parseBinaryOp($expr->left, $expr->right, '<'));
    }

    protected function parsePreInc(Expr\PreInc $expr): string
    {
        return '++' . $this->parseIdentifier($expr->var);
    }

    protected function removeAssignOp(string $op): string
    {
        return str_replace('=', '', $op);
    }

    protected function parseAssignOp(Expr\AssignOp $node, string $op): string
    {
        $var          = $this->parseIdentifier($node->var);
        $expr         = $this->parseIdentifier($node->expr);

        if ($this->isVarExpr($node->var)) {
            if (!$this->hasVar($var)) {
                $this->fatalError($node->var, 'Cannot assign to undefined variable');
            }
            $type         = $this->detectVarType($node->var);
            $rightExprStr = $this->convertExprType($expr, $type, $this->detectTypeOfExpr($node->expr));
            if ($this->isAssignOpConcat($op)) {
                if ($this->isArrayVar($node->var)) {
                    $this->fatalError($node->var, 'Cannot concat string to array');
                }
                return $var . '.append(' . $rightExprStr . ')';
            }
            if ($this->isAssignOpPow($op)) {
                $powExpr = 'php::math::pow(' . $var . ', ' . $rightExprStr . ')';
                return $var . ' = ' . $this->convertVarType($var, $powExpr);
            }
            return $var . ' ' . $op . ' ' . $rightExprStr;
        }

        if ($this->isArrayDimFetch($node->var)) {
            if ($this->isStdContainerExpr($node->var)) {
                return $this->parseStdContainerAssignOp($node, $op);
            }
            /**
             * $count[$r] -= 1;
             * 需要转为下面语句：
             * $tmp_var = $count[$r] - 1;
             * $count[$r] = $tmp_var;.
             */
            $type      = $this->detectVarType($node->var);
            $rightType = $this->detectTypeOfExpr($node->expr);
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

    protected function parseAssignOpConcat(Expr\AssignOp\Concat $expr): string
    {
        return $this->parseAssignOp($expr, '.=');
    }

    protected function parseAssignOpPlus(Expr\AssignOp\Plus $expr): string
    {
        return $this->parseAssignOp($expr, '+=');
    }

    protected function parseAssignOpMinus(Expr\AssignOp\Minus $expr): string
    {
        return $this->parseAssignOp($expr, '-=');
    }

    protected function parseAssignOpMod(Expr\AssignOp\Mod $expr): string
    {
        return $this->parseAssignOp($expr, '%=');
    }

    protected function parseAssignOpMul(Expr\AssignOp\Mul $expr): string
    {
        return $this->parseAssignOp($expr, '*=');
    }

    protected function parseAssignOpDiv(Expr\AssignOp\Div $expr): string
    {
        return $this->parseAssignOp($expr, '/=');
    }

    protected function parseAssignOpBitwiseAnd(Expr\AssignOp\BitwiseAnd $expr): string
    {
        return $this->parseAssignOp($expr, '&=');
    }

    protected function parseAssignOpBitwiseOr(Expr\AssignOp\BitwiseOr $expr): string
    {
        return $this->parseAssignOp($expr, '|=');
    }

    protected function parseAssignOpPow(Expr\AssignOp\Pow $expr): string
    {
        return $this->parseAssignOp($expr, '**=');
    }

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

    protected function fatalError(Node $node, string $msg): never
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

    protected function parseBinaryOpShiftLeft(Expr\BinaryOp\ShiftLeft $expr): string
    {
        return $this->parseBinaryOp($expr->left, $expr->right, '<<');
    }

    protected function parseBinaryOpShiftRight(Expr\BinaryOp\ShiftRight $expr): string
    {
        return $this->parseBinaryOp($expr->left, $expr->right, '>>');
    }

    protected function parseBinaryOpMod(Expr\BinaryOp\Mod $expr): string
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
            $nativeFn = $this->findNativeFunction($name);
            if ($nativeFn) {
                $expr->setAttribute('nativeCall', $nativeFn);
                $this->checkNativeCallArgs($expr, $this->getFunction($nativeFn), $expr->args, $name);
                try {
                    return self::PREFIX . $nativeFn . '(' . $this->parseNativeCallArgs($expr->args, $nativeFn) . ')';
                } catch (PlaceHolder) {
                    return $this->genPlaceHolder($this->identifierToStr($expr->name));
                }
            }
            // 动态调用的函数，转换函数名为带有命名空间的全限定名称
            $name = $this->getNamespacedFuncName($name);
            $code = $this->parseFuncCallWithOptimizer($name, $expr);
            if ($code) {
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
        $functionDef = $this->functions[$nativeFunc];
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
                    if ($argInfo->defaultValue === null) {
                        $this->fatalError($callArgs[$i], 'Named argument `' . $argInfo->name . '` is missing default value');
                    }
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
            if ($argInfo->unsafePtr) {
                $argList[] = $this->getUnsafePtrConvertedArg($arg, $argInfo);
                continue;
            }
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
            $namedArgs[$arg->name->name] = $this->parseArg($arg);
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
        if ($className) {
            // 动态调用类方法，无法判断参数是否为引用
            if ($className === self::DYNAMIC_CALLED_CLASS) {
                return false;
            }
            $param = Reflection::getClassMethodParameter($className, $funcName, $argIndex);
        } else {
            $param = Reflection::getFunctionParameter($funcName, $argIndex);
        }
        return $param && $param->isPassedByReference();
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
                return $this->parseNamedCallArgs($args, $i, $list_args);
            }
            $byRef = $funcName && $this->isReferenceArgument($funcName, $className, $i);
            if ($this->isVarExpr($arg->value)) {
                $name = $this->parseIdentifier($arg->value);
                if ($byRef) {
                    $list_args[] = $this->parseArgRefVar($arg, $name);
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
                    $globalVar = $this->parseGlobalsArrayDimFetch($arg->value);
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
            } elseif ($this->isFuncCallExpr($arg->value)) {
                if ($this->isNameExpr($arg->value->name) and $arg->value->name->toString() === 'refval') {
                    if (count($arg->value->args) === 1 and $this->isVarExpr($arg->value->args[0]->value)) {
                        $name = $this->parseVariable($arg->value->args[0]->value);
                        // 消除 refval() 函数调用，直接使用变量
                        $arg->value = $arg->value->args[0]->value;
                        $list_args[] = $this->parseArgRefVar($arg, $name);
                        continue;
                    } else {
                        $this->fatalError($arg, 'The refval function only accepts one parameter of variable type');
                    }
                }
            } else {
                if ($byRef) {
                    if ($this->isScalar($arg->value)) {
                        $this->fatalError($arg, 'The constants cannot be used as an argument for a reference-type parameter');
                    }
                    $list_args[] = $this->parseChainedExpr($arg->value, self::OP_REFVAL);
                    continue;
                }
            }
            // 变长参数展开的语法，例如：array_merge(...$arr)
            if ($arg->unpack) {
                if ($i !== $last) {
                    $this->fatalError($arg, 'The unpack expression for variadic arguments must be the last');
                }
                // 如果第一个参数是数组变量，数组展开语法直接传递该变量，没必要创建临时变量
                // 例如：function (array $args) { var_dump(...$args); }
                if ($i === 0 and $this->isVarExpr($arg->value) and $this->getVarType($arg->value->name) === self::TYPE_ARRAY) {
                    return $this->parseIdentifier($arg->value);
                } else {
                    $tmpVar = $this->genTmpVarName();
                    $this->context->beforeStmtLines[] = self::TYPE_ARRAY . ' ' . $tmpVar . '{' . implode(', ', $list_args) . '};';
                    $this->context->beforeStmtLines[] = $tmpVar . '.merge(' . $this->parseArrayArg($arg) . ');';
                    return $tmpVar;
                }
            }
            $list_args[] = $this->parseArg($arg);
        }

        return Symbol::argList() . '{' . implode(', ', $list_args) . '}';
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
        if ($this->isVarExpr($arg->value) and $this->isStdContainer($arg->value->name)) {
            return $this->convertArrayExpr($expr);
        }
        return $expr;
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
        return '(' . $this->parseExpr($expr->cond) . ') ? (' . $this->parseExpr($expr->if) . ') : (' . $this->parseExpr($expr->else) . ')';
    }

    protected function parseMatch(Expr\Match_ $expr): string
    {
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
            $code .= $else . ' { return php::throwException("UnhandledMatchError", "Unhandled match case"); }';
        }
        $code .= '}()';

        return $code;
    }

    protected function parseBinaryOpGreater(Expr\BinaryOp\Greater $expr): string
    {
        return $this->convertBoolExpr($this->parseBinaryOp($expr->left, $expr->right, '>'));
    }

    protected function parseBinaryOpPow(Expr\BinaryOp\Pow $expr): string
    {
        $left  = $this->parseIdentifier($expr->left);
        $right = $this->parseIdentifier($expr->right);

        return 'php::math::pow(' . $left . ', ' . $right . ')';
    }

    protected function parsePreDec(Expr\PreDec $expr): string
    {
        return '--' . $this->parseIdentifier($expr->var);
    }

    protected function parseBinaryOpBitwiseAnd(Expr\BinaryOp\BitwiseAnd $expr): string
    {
        return $this->parseBinaryOp($expr->left, $expr->right, '&');
    }

    protected function parseBinaryOpBitwiseOr(Expr\BinaryOp\BitwiseOr $expr): string
    {
        return $this->parseBinaryOp($expr->left, $expr->right, '|');
    }

    protected function parseBinaryOpBitwiseXor(Expr\BinaryOp\BitwiseXor $expr): string
    {
        return $this->parseBinaryOp($expr->left, $expr->right, '^');
    }

    protected function parseBitwiseNot(Expr\BitwiseNot $expr): string
    {
        $var = $this->parseIdentifier($expr->expr);

        return '~' . $var;
    }

    protected function parseIf(Node\Stmt\If_ $v): string
    {
        $cond = $this->parseExpr($v->cond);

        $code = $this->parseBeforeStmtLines() . PHP_EOL;
        $code .= 'if (' . $cond . ') {' . PHP_EOL;
        $code .= $this->parseBlockStmts($v->stmts);
        $code .= $this->getIndent() . '}';

        if ($v->elseifs) {
            foreach ($v->elseifs as $elseif) {
                $elseifCond = $this->parseExpr($elseif->cond);
                $code .= ' else if (' . $elseifCond . ') {' . PHP_EOL;
                $code .= $this->parseBlockStmts($elseif->stmts);
                $code .= $this->getIndent() . '}';
            }
        }

        if ($v->else) {
            $code .= ' else {' . PHP_EOL;
            $code .= $this->parseBlockStmts($v->else->stmts);
            $code .= $this->getIndent() . '}';
        }

        return $code . PHP_EOL;
    }

    protected function parseCompareExpr(NodeAbstract $expr): string
    {
        // PHPX 与 bool 值比较会出现重载错误，所以需要转换成 bool 值
        if ($this->isScalarBool($expr)) {
            return $this->getBoolValue($expr);
        }
        return $this->parseIdentifier($expr);
    }

    protected function parseBinaryOpEqual(Expr\BinaryOp\Equal $expr): string
    {
        return 'php::equals(' . $this->parseCompareExpr($expr->left) . ', ' . $this->parseCompareExpr($expr->right) . ')';
    }

    protected function parseBinaryOpNotEqual(Expr\BinaryOp\NotEqual $expr): string
    {
        return '!php::equals(' . $this->parseCompareExpr($expr->left) . ', ' . $this->parseCompareExpr($expr->right) . ')';
    }

    protected function parseBinaryOpIdentical(Expr\BinaryOp $expr): string
    {
        $left  = $this->parseCompareExpr($expr->left);
        $right = $this->parseCompareExpr($expr->right);
        if ($right === 'nullptr') {
            return $left . '.isNull()';
        }
        return 'php::same(' . $left . ', ' . $right . ')';
    }

    /**
     * 逻辑比较的运算，必须返回 bool 类型.
     */
    protected function parseBinaryOpLogicalAnd(Expr\BinaryOp\LogicalAnd|Expr\BinaryOp\BooleanAnd $expr): string
    {
        return $this->convertBoolExpr($this->parseBinaryOp($expr->left, $expr->right, '&&'));
    }

    protected function parseBinaryOpLogicalOr(Expr\BinaryOp\LogicalOr|Expr\BinaryOp\BooleanOr $expr): string
    {
        return $this->convertBoolExpr($this->parseBinaryOp($expr->left, $expr->right, '||'));
    }

    protected function parseBinaryOpLogicalXor(Expr\BinaryOp\LogicalXor $expr): string
    {
        return $this->convertBoolExpr($this->parseBinaryOp($expr->left, $expr->right, '^'));
    }

    protected function parseBooleanNot(Expr\BooleanNot $expr): string
    {
        return '!(' . $this->parseExpr($expr->expr) . ')';
    }

    protected function parseWhile(Node\Stmt\While_ $v): string
    {
        $cond  = $this->parseExpr($v->cond);
        $stmts = $v->stmts;

        $code = $this->parseBeforeStmtLines() . PHP_EOL;
        $code .= 'while (' . $cond . ') {' . PHP_EOL;
        $code .= $this->parseBlockStmts($stmts);
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

    protected function parseBinaryOpSmallerOrEqual(Expr\BinaryOp\SmallerOrEqual $expr): string
    {
        return $this->convertBoolExpr($this->parseBinaryOp($expr->left, $expr->right, '<='));
    }

    protected function parseBinaryOpGreaterOrEqual(Expr\BinaryOp\GreaterOrEqual $expr): string
    {
        return $this->convertBoolExpr($this->parseBinaryOp($expr->left, $expr->right, '>='));
    }

    protected function parsePrint(Expr\Print_ $expr): string
    {
        return 'php::echo(' . $this->parseExpr($expr->expr) . ')';
    }

    protected function parseDo(Node\Stmt\Do_ $v): string
    {
        $stmts = $v->stmts;
        $cond  = $this->parseExpr($v->cond);
        $code  = $this->parseBeforeStmtLines() . PHP_EOL;
        $code .= 'do {' . PHP_EOL;
        $code .= $this->parseBlockStmts($stmts);
        $code .= $this->getIndent() . '} while (' . $cond . ');' . PHP_EOL;

        return $code;
    }

    protected function parseBinaryOpSpaceship(Expr\BinaryOp\Spaceship $expr): string
    {
        $left  = $this->parseIdentifier($expr->left);
        $right = $this->parseIdentifier($expr->right);

        return 'php::compare(' . $left . ', ' . $right . ')';
    }

    /**
     * 值选择，如 ?: 或者 ??
     */
    protected function parseValueSelection(NodeAbstract $expr, Expr $left, Expr $right, string $op): string
    {
        $leftExpr = $this->parseIdentifier($left);
        if ($this->isVarExpr($left)) {
            $this->checkVarMustExist($left, $leftExpr);
        }

        $condExpr = $this->parseChainedExpr($left, $op, true);
        $chainOpResult = $left->getAttribute('chainOpResult');
        if ($chainOpResult) {
            $leftExpr = $chainOpResult;
        }

        $rightExpr = $this->parseIdentifier($right);
        $this->checkVarMustExist($right, $rightExpr);

        $tmpVar = $this->addTmpVar(self::TYPE_VAR);
        $this->context->beforeStmtLines[] = '// Expr: ' . $this->printer->prettyPrintExpr($expr) . PHP_EOL .
            $tmpVar . ' = ' . $condExpr . ' ? ' . $leftExpr . ' : ' . $rightExpr . ';';
        $expr->setAttribute('replace', $tmpVar);

        return $tmpVar;
    }

    protected function parseBinaryOpCoalesce(Expr\BinaryOp\Coalesce $expr): string
    {
        return $this->parseValueSelection($expr, $expr->left, $expr->right, self::OP_ISSET);
    }

    protected function parseBinaryOpNotIdentical(Expr\BinaryOp $expr): string
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

    protected function parseNew(Expr\New_ $expr): string
    {
        // 匿名类
        if ($expr->class instanceof Node\Stmt\Class_) {
            if ($expr->class->name === null) {
                $classDef = $expr->class;
                $className = $this->genAnonClassName();
                $classDef->name = new Node\Identifier($className);
                // 继承父类可能是 use 的名称，需要转换成全限定名称
                // TODO 匿名类的属性、常量、方法参数中都可能会用相对类名，都需要转为全限定名称
                if ($classDef->extends !== null) {
                    $parentClass = $this->getNamespacedClassName($classDef->extends->toString());
                    $classDef->extends = new Node\Name\FullyQualified($parentClass);
                }
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
                    $cePtr = Symbol::getCalledCe();
                } else {
                    if ($className === 'self') {
                        $className = $this->getFullClassName();
                    } elseif ($className === 'parent') {
                        $className = $this->classDef->extends;
                    } else {
                        $className = $this->getNamespacedClassName($className);
                    }
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
        return 'php::newObject(' . $cePtr . ', ' . $this->parseCallArgs($args) . ')';
    }

    protected function parseClone(Expr\Clone_ $expr): string
    {
        return 'php::clone(' . $this->parseExpr($expr->expr) . ')';
    }

    protected function parseInstanceof(Expr\Instanceof_ $expr): string
    {
        if ($this->isNameExpr($expr->class)) {
            $className = $this->getNamespacedClassName($this->parseIdentifier($expr->class));
            $className = $this->getClassEntryPtr($className);
            return 'php::instanceOf(' . $this->parseExpr($expr->expr) . ', ' . $className . ')';
        } else {
            return 'php::instanceOf(' . $this->parseExpr($expr->expr) . ', ' . $this->identifierToStr($expr->class) . ')';
        }
    }

    protected function parseCastInt(Expr\Cast\Int_ $node): string
    {
        return $this->convertIntExpr($this->parseExpr($node->expr));
    }

    protected function parseCastString(Expr\Cast\String_ $node): string
    {
        return $this->convertStringExpr($this->parseExpr($node->expr));
    }

    protected function parseCastBool(Expr\Cast\Bool_ $node): string
    {
        return $this->convertBoolExpr($this->parseExpr($node->expr));
    }

    protected function parseCastObject(Expr\Cast\Object_ $node): string
    {
        return $this->convertObjectExpr($this->parseExpr($node->expr));
    }

    protected function parseConstFetch(Expr\ConstFetch $expr, bool $scalar = false): string
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
                return Symbol::constant() . '(' . $ce . ', ' . $this->getLiteralString($ns[1]) . ')';
            }
            if ($this->isInternalConstant($name)) {
                return Symbol::constant() . '(' . $this->getLiteralString($name) . ')';
            }
            if (isset($this->useAliases[$name])) {
                $name = $this->useAliases[$name];
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
        $code = $this->parseExpr($expr->expr);

        return '-' . $code;
    }

    protected function parseUnaryPlus(Expr\UnaryPlus $expr)
    {
        return $this->parseExpr($expr->expr);
    }

    protected function parseBinaryOpDiv(Expr\BinaryOp\Div $expr): string
    {
        $left  = $this->parseIdentifier($expr->left);
        $right = $this->parseIdentifier($expr->right);

        return $left . ' / (' . $right . ')';
    }

    protected function parseBinaryOpMinus(Expr\BinaryOp\Minus $expr): string
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
        if (!isset($this->functions[$funcName])) {
            $this->fatalError($arg, "Function `{$funcName}` is undefined, you must adjust the order of function definition");
        }
        $funcDef = $this->functions[$funcName];
        if (!array_key_exists($index, $funcDef->argInfoList)) {
            $this->fatalError($arg, "Argument `{$index}` of function `{$funcName}` not found");
        }

        return $funcDef->argInfoList[$index];
    }

    protected function getReturnType(): string
    {
        return $this->functionDef->returnType;
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
        $expr = $this->parseArg($arg);
        $type = $this->detectTypeOfExpr($arg->value);

        if ($argInfo->byRef) {
            if ($this->isRefvalCall($arg->value)) {
                if (count($arg->value->args) === 1 and $this->isVarExpr($arg->value->args[0]->value)) {
                    // 消除 refval() 函数调用，直接使用变量
                    $arg->value = $arg->value->args[0]->value;
                } else {
                    $this->fatalError($arg, 'The refval function only accepts one parameter of variable type');
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

        $this->checkVarAssignExpr($arg, $argInfo->type, $type);

        if ($argInfo->type === self::TYPE_OBJECT) {
            if ($this->isVarExpr($arg->value)) {
                $object = $this->parseVariable($arg->value);
                if ($this->isTypedObject($object)) {
                    $class = $this->getObjectType($object);
                    if ($class and $argInfo->class and !$this->isInheritedFrom($class, $argInfo->class)) {
                        $this->fatalError($arg, "Argument `{$argInfo->name}` must be an instance of `{$argInfo->class}`, `{$class}` given");
                    }
                }
            }
            return $this->convertObjectExpr($expr);
        }

        return $this->convertExprType($expr, $argInfo->type, $type);
    }

    protected function getUnsafePtrConvertedArg(Node\Arg $arg, ArgInfo $argInfo): string
    {
        if (!$this->isVarExpr($arg->value)) {
            $this->fatalError($arg, "Argument `{$argInfo->name}` must be a std container variable for UnsafePtr parameter");
        }

        $var = $this->parseVariable($arg->value);
        if (!$this->hasVar($var)) {
            $this->fatalError($arg, 'Undefined variable `$' . $var . '`');
        }
        if (!$this->isStdContainer($var)) {
            $this->fatalError($arg, "Argument `{$argInfo->name}` must be a std container variable for UnsafePtr parameter");
        }

        $info = $this->getStdContainerVarInfo($var);
        return 'php_create_unsafe_ptr(&' . $var . ', ' . $info['typeId'] . ')';
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

    protected function parseExit(Expr\Exit_ $node): string
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
            if ($this->isArrayDimFetch($var)) {
                if ($var->dim === null) {
                    $this->fatalError($var, 'Cannot use [] for array unset');
                }
                $array = $this->parseIdentifier($var->var);
                if (($this->isStdMap($array) or $this->isStdUnorderedMap($array))
                    and !empty($this->context->stdContainers[$array]['locking'])) {
                    $this->fatalError($var, 'Cannot delete element in std container in foreach loop');
                }
                $dim = $this->parseIdentifier($var->dim);
                $lines[] = $array . '.offsetUnset(' . $dim . ');';
            } elseif ($this->isPropertyFetch($var)) {
                $object = $this->parseIdentifier($var->var);
                $lines[] = $object . '.unsetProperty(' . $this->identifierToStr($var->name, literal: true) . ');';
                if ($object === 'this_' and $this->isIdExpr($var->name)) {
                    $propName = $this->parseIdentifier($var->name);
                    if ($this->hasObjectPropVar($this->getObjectPropVarName($object, $propName))) {
                        $this->warning($var, "Object property `{$propName}` of native types cannot be unset");
                        $lines = [];
                        $lines[] = $this->getObjectPropVarName($object, $propName) . ' = 0;';
                    }
                }
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
        if ($this->isVarExpr($object) and $this->isIdExpr($property)) {
            $objectName = $this->parseIdentifier($object);
            $propertyName = $this->parseIdentifier($property);
            $nativeProperty = null;
            if ($objectName === 'this_') {
                if ($this->classDef->trait) {
                    goto _dynamic_attr;
                }
                $nativeProperty = $this->findNativeProperty($expr, $propertyName, $this->class, $this->namespace);
            } elseif ($this->isTypedObject($objectName)) {
                $className = $this->getObjectType($objectName);
                $nativeProperty = $this->findNativeProperty($expr, $propertyName, $className);
            }
            if ($nativeProperty) {
                $expr->setAttribute('nativeProperty', $nativeProperty);
                return $nativeProperty;
            }
        }
        _dynamic_attr:
        return $this->identifierToStr($property, literal: true);
    }

    protected function parsePropertyFetch(Expr\PropertyFetch $expr, bool $update = false): string
    {
        $object = $expr->var;
        $property = $expr->name;
        $id = $this->getPropertyIdentifier($expr, $object, $property);
        $objectVar = $this->parseIdentifier($object);
        $getProperty = $objectVar . '.attr(' . $id . ', ' . $this->escapeBool($update) . ')';
        // 将属性设置为 native 类型的引用，以提高性能，仅适用于 this_ ，其他类型对象可能会在中间发生改变
        // TODO 分析对象是否为 SSA , 如果是 SSA 则可以将属性设置为引用
        if ($expr->hasAttribute('nativePropertyDef') and $this->nativeTypes and $objectVar === 'this_') {
            /**
             * @var PropertyDef $def
             */
            $def = $expr->getAttribute('nativePropertyDef');
            if ($def->type === self::TYPE_INT or $def->type === self::TYPE_FLOAT) {
                $propVar = $this->getObjectPropVarName($objectVar, $this->parseIdentifier($property));
                if (!$this->hasObjectPropVar($propVar)) {
                    $this->context->objectProps[$propVar] = [
                        'type' => $def->type,
                        'getter' => $getProperty,
                    ];
                }
                $expr->setAttribute('nativePropertyVar', $propVar);
                return $propVar;
            }
        }
        return $getProperty;
    }

    protected function parseAssignOpShiftLeft(Expr\AssignOp\ShiftLeft $node): string
    {
        return $this->parseAssignOp($node, '<<=');
    }

    protected function parseAssignOpShiftRight(Expr\AssignOp\ShiftRight $node): string
    {
        return $this->parseAssignOp($node, '>>=');
    }

    protected function parseAssignOpBitwiseXor(Expr\AssignOp\BitwiseXor $node): string
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

        if ($this->isArrayDimFetch($node->valueVar)) {
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

    protected function formatCppCode(string $file): void
    {
        if (!$this->formatCode) {
            return;
        }

        $cmd = 'cd ' . $this->rootPath . ' && clang-format -i ' . $file;
        $this->climate->info('format: ' . $this->getRelativePath($file));
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
        return $this->nativeTypes ? $type : self::TYPE_VAR;
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
        $type    = $this->detectTypeOfExpr($cond);
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
                $code .= $this->parseBlockStmts($case->stmts);
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
        $defaultCase = false;
        $defaultOnly = true;
        foreach ($v->cases as $case) {
            if (empty($case->cond)) {
                $defaultCase = true;
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
            if (!$this->isReturnExpr($lastExpr)
                and !$this->isExitExpr($lastExpr)
                and !$this->isBreakExpr($lastExpr)
                and !$this->isThrowExpr($lastExpr)
            ) {
                $this->fatalError($case, 'switch case must end with return/break/exit/throw, ' . $lastExpr->getType() . ' given');
            }

            if ($defaultCase) {
                $else = $defaultOnly ? '' : 'else';
                $code .= $this->getIndent() . $else . ' {' . PHP_EOL;
            } else {
                $code .= $this->getIndent() . 'if (' . implode(' || ', $condList) . ') {' . PHP_EOL;
                $defaultOnly = false;
                $condList = [];
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
            $varName = $this->escapeVarName($var->var->name);
            $type = $var->default ? $this->detectTypeOfExpr($var->default) : self::TYPE_VAR;
            $globalVar = $this->addStaticVar($var->var, $varName, $type);

            $list[] = self::TYPE_VAR . ' &' . $varName . ' = ' . $this->escapeGlobalVar($globalVar) . ';';
            if ($var->default) {
                $initState = self::STATIC_VAR . $varName . '_initialized';
                $initCode = $this->getIndent() . 'static bool ' . $initState . ' = false;';
                $initCode .= $this->getIndent() . "if (!{$initState}) { \n";
                $this->indentLevel++;
                $initCode .= $this->getIndent() . "{$initState} = true;\n";
                $initCode .= $this->genLambdaCall(function () use ($var, $varName) {
                    return $this->getIndent() . $varName . ' = ' . $this->parseExpr($var->default) . ';';
                });
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

    protected function parseEval(Expr\Eval_ $expr): string
    {
        // 对 eval() 指令的 PHP 代码段禁止字面量优化
        $expr->expr->setAttribute('noLiteralString', true);
        return 'php::eval(' . $this->identifierToStr($expr->expr) . ')';
    }

    protected function parseInclude(Expr\Include_ $expr): string
    {
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
        if (!$this->isVarExpr($expr) && !$this->isArrayDimFetch($expr) && !$this->isPropertyFetch($expr) && !$this->isStaticPropertyFetch($expr)) {
            $this->fatalError($expr, 'The left value of assignment operation can only be variable, array item, object property, class static property');
        }
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
            if ($expr->hasAttribute('nativeProperty')) {
                return $fn . '(' . $prop . ')';
            }
        }
        if ($this->isStaticPropertyFetch($expr) and $this->isNameExpr($expr->class) and $this->isIdExpr($expr->name)) {
            $prop = $this->parseStaticPropertyFetch($expr);
            if ($expr->hasAttribute('nativeProperty')) {
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
            return $fn . '(' . $var . ', {' . implode(', ', $list) . '})';
        }
    }

    protected function parseCastArray(Expr\Cast\Array_ $expr): string
    {
        return $this->convertArrayExpr($this->parseIdentifier($expr->expr));
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
        return $this->convertFloatExpr($this->parseIdentifier($expr->expr));
    }

    protected function detectFuncCallReturnType(string $name): string
    {
        if ($this->isInternalFunction($name)) {
            $returnType = Reflection::getFunctionReturnType($name);
            // void 类型将被忽略，类型推测仅用于赋值操作的右值，即使返回值为 void , 赋值操作也应该继续运行，右值会被当做 null
            // 例如 $a = var_dump('hello'); 虽然 var_dump 返回值为 void ，但是 $a 的类型是 mixed，值为 null
            if ($returnType and $returnType !== 'void') {
                return $this->getTypeFromZendType($returnType);
            }
        }
        return self::TYPE_VAR;
    }

    protected function detectMethodCallReturnType(string $class, string $method): string
    {
        $returnType = Reflection::getMethodReturnType($class, $method);
        if ($returnType and $returnType !== 'void') {
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
        if ($type === self::TYPE_STR) {
            return $this->convertStringExpr($expr);
        }
        if ($type === self::TYPE_ARRAY) {
            return $this->convertArrayExpr($expr);
        }
        if ($type === self::TYPE_OBJECT) {
            return $this->convertObjectExpr($expr);
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

    protected function convertToRef(NodeAbstract $expr): string
    {
        $this->checkLeftValue($expr);
        $var = $this->parseIdentifier($expr);
        if ($this->isVarExpr($expr) and $this->isNativeTypeVar($var)) {
            $this->context->localVars[$var] = self::TYPE_VAR;
        }
        return $this->parseIdentifier($expr) . '.toReference()';
    }

    protected function parseAssignRef(Expr\AssignRef $expr): string
    {
        $this->context->inAssignExpr = true;
        $left = $this->parseIdentifier($expr->var);
        $this->context->inAssignExpr = false;

        if ($this->isVarExpr($expr->var)) {
            if ($this->isUnsafePtrParameter($left)) {
                $this->fatalError($expr->var, "Cannot re-assign UnsafePtr parameter `\${$left}`");
            }
            if (!$this->hasVar($left)) {
                $this->addLocalVar($left, self::TYPE_REF);
            } else {
                $type = $this->getVarType($left);
                if ($type !== self::TYPE_REF) {
                    $this->fatalError($expr, 'Cannot assign reference to variable of type ' . $type);
                }
            }
        }

        $tmpVar = $this->addTmpVar(self::TYPE_REF);
        $rightExpr = '';

        if ($this->isVarExpr($expr->expr)) {
            $rightExpr = $tmpVar . ' = ' . $this->parseIdentifier($expr->expr) . '.toReference()';
        } elseif ($this->isPropertyFetch($expr->expr)) {
            $left = $this->parseIdentifier($expr->var);
            $object = $this->parseExpr($expr->expr->var);
            $prop = $this->identifierToStr($expr->expr->name);
            $rightExpr = $tmpVar . ' = ' . $object . '.attrRef(' . $prop . ')';
        } elseif ($this->isArrayDimFetch($expr->expr)) {
            $left = $this->parseIdentifier($expr->var);
            $array = $this->parseIdentifier($expr->expr->var);
            if ($expr->expr->dim == null) {
                $this->fatalError($expr, 'Cannot assign reference to array dim fetch without dim');
            }
            $rightExpr = $tmpVar . ' = ' . $array . '.itemRef(' . $this->parseIdentifier($expr->expr->dim) . ')';
        } else {
            $this->fatalError($expr, 'Cannot assign reference to ' . $this->parseIdentifier($expr->expr));
        }

        $this->context->beforeStmtLines[] = $rightExpr . ';';
        return $left . ' = &' . $tmpVar;
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

        // 可转为原生调用的 MethodCall
        if ($this->isVarExpr($expr->var) and $this->isNamedMethod($expr->name)) {
            $type = $this->getVarType($object);
            if (!$this->checkArgType($type, self::TYPE_OBJECT)) {
                $this->fatalError($expr, 'Cannot call method `' . $expr->name->toString() . '()` on variable of type ' . $type);
            }
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
                $magicMethod = true;
            }
        }

        if ($this->isNamedMethod($expr->name)) {
            $funcName = $this->parseIdentifier($expr->name);
        } else {
            $funcName = '';
        }

        if ($class and $funcName and !$magicMethod) {
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
        try {
            // 类名为空，这是一个 var ，类型是 any ，编译期无法获得它的类型，需要动态调用
            $class = empty($class) ? self::DYNAMIC_CALLED_CLASS : $class;
            return $object . '.call(' . $methodPtr . ', ' . $this->parseCallArgs($expr->args, $funcName, $class) . ')';
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

    protected function findNativeStaticProperty(Expr\StaticPropertyFetch $expr, ?string &$class): ?string
    {
        if ($this->isNameExpr($expr->class) and $this->isIdExpr($expr->name)) {
            $class = $this->parseIdentifier($expr->class);
            $propertyName = $this->parseIdentifier($expr->name);
            if ($class === 'self') {
                if ($this->classDef->trait) {
                    return Symbol::getStaticProperty() . '(' . Symbol::getCalledCe() . ', ' . $this->getLiteralString($propertyName) . ')';
                }
                $class = $this->getFullClassName();
            } elseif ($class === 'parent') {
                if (!$this->classDef->extends) {
                    $this->fatalError($expr, 'Cannot access parent:: when current class does not extend any class');
                }
                $class = $this->classDef->extends;
            }
            $nativeProperty = $this->findNativeProperty($expr, $propertyName, $class, $this->namespace, true);
            if ($nativeProperty) {
                $expr->setAttribute('nativeProperty', $nativeProperty);
                return $nativeProperty;
            }
        }
        return null;
    }

    /**
     * @param NodeAbstract $expr 仅用于输出错误日志
     */
    protected function findNativeProperty(NodeAbstract $expr, string $property, string $class, string $namespace = '', bool $static = false): ?string
    {
        $findClass = $class;
        if ($namespace) {
            $findClass = $namespace . '\\' . $class;
        }
        $scope = $this->class ? ltrim($namespace . '\\' . $class, '\\') : '';
        $propertyDef = null;
        $classDef = null;
        while (true) {
            if ($this->hasClass($findClass)) {
                $classDef = $this->getClass($findClass);
                if ($classDef->hasProperty($property)) {
                    $propertyDef = $classDef->getProperty($property);
                    // 获取动态属性，但找到了静态属性，或者获取静态属性，但是是动态属性，直接返回 null
                    if ((!$static and $propertyDef->isStatic()) or ($static and !$propertyDef->isStatic())) {
                        return null;
                    }
                    if ($propertyDef->isPublic()) {
                        break;
                    }
                    if ($propertyDef->isProtected()) {
                        if ($scope) {
                            break;
                        }
                        $displayClass = ltrim($class, '\\');
                        $this->fatalError($expr, "Cannot access protected property `{$property}` of class `{$displayClass}`");
                    } else {
                        if ($scope === $findClass) {
                            break;
                        }
                        $displayClass = ltrim($class, '\\');
                        $this->fatalError($expr, "Cannot access private property `{$property}` of class `{$displayClass}`");
                    }
                } elseif ($classDef->extends) {
                    $findClass = $classDef->extends;
                    continue;
                }
            }
            break;
        }
        if ($propertyDef and $classDef) {
            $expr->setAttribute('nativePropertyDef', $propertyDef);
            $expr->setAttribute('nativeClassDef', $classDef);
            return $this->getPropertyOffset($classDef->getNamespacedName(false), $property);
        }
        return null;
    }

    protected function parseNativeStaticPropertyFetch(Expr\StaticPropertyFetch $expr): string|bool
    {
        $nativeProp = $this->findNativeStaticProperty($expr, $class);
        if ($nativeProp) {
            if ($expr->hasAttribute('nativeProperty')) {
                $classPtr = $this->getClassEntryPtr($class);
                return Symbol::getStaticProperty() . '(' . $classPtr . ', ' . $nativeProp . ')';
            } else {
                return $nativeProp;
            }
        }
        return false;
    }

    protected function parseStaticPropertyFetch(Expr\StaticPropertyFetch $expr): string
    {
        $native = $this->parseNativeStaticPropertyFetch($expr);
        if ($native) {
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
        if ($this->isNewExpr($expr->expr)) {
            $ex = $this->parseExpr($expr->expr);
        } elseif ($this->isVarExpr($expr->expr)) {
            $ex = $this->parseIdentifier($expr->expr);
            if ($this->getVarType($ex) != self::TYPE_OBJECT) {
                goto _to_object;
            }
        } else {
            $ex = $this->parseIdentifier($expr->expr);
            _to_object:
            $ex = $this->convertObjectExpr($ex);
        }
        return 'php::throwException(' . $ex . ')';
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
        foreach ($types as $type) {
            if ($this->isNameExpr($type) or $this->isFullNameExpr($type)) {
                $class = $this->getNamespacedClassName($this->parseIdentifier($type));
                $ce = $this->getClassEntryPtr($class);
                $code .= Symbol::instanceOf() . '(' . $var . ', ' . $ce . ')';
            } else {
                $this->fatalError($type, 'Unsupported catch type');
            }
        }

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
        return 'php::shell_exec(php::concat({' . implode(', ', $list) . '}))';
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
        $this->constants[$this->escapeNamespace($name)] = $constInfo;
    }

    protected function hasConstant(string $name): bool
    {
        return isset($this->constants[$this->escapeNamespace($name)]);
    }

    protected function getConstant(string $name): string
    {
        return $this->escapeNamespace($name);
    }

    protected function getConstantType(string $name): string
    {
        return $this->constants[$this->escapeNamespace($name)]->type;
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
            $key = $this->parseIdentifier($declare->key);
            $value = $this->parseIdentifier($declare->value);
            if ($key === 'ticks') {
                $this->fatalError($v, 'declare(ticks=1) is not supported');
            } elseif ($key === 'encoding') {
                if (strtolower($value) !== 'utf-8') {
                    $this->fatalError($v, 'declare(encoding="' . $value . '") is not supported, only UTF-8 is supported');
                }
            } elseif ($key === 'strict_types') {
                $this->strictTypes = boolval(intval($value));
            } else {
                $this->fatalError($v, 'declare(' . $key . '=' . $value . ') is not supported');
            }
        }
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
                $this->useFunctions[$fn] = $ns;
            } else {
                if ($id === 'native_types') {
                    $this->nativeTypes = true;
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
        if ($toType === self::TYPE_OBJECT and $fromType === self::TYPE_OBJECT) {
            return true;
        }
        if ($toType === self::TYPE_ARRAY and $fromType === self::TYPE_ARRAY) {
            return true;
        }
        if ($toType === self::TYPE_STR) {
            if ($fromType === self::TYPE_STR) {
                return true;
            }
            if (!$this->strictTypes) {
                $this->warning($left, "Attempt to implicitly convert `{$fromType}` to `{$toType}`");
                return true;
            }
        }
        // 原生类型可以互相转换，由 C++ 底层完成
        if ($this->isNativeType($toType) and $this->isNativeType($fromType)) {
            return true;
        }
        $this->fatalError($left, "Cannot re-assign variable from `{$fromType}` to `{$toType}`");
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
            return $this->isInheritedFrom($this->classDef->getNamespacedName(false), $classDef->getNamespacedName(false));
        }
        // 类外部调用，只允许调用 public 方法
        return true;
    }

    protected function isOverrideMethod(string $fullMethodName): bool
    {
        $fullMethodNameLower = strtolower($fullMethodName);
        return isset($this->classMethodOverride[$fullMethodNameLower]) and $this->classMethodOverride[$fullMethodNameLower];
    }

    protected function findNativeMethod(CallLike $expr, string $object, string $method): string|false
    {
        $classDef = null;
        if ($object === 'this_') {
            $class = $this->getFullClassName();
            $classDef = $this->classDef;
        } elseif (isset($this->context->objects[$object])) {
            $class = $this->context->objects[$object];
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

        if ($classDef) {
            $fullMethodName = $classDef->getNamespacedName(false) . '::' . $method;
        } else {
            $fullMethodName = $object . '::' . $method;
        }

        // 存在子类同名方法，需要转为动态调用
        if ($this->isOverrideMethod($fullMethodName)) {
            return false;
        }
        if ($nativeFunc) {
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

    protected function parseStdCall(Expr\StaticCall $expr): string
    {
        $func = $this->parseIdentifier($expr->name);
        $type = match ($func) {
            'int' => self::TYPE_INT,
            'float' => self::TYPE_FLOAT,
            'bool' => self::TYPE_BOOL,
            default => '',
        };
        if ($type) {
            $expr->setAttribute('nativeType', $type);
            $valueExpr = $this->parseExpr($expr->args[0]->value);
            return $this->convertExprFromType($type, $valueExpr);
        } else {
            $this->fatalError($expr, 'Unknown std method: ' . $func);
        }
    }

    protected function isUnsafePtrTypeDecl(?NodeAbstract $type): bool
    {
        return $type !== null
            and !$type instanceof UnionType
            and !$type instanceof NullableType
            and $this->parseIdentifier($type) === 'UnsafePtr';
    }

    protected function isUnsafePtrParameter(string $name): bool
    {
        if (!$this->functionDef) {
            return false;
        }
        foreach ($this->functionDef->argInfoList as $argInfo) {
            if ($argInfo->name === $name) {
                return $argInfo->unsafePtr;
            }
        }
        return false;
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

    protected function genScopeVarDecl(): string
    {
        $code = '';
        foreach ($this->context->localVars as $name => $type) {
            if (isset($this->context->arguments[$name])) {
                continue;
            }
            $code .= $this->getIndent();
            if ($type === self::TYPE_STD_ARRAY) {
                $info = $this->context->stdArrays[$name];
                if (isset($info['unsafePtr'])) {
                    $code .= 'auto &' . $name . ' = php_unsafe_cast<' . $info['decl'] . '>(' . $info['unsafePtr'] . ', ' . $info['typeId'] . ');';
                } elseif ($info['bytes'] > self::MAX_BYTES_IN_STACK) {
                    $code .= "auto {$name}_unique_ptr = std::make_unique<{$info['decl']}>();\n";
                    $code .= $this->getIndent() . ' auto &' . $name . ' = *' . $name . '_unique_ptr;';
                } else {
                    $code .= $info['decl'] . ' ' . $name . '{};';
                }
            } elseif ($type === self::TYPE_STD_VECTOR) {
                $info = $this->context->stdContainers[$name];
                if (isset($info['unsafePtr'])) {
                    $code .= 'auto &' . $name . ' = php_unsafe_cast<' . $info['decl'] . '>(' . $info['unsafePtr'] . ', ' . $info['typeId'] . ');';
                } else {
                    $code .= $info['decl'] . ' ' . $name;
                    if ($info['size'] !== null) {
                        $code .= '(' . $info['size'] . ')';
                    } else {
                        $code .= '{}';
                    }
                    $code .= ';';
                }
            } elseif ($type === self::TYPE_STD_MAP || $type === self::TYPE_STD_UNORDERED_MAP) {
                $info = $this->context->stdContainers[$name];
                if (isset($info['unsafePtr'])) {
                    $code .= 'auto &' . $name . ' = php_unsafe_cast<' . $info['decl'] . '>(' . $info['unsafePtr'] . ', ' . $info['typeId'] . ');';
                } else {
                    $code .= $info['decl'] . ' ' . $name . '{};';
                }
            } else {
                $code .= $type . ' ' . $name;
                if ($type === self::TYPE_INT or $type === self::TYPE_FLOAT or $type === self::TYPE_BOOL) {
                    $code .= ' = 0';
                }
                $code .= ';';
            }
            $code .= PHP_EOL;
        }
        foreach ($this->context->globalVars as $name => $type) {
            $code .= $this->getIndent() . self::TYPE_VAR . ' &' . $name . ' = ' . $this->escapeGlobalVar($name) . ';' . PHP_EOL;
        }
        foreach ($this->context->objectProps as $name => $info) {
            $code .= $this->getIndent() . $info['type'] . ' &' . $name . ' = Z_LVAL_P(' . $info['getter'] . '.unwrap_ptr());' . PHP_EOL;
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

        $cb = function () use ($expr) {
            $code = $this->parseExpr($expr->expr);
            if ($this->context->beforeStmtLines) {
                $beforeCode = implode(PHP_EOL, $this->context->beforeStmtLines);
            } else {
                $beforeCode = '';
            }
            if ($this->isCallExpr($expr->expr)) {
                $nativeCall = $expr->expr->getAttribute('nativeCall');
                if ($nativeCall and $this->functions[$nativeCall]->returnType === self::TYPE_VOID) {
                    return $beforeCode . PHP_EOL . $code . ';' . PHP_EOL . 'return ' . self::VALUE_NULL . ';';
                }
            }
            return $beforeCode . PHP_EOL . 'return ' . $code . ';';
        };

        return $this->genClosure($expr, $expr->params, $cb, $uses);
    }

    protected function parseClosure(Expr\Closure $expr): string
    {
        $cb = function () use ($expr) {
            $fnCode = $this->parseStmts($expr->stmts);
            if (!$this->isReturnStmtInLastLine($expr->stmts)) {
                $fnCode .= 'return ' . self::VALUE_NULL . ';' . PHP_EOL;
            }
            return $fnCode;
        };
        return $this->genClosure($expr, $expr->params, $cb, $expr->uses);
    }

    protected function parseAssignOpCoalesce(Expr\AssignOp\Coalesce $expr): string
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
            $this->addLocalVar($var, $this->detectTypeOfExpr($expr->expr));
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
                $list[] = ['property', $this->identifierToStr($expr->name, literal: true)];
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
                $code .= $this->getIndent() . "{$tmpVar} = {$object}.call({$item[1]}, {$args});";
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
    private function parseArrayMixed(Expr\Array_ $node): string
    {
        $tmpVar = $this->genTmpVarName();
        $this->addLocalVar($tmpVar, self::TYPE_ARRAY);

        $items = $node->items;
        foreach ($items as $item) {
            $value = $this->parseIdentifier($item->value);
            if ($item->unpack) {
                $this->context->beforeStmtLines[] = $this->getIndent() . $tmpVar . '.merge(' . $value . ');';
            } elseif ($item->key) {
                $key = $this->parseArrayKey($item->key);
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