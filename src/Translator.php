<?php
/**
 * This file is part of TypePHP.
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace TypePhp;

use Ajaxray\AnsiKit\AnsiTerminal;
use Ajaxray\AnsiKit\Components\Progressbar;
use MJS\TopSort\Implementations\StringSort;
use TypePhp\Analysis\SsaBuilder;
use TypePhp\Backend\CompilerFactory;
use TypePhp\Build\FileScanner;
use TypePhp\Build\NativeCommandOptionsTrait;
use TypePhp\Build\NativeBuilder;
use TypePhp\Build\PrecompiledHeaderManager;
use TypePhp\Build\SourcePipelineTrait;
use TypePhp\Config\ProjectYamlLoader;
use TypePhp\Diagnostics\CompileTimeAttributeDiagnostic;
use TypePhp\Build\ResourceCompilationTrait;
use TypePhp\Entity\ArgInfo;
use TypePhp\Entity\ClassDef;
use TypePhp\Entity\ClassLikeDef;
use TypePhp\Entity\ConstantDef;
use TypePhp\Entity\FunctionDef;
use TypePhp\Entity\InterfaceDef;
use TypePhp\Entity\MethodDef;
use TypePhp\Entity\PropertyDef;
use TypePhp\Exception\Redo;
use TypePhp\Exception\Skip;
use TypePhp\Exception\SyntaxError;
use TypePhp\Generator\DefaultArgumentGenerator;
use TypePhp\Generator\LibraryImportStubGenerator;
use TypePhp\Generator\Symbol;
use TypePhp\Metadata\Constants;
use TypePhp\Platform\PlatformFactory;
use TypePhp\Platform\Windows;
use TypePhp\Resolver\Reflection;
use TypePhp\Resolver\ClassConstantValueTrait;
use TypePhp\Transform\Visitor;
use TypePhp\Transform\ConstructorLowering;
use TypePhp\Transform\ConstantExpressionValidationVisitor;
use TypePhp\Transform\RuntimeAttributeFactoryLowering;
use PhpParser\Modifiers;
use PhpParser\Node;
use PhpParser\NodeAbstract;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;

class Translator extends Preprocessor
{
    use DefaultArgumentGenerator;
    use NativeCommandOptionsTrait;
    use SourcePipelineTrait;
    use ResourceCompilationTrait;
    use ClassConstantValueTrait;

    public const string VERSION = '0.4.0';
    public const string APP_NAME = 'TypePHP Compiler (AOT)';
    protected const string MODULE_NAME_PREFIX = 'app_';

    protected string $targetName = 'app';
    protected bool $hasExplicitOutput = false;
    protected ?string $explicitOutputExtension = null;
    protected array $sourceDirs = [];
    private ?ProjectYamlLoader $projectYamlLoader = null;
    private ?NativeBuilder $nativeBuilder = null;
    protected bool $verbose = false;
    protected array $phpSrcFiles = [];
    protected array $ignorePaths = [];
    protected array $ignoreExtensions = [];
    protected array $argInfoHeaderFiles = [];
    protected array $registerSymbols = [];

    // Windows 资源文件配置（图标、版本信息等）
    protected array $resourceConfig = [];
    protected bool $useRegisterSymbolsFn = false;
    protected array $globalHeaders = [
        'phpx.h',
        'phpx_helper.h',
        'phpx_big_int.h',
        'phpx_big_float.h',
        'phpx_decimal.h',
        'typephp_helper.h',
        'typephp_fiber_generator.h',
        'phpx_std.h',
    ];
    /**
     * @var array<string>
     */
    protected array $classCeList = [];
    protected array $classCeInfo = [];

    protected function isConstructorNativeFunction(FunctionDef $func): bool
    {
        return $func->method && str_ends_with($func->name, self::NAMESPACE_SEPARATOR . '__construct');
    }

    public function __construct(string $rootPath)
    {
        parent::__construct($rootPath);
        $this->climate->arguments->add(Constants::COMPILER_OPTIONS);
        $this->preprocessArgvAdvanced();
        $this->climate->arguments->parse();

        // 只读取命令行参数，不立即应用（等待 YAML 解析后再应用）
        // 这样可以确保优先级：命令行 > YAML > 默认值
        $this->internalFunctions = array_flip(get_defined_functions()['internal']);
        unset($this->internalFunctions[self::ENTRY_FUNCTION]);
        $this->internalConstants = $this->loadInternalConstants();
        if ($this->climate->arguments->defined('help')) {
            $this->showUsage();
            exit(0);
        }
        if ($this->climate->arguments->defined('version')) {
            $this->showVersion();
            exit(0);
        }

        // 提前处理 --no-color，确保后续所有输出均为无颜色模式
        if ($this->climate->arguments->defined('no-color')) {
            $this->climate->forceAnsiOff();
        }


        // 检测操作系统、编译器以及 Windows 平台的 PHP lib 文件
        $this->detectPlatform();
    }

    protected function loadInternalConstants(): array
    {
        $groups = get_defined_constants(true);
        if (!is_array($groups)) {
            return get_defined_constants();
        }

        $constants = [];
        foreach ($groups as $groupName => $group) {
            // 编译器进程中的用户常量属于被编译程序的运行时状态，不能在静态阶段展开。
            if (strcasecmp((string) $groupName, 'user') === 0 || !is_array($group)) {
                continue;
            }
            foreach ($group as $name => $value) {
                $constants[$name] = $value;
            }
        }
        return $constants;
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

    public function parseArgv(array $argv)
    {
        $path = null;
        for ($i = 1; $i < count($argv); $i++) {
            if ($argv[$i] !== '' && $argv[$i][0] !== '-') {
                $path = $argv[$i];
                break;
            }
        }
        if (empty($path)) {
            $this->showUsage();
            exit(1);
        }
        return $path;
    }

    public function showUsage(): void
    {
        $climate = $this->climate;
        $this->showVersion();
        $climate->br();

        global $argv;
        $cmd = $argv[0];

        $climate->bold('USAGE:');
        $climate->tab()->out($cmd . ' <file/dir/config.yml> [options]');
        $climate->br();

        $climate->bold('ARGUMENTS:');
        $climate->tab()->out('<file>    Input PHP file/directory/YAML config to compile');
        $climate->br();

        $climate->bold('EXAMPLES:');
        $climate->tab()->out($cmd . ' hello.php');
        $climate->tab()->out($cmd . ' bench.php -O2');
        $climate->tab()->out($cmd . ' project/config.yml -O2');
        $climate->tab()->out($cmd . ' my-ext/ -O2 -o myapp -m ext');
        $climate->tab()->out($cmd . ' app.php -r -O2 -- --flag1 value1');
        $climate->br();

        $climate->bold('OPTIONS:');
        $climate->tab()->out('-O <level>           Optimization level (0-3, default: 0)');
        $climate->tab()->out('--profile            Enable performance profiling (adds -lprofiler, forces recompile)');
        $climate->tab()->out('-d, --debug            Enable debug mode (auto-disable optimizations, add debug symbols)');
        $climate->tab()->out('-o, --output <file>  Output binary name (default: input basename)');
        $climate->tab()->out('-v, --version        Show version');
        $climate->tab()->out('-h, --help           Show this help message');
        $climate->tab()->out('-f, --force          Force recompile phpx misc files (ignore cache)');
        $climate->tab()->out('-m, --mode <mode>    Compilation mode: bin (binary), lib (shared library), or ext (PHP extension); default: bin');
        $climate->tab()->out('-r, --run           Run the compiled binary after build');
        $climate->tab()->out('-j, --job <num>      Number of parallel compilation jobs (default: 4)');
        $climate->tab()->out('--cxx-std <ver>      C++ standard version (c++17, c++20, etc., default: c++17)');
        $climate->tab()->out('--march <arch>       Target CPU instruction set (e.g. native, x86-64-v3, armv8-a)');
        $climate->tab()->out('--target-platform <triple> Cross-compilation target triple (e.g. aarch64-linux-gnu)');
        $climate->tab()->out('--lto                Enable Link Time Optimization (-flto)');
        $climate->tab()->out('--no-literal-strings Disable literal strings optimization');
        $climate->tab()->out('--php-version <ver>  PHP language version to accept (8.2-8.5, default: 8.5)');
        $climate->tab()->out('--no-progress        Disable progress bar, output per-file compilation progress line by line');
        $climate->tab()->out('--no-console         Hide console window (Windows only, GUI application)');
        $climate->tab()->out('--no-color           Disable ANSI color output');
        $climate->tab()->out('--sanitize <type>    Enable sanitizers (address, undefined, etc.)');
        $climate->tab()->out('--build-dir <dir>   Specify build directory for generated C++ code (default: <root>/build)');
        $climate->tab()->out('--dry                Dry run: only generate C++ code, skip compilation and linking');
        $climate->tab()->out('-I, --include-path <dir> Add an additional C++ include directory (repeatable)');
        $climate->tab()->out('-D, --define <macro>  Define a preprocessor macro (repeatable, e.g. -D FOO=bar)');
        $climate->tab()->out('--format             Enable clang-format code formatting (disabled by default)');
        $climate->tab()->out('-l, --link-lib <lib> Link against a library (repeatable, e.g. -lcurl)');
        $climate->tab()->out('-L, --link-path <dir> Add a library search path (repeatable, e.g. -L/usr/local/lib)');
        $climate->br();
    }

    /**
     * 应用命令行参数（在 YAML 解析后调用，确保命令行参数优先级最高）
     */
    protected function applyCommandLineArguments(): void
    {
        $this->applyPhpVersionCommandLineArgument();

        // 优化级别
        if ($this->climate->arguments->defined('optimize')) {
            $this->optimizeLevel = $this->climate->arguments->get('optimize');
        }

        // 构建模式
        if ($this->climate->arguments->defined('mode')) {
            $this->setBuildMode($this->climate->arguments->get('mode'));
        }

        // 调试行号
        if ($this->climate->arguments->defined('debug-line')) {
            $this->debugLine = intval($this->climate->arguments->get('debug-line'));
        }

        // 最大并行任务数
        if ($this->climate->arguments->defined('job')) {
            $this->maxJob = intval($this->climate->arguments->get('job'));
        }

        // 调试模式
        if ($this->climate->arguments->defined('debug')) {
            $this->debug = true;
        }

        // 禁用字面量字符串优化
        if ($this->climate->arguments->defined('no-literal-strings')) {
            $this->noLiteralStrings = true;
        }

        // 启用性能分析（需强制重编译 misc 文件以确保 PPROF_ON 宏生效，仅 Linux 支持）
        if ($this->climate->arguments->defined('profile')) {
            if (!$this->isLinux()) {
                $this->climate->error('--profile is only supported on Linux (requires gperftools)');
                exit(1);
            }
            $this->enableProfiler = true;
        }

        // 禁用进度条
        if ($this->climate->arguments->defined('no-progress')) {
            $this->noProgress = true;
        }

        // 隐藏控制台窗口
        if ($this->climate->arguments->defined('no-console')) {
            $this->noConsole = true;
        }

        // Sanitizer
        if ($this->climate->arguments->defined('sanitize')) {
            $this->sanitize = $this->climate->arguments->get('sanitize');
        }

        // C++ 标准版本
        if ($this->climate->arguments->defined('cxx-std')) {
            $this->cxxStd = $this->climate->arguments->get('cxx-std');
        }

        // 目标 CPU 指令集
        if ($this->climate->arguments->defined('march')) {
            $this->march = $this->climate->arguments->get('march');
        }

        // 交叉编译目标平台
        if ($this->climate->arguments->defined('target-platform')) {
            $this->targetPlatform = $this->climate->arguments->get('target-platform');
        }

        // 输出文件名/路径
        if ($this->climate->arguments->defined('output')) {
            $this->setOutputPath($this->climate->arguments->get('output'));
        }

        // 构建目录
        if ($this->climate->arguments->defined('build-dir')) {
            $buildDir = $this->climate->arguments->get('build-dir');
            if (!empty($buildDir)) {
                $this->setBuildDir($buildDir);
            }
        }

        // 干运行模式
        if ($this->climate->arguments->defined('dry')) {
            $this->dryRun = true;
        }

        // 用户自定义 C++ include 路径（直接从 argv 解析以支持多值）
        if ($this->hasRepeatableArgvFlag(['-I', '--include-path'])) {
            $this->userIncludePaths = $this->parseRepeatableArgv(['-I', '--include-path']);
        }
        // 用户自定义预处理器宏（直接从 argv 解析以支持多值）
        if ($this->hasRepeatableArgvFlag(['-D', '--define'])) {
            $this->userDefines = $this->parseRepeatableArgv(['-D', '--define']);
        }

        // 链接时优化
        if ($this->climate->arguments->defined('lto')) {
            $this->enableLto = true;
        }

        // clang-format 代码格式化（默认关闭，需显式 --format 开启）
        if ($this->climate->arguments->defined('format')) {
            $this->enableCodeFormattingIfAvailable('--format');
        }

        // 用户自定义链接库（直接从 argv 解析以支持多值）
        if ($this->hasRepeatableArgvFlag(['-l', '--link-lib'])) {
            $this->linkLibs = $this->parseRepeatableArgv(['-l', '--link-lib']);
        }
        // 用户自定义库搜索路径（直接从 argv 解析以支持多值）
        if ($this->hasRepeatableArgvFlag(['-L', '--link-path'])) {
            $this->linkPaths = $this->parseRepeatableArgv(['-L', '--link-path']);
        }
    }

    /** Apply this option early because YAML source conditions depend on it. */
    protected function applyPhpVersionCommandLineArgument(): void
    {
        if ($this->climate->arguments->defined('php-version')) {
            $this->setPhpVersion((string) $this->climate->arguments->get('php-version'));
        }
    }

    /**
     * 从原始 $argv 中解析可重复参数，支持 -X val 和 --long val 两种形式。
     * CLImate 的 multiple 选项只能保留最后一个值，因此需要手动解析。
     *
     * @param string[] $flags 要匹配的标志列表，如 ['-I', '--include-path']
     * @return string[] 收集到的所有值
     */
    protected function parseRepeatableArgv(array $flags): array
    {
        global $argv;
        $values = [];
        for ($i = 1; $i < count($argv); $i++) {
            // 精确匹配标志（如 -I, --include-path）
            if (in_array($argv[$i], $flags, true) && isset($argv[$i + 1]) && $argv[$i + 1] !== '' && $argv[$i + 1][0] !== '-') {
                $values[] = $argv[$i + 1];
                $i++; // 跳过值
            }
            // 合并形式：-I/path 或 --include-path=/path
            elseif (!$this->isLongFlagWithEquals($argv[$i], $flags, $values)) {
                // 检查短标志合并：-I/path
                foreach ($flags as $flag) {
                    if (strlen($flag) === 2 && $flag[0] === '-') {
                        $short = substr($flag, 1);
                        if (preg_match('/^-' . preg_quote($short, '/') . '(.+)$/', $argv[$i], $m)) {
                            $values[] = $m[1];
                        }
                    }
                }
            }
        }
        return $values;
    }

    protected function hasRepeatableArgvFlag(array $flags): bool
    {
        global $argv;

        for ($i = 1; $i < count($argv); $i++) {
            $arg = $argv[$i];
            if (in_array($arg, $flags, true)) {
                return true;
            }

            foreach ($flags as $flag) {
                if (str_starts_with($arg, $flag . '=')) {
                    return true;
                }
                if (strlen($flag) === 2 && $flag[0] === '-') {
                    $short = substr($flag, 1);
                    if (preg_match('/^-' . preg_quote($short, '/') . '(.+)$/', $arg)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * 处理 --flag=value 格式的长标志
     */
    private function isLongFlagWithEquals(string $arg, array $flags, array &$values): bool
    {
        foreach ($flags as $flag) {
            if (str_starts_with($flag, '--') && preg_match('/^' . preg_quote($flag, '/') . '=(.+)$/', $arg, $m)) {
                $values[] = $m[1];
                return true;
            }
        }
        return false;
    }

    private function showVersion(): void
    {
        $this->climate->bold()->out(self::APP_NAME . ' v' . self::VERSION);
    }

    private function enableCodeFormattingIfAvailable(string $source): void
    {
        $clangFormatVersion = shell_exec('clang-format --version');
        if (!empty($clangFormatVersion)) {
            $this->formatCode = true;
            return;
        }

        $this->climate->warning($source . ' requested but clang-format not found, skipping formatting');
    }

    protected function formatCppCode(string $file): void
    {
        if (!$this->formatCode) {
            return;
        }

        $cmd = 'cd ' . escapeshellarg($this->rootPath) . ' && clang-format -i ' . escapeshellarg($file);
        $this->climate->info('format: ' . $this->getRelativePath($file));
        $this->climate->comment($cmd);
        shell_exec($cmd);
    }

    public function save(string $code, string $file): void
    {
        $this->writeFile($file, $code);
        $this->formatCppCode($file);
    }

    public function convertFile(string $file): string
    {
        $previousPhase = $this->enterCompilerPhase(self::PHASE_CONVERT);
        try {
            $file = realpath($file);
            $phpCode = $this->loadFile($file);
            $this->localHeaders = [];
            while (true) {
                try {
                    $cppCode = $this->doConvert($phpCode);
                    $cppFile = $this->getCppFile($file);
                    $this->save($cppCode, $cppFile);
                    $this->phpSrcFiles[] = $file;
                    // 生成 stub 文件，依赖 convert 阶段的 use 等信息
                    $this->genStubFile($this->file);
                    return $cppFile;
                } catch (Redo $e) {
                    continue;
                }
            }
        } finally {
            $this->restoreCompilerPhase($previousPhase);
        }
    }

    public function getRegisterClassFunctionArgs(ClassDef|InterfaceDef $classDef): string
    {
        return implode(', ', $this->getRegisterClassFunctionCeList($classDef));
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

    public function setBuildMode(string $mode): void
    {
        $mode = strtolower(trim($mode));
        $mode = match ($mode) {
            'binary', 'cli' => self::BUILD_MODE_BIN,
            'extension' => self::BUILD_MODE_EXT,
            'library', 'shared', 'dll', 'dylib', 'so' => self::BUILD_MODE_LIB,
            default => $mode,
        };

        if (!in_array($mode, [self::BUILD_MODE_BIN, self::BUILD_MODE_EXT, self::BUILD_MODE_LIB], true)) {
            $this->error("Invalid build mode `{$mode}`. Expected bin, lib, or ext.");
        }

        $this->buildMode = $mode;
    }

    public function setTargetName(string $name): void
    {
        // 如果指定了路径（包含目录分隔符），提取目录和文件名
        if (str_contains($name, '/') || str_contains($name, '\\')) {
            $this->outputDir = dirname($name);
            $name = basename($name);
        }
        $name = str_replace(['-', '*'], '_', $name);
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $name)) {
            $this->climate->red('The target name `' . $name . '` must be a valid identifier');
            exit(1);
        }
        $realTargetPath = $this->rootPath . '/' . $name;
        if (is_dir($realTargetPath)) {
            $this->climate->red('The target name `' . $name . '` must not be a directory');
            exit(1);
        }
        $this->targetName = $name;
    }

    /**
     * Set an explicit output path without using its extension in generated symbols.
     */
    public function setOutputPath(string $path): void
    {
        if (str_contains($path, '/') || str_contains($path, '\\')) {
            $this->outputDir = dirname($path);
            $path = basename($path);
        }

        $extension = pathinfo($path, PATHINFO_EXTENSION);
        if ($extension !== '') {
            $this->explicitOutputExtension = '.' . $extension;
            $path = substr($path, 0, -strlen($this->explicitOutputExtension));
        } else {
            $this->explicitOutputExtension = null;
        }

        $this->hasExplicitOutput = true;
        $this->setTargetName($path);
    }

    protected function getTargetFileName(): string
    {
        $targetFile = $this->targetName;
        if ($this->isBuildModeLib() && !$this->isWindows() && !$this->hasExplicitOutput) {
            $targetFile = 'lib' . $targetFile;
        }

        $extension = $this->explicitOutputExtension ?? $this->getPlatform()->getTargetExtension($this->buildMode);
        if ($extension !== '' && !str_ends_with($targetFile, $extension)) {
            $targetFile .= $extension;
        }

        if ($this->outputDir !== '') {
            $targetFile = rtrim($this->outputDir, '/\\') . '/' . $targetFile;
        }

        return $targetFile;
    }

    public function getLibraryImportStubFile(): string
    {
        $directory = $this->outputDir !== '' ? $this->outputDir : (getcwd() ?: $this->rootPath);
        return rtrim($directory, '/\\') . '/' . $this->targetName . '.stub.php';
    }

    /** @param array<string> $files */
    public function genLibraryImportStub(array $files): string
    {
        $file = $this->getLibraryImportStubFile();
        $generator = new LibraryImportStubGenerator($this->parser, $this->printer);
        $this->writeFile($file, $generator->generate($files, $this->externalImportStubFiles));
        $this->climate->info('generate library import stub: ' . $this->getRelativePath($file));
        return $file;
    }

    public function preprocessArgvAdvanced(): void
    {
        global $argv;
        $processed = [$argv[0]];

        for ($i = 1; $i < count($argv); $i++) {
            $arg = $argv[$i];
            if (preg_match('/^-([a-zA-Z])(.+)$/', $arg, $matches)) {
                $option = $matches[1];
                $value = $matches[2];
                $processed[] = "-{$option}";
                $processed[] = $value;
            } elseif (preg_match('/^-([a-zA-Z]{2,})$/', $arg, $matches)) {
                $options = str_split($matches[1]);
                foreach ($options as $opt) {
                    $processed[] = "-{$opt}";
                }
            } else {
                $processed[] = $arg;
            }
        }
        $argv = $processed;
    }

    public function genDataDeclarations(string $file): void
    {
        $lines[] = '#include <phpx.h>';
        $lines[] = PHP_EOL;
        foreach ($this->globalVars as $name => $type) {
            $lines[] = 'extern THREAD_LOCAL ' . Type::VAR . ' ' . $this->escapeGlobalVar($name) . ';';
        }

        if ($this->literalStrings) {
            $literalStringsCount = count($this->literalStrings);
            $lines[] = 'extern ' . Type::STR . ' ' . self::LITERAL_STRINGS . '[' . $literalStringsCount . '];' . PHP_EOL;
        }

        foreach ($this->constants as $name => $constant) {
            $lines[] = 'extern ' . $constant->type . ' ' . $name . ';';
        }

        // 确保数组大小至少为 1，避免 C/C++ 编译错误
        $classCount = max(1, count($this->classMap));
        $lines[] = 'extern THREAD_LOCAL zend_class_entry *' . self::PREFIX . self::CLASS_MAP . '[' . $classCount . '];' . PHP_EOL;

        $funcCount = max(1, count($this->funcMap));
        $lines[] = 'extern THREAD_LOCAL zend_function *' . self::PREFIX . self::FUNC_MAP . '[' . $funcCount . '];' . PHP_EOL;

        $propCount = max(1, count($this->propMap));
        $lines[] = 'extern THREAD_LOCAL uint32_t ' . self::PREFIX . self::PROP_MAP . '[' . $propCount . '];' . PHP_EOL;

        foreach ($this->getClassLikesWithConstants() as $classDef) {
            foreach ($classDef->constants as $constant) {
                if ($constant->type === Type::ARRAY) {
                    $constName = self::PREFIX . $this->getNativeName($constant->name, $classDef->namespace, $classDef->name);
                    $lines[] = 'extern ' . Type::VAR . ' ' . $constName . ';' . PHP_EOL;
                }
            }
        }

        $code = implode(PHP_EOL, $lines) . PHP_EOL . PHP_EOL;
        $this->writeFile($file, $code);
    }

    public function genExtension(): string
    {
        if ($this->isBuildModeBin()) {
            if (!$this->hasFunction(self::ENTRY_FUNCTION)) {
                $this->climate->red('When the build mode is a binary executable file, the `main()` function must be defined');
                exit(1);
            }
        }
        $file = $this->getBuildDir() . '/extension-' . $this->targetName . '.cc';
        $this->localHeaders = $this->argInfoHeaderFiles;
        $this->genClassCeList();
        $this->indentLevel++;

        $code = '#include <cstring>' . PHP_EOL;
        $code .= $this->genIncludeHeaderFiles();

        if ($this->isBuildModeLib() && !$this->isWindows()) {
            // PHPX's embedded runtime references this CLI-only symbol even when main() is disabled.
            $code .= 'extern "C" void save_ps_args(int, char **) {}' . PHP_EOL;
        }

        if ($this->isBuildModeBin()) {
            $cliHeaders = [
                '#include "php_cli_process_title.h"',
                '#include "php_cli_process_title_arginfo.h"',
            ];
            $code .= 'extern "C" {' . PHP_EOL;
            $code .= implode(PHP_EOL, $cliHeaders) . PHP_EOL;
            $code .= '}' . PHP_EOL;
        }

        $code .= "// global vars \n";
        foreach ($this->globalVars as $name => $type) {
            $code .= 'THREAD_LOCAL ' . Type::VAR . ' ' . $this->escapeGlobalVar($name) . ';' . PHP_EOL;
        }

        $code .= "// class register functions \n";
        foreach ($this->classCeList as $ce) {
            $code .= 'zend_class_entry *' . $ce . ';' . PHP_EOL;
        }

        $code .= "// class entry \n";
        // 确保数组大小至少为 1，避免 C/C++ 编译错误
        $code .= 'THREAD_LOCAL zend_class_entry *' . self::PREFIX . self::CLASS_MAP . '[' . max(1, count($this->classMap)) . '];' . PHP_EOL;

        $code .= "// func \n";
        $code .= 'THREAD_LOCAL zend_function *' . self::PREFIX . self::FUNC_MAP . '[' . max(1, count($this->funcMap)) . '];' . PHP_EOL;

        $code .= "// property \n";
        $code .= 'THREAD_LOCAL uint32_t ' . self::PREFIX . self::PROP_MAP . '[' . max(1, count($this->propMap)) . '];' . PHP_EOL;

        $code .= "// functions \n";

        $code .= <<<'CODE'
zend_class_entry *php_get_class(int class_id, const php::Str &class_name) {
    if (UNEXPECTED(php_class_map[class_id] == nullptr)) {
        php_class_map[class_id] = php::getClassEntrySafe(class_name);
    }
    return php_class_map[class_id];
}

zend_function *php_get_func(int func_id, const php::Str &func_name) {
    if (UNEXPECTED(php_func_map[func_id] == nullptr)) {
        php_func_map[func_id] = php::getFunction(func_name);
    }
    return php_func_map[func_id];
}

zend_function *php_get_method(int func_id, const php::Str &method_name, int class_id, const php::Str &class_name) {
    if (UNEXPECTED(php_func_map[func_id] == nullptr)) {
        auto ce = php_get_class(class_id, class_name);
        php_func_map[func_id] = php::getMethod(ce, method_name);
    }
    return php_func_map[func_id];
}

uint32_t php_get_prop(int prop_id, const php::Str &prop_name, int class_id, const php::Str &class_name) {
    if (UNEXPECTED(php_property_map[prop_id] == 0)) {
        php_property_map[prop_id] = php::getPropertyOffset(class_name, prop_name) + 1024;
    }
    return php_property_map[prop_id] - 1024;
}
CODE;
        $code .= "\n\n";

        $code .= "// literal strings \n";
        if ($this->literalStrings) {
            $code .= Type::STR . ' ' . self::LITERAL_STRINGS . '[] = {' . PHP_EOL;
            foreach ($this->literalStrings as $str => $index) {
                $code .= Type::STR . '{ZEND_STRL("' . $this->escapeString($str) . '"), true}, // [' . $index . ']' . PHP_EOL;
            }
            $code .= '};' . PHP_EOL . PHP_EOL;
        } else {
            $code .= PHP_EOL;
        }

        $code .= "// default argument values \n";
        $code .= $this->genDefaultArgumentHelperDefinitions();

        $code .= "// constants \n";
        foreach ($this->constants as $name => $const) {
            $code .= $const->type . ' ' . $name . ";\n";
        }

        $code .= "// class \n";
        foreach ($this->getClassLikesWithConstants() as $classDef) {
            if ($classDef instanceof ClassDef && !$classDef->trait && !$classDef->enum) {
                $code .= 'static zend_object* (*create_object_' . $classDef->getNamespacedName() . ")(zend_class_entry *class_type);\n";
                $code .= 'static zend_object_handlers property_handlers_' . $classDef->getNamespacedName() . ";\n";
            }
            foreach ($classDef->constants as $constant) {
                if ($constant->type === Type::ARRAY) {
                    $constName = self::PREFIX . $this->getNativeName($constant->name, $classDef->namespace, $classDef->name);
                    $code .= Type::VAR . ' ' . $constName . ";\n";
                }
            }
        }

        $code .= "// clang-format off\n";
        $code .= "static const zend_function_entry ext_functions[] = {\n";
        if ($this->isBuildModeBin()) {
            $code .= $this->getIndent() . "PHP_FE(cli_set_process_title,        arginfo_cli_set_process_title)\n";
            $code .= $this->getIndent() . "PHP_FE(cli_get_process_title,        arginfo_cli_get_process_title)\n";
        }

        foreach ($this->symbols->functions() as $functionDef) {
            if ($functionDef->attributeFactory) {
                continue;
            }
            if ($this->isBuildModeExt() and $functionDef->name === self::ENTRY_FUNCTION) {
                continue;
            }
            if ($functionDef->method) {
                continue;
            }
            $fullName = $functionDef->getNamespacedName();
            $zifName = $this->escapeZendFnName($fullName);
            if ($functionDef->namespace) {
                $code .= $this->getIndent() . 'ZEND_NAMED_FE("' . $this->escapeString($fullName) . '", ZEND_FN(' . $zifName . '), arginfo_' . $zifName . ')' . PHP_EOL;
            } else {
                $code .= $this->getIndent() . 'ZEND_FE(' . $zifName . ', arginfo_' . $zifName . ')' . PHP_EOL;
            }
        }
        $code .= $this->getIndent() . "ZEND_FE_END\n};\n// clang-format on" . PHP_EOL . PHP_EOL;

        // minit begin
        $code .= 'PHP_MINIT_FUNCTION(' . $this->getModuleName() . ') {' . PHP_EOL;
        $code .= 'zend_try {' . PHP_EOL;
        $code .= '// class/interface class entries' . PHP_EOL;
        $code .= 'typephp_register_fiber_generator_class();' . PHP_EOL;
        $code .= 'if (typephp_install_reflection_attribute_handlers() != SUCCESS) {' . PHP_EOL;
        $code .= $this->getIndent() . 'return FAILURE;' . PHP_EOL;
        $code .= '}' . PHP_EOL;
        $code .= $this->genClassPropertyInit() . PHP_EOL;

        $code .= '// register symbols' . PHP_EOL;
        foreach ($this->registerSymbols as $registerSymbolFn) {
            $code .= $registerSymbolFn . '(module_number);' . PHP_EOL;
        }
        $code .= '} zend_end_try();' . PHP_EOL;
        $code .= 'return SUCCESS;' . PHP_EOL;
        $code .= '}' . PHP_EOL . PHP_EOL;
        // minit end

        $code .= 'PHP_MSHUTDOWN_FUNCTION(' . $this->getModuleName() . ') {' . PHP_EOL;
        $code .= 'typephp_uninstall_reflection_attribute_handlers();' . PHP_EOL;
        $code .= 'return SUCCESS;' . PHP_EOL;
        $code .= '}' . PHP_EOL . PHP_EOL;

        $code .= 'THREAD_LOCAL zval globals_array;' . PHP_EOL;

        // php_app_init begin
        $code .= 'void php_app_init() {' . PHP_EOL;
        $code .= '// register constants' . PHP_EOL;
        foreach ($this->constants as $name => $const) {
            $code .= "{$name} = {$const->value};\n";
            $code .= 'php::fn::define(' . $this->genCharPtr($const->name, true) . ', ' . $name . ');' . PHP_EOL;
        }
        $code .= '// global vars ' . PHP_EOL;
        foreach ($this->globalVars as $name => $type) {
            if ($name == 'GLOBALS') {
                continue;
            }
            $code .= 'php::initGlobal(' . $this->genCharPtr($name) . ', ' . $this->escapeGlobalVar($name) . ');' . PHP_EOL;
        }

        $code .= '// static property ' . PHP_EOL;
        foreach ($this->symbols->classes() as $classDef) {
            // Traits are never instantiated on their own; their static properties
            // live on the classes that use them (where the members are flattened).
            // Initialising a default on the trait itself would write to the trait's
            // static property table and, on PHP >= 8.3, trigger a
            // "Accessing static trait property" deprecation when the value is read
            // through `self::` from a consuming class. Skip traits here; the
            // consuming classes still initialise their own (flattened) copies.
            if ($classDef->trait) {
                continue;
            }
            foreach ($classDef->properties as $property) {
                if (!$property->isStatic() || $property->default === null) {
                    continue;
                }
                if ($property->arrayInitPlan) {
                    $statement = 'php::setStaticProperty('
                        . $this->genCharPtr($classDef->getNamespacedName(false), true) . ', '
                        . $this->genCharPtr($property->name) . ', '
                        . $property->arrayInitPlan->expr . ');' . PHP_EOL;
                    $code .= $this->wrapArrayInitPlan($property->arrayInitPlan, $statement);
                } else {
                    $default = $property->type === Type::FLOAT
                        ? $this->convertFloatExpr($property->default)
                        : $property->default;
                    $statement = 'php::setStaticProperty('
                        . $this->genCharPtr($classDef->getNamespacedName(false), true) . ', '
                        . $this->genCharPtr($property->name) . ', '
                        . 'php::Var(' . $default . '));' . PHP_EOL;
                    $code .= $statement;
                }
            }
        }

        $code .= '// class array constants' . PHP_EOL;
        $code .= $this->genClassArrayConstants();
        $code .= '}' . PHP_EOL . PHP_EOL;
        // php_app_init end

        // php_app_clean begin
        $code .= 'void php_app_clean() {' . PHP_EOL;
        foreach ($this->globalVars as $name => $type) {
            if ($name != 'GLOBALS') {
                $code .= $this->escapeGlobalVar($name) . '.unset();' . PHP_EOL;
                $code .= 'php::unsetGlobal("' . $name . '");' . PHP_EOL;
            }
        }
        foreach ($this->constants as $name => $const) {
            if ($const->type !== Type::VAR) {
                continue;
            }
            $code .= $name . '.unset();' . PHP_EOL;
        }

        $code .= '// class array constants' . PHP_EOL;
        foreach ($this->getClassLikesWithConstants() as $classDef) {
            foreach ($classDef->constants as $constant) {
                if ($constant->type === Type::ARRAY) {
                    $constName = self::PREFIX . $this->getNativeName($constant->name, $classDef->namespace, $classDef->name);
                    $code .= $constName . ".unset();\n";

                    $classNameStr = $this->genCharPtr($classDef->getNamespacedName(false), true);
                    $classConstStr = $this->genCharPtr($constant->name);
                    $code .= "php::updateConstant($classNameStr, $classConstStr, php::null);\n";
                }
            }
        }

        // Clean up inherited array constants from child classes
        foreach ($this->symbols->classes() as $className => $classDef) {
            $ownConstNames = [];
            foreach ($classDef->constants as $constant) {
                if ($constant->type === Type::ARRAY) {
                    $ownConstNames[$constant->name] = true;
                }
            }

            $parentName = $this->escapeClass($classDef->extends);
            while ($parentName && $this->symbols->hasClass($parentName)) {
                $parentDef = $this->symbols->class($parentName);
                foreach ($parentDef->constants as $constant) {
                    if ($constant->type === Type::ARRAY && !isset($ownConstNames[$constant->name])) {
                        $ownConstNames[$constant->name] = true;
                        $classNameStr = $this->genCharPtr($classDef->getNamespacedName(false), true);
                        $classConstStr = $this->genCharPtr($constant->name);
                        $code .= "php::updateConstant($classNameStr, $classConstStr, php::null);\n";
                    }
                }
                $parentName = $this->escapeClass($parentDef->extends);
            }

            foreach ($this->getClassImplementedInterfaces($classDef) as $interfaceName) {
                if (!$this->hasInterface($interfaceName)) {
                    continue;
                }
                $interfaceDef = $this->getInterface($interfaceName);
                foreach ($interfaceDef->constants as $constant) {
                    if ($constant->type === Type::ARRAY && !isset($ownConstNames[$constant->name])) {
                        $ownConstNames[$constant->name] = true;
                        $classNameStr = $this->genCharPtr($classDef->getNamespacedName(false), true);
                        $classConstStr = $this->genCharPtr($constant->name);
                        $code .= "php::updateConstant($classNameStr, $classConstStr, php::null);\n";
                    }
                }
            }
        }

        // 扩展模式，需要在 RSHUTDOWN 阶段中清理函数、类、属性表
        if ($this->isBuildModeExt()) {
            $code .= 'std::memset(' . self::PREFIX . self::FUNC_MAP . ', 0, sizeof(' . self::PREFIX . self::FUNC_MAP . '));' . PHP_EOL;
            $code .= 'std::memset(' . self::PREFIX . self::CLASS_MAP . ', 0, sizeof(' . self::PREFIX . self::CLASS_MAP . '));' . PHP_EOL;
            $code .= 'std::memset(' . self::PREFIX . self::PROP_MAP . ', 0, sizeof(' . self::PREFIX . self::PROP_MAP . '));' . PHP_EOL;
        }

        $code .= '}' . PHP_EOL . PHP_EOL;
        // php_app_clean end

        $moduleName = $this->getModuleName();
        // rinit begin
        $code .= 'PHP_RINIT_FUNCTION(' . $moduleName . ') {' . PHP_EOL;
        $code .= 'php::request_init();' . PHP_EOL;
        $code .= 'php_app_init();' . PHP_EOL;

        if ($this->isBuildModeBin()) {
            $entryFunction = $this->symbols->function(self::ENTRY_FUNCTION);
            $entryFile = $entryFunction->sourceFile;
            $entryFileArg = $this->genCharPtr($entryFile, true);
            $entryPrefix = str_repeat("\n", max(0, $entryFunction->startLine - 1));
            if (count($entryFunction->argInfoList) == 2) {
                $entryScript = $entryPrefix . 'global $argc, $argv; main($argc, $argv);';
            } else {
                $entryScript = $entryPrefix . 'main();';
            }
            $code .= 'php::eval(' . $this->genCharPtr($entryScript, true) . ', ' . $entryFileArg . ');' . PHP_EOL;
        }

        $code .= 'return SUCCESS;' . PHP_EOL;
        $code .= '}' . PHP_EOL . PHP_EOL;
        // rinit end

        $code .= <<<CODE
PHP_RSHUTDOWN_FUNCTION({$moduleName}) {
    php_app_clean();
    php::request_shutdown();
    return SUCCESS;
}

zend_module_entry {$moduleName}_module_entry = {
    STANDARD_MODULE_HEADER,
    "{$moduleName}",
    ext_functions,
    PHP_MINIT({$moduleName}),
    PHP_MSHUTDOWN({$moduleName}),
    PHP_RINIT({$moduleName}),
    PHP_RSHUTDOWN({$moduleName}),
    nullptr,
    nullptr,
    STANDARD_MODULE_PROPERTIES,
};
CODE;
        $code .= PHP_EOL . PHP_EOL;

        if ($this->isBuildModeExt()) {
            $code .= "ZEND_GET_MODULE({$moduleName});\n";
        } else {
            $code .= 'zend_module_entry *' . self::PREFIX . 'embed_get_module() {' . PHP_EOL;
            $code .= $this->getIndent() . 'return &' . $moduleName . '_module_entry;' . PHP_EOL;
            $code .= '}' . PHP_EOL;
        }

        $this->indentLevel--;

        $this->writeFile($file, $code);
        $this->formatCppCode($file);
        $this->localHeaders = [];
        return $file;
    }

    public function getModuleName(): string
    {
        return self::MODULE_NAME_PREFIX . $this->targetName;
    }

    /**
     * 检查 phpx/src/misc/ 下的源文件是否已有有效缓存，始终生效（除非指定 --force）。
     * 缓存必须匹配编译命令和 PHP ABI，且 .o 文件必须不早于源文件和 phpx 头文件。
     */
    public function hasMiscObjectFileCache(string $cppFile): bool
    {
        if ($this->climate->arguments->defined('force') || $this->enableProfiler) {
            return false;
        }

        $objectFile = $this->getObjectFile($cppFile);
        if (!file_exists($objectFile)) {
            return false;
        }

        $metadataFile = $this->getMiscObjectCacheMetadataFile($objectFile);
        if (!is_file($metadataFile)) {
            return false;
        }

        $cachedKey = file_get_contents($metadataFile);
        if ($cachedKey === false || trim($cachedKey) !== $this->getMiscObjectCacheKey($cppFile, $objectFile)) {
            return false;
        }

        $objectMtime = filemtime($objectFile);
        if ($objectMtime <= filemtime($cppFile)) {
            return false;
        }

        $phpxDir = $this->getPhpxDir();
        $headerDirs = [$phpxDir . '/include', $phpxDir . '/src/misc'];

        foreach ($headerDirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if ($file->getExtension() === 'h' && $file->getMTime() > $objectMtime) {
                    return false;
                }
            }
        }

        return true;
    }

    protected function getMiscObjectCacheMetadataFile(string $objectFile): string
    {
        return $objectFile . '.typephp-cache';
    }

    protected function getMiscObjectCacheKey(string $sourceFile, string $objectFile): string
    {
        $abi = [
            'php_version_id' => PHP_VERSION_ID,
            'php_api_version' => defined('PHP_API_VERSION') ? constant('PHP_API_VERSION') : null,
            'zend_module_api' => defined('ZEND_MODULE_API_NO') ? constant('ZEND_MODULE_API_NO') : null,
            'php_zts' => defined('PHP_ZTS') ? PHP_ZTS : null,
            'php_debug' => defined('PHP_DEBUG') ? PHP_DEBUG : null,
            'integer_size' => PHP_INT_SIZE,
        ];

        return hash('sha256', $this->buildCompileFileCommand($sourceFile, $objectFile) . "\0" . serialize($abi));
    }

    protected function writeMiscObjectCacheMetadata(string $sourceFile, string $objectFile): void
    {
        $metadataFile = $this->getMiscObjectCacheMetadataFile($objectFile);
        if (file_put_contents($metadataFile, $this->getMiscObjectCacheKey($sourceFile, $objectFile) . PHP_EOL) === false) {
            throw new \RuntimeException('Cannot write misc object cache metadata: ' . $metadataFile);
        }
    }

    protected function invalidateMiscObjectCache(string $objectFile): void
    {
        $metadataFile = $this->getMiscObjectCacheMetadataFile($objectFile);
        if (is_file($metadataFile)) {
            unlink($metadataFile);
        }
    }

    public function isPhpxMiscFile(string $cppFile): bool
    {
        $miscDir = $this->getPhpxDir() . '/src/misc/';
        return str_starts_with($cppFile, $miscDir);
    }

    /**
     * 判断文件是否为 C++ 源文件
     */
    protected function isCppFile(string $filePath): bool
    {
        $extension = pathinfo($filePath, PATHINFO_EXTENSION);
        return in_array($extension, ['cc', 'cpp', 'cxx'], true);
    }

    /**
     * 根据文件扩展名获取语言类型标识（用于 -x 参数）.
     *
     * @return string|null 语言标识（c, assembler, objective-c, objective-c++），
     *                     或 null 表示使用默认检测（C++ 文件）
     */
    protected function getLanguageFromExtension(string $filePath): ?string
    {
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        return match ($ext) {
            'c' => 'c',
            's', 'S' => 'assembler',
            'm' => 'objective-c',
            'mm' => 'objective-c++',
            'cc', 'cpp', 'cxx' => null,
            default => null,
        };
    }

    /**
     * 判断文件是否为原生编译型源文件（C/C++/汇编/ObjC 等）.
     */
    protected function isNativeSourceFile(string $filePath): bool
    {
        return FileScanner::isNativeSourceFile($filePath);
    }

    public function compileFile(string $cppFile, string $objectFile, bool $parallel = false): void
    {
        if ($this->isPhpxMiscFile($cppFile) && $this->hasMiscObjectFileCache($cppFile)) {
            if (!$parallel) {
                $this->climate->darkGray('[cache] skip: ' . $cppFile);
            }
            return;
        }

        $isMiscFile = $this->isPhpxMiscFile($cppFile);
        if ($isMiscFile) {
            $this->invalidateMiscObjectCache($objectFile);
        }

        $language = $this->getLanguageFromExtension($cppFile);
        $options = match ($language) {
            null => $this->getCompileCommandOptions(),
            'c' => $this->getCCompileCommandOptions(),
            default => $this->getNativeCompileCommandOptions($language),
        };
        $result = $this->getNativeBuilder()->compile($cppFile, $objectFile, $options, $language, $parallel);
        if (!$parallel) {
            $this->climate->comment($result['command']);
        }
        if ($result['status'] !== 0) {
            if ($parallel && !empty($result['output'])) {
                foreach ($result['output'] as $line) {
                    $this->climate->red($line);
                }
            }
            $this->error('compile failed: ' . $cppFile);
        }

        if ($isMiscFile) {
            $this->writeMiscObjectCacheMetadata($cppFile, $objectFile);
        }
    }

    protected function buildCompileFileCommand(string $sourceFile, string $objectFile): string
    {
        $language = $this->getLanguageFromExtension($sourceFile);
        $options = match ($language) {
            null => $this->getCompileCommandOptions(),
            'c' => $this->getCCompileCommandOptions(),
            default => $this->getNativeCompileCommandOptions($language),
        };
        return $this->getNativeBuilder()->compileCommand($sourceFile, $objectFile, $options, $language);
    }

    public function compile(array $sourceFiles): array
    {
        $job = $this->maxJob;

        $sourceFiles[] = $this->getPhpxDir() . '/src/misc/typephp_fiber_generator.cc';
        $sourceFiles[] = $this->getPhpxDir() . '/src/misc/typephp_helper.cc';

        // embed 需要 main 函数，以及 cli 的内置函数定义
        if ($this->isBuildModeEmbed()) {
            $sourceFiles[] = $this->getPhpxDir() . '/src/misc/typephp_main.cc';
        }

        if ($this->isBuildModeBin()) {
            $sourceFiles[] = $this->getPhpxDir() . '/src/misc/php_cli_process_title.c';
            $sourceFiles[] = $this->getPhpxDir() . '/src/misc/ps_title.c';
        }

        $this->preparePhpXPrecompiledHeader();

        // Windows 平台：编译资源文件（图标、版本信息等）
        $this->compileResourceFile();

        if (!$this->getPlatform()->supportsPcntlParallelCompile() or $job <= 1) {
            return $this->compileSourceFile($sourceFiles);
        }

        // Unix/Linux/macOS 使用 pcntl 并行编译
        return $this->compileWithPcntl($sourceFiles, $job);
    }

    protected function preparePhpXPrecompiledHeader(): void
    {
        $backend = $this->getCompilerBackend();
        if (!$backend->supportsPrecompiledHeaders()) {
            return;
        }

        $phpxDir = $this->getPhpxDir();
        $phpDir = $this->getPhpDir();
        $dependencies = [
            $phpxDir . '/include',
            $phpxDir . '/src/misc',
            $phpxDir . '/thirdparty/mpdecimal/libmpdec',
            $phpxDir . '/thirdparty/mpdecimal/libmpdec++',
            $phpDir . '/include',
        ];

        try {
            $result = (new PrecompiledHeaderManager($backend, $this->getNativeBuilder()))->prepare(
                $this->globalHeaders,
                $dependencies,
                $this->getBuildDir() . '/cache/pch',
                $this->getPrecompiledHeaderCompileCommandOptions(),
            );
            $this->precompiledHeader = [
                'header' => $result['header'],
                'artifact' => $result['artifact'],
            ];
            $displayArtifact = $this->getRelativePath($result['artifact']);
            $this->climate->darkGray($result['cached']
                ? '[pch] cache: ' . $displayArtifact
                : '[pch] built: ' . $displayArtifact);
        } catch (\Throwable $e) {
            // PCH is an optimization. A compiler-specific failure must not make
            // an otherwise valid TypePHP project unbuildable.
            $this->precompiledHeader = null;
            $this->climate->warning('[pch] disabled: ' . $e->getMessage());
        }
    }

    protected function compileSourceFile(array $sourceFiles): array
    {
        $objectFiles = [];
        $totalFiles = count($sourceFiles);
        $failedFiles = [];

        $this->climate->lightBlue("Starting compilation for {$totalFiles} files");

        $index = 0;
        foreach ($sourceFiles as $cppFile) {
            $objectFile = $this->getObjectFile($cppFile);

            try {
                $this->compileFile($cppFile, $objectFile, false);
                if (is_file($objectFile)) {
                    $objectFiles[] = $objectFile;
                } else {
                    $failedFiles[] = $cppFile;
                    $this->climate->red("Compilation failed: {$cppFile}");
                    $index++;
                    continue;
                }
            } catch (\Throwable $e) {
                $failedFiles[] = $cppFile;
                $this->climate->red("Compilation error: {$cppFile} - " . $e->getMessage());
                $index++;
                continue;
            }

            $index++;
            if ($this->noProgress) {
                $percent = intval($index / $totalFiles * 100);
                $cppFileShorted = $this->removeCommonPrefix($this->buildDir, $cppFile);
                $this->climate->white("[{$index}/{$totalFiles}] {$percent}% {$cppFileShorted}");
            }
        }

        if (!empty($failedFiles)) {
            throw new \Exception('Compilation failed for: ' . implode(', ', $failedFiles));
        }

        $this->climate->green("Successfully compiled {$totalFiles} files");
        return $objectFiles;
    }

    /**
     * Unix/Linux/macOS 平台并行编译（使用 pcntl）
     */
    protected function pcntlWait(?int &$status): int
    {
        return pcntl_wait($status);
    }

    protected function pcntlFork(): int
    {
        return pcntl_fork();
    }

    protected function pcntlLastError(): int
    {
        return pcntl_get_last_error();
    }

    protected function waitForCompileChild(): array
    {
        do {
            $status = null;
            $pid = $this->pcntlWait($status);
            $error = $pid === -1 ? $this->pcntlLastError() : 0;
        } while ($pid === -1 && defined('PCNTL_EINTR') && $error === PCNTL_EINTR);

        if ($pid === -1) {
            $message = function_exists('pcntl_strerror') ? pcntl_strerror($error) : 'error ' . $error;
            throw new \RuntimeException('Failed to wait for compiler process: ' . $message);
        }

        return [$pid, (int) $status];
    }

    protected function compileChildSucceeded(int $status): bool
    {
        return pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0;
    }

    protected function getCompileChildFailureReason(int $status): string
    {
        if (pcntl_wifsignaled($status)) {
            return 'terminated by signal ' . pcntl_wtermsig($status);
        }
        if (pcntl_wifexited($status)) {
            return 'exited with status ' . pcntl_wexitstatus($status);
        }
        return 'terminated abnormally';
    }

    protected function compileWithPcntl(array $sourceFiles, int $job): array
    {
        if (!function_exists('pcntl_fork')) {
            $this->climate->warning('pcntl extension not available, using sequential compilation');
            return $this->compileSourceFile($sourceFiles);
        }

        $totalFiles = count($sourceFiles);
        $this->climate->lightBlue("Starting parallel compilation with {$job} jobs for {$totalFiles} files");
        $progress = null;
        if (!$this->noProgress) {
            $progress = new Progressbar();
            $progress->barStyle([AnsiTerminal::FG_GREEN])
                ->percentageStyle([AnsiTerminal::TEXT_BOLD])
                ->labelStyle([AnsiTerminal::FG_CYAN]);
            $progress->renderInPlace(0, $totalFiles, 'Compiling');
        }
        $result = $this->getNativeBuilder()->dispatchParallel(
            $sourceFiles,
            $job,
            fn(string $source): string => $this->getObjectFile($source),
            function (string $source, string $object): void {
                $this->compileFile($source, $object, true);
            },
            fn(): int => $this->pcntlFork(),
            fn(): array => $this->waitForCompileChild(),
            fn(int $status): bool => $this->compileChildSucceeded($status),
            function (string $source, string $object, int $status, bool $success, int $completed) use ($progress, $totalFiles): void {
                if (!$success) {
                    echo PHP_EOL;
                    $this->climate->red("Compilation failed: {$source} ({$this->getCompileChildFailureReason($status)})");
                }
                if ($this->noProgress) {
                    $percent = $completed >= $totalFiles
                        ? 100
                        : min(99, (int) ceil($completed / $totalFiles * 100));
                    $shortSource = $this->removeCommonPrefix($this->buildDir, $source);
                    $this->climate->white("[{$completed}/{$totalFiles}] {$percent}% {$shortSource}");
                } else {
                    $progress->renderInPlace($completed, $totalFiles, 'Compiling');
                }
            },
        );

        if (!$this->noProgress) {
            echo PHP_EOL;
        }

        if ($result['failures'] !== []) {
            throw new \Exception('Compilation failed for: ' . implode(', ', $result['failures']));
        }
        $this->climate->green("Successfully compiled {$totalFiles} files");
        return $result['objects'];
    }

    public function output(string $message, string $style = 'out'): void
    {
        $this->climate->{$style}($message);
    }

    protected function buildLinkCommand(array $objectFiles, string $targetFile): string
    {
        return $this->getNativeBuilder()->linkCommand($objectFiles, $targetFile, $this->getLinkCommandOptions());
    }

    public function build(array $objectFiles): string
    {
        $targetFile = $this->getTargetFileName();

        // Windows 平台：将 .res 资源文件加入链接
        if ($this->isWindows() && $this->hasResourceFile()) {
            $resFile = $this->getResourceResFile();
            if (file_exists($resFile)) {
                $objectFiles[] = $resFile;
            }
        }

        $buildError = null;
        $result = $this->getNativeBuilder()->link($objectFiles, $targetFile, $this->getLinkCommandOptions());
        $this->climate->comment($result['command']);
        foreach ($result['output'] as $line) {
            $this->climate->out($line);
        }
        if ($result['status'] !== 0) {
            $buildError = 'link failed: ' . $targetFile;
        } elseif (!$result['generated']) {
            $buildError = 'target file not generated: ' . $targetFile;
        }

        if ($buildError !== null) {
            $this->error($buildError);
        }

        $this->climate->green('Build successful: ' . $targetFile);

        return $targetFile;
    }

    protected function getNativeBuilder(): NativeBuilder
    {
        return $this->nativeBuilder ??= new NativeBuilder($this->getCompilerBackend());
    }

    public function isRunRequested(): bool
    {
        return $this->climate->arguments->defined('run');
    }

    public function isDryRun(): bool
    {
        return $this->dryRun;
    }

    public function run(string $targetFile): never
    {
        if ($this->buildMode !== self::BUILD_MODE_BIN) {
            $this->climate->error('--run is only supported in binary mode (-m bin), not library or extension mode');
            exit(1);
        }

        if (DIRECTORY_SEPARATOR !== '\\' && !str_starts_with($targetFile, '/')) {
            $targetFile = './' . $targetFile;
        }

        $targetArgs = $this->getTargetArgs();
        $command = escapeshellcmd($targetFile);
        if (!empty($targetArgs)) {
            $escapedArgs = [];
            foreach ($targetArgs as $targetArg) {
                $escapedArgs[] = escapeshellarg($targetArg);
            }
            $command .= ' ' . implode(' ', $escapedArgs);
        }

        fwrite(STDERR, "Running: {$command}\n");
        passthru($command, $exitCode);
        exit($exitCode);
    }

    public function getTargetArgs(): array
    {
        return $this->climate->arguments->trailingArray() ?? [];
    }

    public function genFunctionDeclarations(string $file): void
    {
        $code = '#pragma once' . PHP_EOL . PHP_EOL;
        $code .= '#include <phpx.h>' . PHP_EOL;
        $code .= '#include <typephp_helper.h>' . PHP_EOL;
        $code .= '#include <typephp_fiber_generator.h>' . PHP_EOL;
        $code .= PHP_EOL;

        if ($this->isBuildModeLib()) {
            $code .= $this->genLibraryApiMacro($this->targetName);
        }
        $importLibraries = [];
        foreach ($this->symbols->functions() as $function) {
            if ($this->isImportedFunction($function)) {
                $importLibraries[$function->importLibrary] = true;
            }
        }
        foreach (array_keys($importLibraries) as $library) {
            $code .= $this->genLibraryImportMacro($library);
        }

        $code .= $this->genDefaultArgumentHelperDeclarations();

        foreach ($this->symbols->functions() as $name => $func) {
            $functionDeclarationPrefix = $this->getFunctionDeclarationPrefix($func);
            $list = [];
            if ($func->method) {
                $list[] = Type::OBJECT . ' &this_';
            }
            $argInfoList = $func->argInfoList;
            if ($argInfoList) {
                foreach ($argInfoList as $argumentIndex => $argInfo) {
                    if ($argInfo->variadic) {
                        $arg = Type::ARRAY . ' ' . $argInfo->name
                            . ' = ' . $this->genDefaultArgumentExpr($name, $argumentIndex);
                    } else {
                        $arg = $this->genArgumentDeclaration($argInfo);
                        if ($argInfo->default !== '' && !$this->isConstructorNativeFunction($func)) {
                            $arg .= ' = ' . $this->genDefaultArgumentExpr($name, $argumentIndex);
                        }
                    }
                    $list[] = $arg;
                }
            }
            $params = implode(', ', $list);
            $functionAttribute = $this->getFunctionOptimizationAttribute($func);
            $code .= $functionDeclarationPrefix . $functionAttribute . ($func->returnsByRef ? Type::REF : $func->returnType) . ' ' . self::PREFIX . $name . '(' . $params . ');' . PHP_EOL;
            if ($func->hasMultiReturn()) {
                $code .= 'namespace ' . self::MULTI_RETURN_NAMESPACE . ' {' . PHP_EOL;
                $code .= $functionDeclarationPrefix . $functionAttribute . $func->getMultiReturnCppType() . ' ' . self::PREFIX . $name . '(' . $params . ');' . PHP_EOL;
                $code .= '}' . PHP_EOL;
            }
        }

        $this->writeFile($file, $code);
    }

    protected function getLibraryApiMacroName(): string
    {
        return 'TYPEPHP_' . strtoupper($this->targetName) . '_API';
    }

    protected function genLibraryApiMacro(string $library): string
    {
        $apiMacro = $this->getNamedLibraryApiMacroName($library);
        $exportsMacro = $this->getNamedLibraryExportsMacroName($library);
        $code = "#if defined({$exportsMacro})\n";
        $code .= "# define {$apiMacro} TYPEPHP_SYMBOL_EXPORT\n";
        $code .= "#else\n";
        $code .= "# define {$apiMacro} TYPEPHP_SYMBOL_IMPORT\n";
        return $code . "#endif\n\n";
    }

    protected function genLibraryImportMacro(string $library): string
    {
        $importMacro = $this->getNamedLibraryImportMacroName($library);
        return "#define {$importMacro} TYPEPHP_SYMBOL_IMPORT\n\n";
    }

    protected function getFunctionDeclarationPrefix(FunctionDef $function): string
    {
        if ($this->isImportedFunction($function)) {
            return $this->getNamedLibraryImportMacroName($function->importLibrary) . ' ';
        }
        if ($this->isBuildModeLib() && $function->exported) {
            return $this->getLibraryApiMacroName() . ' ';
        }
        return 'extern ';
    }

    protected function getFunctionOptimizationAttribute(FunctionDef $function): string
    {
        if ($function->hot) {
            return 'TYPEPHP_HOT_ATTRIBUTE ';
        }
        if ($function->cold) {
            return 'TYPEPHP_COLD_ATTRIBUTE ';
        }
        return '';
    }

    protected function isImportedFunction(FunctionDef $function): bool
    {
        return $function->importLibrary !== '';
    }

    protected function getNamedLibraryApiMacroName(string $library): string
    {
        return 'TYPEPHP_' . strtoupper($library) . '_API';
    }

    protected function getNamedLibraryImportMacroName(string $library): string
    {
        return 'TYPEPHP_' . strtoupper($library) . '_IMPORT';
    }

    protected function getNamedLibraryExportsMacroName(string $library): string
    {
        return 'TYPEPHP_' . strtoupper($library) . '_EXPORTS';
    }

    protected function getLibraryExportsMacroName(): string
    {
        return $this->getNamedLibraryExportsMacroName($this->targetName);
    }

    public function getBuildMode(): string
    {
        return $this->buildMode;
    }

    public function getArgInfoStubFilename(string $stubFile): string
    {
        $rs = str_replace(['.stub.php', '.php'], '', $stubFile);
        return str_replace('-', '_', $rs);
    }

    public function getArgInfoHeaderFile(string $file, bool $relative = false): string
    {
        $filePath = $this->getRelativePath(str_replace(['.stub.php', '.php'], '', $file));
        $filename = self::PREFIX . str_replace(['/', '\\'], '_', $filePath);
        $filename = $this->escapeFileName($filename);
        $absPath = $this->getIncludeDir() . "/{$filename}_arginfo.h";
        if ($relative) {
            return ltrim($this->removeCommonPrefix($this->getIncludeDir(), $absPath), '/');
        }
        return $absPath;
    }

    public function genIncludeHeaderFiles(): string
    {
        $headers = array_merge($this->globalHeaders, [
            "php_{$this->targetName}_func_decl.h",
            "php_{$this->targetName}_data_decl.h",
        ], $this->localHeaders);
        $lines = [];
        foreach ($headers as $header) {
            $lines[] = '#include <' . $header . '>';
        }

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    public function genClassPropertyInit(): string
    {
        $code = '';
        foreach ($this->classCeList as $ce) {
            $info = $this->classCeInfo[$ce] ?? $this->getInternalCeInfo($ce);
            $code .= "{$ce} = {$info['func']}({$info['args']});\n";
            $classDef = !empty($info['classDef']) ? $info['classDef'] : null;

            /**
             * @var ClassDef $classDef
             */
            if ($classDef && !$classDef->trait && !$classDef->enum) {
                $className = $classDef->getNamespacedName();
                $handlers = "property_handlers_{$className}";
                $initBlock = '';
                foreach ($classDef->properties as $property) {
                    if ($property->isStatic() || $property->default === null) {
                        continue;
                    }
                    if ($property->arrayInitPlan) {
                        $init = "auto value = {$property->arrayInitPlan->expr};\n";
                        $init .= 'zend_update_property(' . $ce . ', obj, ' . $this->genZendStrl($property->name) . ", value.ptr());\n";
                        $init .= "php::throwErrorIfOccurred();\n";
                        $initBlock .= $this->wrapArrayInitPlan($property->arrayInitPlan, $init);
                    } else {
                        // Scalar / constant / null default value. Wrap it in a
                        // php::Var so it can be stored as a zval in the object's
                        // property table via zend_update_property. Each property is
                        // wrapped in its own block so the local `value` does not
                        // clash with siblings declared in the same create_object body.
                        $default = $property->type === Type::FLOAT
                            ? $this->convertFloatExpr($property->default)
                            : $property->default;
                        $init = "do {\n";
                        $init .= "auto value = php::Var({$default});\n";
                        $init .= 'zend_update_property(' . $ce . ', obj, ' . $this->genZendStrl($property->name) . ", value.ptr());\n";
                        $init .= "php::throwErrorIfOccurred();\n";
                        $init .= "} while (0);\n";
                        $initBlock .= $init;
                    }
                }

                $delegateToParentAllocator = $this->parentHasCustomCreateObjectOnPhp84($classDef);
                $buildCreateBody = function () use ($classDef, $className, $handlers, $initBlock, $delegateToParentAllocator): string {
                    $body = $classDef->ctorInit;
                    $body .= "auto obj = typephp_create_object_with_defaults(\n";
                    $body .= "class_type, create_object_{$className}, &{$handlers}, ";
                    $body .= ($delegateToParentAllocator ? 'true' : 'false') . ",\n";
                    $body .= "[&](zend_object *obj) {\n";
                    $body .= $initBlock;
                    $body .= "});\n";
                    $body .= $classDef->ctorClean;
                    return $body . "return obj;\n";
                };

                $code .= "typephp_install_property_handlers({$ce}, &{$handlers});\n";
                $code .= "#if (PHP_VERSION_ID < 80400)\n";
                $code .= "create_object_{$className} = php_get_create_object_fn({$ce});\n";
                $code .= "{$ce}->create_object = [](zend_class_entry *class_type) -> zend_object* {\n";
                $code .= $buildCreateBody();
                $code .= "};\n";
                if ($classDef->requireCtor || $this->classHasAsymmetricOrHookedProperty($classDef)) {
                    $code .= "#else\n";
                    $code .= "create_object_{$className} = php_get_create_object_fn({$ce});\n";
                    $code .= "{$ce}->create_object = [](zend_class_entry *class_type) -> zend_object* {\n";
                    $code .= $buildCreateBody();
                    $code .= "};\n";
                }
                $code .= "#endif\n";
            }
        }
        return $code;
    }

    /**
     * Whether the given class (or any of its ancestors) declares an asymmetric
     * visibility property (private(set)/protected(set)) or a hooked property
     * (getter/setter). Such classes install a custom write_property handler, and
     * on PHP >= 8.4 that handler lives in the class's default object handlers, so
     * the engine's object_properties_init would reject inherited default values
     * unless we generate our own create_object that initializes with the standard
     * handlers first.
     */
    private function classHasAsymmetricOrHookedProperty(ClassDef $classDef): bool
    {
        $current = $classDef;
        $seen = [];
        while ($current !== null) {
            $key = strtolower(ltrim($current->getNamespacedName(), '\\'));
            if (isset($seen[$key])) {
                break;
            }
            $seen[$key] = true;
            foreach ($current->properties as $property) {
                if ($property->isPrivateSet()
                    || $property->isProtectedSet()
                    || $property->getter !== null
                    || $property->setter !== null
                ) {
                    return true;
                }
            }
            if (!$current->extends) {
                break;
            }
            $parent = $this->getClassDef($current->extends);
            if ($parent === null) {
                break;
            }
            $current = $parent;
        }
        return false;
    }

    private function parentHasCustomCreateObjectOnPhp84(ClassDef $classDef): bool
    {
        if ($classDef->extends === '') {
            return false;
        }
        if ($classDef->inheritedFromInternalClass) {
            return true;
        }

        $parent = $this->getClassDef($classDef->extends);
        while ($parent !== null) {
            foreach ($parent->properties as $property) {
                if (!$property->isStatic() && $property->default !== null) {
                    return true;
                }
            }
            if ($this->classHasAsymmetricOrHookedProperty($parent)) {
                return true;
            }
            if ($parent->extends === '') {
                break;
            }
            if ($parent->inheritedFromInternalClass) {
                return true;
            }
            $parent = $this->getClassDef($parent->extends);
        }
        return false;
    }

    protected function getRegisterClassFunction(string $name): string
    {
        return self::PREFIX . 'register_class_' . $name;
    }

    protected function getRegisterClassFunctionCeList(ClassDef|InterfaceDef $classDef): array
    {
        $list = [];
        if ($classDef instanceof InterfaceDef) {
            foreach ($classDef->extendsList ?: ($classDef->extends ? [$classDef->extends] : []) as $parentInterface) {
                $list[] = self::PREFIX . 'class_entry_' . $this->escapeCeName($parentInterface);
            }
            return $list;
        }

        $parentCe = $this->getParentClassCe($classDef);
        if ($parentCe !== '') {
            $list = [$parentCe];
        }
        $implements = $this->getImplementCe($classDef);

        return array_merge($list, $implements);
    }

    protected function getClassCe(ClassLikeDef $classDef): string
    {
        return self::PREFIX . 'class_entry_' . $this->escapeCeName($classDef->getNamespacedName());
    }

    /**
     * @return array<ClassDef|InterfaceDef>
     */
    private function getClassLikesWithConstants(): array
    {
        return array_merge($this->symbols->classes(), $this->symbols->interfaces());
    }

    protected function getFilesFromDir(string $path): array
    {
        $scanner = new FileScanner($path);

        return $scanner->scan();
    }

    protected function genClassArrayConstants(): string
    {
        $code = '';
        foreach ($this->getClassLikesWithConstants() as $classDef) {
            foreach ($classDef->constants as $constant) {
                if ($constant->type === Type::ARRAY) {
                    $constName = self::PREFIX . $this->getNativeName($constant->name, $classDef->namespace, $classDef->name);
                    $code .= "do {\n";
                    $code .= $constant->arrayExpr;
                    $code .= $constName . ' = ' . $constant->value . ";\n";
                    $classNameStr = $this->genCharPtr($classDef->getNamespacedName(false), true);
                    $classConstStr = $this->genCharPtr($constant->name);
                    $code .= "php::updateConstant($classNameStr, $classConstStr, {$constant->value});\n";
                    $code .= "} while(0);\n";
                }
            }
        }

        // Propagate array constants to child classes that don't override them
        foreach ($this->symbols->classes() as $className => $classDef) {
            $ownConstNames = [];
            foreach ($classDef->constants as $constant) {
                if ($constant->type === Type::ARRAY) {
                    $ownConstNames[$constant->name] = true;
                }
            }

            $parentName = $this->escapeClass($classDef->extends);
            while ($parentName && $this->symbols->hasClass($parentName)) {
                $parentDef = $this->symbols->class($parentName);
                foreach ($parentDef->constants as $constant) {
                    if ($constant->type === Type::ARRAY && !isset($ownConstNames[$constant->name])) {
                        $ownConstNames[$constant->name] = true;
                        $constName = self::PREFIX . $this->getNativeName($constant->name, $parentDef->namespace, $parentDef->name);
                        $classNameStr = $this->genCharPtr($classDef->getNamespacedName(false), true);
                        $classConstStr = $this->genCharPtr($constant->name);
                        $code .= "php::updateConstant($classNameStr, $classConstStr, {$constName});\n";
                    }
                }
                $parentName = $this->escapeClass($parentDef->extends);
            }

            foreach ($this->getClassImplementedInterfaces($classDef) as $interfaceName) {
                if (!$this->hasInterface($interfaceName)) {
                    continue;
                }
                $interfaceDef = $this->getInterface($interfaceName);
                foreach ($interfaceDef->constants as $constant) {
                    if ($constant->type === Type::ARRAY && !isset($ownConstNames[$constant->name])) {
                        $ownConstNames[$constant->name] = true;
                        $constName = self::PREFIX . $this->getNativeName($constant->name, $interfaceDef->namespace, $interfaceDef->name);
                        $classNameStr = $this->genCharPtr($classDef->getNamespacedName(false), true);
                        $classConstStr = $this->genCharPtr($constant->name);
                        $code .= "php::updateConstant($classNameStr, $classConstStr, {$constName});\n";
                    }
                }
            }
        }

        return $code;
    }

    protected function getAbsolutePath(string $path, string $projectDir): string
    {
        $absPath = $this->resolvePath($path, $projectDir, 'Source path');
        return realpath($absPath);
    }

    protected function resolvePath(string $path, string $baseDir, string $label = 'Path'): string
    {
        $path = trim($path);
        if ($path === '') {
            $this->error($label . ' must not be empty');
        }

        if ($this->isAbsolutePath($path)) {
            return $path;
        }

        return $baseDir . '/' . $path;
    }

    protected function isAbsolutePath(string $path): bool
    {
        return $path !== ''
            && (
                $path[0] === '/'
                || $path[0] === '\\'
                || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1
            );
    }

    protected function parseProjectYaml(string $path): array
    {
        $cfg = $this->getProjectYamlLoader()->load($path);
        $projectDir = dirname($path);

        if (array_key_exists('php-version', $cfg) && !$this->climate->arguments->defined('php-version')) {
            $this->setPhpVersion((string) $cfg['php-version']);
        }

        if (!empty($cfg['sources'])) {
            $sources = $cfg['sources'];
            if (!is_array($sources)) {
                $this->error('`sources` must be array');
            }
            $list = [];
            foreach ($sources as $sourceEntry) {
                [$src, $condition] = $this->parseProjectYamlSourceEntry($sourceEntry);
                if ($condition !== null && !$this->evaluateProjectYamlCondition($condition)) {
                    continue;
                }
                $realPath = $this->getAbsolutePath($src, $projectDir);
                if (!$realPath) {
                    $this->error('Source file not exists: `' . $src . '`');
                }
                if (is_file($realPath)) {
                    $list[] = $realPath;
                    $this->sourceDirs[] = basename($realPath);
                } else {
                    $tmp = $this->getFilesFromDir($realPath);
                    $list = array_merge($list, $tmp);
                    $this->sourceDirs[] = $realPath;
                }
            }
        } else {
            $list = $this->getFilesFromDir($projectDir);
        }

        if (array_key_exists('optimize', $cfg)) {
            $this->optimizeLevel = (int) $cfg['optimize'];
        }

        if (array_key_exists('job', $cfg)) {
            $this->maxJob = (int) $cfg['job'];
        }

        if (!empty($cfg['debug'])) {
            $this->debug = true;
        }

        if (!empty($cfg['no-literal-strings'])) {
            $this->noLiteralStrings = true;
        }

        if (!empty($cfg['profile'])) {
            if (!$this->isLinux()) {
                $this->climate->error('`profile` in YAML is only supported on Linux (requires gperftools)');
                exit(1);
            }
            $this->enableProfiler = true;
        }

        if (!empty($cfg['no-progress'])) {
            $this->noProgress = true;
        }

        if (!empty($cfg['no-console'])) {
            $this->noConsole = true;
        }

        $sanitize = $cfg['sanitize'] ?? null;
        if (!empty($sanitize)) {
            $this->sanitize = (string) $sanitize;
        }

        // 读取 cxx-flags
        $cxxFlags = $cfg['cxx-flags'] ?? null;
        if (!empty($cxxFlags)) {
            if (is_array($cxxFlags)) {
                $this->cxxFlags = implode(' ', $cxxFlags);
            } else {
                $this->cxxFlags = str_replace("\n", ' ', $cxxFlags);
            }
        }

        // 读取 cxx-std
        $cxxStd = $cfg['cxx-std'] ?? null;
        if (!empty($cxxStd)) {
            $this->cxxStd = $cxxStd;
        }

        // 读取 march（目标 CPU 指令集）
        $march = $cfg['march'] ?? null;
        if (!empty($march)) {
            $this->march = $march;
        }

        // 读取 target-platform
        $targetPlatform = $cfg['target-platform'] ?? null;
        if (!empty($targetPlatform)) {
            $this->targetPlatform = (string) $targetPlatform;
        }

        // 读取 build-dir
        $buildDir = $cfg['build-dir'] ?? null;
        if (!empty($buildDir)) {
            $this->setBuildDir($this->resolvePath((string) $buildDir, $projectDir, 'Build path'));
        }

        if (!empty($cfg['dry'])) {
            $this->dryRun = true;
        }

        // 读取 ld-flags
        $ldflags = $cfg['ld-flags'] ?? null;
        if (!empty($ldflags)) {
            if (is_array($ldflags)) {
                $this->ldflags = implode(' ', $ldflags);
            } else {
                $this->ldflags = str_replace("\n", ' ', $ldflags);
            }
        }

        // 读取 include-paths
        $includePaths = $cfg['include-paths'] ?? null;
        if (!empty($includePaths) && is_array($includePaths)) {
            foreach ($includePaths as $includePath) {
                $this->userIncludePaths[] = $this->resolvePath((string) $includePath, $projectDir, 'Include path');
            }
        }

        // 读取 defines
        $defines = $cfg['defines'] ?? null;
        if (!empty($defines) && is_array($defines)) {
            foreach ($defines as $define) {
                $this->userDefines[] = (string) $define;
            }
        }

        // 读取 lto
        if (!empty($cfg['lto'])) {
            $this->enableLto = true;
        }

        // 读取 format
        if (!empty($cfg['format'])) {
            $this->enableCodeFormattingIfAvailable('YAML format');
        }

        // 读取 link-libs
        $linkLibs = $cfg['link-libs'] ?? null;
        if (!empty($linkLibs) && is_array($linkLibs)) {
            foreach ($linkLibs as $lib) {
                $this->linkLibs[] = (string)$lib;
            }
        }

        // 读取 link-paths
        $linkPaths = $cfg['link-paths'] ?? null;
        if (!empty($linkPaths) && is_array($linkPaths)) {
            foreach ($linkPaths as $linkPath) {
                $this->linkPaths[] = $this->resolvePath((string) $linkPath, $projectDir, 'Link path');
            }
        }

        // 读取 output/name。name 只表示目标名，不能按 YAML 目录解析成输出路径。
        $output = $cfg['output'] ?? null;
        if (!empty($output)) {
            $this->setOutputPath($this->resolvePath((string) $output, $projectDir, 'Output path'));
        } elseif (!empty($cfg['name'])) {
            $this->setTargetName((string) $cfg['name']);
        }

        // 读取 cpp-compiler
        $cppCompiler = $cfg['cpp-compiler'] ?? null;
        if (!empty($cppCompiler)) {
            $this->setCppCompiler($cppCompiler);
        }

        // 读取 mode/type/build-mode（支持 CLI/YAML 两套命名）
        $buildMode = $cfg['mode'] ?? $cfg['build-mode'] ?? $cfg['type'] ?? null;
        if (!empty($buildMode)) {
            $this->setBuildMode((string) $buildMode);
        }

        // 读取 ignore（支持中横线和下划线）
        $ignore = $cfg['ignore'] ?? null;
        if (!empty($ignore)) {
            if (!is_array($ignore)) {
                $this->error('`ignore` must be array');
            }
            foreach ($ignore as $src) {
                if (preg_match('/ext-([a-z0-9_]+)/i', $src, $matches)) {
                    $this->ignoreExtensions[] = $matches[1];
                    continue;
                }
                $realPath = $this->getAbsolutePath($src, $projectDir);
                if (!$realPath) {
                    $this->error('Source file not exists: `' . $src . '`');
                }
                $this->ignorePaths[] = $realPath;
            }
        }

        // 读取 resource（Windows 资源配置：图标、版本信息）
        $resource = $cfg['resource'] ?? null;
        if (!empty($resource)) {
            if (!is_array($resource)) {
                $this->error('`resource` must be array');
            }
            // 验证图标文件是否存在
            if (!empty($resource['icon'])) {
                $iconPath = $resource['icon'];
                if (!preg_match('/^[A-Za-z]:\\|^\//', $iconPath)) {
                    $iconPath = $projectDir . DIRECTORY_SEPARATOR . $iconPath;
                }
                if (!file_exists($iconPath)) {
                    $this->error('Icon file not exists: `' . $resource['icon'] . '`');
                }
            }
            $this->resourceConfig = $resource;
            $this->resourceConfig['_projectDir'] = $projectDir;
        }

        // 读取 manifest（Windows 清单文件，与 resource 同级，缺省不携带）
        $manifest = $cfg['manifest'] ?? null;
        if (!empty($manifest)) {
            if (!is_string($manifest)) {
                $this->error('`manifest` must be a string (path to manifest file)');
            }
            $manifestPath = $manifest;
            if (!preg_match('/^[A-Za-z]:\\|^\//', $manifestPath)) {
                $manifestPath = $projectDir . DIRECTORY_SEPARATOR . $manifestPath;
            }
            if (!file_exists($manifestPath)) {
                $this->error('Manifest file not exists: `' . $manifest . '`');
            }
            if (empty($this->resourceConfig)) {
                $this->resourceConfig = ['_projectDir' => $projectDir];
            }
            $this->resourceConfig['manifest'] = $manifest;
        }

        return $this->filterIgnoredFiles($list);
    }

    /**
     * @return array{0: string, 1: string|null}
     */
    protected function parseProjectYamlSourceEntry(mixed $entry): array
    {
        return $this->getProjectYamlLoader()->parseSourceEntry($entry);
    }

    protected function evaluateProjectYamlCondition(string $condition): bool
    {
        return $this->getProjectYamlLoader()->evaluateCondition($condition);
    }

    protected function getProjectYamlLoader(): ProjectYamlLoader
    {
        $this->projectYamlLoader ??= new ProjectYamlLoader($this->phpVersion, fn(string $message): never => $this->error($message));
        $this->projectYamlLoader->setPhpVersion($this->phpVersion);
        return $this->projectYamlLoader;
    }

    protected function getInternalCeInfo(string $ce): array
    {
        return [
            'func' => Symbol::getClassEntrySafe(),
            'args' => '"' . substr($ce, strlen(self::PREFIX . 'class_entry_')) . '"',
        ];
    }

    protected function getParentClassCe(ClassLikeDef $classDef): string
    {
        if (!$classDef->extends) {
            return '';
        }

        return self::PREFIX . 'class_entry_' . $this->escapeCeName($classDef->extends);
    }

    protected function doConvert(string $phpCode): string
    {
        $this->climate->info('convert: ' . $this->getRelativePath($this->file));

        $ast = $this->parser->parse($phpCode);
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver(null, ['replaceNodes' => false]));
        $traverser->addVisitor(new Visitor(sourceFile: $this->file));
        $traverser->addVisitor(new ConstantExpressionValidationVisitor($this->phpVersion));
        $traverser->addVisitor(new RuntimeAttributeFactoryLowering($this->file));

        $stmts = $traverser->traverse($ast);

        $this->resetFile();
        $this->resetNamespace();
        $this->resetClass();
        $this->resetMethod();
        $this->resetFunction();

        $cppCode = '';
        foreach ($stmts as $v) {
            $type = $v->getType();
            switch ($type) {
                case 'Stmt_Declare':
                    $this->parseDeclare($v);
                    break;
                case 'Stmt_Namespace':
                    $cppCode .= $this->parseNamespace($v);
                    break;
                case 'Stmt_Class':
                case 'Stmt_Trait':
                case 'Stmt_Enum':
                    $cppCode .= $this->parseClass($v);
                    break;
                case 'Stmt_Use':
                    $cppCode .= $this->parseUse($v) . PHP_EOL;
                    break;
                case 'Stmt_GroupUse':
                    $this->parseGroupUse($v);
                    break;
                case 'Stmt_Function':
                    $cppCode .= $this->parseFunction($v) . PHP_EOL;
                    break;
                case 'Stmt_Const':
                    $this->parseConstDef($v);
                    break;
                case 'Stmt_Interface':
                    $this->validateInterfaceOverrideAttributes($v);
                    break;
                case 'Stmt_Nop':
                    break;
                default:
                    abort($v);
                    break;
            }
        }

        foreach ($this->classesDefineInFile as $classDef) {
            $cppCode .= $this->genClassWrapper($classDef);
        }

        foreach ($this->interfacesDefineInFile as $interfaceDef) {
            $cppCode .= $this->genClassWrapper($interfaceDef);
        }

        foreach ($this->functionDefineInFile as $functionDef) {
            if ($functionDef->attributeFactory) {
                continue;
            }
            $cppCode .= $this->genFunctionWrapper($functionDef);
        }

        $constDataCode = '';
        foreach ($this->constData as $name => $data) {
            $constDataCode .= 'static const unsigned char ' . $name . '[] = {' . $data . '};' . PHP_EOL;
        }
        $constDataCode .= PHP_EOL;

        return $this->genIncludeHeaderFiles() . $constDataCode . $cppCode;
    }

    protected function genClassCeList(): void
    {
        if (empty($this->symbols->interfaces()) and empty($this->symbols->classes())) {
            return;
        }

        $sorter = new StringSort();

        foreach ($this->symbols->interfaces() as $interfaceDef) {
            $ce = $this->getClassCe($interfaceDef);
            $deps = [];

            foreach ($interfaceDef->extendsList ?: ($interfaceDef->extends ? [$interfaceDef->extends] : []) as $parent) {
                $tmpCe = self::PREFIX . 'class_entry_' . $this->escapeCeName($parent);
                // 不存在的接口，说明可能是内置接口
                if (!$this->hasInterface($parent)) {
                    $sorter->add($tmpCe);
                }
                $deps[] = $tmpCe;
            }

            $this->classCeInfo[$ce] = [
                'deps' => $deps,
                'func' => $this->getRegisterClassFunction($interfaceDef->getNamespacedName()),
                'args' => $this->getRegisterClassFunctionArgs($interfaceDef),
                'argDef' => $this->getRegisterClassFunctionArgDef($interfaceDef),
            ];
            $sorter->add($ce, $deps);
        }

        foreach ($this->symbols->classes() as $classDef) {
            $ce = $this->getClassCe($classDef);
            $deps = [];
            $parent = $classDef->extends;
            if ($parent) {
                // 不存在的父类，说明可能是内置类
                $tmpCe = $this->getParentClassCe($classDef);
                if (!$this->symbols->hasClass($parent)) {
                    $sorter->add($tmpCe);
                }
                $deps[] = $tmpCe;
            }

            $implements = $classDef->implements;
            if ($implements) {
                foreach ($implements as $interface) {
                    $tmpCe = self::PREFIX . 'class_entry_' . $this->escapeCeName($interface);
                    if (!$this->symbols->hasInterface($interface)) {
                        $sorter->add($tmpCe);
                    }
                    $deps[] = $tmpCe;
                }
            }

            $this->classCeInfo[$ce] = [
                'classDef' => $classDef,
                'deps' => $deps,
                'func' => $this->getRegisterClassFunction($classDef->getNamespacedName()),
                'args' => $this->getRegisterClassFunctionArgs($classDef),
                'argDef' => $this->getRegisterClassFunctionArgDef($classDef),
            ];
            $sorter->add($ce, $deps);
        }

        $this->classCeList = $sorter->sort();
    }

    protected function getNativeMethodName(ClassDef $classDef, MethodDef $methodDef): string
    {
        return $this->getNativeName($methodDef->name, $classDef->namespace, $classDef->name);
    }

    protected function parseDeclare(mixed $v): void
    {
        $declares = $v->declares;
        foreach ($declares as $declare) {
            $key = $this->parseIdentifier($declare->key);
            $value = match (true) {
                $declare->value instanceof Node\Scalar\String_ => $declare->value->value,
                $declare->value instanceof Node\Scalar\Int_ => (string) $declare->value->value,
                default => $this->parseIdentifier($declare->value),
            };
            if ($key === 'ticks') {
                $this->fatalError($v, 'declare(ticks=1) is not supported');
            } elseif ($key === 'encoding') {
                if (strtolower($value) !== 'utf-8') {
                    $this->fatalError($v, 'declare(encoding="' . $value . '") is not supported, only UTF-8 is supported');
                }
            } elseif ($key === 'strict_types') {
                if (!($declare->value instanceof Node\Scalar\Int_) or $declare->value->value !== 1) {
                    $this->fatalError($v, 'declare(strict_types=0) is not allowed, only strict_types=1 is supported');
                }
            } else {
                $this->fatalError($v, 'declare(' . $key . '=' . $value . ') is not supported');
            }
        }
    }

    protected function parseNamespace(Node\Stmt\Namespace_ $node): string
    {
        $ns = $node->name ? $this->parseIdentifier($node->name) : '';
        $code = '';

        $this->resetNamespace();
        $this->resetClass();
        $this->resetMethod();
        $this->resetFunction();

        $this->namespace = $ns;
        $ns_end = '';

        foreach ($node->stmts as $v2) {
            $type2 = $v2->getType();
            switch ($type2) {
                case 'Stmt_Class':
                case 'Stmt_Trait':
                case 'Stmt_Enum':
                    $code .= $this->parseClass($v2);
                    break;
                case 'Stmt_Const':
                    $this->parseConstDef($v2);
                    break;
                case 'Stmt_Function':
                    $code .= $this->parseFunction($v2) . PHP_EOL;
                    break;
                case 'Stmt_Use':
                    $code .= $this->parseUse($v2) . PHP_EOL;
                    break;
                case 'Stmt_GroupUse':
                    $this->parseGroupUse($v2);
                    break;
                case 'Stmt_Interface':
                    $this->validateInterfaceOverrideAttributes($v2);
                    break;
                default:
                    abort($v2);
                    break;
            }
        }
        $code .= $ns_end;
        $this->resetNamespace();

        return $code;
    }

    protected function genStubFile(string $file): void
    {
        $headerFile = $this->getArgInfoHeaderFile($file, true);

        $this->climate->info('generate arginfo file: ' . $this->getRelativePath($file));
        generateStubFile($file, $this->getIncludeDir() . '/' . $headerFile, true, $this->getPhpVersion());

        $headerCode = file_get_contents($this->getBuildDir() . '/include/' . $headerFile);
        $needsAttributeSymbols = str_contains($headerCode, 'zend_add_function_attribute(')
            || str_contains($headerCode, 'zend_add_parameter_attribute(')
            || str_contains($headerCode, 'zend_add_global_constant_attribute(');
        if ($this->useRegisterSymbolsFn || $needsAttributeSymbols) {
            if (preg_match('/\bstatic\s+void\s+(register_[A-Za-z0-9_]+_symbols)\s*\(\s*int\s+module_number\s*\)/', $headerCode, $matches)) {
                $registerSymbolFn = $matches[1];
                $this->registerSymbols[] = $registerSymbolFn;
            }
        }
        $this->argInfoHeaderFiles[] = $headerFile;
    }

    public function parseTraitUseForStub(Node\Stmt\ClassLike $stmt, Node\Name $className): void
    {
        $methods = [];
        $constants = [];
        $properties = [];
        $traitMethods = [];
        $traitConstants = [];
        $traitProperties = [];
        $classDef = $this->getClass($className->toString());

        foreach ($stmt->stmts as $classStmt) {
            if ($classStmt instanceof Node\Stmt\ClassMethod) {
                $name = strtolower($classStmt->name->toString());
                $methods[$name] = $classStmt;
            }
            if ($classStmt instanceof Node\Stmt\Property) {
                foreach ($classStmt->props as $prop) {
                    $name = strtolower($prop->name->toString());
                    $properties[$name] = $prop;
                }
            }
            if ($classStmt instanceof Node\Stmt\ClassConst) {
                foreach ($classStmt->consts as $const) {
                    $name = strtolower($const->name->toString());
                    $constants[$name] = $const;
                }
            }
        }

        foreach ($stmt->stmts as $classStmt) {
            if (!$classStmt instanceof Node\Stmt\TraitUse) {
                continue;
            }

            foreach ($classStmt->traits as $trait1) {
                $traitFullName = $this->getNamespacedClassName($trait1);
                if (!$this->hasClass($traitFullName)) {
                    $this->fatalError($classStmt, "Trait `{$traitFullName}` not found");
                }

                $traitDef = $this->getClass($traitFullName);
                if (!$traitDef->trait) {
                    $this->fatalError($classStmt, "Trait `{$traitFullName}` not found");
                }

                $traitAst = clone $traitDef->trait;
                $traitStmts = $traitAst->stmts;
                $aliasStmts = [];
                foreach ($traitStmts as $k1 => $traitStmt) {
                    if ($traitStmt instanceof Node\Stmt\ClassMethod) {
                        $methodName = strtolower($traitStmt->name->toString());
                        $fullMethodName = $this->getFullMethodName($traitFullName, $methodName);
                        // A trait method's `self`/`static`/`parent` return and parameter
                        // types refer to the class that uses the trait, not the trait
                        // itself. Re-resolve them on the cloned AST so the generated
                        // arginfo reflects the consuming class (PHP trait semantics) and
                        // passes ZendVM's runtime signature-compatibility checks. The
                        // alias clones below inherit this rewrite.
                        $this->reresolveTraitMethodAstLateBoundTypes($classDef, $traitFullName, $traitStmt);
                        foreach ($classDef->traitAliases[$fullMethodName] ?? [] as $alias) {
                            $aliasName = strtolower($alias['newName']);
                            if ($aliasName === $methodName) {
                                if ($alias['newModifier']) {
                                    $traitStmt->flags = $alias['newModifier'];
                                }
                            } elseif (!isset($methods[$aliasName]) && !isset($traitMethods[$aliasName])) {
                                $aliasStmt = clone $traitStmt;
                                $aliasStmt->name = new Node\Identifier($alias['newName']);
                                if ($alias['newModifier']) {
                                    $aliasStmt->flags = $alias['newModifier'];
                                }
                                $aliasStmts[] = $aliasStmt;
                                $traitMethods[$aliasName] = [$traitFullName, $aliasStmt];
                            }
                        }
                        if (isset($classDef->traitIgnored[$fullMethodName])) {
                            unset($traitStmts[$k1]);
                            continue;
                        }
                        if (isset($traitMethods[$methodName])) {
                            [$existingTraitName, $existingStmt] = $traitMethods[$methodName];
                            $newAbstract = $traitStmt->isAbstract();
                            $existingAbstract = $existingStmt->isAbstract();

                            if ($newAbstract && $existingAbstract) {
                                // Both abstract: validate signature compatibility
                                $this->validateTraitAbstractMethodCompatibility(
                                    $classStmt, $existingTraitName, $traitFullName,
                                    $methodName, $existingStmt, $traitStmt
                                );
                                // Signatures compatible, skip this duplicate
                                unset($traitStmts[$k1]);
                                continue;
                            }

                            if ($newAbstract && !$existingAbstract) {
                                // Existing concrete wins over new abstract
                                unset($traitStmts[$k1]);
                                continue;
                            }

                            if (!$newAbstract && $existingAbstract) {
                                // New concrete replaces existing abstract
                                $traitMethods[$methodName] = [$traitFullName, $traitStmt];
                                unset($traitStmts[$k1]);
                                continue;
                            }

                            // Both concrete — error
                            $this->fatalError($classStmt, "Trait `{$traitFullName}` method `{$methodName}` already exists");
                        }
                        if (isset($methods[$methodName])) {
                            unset($traitStmts[$k1]);
                        }
                        $traitMethods[$methodName] = [$traitFullName, $traitStmt];
                    }
                    if ($traitStmt instanceof Node\Stmt\ClassConst) {
                        foreach ($traitStmt->consts as $k2 => $const) {
                            $constName = strtolower($const->name->toString());
                            if (isset($constants[$constName])) {
                                unset($traitStmt->consts[$k2]);
                                if (!$traitStmt->consts) {
                                    unset($traitStmts[$k1]);
                                }
                                continue;
                            }
                            if (isset($traitConstants[$constName])) {
                                [$existingConstStmt, $existingConst] = $traitConstants[$constName];
                                if ($existingConstStmt->flags !== $traitStmt->flags ||
                                    $this->typeNodeToStringOrNull($existingConstStmt->type) !== $this->typeNodeToStringOrNull($traitStmt->type) ||
                                    $this->printer->prettyPrintExpr($existingConst->value) !== $this->printer->prettyPrintExpr($const->value)) {
                                    $this->fatalError($classStmt, "Trait `{$traitFullName}` constant `{$constName}` already exists");
                                }
                                unset($traitStmt->consts[$k2]);
                                if (!$traitStmt->consts) {
                                    unset($traitStmts[$k1]);
                                }
                                continue;
                            }
                            $traitConstants[$constName] = [$traitStmt, $const];
                        }
                    }
                    if ($traitStmt instanceof Node\Stmt\Property) {
                        foreach ($traitStmt->props as $k2 => $prop) {
                            $propName = strtolower($prop->name->toString());
                            if (isset($properties[$propName])) {
                                unset($traitStmt->props[$k2]);
                                if (!$traitStmt->props) {
                                    unset($traitStmts[$k1]);
                                }
                                continue;
                            }
                            if (isset($traitProperties[$propName])) {
                                [$existingPropStmt, $existingProp] = $traitProperties[$propName];
                                $existingDefault = $existingProp->default ? $this->printer->prettyPrintExpr($existingProp->default) : null;
                                $propDefault = $prop->default ? $this->printer->prettyPrintExpr($prop->default) : null;
                                if ($existingPropStmt->flags !== $traitStmt->flags ||
                                    $this->typeNodeToStringOrNull($existingPropStmt->type) !== $this->typeNodeToStringOrNull($traitStmt->type) ||
                                    $existingDefault !== $propDefault) {
                                    $this->fatalError($classStmt, "Trait `{$traitFullName}` property `{$propName}` already exists");
                                }
                                unset($traitStmt->props[$k2]);
                                if (!$traitStmt->props) {
                                    unset($traitStmts[$k1]);
                                }
                                continue;
                            }
                            $traitProperties[$propName] = [$traitStmt, $prop];
                        }
                    }
                }

                $stmt->stmts = array_merge($stmt->stmts, $traitStmts, $aliasStmts);
            }
        }
    }

    /**
     * Re-resolve a trait method's late-bound `self`/`static`/`parent` return and
     * parameter types on the cloned AST that is being flattened into a class.
     *
     * `resolveTypeDecl()` mutates a trait method's `self`/`static`/`parent` type
     * node to the trait's own name at parse time, so the cloned AST carries the
     * trait name rather than the late-bound keyword. We instead rewrite those
     * nodes to the consuming class (or its parent) using the keyword recorded on
     * the trait method's FunctionDef, matching PHP's trait semantics. This keeps
     * the generated arginfo correct for ZendVM's runtime compatibility checks.
     */
    private function reresolveTraitMethodAstLateBoundTypes(
        ClassDef $usingClassDef,
        string $traitFullName,
        Node\Stmt\ClassMethod $methodStmt
    ): void {
        if (!$this->hasClass($traitFullName)) {
            return;
        }
        $traitDef = $this->getClass($traitFullName);
        if (!$traitDef->hasMethod($methodStmt->name->toString())) {
            return;
        }
        $fn = $traitDef->getMethod($methodStmt->name->toString())->functionDef;

        if ($fn->returnTypeKeyword !== '' && $methodStmt->returnType instanceof Node\Name) {
            if ($fn->returnTypeKeyword === 'static') {
                // `static` remains late-bound in the composed method signature.
                $methodStmt->returnType = new Node\Name('static');
            } else {
                $resolved = $this->resolveLateBoundClass($usingClassDef, $fn->returnTypeKeyword);
                if ($resolved !== null) {
                    $methodStmt->returnType = new Node\Name($resolved);
                }
            }
        }

        foreach ($fn->argInfoList as $i => $arg) {
            if (
                $arg->typeKeyword !== ''
                && isset($methodStmt->params[$i])
                && $methodStmt->params[$i]->type instanceof Node\Name
            ) {
                if ($arg->typeKeyword === 'static') {
                    $methodStmt->params[$i]->type = new Node\Name('static');
                } else {
                    $resolved = $this->resolveLateBoundClass($usingClassDef, $arg->typeKeyword);
                    if ($resolved !== null) {
                        $methodStmt->params[$i]->type = new Node\Name($resolved);
                    }
                }
            }
        }
    }

    /**
     * Validate that two abstract trait methods have compatible signatures.
     * PHP allows multiple traits to declare the same abstract method as long
     * as parameters and return type are compatible.
     */
    protected function validateTraitAbstractMethodCompatibility(
        Node\Stmt\TraitUse $classStmt,
        string $traitA,
        string $traitB,
        string $methodName,
        Node\Stmt\ClassMethod $a,
        Node\Stmt\ClassMethod $b
    ): void {
        // Compare visibility
        if ($a->flags !== $b->flags) {
            $this->fatalError(
                $classStmt,
                "Trait `{$traitA}` and Trait `{$traitB}` define the same abstract method `{$methodName}` " .
                'but with different visibility'
            );
        }

        // Compare return type
        $aRet = $a->returnType ? $this->typeNodeToString($a->returnType) : null;
        $bRet = $b->returnType ? $this->typeNodeToString($b->returnType) : null;
        if ($aRet !== $bRet) {
            $this->fatalError(
                $classStmt,
                "Trait `{$traitA}` and Trait `{$traitB}` define the same abstract method `{$methodName}` " .
                'but with different return types'
            );
        }

        // Compare parameter count
        if (count($a->params) !== count($b->params)) {
            $this->fatalError(
                $classStmt,
                "Trait `{$traitA}` and Trait `{$traitB}` define the same abstract method `{$methodName}` " .
                'but with incompatible parameter counts'
            );
        }

        // Compare parameter types
        foreach ($a->params as $i => $paramA) {
            $paramB = $b->params[$i];
            $typeA = $paramA->type ? $this->typeNodeToString($paramA->type) : null;
            $typeB = $paramB->type ? $this->typeNodeToString($paramB->type) : null;
            if ($typeA !== $typeB) {
                $this->fatalError(
                    $classStmt,
                    "Trait `{$traitA}` and Trait `{$traitB}` define the same abstract method `{$methodName}` " .
                    "but parameter #{$i} has incompatible types"
                );
            }
            if ($paramA->byRef !== $paramB->byRef) {
                $this->fatalError(
                    $classStmt,
                    "Trait `{$traitA}` and Trait `{$traitB}` define the same abstract method `{$methodName}` " .
                    "but parameter #{$i} differs in by-reference"
                );
            }
            if ($paramA->variadic !== $paramB->variadic) {
                $this->fatalError(
                    $classStmt,
                    "Trait `{$traitA}` and Trait `{$traitB}` define the same abstract method `{$methodName}` " .
                    "but parameter #{$i} differs in variadic"
                );
            }
        }
    }

    /**
     * Convert a PHP-Parser type node to a normalized string for comparison.
     */
    private function typeNodeToString(NodeAbstract $typeNode): string
    {
        if ($typeNode instanceof Node\Identifier) {
            return $typeNode->name;
        }
        if ($typeNode instanceof Node\Name) {
            return $typeNode->toString();
        }
        if ($typeNode instanceof Node\NullableType) {
            return '?' . $this->typeNodeToString($typeNode->type);
        }
        if ($typeNode instanceof Node\UnionType) {
            $parts = [];
            foreach ($typeNode->types as $t) {
                $parts[] = $this->typeNodeToString($t);
            }
            sort($parts);
            return implode('|', $parts);
        }
        if ($typeNode instanceof Node\IntersectionType) {
            $parts = [];
            foreach ($typeNode->types as $t) {
                $parts[] = $this->typeNodeToString($t);
            }
            sort($parts);
            return implode('&', $parts);
        }
        // Fallback: use pretty printer
        return $this->printer->prettyPrint([$typeNode]);
    }

    private function typeNodeToStringOrNull(?NodeAbstract $typeNode): ?string
    {
        return $typeNode ? $this->typeNodeToString($typeNode) : null;
    }

    private function configureGeneratedConstructorParentCall(Node\Stmt\Class_ $class): void
    {
        $constructor = null;
        foreach ($class->getMethods() as $method) {
            if ($method->getAttribute(ConstructorLowering::GENERATED_ATTRIBUTE, false)) {
                $constructor = $method;
                break;
            }
        }
        if ($constructor === null || $this->classDef->extends === '') {
            return;
        }

        $parent = $this->classDef->extends;
        while ($parent !== '') {
            $parentDef = $this->getClassDef($parent);
            if ($parentDef === null) {
                $reflection = Reflection::getClass($parent);
                $parentConstructor = $reflection?->getConstructor();
                if ($parentConstructor === null) {
                    return;
                }
                $owner = $parentConstructor->getDeclaringClass()->getName();
                $this->applyGeneratedConstructorParentRule(
                    $constructor,
                    $owner,
                    $parentConstructor->getModifiers(),
                    $parentConstructor->getNumberOfRequiredParameters(),
                    $parentConstructor->isAbstract(),
                );
                return;
            }

            if ($parentDef->hasMethod('__construct')) {
                $parentConstructor = $parentDef->getMethod('__construct');
                $this->applyGeneratedConstructorParentRule(
                    $constructor,
                    $parent,
                    $parentConstructor->flags,
                    $parentConstructor->functionDef?->argCountRequired ?? 0,
                    false,
                );
                return;
            }
            if ($parentDef->hasAbstractMethod('__construct')) {
                $parentConstructor = $parentDef->getAbstractMethod('__construct');
                $this->applyGeneratedConstructorParentRule(
                    $constructor,
                    $parent,
                    $parentConstructor->flags,
                    $parentConstructor->functionDef?->argCountRequired ?? 0,
                    true,
                );
                return;
            }
            $parent = $parentDef->extends;
        }
    }

    private function applyGeneratedConstructorParentRule(
        Node\Stmt\ClassMethod $constructor,
        string $parent,
        int $flags,
        int $requiredArguments,
        bool $abstract,
    ): void {
        $attributeTarget = $constructor->getAttribute(
            \TypePhp\Diagnostics\CompileTimeAttributeDiagnostic::GENERATED_TARGET,
            $constructor,
        );
        if (!$attributeTarget instanceof Node) {
            $attributeTarget = $constructor;
        }
        if ($flags & Modifiers::FINAL) {
            $this->fatalCompileTimeAttribute(
                $attributeTarget,
                'Constructor',
                "Cannot override final method `{$parent}::__construct()`",
                $attributeTarget,
            );
        }
        if ($flags & Modifiers::PRIVATE) {
            return;
        }
        if ($requiredArguments > 0) {
            $this->fatalCompileTimeAttribute(
                $attributeTarget,
                'Constructor',
                "Constructor cannot be generated because parent constructor `{$parent}::__construct()` " .
                "requires {$requiredArguments} argument(s); declare `__construct()` explicitly",
                $attributeTarget,
            );
        }
        if ($abstract) {
            return;
        }

        array_unshift($constructor->stmts, new Node\Stmt\Expression(new Node\Expr\StaticCall(
            new Node\Name('parent'),
            new Node\Identifier('__construct'),
        )));
    }

    protected function parseClass(Node\Stmt\Class_|Node\Stmt\Trait_|Node\Stmt\Enum_ $class): string
    {
        $this->class = $this->parseIdentifier($class->name);
        $fullName = $this->getFullClassName();
        if (!$this->hasClass($fullName)) {
            $this->fatalError($class, "class {$fullName} not found");
        }
        $this->classDef = $this->getClass($fullName);
        $this->parseMethodsForTarget($class);

        if ($class instanceof Node\Stmt\Class_) {
            $this->configureGeneratedConstructorParentCall($class);
        }

        if ($class instanceof Node\Stmt\Class_ && $this->classDef->printerGenerated) {
            $available = [...$this->parentPublicProperties($this->classDef->extends), ...\TypePhp\Transform\ClassFieldSelection::ownPublicProperties($class)];
            try {
                $properties = \TypePhp\Transform\ClassFieldSelection::resolve(
                    $this->classDef->printerFields,
                    $this->classDef->printerFields === null
                        ? $available
                        : $this->selectableProperties($this->classDef),
                    'Printer',
                );
            } catch (SyntaxError $error) {
                throw new SyntaxError(CompileTimeAttributeDiagnostic::format(
                    $error->getMessage(),
                    'Printer',
                    $class,
                    $this->file,
                ), 0, $error);
            }
            \TypePhp\Transform\PrinterLowering::rebuildGeneratedMethod(
                $class,
                $properties,
                $this->classDef->printerFields,
                $this->classStringProperties($this->classDef),
            );
        }
        if ($class instanceof Node\Stmt\Class_ && $this->classDef->arrayableGenerated) {
            $available = [...$this->parentPublicProperties($this->classDef->extends), ...\TypePhp\Transform\ClassFieldSelection::ownPublicProperties($class)];
            try {
                $properties = \TypePhp\Transform\ClassFieldSelection::resolve(
                    $this->classDef->arrayableFields,
                    $this->classDef->arrayableFields === null
                        ? $available
                        : $this->selectableProperties($this->classDef),
                    'Arrayable',
                );
            } catch (SyntaxError $error) {
                throw new SyntaxError(CompileTimeAttributeDiagnostic::format(
                    $error->getMessage(),
                    'Arrayable',
                    $class,
                    $this->file,
                ), 0, $error);
            }
            \TypePhp\Transform\ArrayableLowering::rebuildGeneratedMethod(
                $class,
                $properties,
                $this->classDef->arrayableFields,
            );
        }

        // 如果不是继承自内置类，需要检查父类是否存在，在预处理阶段只需检查了是否继承内置类
        // 目前不允许继承自动态加载的自定义类
        if ($this->classDef->extends and !$this->classDef->inheritedFromInternalClass) {
            $parentClass = $this->getNamespacedClassName($this->parseIdentifier($class->extends));
            if ($this->hasClass($parentClass)) {
                $parent = $this->getClass($parentClass);
                // 父类是 final 无法继承
                if ($parent->flags & Modifiers::FINAL) {
                    $this->fatalError($class, "Class `{$this->class}` cannot extend final class `{$parentClass}`");
                }
            } else {
                $this->fatalError($class, "Class `{$this->class}` inherits from a non-existent class `{$parentClass}`");
            }
        }

        if (is_array($this->classDef->implements)) {
            foreach ($this->classDef->implements as $interfaceName) {
                if (!$this->hasInterface($interfaceName) and !$this->isInternalInterface($interfaceName)) {
                    $this->fatalError($class, "Class `{$this->class}` implements a non-existent interface `{$interfaceName}`");
                }
            }
        }

        $this->checkPropertyOverride($class);
        $this->checkConstantOverride($class);

        $className = $this->classDef->getNamespacedName();
        $this->classesDefineInFile[$className] = $this->classDef;

        $methodCodes = [];

        foreach ($class->stmts as $v) {
            $type = $v->getType();
            switch ($type) {
                case 'Stmt_ClassConst':
                case 'Stmt_Property':
                case 'Stmt_Nop':
                case 'Stmt_EnumCase':
                    break;
                case 'Stmt_ClassMethod':
                    $this->parseClassMethod($v, $methodCodes);
                    break;
                case 'Stmt_TraitUse':
                    $this->parseTraitUse($v, $methodCodes);
                    break;
                default:
                    abort($v);
                    break;
            }
        }
        if (!$class instanceof Node\Stmt\Trait_) {
            $this->validateOverrideAttributes($class);
            $this->checkInterfaceImplementations($class);
            $this->checkInheritedAbstractMethodsAreImplemented($class);
        }
        $code = $this->genNativeMethod($methodCodes);

        $oriCtx = $this->context;
        $this->context = $this->classDef->propertyContext;
        $this->classDef->ctorInit .= $this->genScopeVarDecl() . $this->parseBeforeStmtLines();
        $this->classDef->ctorClean .= $this->parseAfterStmtLines();
        $this->context = $oriCtx;

        $this->resetClass();

        return $code;
    }

    protected function genNativeMethod(array $methodCodes): string
    {
        $code = '';
        $classDef = $this->classDef;
        foreach ($classDef->methods as $method) {
            $code .= $methodCodes[$method->name] . PHP_EOL;
        }
        $code .= PHP_EOL;

        return $code;
    }

    protected function genWrapperFunctionArgs(
        string $fn,
        FunctionDef $functionDef,
        string $displayName,
        array $implicitMethodArgs = []
    ): string
    {
        $cppCode = '';
        $callParams = '';
        if ($functionDef->argCountRequired > 0) {
            $cppCode .= $this->genWrapperRequiredArgCountCheck($functionDef, $displayName);
        }
        foreach ($functionDef->argInfoList as $k => $argInfo) {
            $var = 'arg_' . $argInfo->name;
            if ($argInfo->variadic) {
                $cppCode .= $this->getIndent() . Type::ARRAY . ' ' . $var . ';' . PHP_EOL;
                $cppCode .= $this->getIndent() . 'for (uint32_t i = ' . $k . '; i < php::getCallArgNum(); i++) {' . PHP_EOL;
                $this->indentLevel++;
                $cppCode .= $this->getIndent() . $var . '.append(php::getCallArg(i));' . PHP_EOL;
                $this->indentLevel--;
                $cppCode .= '}' . PHP_EOL;
                $cppCode .= $this->genExtraNamedVariadicArgs($var);
            } else {
                if ($argInfo->default !== '') {
                    $nativeName = str_starts_with($fn, self::PREFIX)
                        ? substr($fn, strlen(self::PREFIX))
                        : $fn;
                    $defaultExpr = $this->genDefaultArgumentExpr($nativeName, $k);
                    if ($argInfo->byRef) {
                        $argExpr = 'php::getCallArgByRef(' . $k . ', ' . $defaultExpr . ')';
                    } else {
                        $argExpr = 'php::getCallArg(' . $k . ', ' . $defaultExpr . ')';
                    }
                } else {
                    if ($argInfo->byRef) {
                        $argExpr = 'php::getCallArgByRef(' . $k . ')';
                    } else {
                        $argExpr = 'php::getCallArg(' . $k . ')';
                    }
                }
                $cppType = $this->getDefaultArgumentType($argInfo);
                $declaredClass = $argInfo->declaredClass ?: $argInfo->class;
                if ($argInfo->type === Type::OBJECT && $declaredClass !== '') {
                    $expr = $this->convertObjectExpr($argExpr, $this->getClassEntryPtr($declaredClass));
                } else {
                    $expr = $this->convertExprFromType($argInfo->type, $argExpr);
                }
                $cppCode .= $this->getIndent() . $cppType . ' ' . $var . ' = ' . $expr . ';' . PHP_EOL;
            }
            $callParam = $var;
            if ($this->canConsumeForwardedArgument($argInfo)) {
                $callParam = 'php::takeValue(' . $var . ')';
            }
            $callParams .= $callParam . ',';
        }

        if ($functionDef->method) {
            $methodArgs = implode(', ', array_merge(['this_'], $implicitMethodArgs));
            $callParams = $functionDef->argInfoList ? $methodArgs . ', ' . rtrim($callParams, ',') : $methodArgs;
        } else {
            $callParams = $functionDef->argInfoList ? rtrim($callParams, ',') : '';
        }

        if ($functionDef->returnType !== Type::VOID) {
            $cppCode .= $this->getIndent() . 'auto retval = ' . $fn . '(' . $callParams . ');' . PHP_EOL;
            $cppCode .= $this->getIndent() . 'php::move(retval, return_value);' . PHP_EOL;
            if (!$functionDef->returnsByRef) {
                $cppCode .= $this->getIndent() . 'php::deref(return_value);' . PHP_EOL;
            }
        } else {
            $cppCode .= $this->getIndent() . $fn . '(' . $callParams . ');' . PHP_EOL;
        }
        $cppCode .= '}' . PHP_EOL . PHP_EOL;

        return $cppCode;
    }

    private function canConsumeForwardedArgument(ArgInfo $argInfo): bool
    {
        if ($argInfo->byRef) {
            return false;
        }
        if ($argInfo->variadic) {
            return true;
        }

        return in_array($this->getDefaultArgumentType($argInfo), [
            Type::VAR,
            Type::STR,
            Type::ARRAY,
            Type::OBJECT,
        ], true);
    }

    private function genWrapperRequiredArgCountCheck(FunctionDef $functionDef, string $displayName): string
    {
        $required = $functionDef->argCountRequired;
        $expected = $required === count($functionDef->argInfoList) ? 'exactly' : 'at least';
        $message = $this->genCharPtr(
            'Too few arguments to function ' . $displayName . '(), %u passed and ' . $expected . ' ' . $required . ' expected',
            true
        );

        $code = $this->getIndent() . 'if (UNEXPECTED(php::getCallArgNum() < ' . $required . ')) {' . PHP_EOL;
        $this->indentLevel++;
        $code .= $this->getIndent() . 'php::throwExceptionEx(zend_ce_argument_count_error, 0, ' . $message . ', php::getCallArgNum());' . PHP_EOL;
        $code .= $this->getIndent() . 'return;' . PHP_EOL;
        $this->indentLevel--;
        $code .= $this->getIndent() . '}' . PHP_EOL;

        return $code;
    }

    protected function genMethodWrapper(ClassDef $classDef, MethodDef $methodDef): string
    {
        $name = $classDef->getNamespacedName();
        $cppCode = 'ZEND_METHOD(' . $name . ', ' . $methodDef->name . '){' . PHP_EOL;
        $cppCode .= $this->getIndent() . Type::OBJECT . ' this_(&execute_data->This);' . PHP_EOL;
        $fn = self::PREFIX . $this->getNativeMethodName($classDef, $methodDef);
        $implicitMethodArgs = [];
        if ($classDef->trait !== null && $methodDef->parentMethodCalls) {
            // Trait methods are not directly callable without a composing class,
            // but keep the generated Zend wrapper well-formed.
            $implicitMethodArgs[] = 'this_.parent_ce()';
        }
        $cppCode .= $this->genWrapperFunctionArgs(
            $fn,
            $methodDef->functionDef,
            $classDef->getNamespacedName(false) . '::' . $methodDef->name,
            $implicitMethodArgs
        );

        return $cppCode;
    }

    protected function getClassRegisterCeFunc(ClassDef|InterfaceDef $classDef): string
    {
        $cppCode = '';
        $name = $classDef->getNamespacedName();
        $argsDef = $this->getRegisterClassFunctionArgDef($classDef);
        $param = $this->getRegisterClassFunctionArgs($classDef);
        $cppCode .= 'zend_class_entry *' . $this->getRegisterClassFunction($name) . '(' . $argsDef . ') {' . PHP_EOL;
        $cppCode .= $this->getIndent() . 'return register_class_' . $name . '(' . $param . ');' . PHP_EOL;
        $cppCode .= '}' . PHP_EOL . PHP_EOL;

        return $cppCode;
    }

    protected function genClassWrapper(ClassDef|InterfaceDef $classDef): string
    {
        $cppCode = '';

        // 接口没有方法实体
        if ($classDef instanceof ClassDef) {
            $defaultPropCount = 0;
            foreach ($classDef->properties as $property) {
                if (!$property->isStatic() && $property->default !== null) {
                    $defaultPropCount++;
                }
            }
            if ($defaultPropCount > 0) {
                $classDef->requireCtor = true;
            }
            $methods = $classDef->methods;
            foreach ($methods as $methodDef) {
                $cppCode .= $this->genMethodWrapper($classDef, $methodDef);
            }
        }

        return $cppCode;
    }

    /**
     * @param array<ConstantDef> $list
     */
    protected function genClassConstantList(array $list): string
    {
        $code = '';
        foreach ($list as $const) {
            $code .= $this->getIndent() . $this->genClassConstant($const);
        }

        return $code;
    }

    protected function genClassConstant(ConstantDef $const): string
    {
        return 'static const ' . $const->type . ' ' . $const->name . ';' . PHP_EOL;
    }

    /**
     * @param array<PropertyDef> $list
     */
    protected function genClassPropertyList(array $list): string
    {
        $code = '';
        foreach ($list as $prop) {
            $code .= $this->getIndent() . $this->genClassProperty($prop);
        }

        return $code;
    }

    protected function genClassProperty(PropertyDef $prop): string
    {
        $code = $prop->type . ' ' . $prop->name;
        if ($prop->default !== null) {
            $code .= ' = ' . $prop->default;
        }
        return $code . ';' . PHP_EOL;
    }

    protected function genFunction(string $name, string $returnType, array $args = [], array $lines = []): string
    {
        $_args = [];
        foreach ($args as $arg => $type) {
            $_args[] = $type . ' ' . $arg;
        }
        $code = $returnType . ' ' . $name . '(' . implode(', ', $_args) . ') {' . PHP_EOL;
        $code .= implode(PHP_EOL, $lines) . PHP_EOL;
        $code .= '}' . PHP_EOL;

        return $code;
    }

    /**
     * Build type-check descriptor array from UnionType or NullableType AST node.
     * Returns ['check' => array, 'typeStr' => string] or empty check array if no check needed.
     */
    /**
     * Generate a C++ boolean expression for a single type descriptor entry.
     */
    /**
     * Generate C++ runtime type-check block for a function parameter with union/nullable type.
     */
    /**
     * Generate C++ runtime type-check block for a function return value with union/nullable type.
     */
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
            $this->addArgument('this_', Type::OBJECT);
        }
        foreach ($this->functionDef->argInfoList as $argInfo) {
            $this->addArgument($argInfo->name, $argInfo->variadic ? Type::ARRAY : $argInfo->type);
            if (!$argInfo->variadic and $argInfo->declaredClass) {
                $this->addObject($argInfo->name, $argInfo->declaredClass);
            }
        }

        if ($this->functionDef->generator) {
            try {
                return $this->genFiberGeneratorFunction($v, $this->functionDef, $name);
            } finally {
                $this->resetFunction();
            }
        }

        // Build SSA/e-SSA analysis for this function
        if ($v->stmts) {
            $oriLocalVars = $this->context->localVars;
            $oriTmpVarIndex = $this->context->tmpVarIndex;
            $oriDeclaredObjects = $this->context->declaredObjects;
            /** SSA/e-SSA analysis for the current function. Built once per function, discarded with the context. */
            $ssaBuilder = new SsaBuilder($v->stmts, $this->functionDef->argInfoList);
            $ssaBuilder->build();
            $this->context->ssaBuilder = $ssaBuilder;
            $this->analyzeStableObjects($ssaBuilder);
            if ($this->nativeTypes) {
                // Narrow local variable types based on SSA analysis.
                $this->optimizeVarTypes($ssaBuilder);
                // Narrow range-proven loop counters and native property accesses.
                $this->optimizeLoopVars($ssaBuilder);
                $this->optimizeObjectProps($ssaBuilder);
            }
            $this->context->resetAnalysisTemporaries($oriLocalVars, $oriTmpVarIndex, $oriDeclaredObjects);
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

        $multiReturn = $this->functionDef->hasMultiReturn();
        $cppReturnType = $multiReturn
            ? $this->functionDef->getMultiReturnCppType()
            : ($this->functionDef->returnsByRef ? Type::REF : $this->getReturnType());
        $nativeName = self::PREFIX . $name;
        $functionAttribute = $this->getFunctionOptimizationAttribute($this->functionDef);
        $functionDeclCode = $functionAttribute . $cppReturnType . ' ' . ($multiReturn ? $this->getMultiReturnImplName($name) : $nativeName) . '(';
        if ($this->class) {
            $functionDeclCode .= Type::OBJECT . ' &this_';
            if ($this->classDef?->trait !== null && $this->methodDef?->parentMethodCalls) {
                $functionDeclCode .= ', zend_class_entry *trait_parent_ce';
            }
            if ($this->functionDef->params) {
                $functionDeclCode .= ', ';
            }
        }
        $functionDeclCode .= $this->functionDef->params . ')';

        $code = $functionDeclCode . ' {' . PHP_EOL;
        $this->indentLevel++;
        $code .= $this->genScopeVarDecl();
        $code .= "\n";
        // Runtime union/nullable parameter type checks
        foreach ($this->functionDef->argInfoList as $i => $argInfo) {
            if (!empty($argInfo->typeCheck)) {
                $code .= $this->genUnionParamCheck($argInfo, $i);
            }
        }
        // Constructor Property Promotion happens after parameter type validation.
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

        if ($multiReturn) {
            $forwardArgs = implode(', ', array_map(
                fn($argInfo) => $this->canConsumeForwardedArgument($argInfo)
                    ? 'php::takeValue(' . $argInfo->name . ')'
                    : $argInfo->name,
                $this->functionDef->argInfoList,
            ));
            $code .= $functionAttribute . Type::ARRAY . ' ' . $nativeName . '(' . $this->functionDef->params . ') {' . PHP_EOL;
            $this->indentLevel++;
            $code .= $this->getIndent() . 'return ' . Type::ARRAY . '(' . $this->getMultiReturnImplName($name) . '(' . $forwardArgs . '));' . PHP_EOL;
            $this->indentLevel--;
            $code .= '}' . PHP_EOL;
        }

        $this->resetFunction();

        return $code;
    }

    /**
     * 检查父类方法是否可以被重写，私有方法不能被重写，方法签名必须兼容
     */
    protected function checkParentMethodCanBeOverridden(Node\Stmt\ClassMethod $v, string $name): void
    {
        if ($name === '__construct') {
            return;
        }

        $classDef = $this->classDef;
        $childFuncDef = $this->methodDef->functionDef;
        while (true) {
            $extends = $classDef->extends;
            if (!$extends) {
                break;
            }
            // 父类是内置类
            if ($classDef->inheritedFromInternalClass) {
                $modifiers = Reflection::getClassMethodModifiers($extends, $name);
                if ($modifiers & \ReflectionMethod::IS_PRIVATE) {
                    goto _error;
                }
                if ($modifiers & \ReflectionMethod::IS_FINAL) {
                    goto _final_error;
                }
                break;
            }
            $classDef = $this->getClass($extends);
            if ($classDef->hasMethod($name)) {
                $methodDef = $classDef->getMethod($name);
                if ($methodDef->flags & Modifiers::PRIVATE) {
                    _error:
                    $message = 'Cannot override private method `' . $extends . '::' . $name . '()`';
                    $this->fatalGeneratedMethodAttributeIfAny($v, $message, $extends, $name);
                    $this->fatalError($v,
                        $message);
                }
                if ($methodDef->flags & Modifiers::FINAL) {
                    _final_error:
                    $message = 'Cannot override final method `' . $extends . '::' . $name . '()`';
                    $this->fatalGeneratedMethodAttributeIfAny($v, $message, $extends, $name);
                    $this->fatalError($v,
                        $message);
                }
                $this->validateMethodOverrideSignature($v, $name, $this->methodDef, $methodDef, $extends);
                break;
            }
            if ($classDef->hasAbstractMethod($name) && isset($classDef->abstractMethodDefs[strtolower($name)])) {
                $this->validateMethodOverrideSignature($v, $name, $this->methodDef, $classDef->getAbstractMethod($name), $extends);
                break;
            }
        }
    }

    private function validateMethodOverrideSignature(
        NodeAbstract $v,
        string $methodName,
        MethodDef $childMethodDef,
        MethodDef $parentMethodDef,
        string $parentClass
    ): void {
        $className = $this->getFullClassName();

        // PHP allows widening visibility in overrides (e.g. protected -> public),
        // but forbids narrowing it.
        if ($this->getVisibilityRank($childMethodDef->flags) < $this->getVisibilityRank($parentMethodDef->flags)) {
            $this->fatalMethodOverrideIncompatible($v, $className, $methodName, $parentClass);
        }

        if (($childMethodDef->flags & Modifiers::STATIC) !== ($parentMethodDef->flags & Modifiers::STATIC)) {
            $this->fatalMethodOverrideIncompatible($v, $className, $methodName, $parentClass);
        }

        $childFuncDef = $childMethodDef->functionDef;
        $parentFuncDef = $parentMethodDef->functionDef;
        if (!$childFuncDef || !$parentFuncDef) {
            return;
        }

        // MustUse is part of the callable contract. An override may strengthen
        // this guarantee, but it must not drop one promised by a parent class
        // or interface.
        if ($parentFuncDef->mustUse && !$childFuncDef->mustUse) {
            $message = "Declaration of `{$className}::{$methodName}()` must be compatible with " .
                "`{$parentClass}::{$methodName}()`";
            $this->error(CompileTimeAttributeDiagnostic::formatPositions(
                $message,
                'MustUse',
                "method {$parentClass}::{$methodName}()",
                $parentFuncDef->sourceFile,
                $parentFuncDef->startLine,
                'override drops MustUse contract',
                $this->file,
                $v->getStartLine(),
            ));
        }

        if (!$this->isReturnTypeOverrideCompatible($childFuncDef, $parentFuncDef)) {
            $this->fatalMethodOverrideIncompatible($v, $className, $methodName, $parentClass);
        }
        if ($childFuncDef->returnsByRef !== $parentFuncDef->returnsByRef) {
            $this->fatalMethodOverrideIncompatible($v, $className, $methodName, $parentClass);
        }

        // Child methods may add optional trailing parameters, but they cannot
        // require more arguments than the parent contract.
        if ($childFuncDef->argCountRequired > $parentFuncDef->argCountRequired) {
            $this->fatalMethodOverrideIncompatible($v, $className, $methodName, $parentClass);
        }

        // Compare each parent-declared parameter position.
        foreach ($parentFuncDef->argInfoList as $i => $parentArg) {
            if (!isset($childFuncDef->argInfoList[$i])) {
                $this->fatalMethodOverrideIncompatible($v, $className, $methodName, $parentClass);
            }
            $childArg = $childFuncDef->argInfoList[$i];
            if (!$this->isParameterTypeOverrideCompatible($childArg, $parentArg)) {
                $this->fatalMethodOverrideIncompatible($v, $className, $methodName, $parentClass);
            }
            if ($childArg->byRef !== $parentArg->byRef) {
                $this->fatalMethodOverrideIncompatible($v, $className, $methodName, $parentClass);
            }
            if ($childArg->variadic !== $parentArg->variadic) {
                $this->fatalMethodOverrideIncompatible($v, $className, $methodName, $parentClass);
            }
        }

        // Any extra child parameters must be optional or variadic.
        for ($i = count($parentFuncDef->argInfoList); $i < count($childFuncDef->argInfoList); $i++) {
            $childArg = $childFuncDef->argInfoList[$i];
            if (!$childArg->variadic && $childArg->defaultValue === null) {
                $this->fatalMethodOverrideIncompatible($v, $className, $methodName, $parentClass);
            }
        }
    }

    private function fatalMethodOverrideIncompatible(
        NodeAbstract $v,
        string $className,
        string $methodName,
        string $parentClass
    ): void {
        $message = "Declaration of `{$className}::{$methodName}()` must be compatible " .
            "with `{$parentClass}::{$methodName}()`";
        $this->fatalGeneratedMethodAttributeIfAny($v, $message, $parentClass, $methodName);
        $this->fatalError($v, $message);
    }

    private function fatalGeneratedMethodAttributeIfAny(
        NodeAbstract $method,
        string $message,
        string $parentClass,
        string $methodName,
    ): void {
        $attribute = $method->getAttribute(CompileTimeAttributeDiagnostic::GENERATED_BY);
        $target = $method->getAttribute(CompileTimeAttributeDiagnostic::GENERATED_TARGET);
        if (!is_string($attribute) || !$target instanceof Node) {
            return;
        }

        $parentFunction = null;
        $parentDef = $this->getClassDef($parentClass);
        if ($parentDef instanceof ClassDef) {
            if ($parentDef->hasMethod($methodName)) {
                $parentFunction = $parentDef->getMethod($methodName)->functionDef;
            } elseif ($parentDef->hasAbstractMethod($methodName)
                && isset($parentDef->abstractMethodDefs[strtolower($methodName)])) {
                $parentFunction = $parentDef->getAbstractMethod($methodName)->functionDef;
            }
        }
        $this->error(CompileTimeAttributeDiagnostic::formatPositions(
            $message,
            $attribute,
            CompileTimeAttributeDiagnostic::describeTarget($target),
            $this->file,
            $target->getStartLine(),
            $parentFunction === null ? null : 'parent method',
            $parentFunction?->sourceFile,
            $parentFunction?->startLine,
        ));
    }

    private function isReturnTypeOverrideCompatible(FunctionDef $childFuncDef, FunctionDef $parentFuncDef): bool
    {
        if ($parentFuncDef->returnTypeUndeclared) {
            return true;
        }
        if ($childFuncDef->returnTypeUndeclared) {
            return false;
        }
        if ($parentFuncDef->returnTypeCheck || $childFuncDef->returnTypeCheck) {
            return $parentFuncDef->returnTypeStr === $childFuncDef->returnTypeStr;
        }
        if ($parentFuncDef->returnType === Type::VAR) {
            return true;
        }
        if ($childFuncDef->returnType !== $parentFuncDef->returnType) {
            return false;
        }
        if ($parentFuncDef->returnType !== Type::OBJECT) {
            return true;
        }
        if ($childFuncDef->returnClass === $parentFuncDef->returnClass) {
            return true;
        }
        if (!$childFuncDef->returnClass || !$parentFuncDef->returnClass) {
            return false;
        }
        return $this->isInheritedFrom($childFuncDef->returnClass, $parentFuncDef->returnClass);
    }

    private function isParameterTypeOverrideCompatible(ArgInfo $childArg, ArgInfo $parentArg): bool
    {
        // Child methods may omit parameter types (contravariance — accepting a
        // wider set of inputs is always compatible with the parent contract).
        if ($this->isTopParameterType($childArg)) {
            return true;
        }
        if ($this->isTopParameterType($parentArg)) {
            return false;
        }

        $parentAcceptedTypes = $this->getParameterAcceptedTypes($parentArg);
        $childAcceptedTypes = $this->getParameterAcceptedTypes($childArg);
        if ($parentAcceptedTypes !== null || $childAcceptedTypes !== null) {
            if ($parentAcceptedTypes === null || $childAcceptedTypes === null) {
                return false;
            }
            return $this->isAcceptedTypeSubset($parentAcceptedTypes, $childAcceptedTypes);
        }

        if ($childArg->type !== $parentArg->type) {
            return false;
        }
        if ($parentArg->type !== Type::OBJECT) {
            return true;
        }
        if ($childArg->class === $parentArg->class) {
            return true;
        }
        if (!$childArg->class || !$parentArg->class) {
            return false;
        }
        return $this->isInheritedFrom($parentArg->class, $childArg->class);
    }

    private function isTopParameterType(ArgInfo $arg): bool
    {
        return $arg->undeclared || $arg->explicitMixed;
    }

    private function getParameterAcceptedTypes(ArgInfo $arg): ?array
    {
        if (!empty($arg->typeCheck)) {
            return $arg->typeCheck;
        }

        $declaredClass = $arg->declaredClass ?: $arg->class;
        return match ($arg->type) {
            Type::INT => [['kind' => 'isInt']],
            Type::FLOAT => [['kind' => 'isFloat']],
            Type::BOOL => [['kind' => 'isBool']],
            Type::STR => [['kind' => 'isString']],
            Type::ARRAY => [['kind' => 'isArray']],
            Type::RESOURCE => [['kind' => 'isResource']],
            Type::OBJECT => $declaredClass
                ? [['kind' => 'instanceof', 'class' => $declaredClass]]
                : [['kind' => 'isObject']],
            default => null,
        };
    }

    private function isAcceptedTypeSubset(array $parentTypes, array $childTypes): bool
    {
        foreach ($parentTypes as $parentType) {
            if (!$this->isAcceptedTypeCovered($parentType, $childTypes)) {
                return false;
            }
        }
        return true;
    }

    private function isAcceptedTypeCovered(array $parentType, array $childTypes): bool
    {
        foreach ($childTypes as $childType) {
            if ($this->isAcceptedTypeCompatible($parentType, $childType)) {
                return true;
            }
        }
        return false;
    }

    private function isAcceptedTypeCompatible(array $parentType, array $childType): bool
    {
        $parentKind = $parentType['kind'] ?? null;
        $childKind = $childType['kind'] ?? null;

        if ($parentKind === 'instanceof' && $childKind === 'isObject') {
            return true;
        }
        if ($parentKind !== $childKind) {
            return false;
        }

        if ($parentKind === 'allOf') {
            return $parentType == $childType;
        }

        if ($parentKind !== 'instanceof') {
            return true;
        }

        $parentClass = $parentType['class'] ?? '';
        $childClass = $childType['class'] ?? '';
        if ($parentClass === $childClass) {
            return true;
        }
        if ($parentClass === '' || $childClass === '') {
            return false;
        }
        return $this->isInheritedFrom($parentClass, $childClass);
    }

    private function checkInterfaceImplementations(Node\Stmt\Class_|Node\Stmt\Enum_ $classStmt): void
    {
        $classDef = $this->classDef;
        foreach ($this->getClassImplementedInterfaces($classDef) as $interfaceName) {
            $this->checkInterfaceImplementation($classStmt, $classDef, $interfaceName);
        }
    }

    private function validateOverrideAttributes(Node\Stmt\Class_|Node\Stmt\Enum_ $classStmt): void
    {
        $methods = [...$this->classDef->methods, ...$this->classDef->abstractMethodDefs];
        foreach ($methods as $methodDef) {
            if (!$methodDef->functionDef?->overrideRequired) {
                continue;
            }
            if ($this->hasMatchingOverrideDeclaration($this->classDef, $methodDef->name)) {
                continue;
            }
            $this->fatalMissingOverride(
                $methodDef->node ?? $classStmt,
                $this->classDef->getNamespacedName(false),
                $methodDef->name,
            );
        }
    }

    private function hasMatchingOverrideDeclaration(ClassDef $classDef, string $methodName): bool
    {
        if (strtolower($methodName) === '__construct') {
            return false;
        }

        $current = $classDef;
        while ($current->extends !== '') {
            $parentName = $current->extends;
            if ($current->inheritedFromInternalClass || $this->isInternalClass($parentName)) {
                $modifiers = Reflection::getClassMethodModifiers($parentName, $methodName);
                if ($modifiers !== null && !($modifiers & \ReflectionMethod::IS_PRIVATE)) {
                    return true;
                }
                break;
            }
            if (!$this->hasClass($parentName)) {
                break;
            }
            $current = $this->getClass($parentName);
            if ($current->hasMethod($methodName)) {
                if (!($current->getMethod($methodName)->flags & Modifiers::PRIVATE)) {
                    return true;
                }
            } elseif ($current->hasAbstractMethod($methodName)) {
                if (!($current->getMethodFlags($methodName) & Modifiers::PRIVATE)) {
                    return true;
                }
            }
        }

        foreach ($this->getClassImplementedInterfaces($classDef) as $interfaceName) {
            if ($this->isInternalInterface($interfaceName)) {
                if (Reflection::hasMethod($interfaceName, $methodName)) {
                    return true;
                }
                continue;
            }
            if ($this->hasInterface($interfaceName) && $this->getInterface($interfaceName)->hasMethod($methodName)) {
                return true;
            }
        }
        return false;
    }

    private function validateInterfaceOverrideAttributes(Node\Stmt\Interface_ $interfaceStmt): void
    {
        $name = $this->parseIdentifier($interfaceStmt->name);
        $interfaceName = $this->namespace === '' ? $name : $this->namespace . '\\' . $name;
        if (!$this->hasInterface($interfaceName)) {
            return;
        }

        $interfaceDef = $this->getInterface($interfaceName);
        foreach ($interfaceDef->methods as $methodDef) {
            if (!$methodDef->functionDef?->overrideRequired) {
                continue;
            }
            $visited = [];
            if ($this->interfaceParentsHaveMethod($interfaceDef, $methodDef->name, $visited)) {
                continue;
            }
            $this->fatalMissingOverride($methodDef->node ?? $interfaceStmt, $interfaceName, $methodDef->name);
        }
    }

    private function fatalMissingOverride(NodeAbstract $node, string $className, string $methodName): never
    {
        $this->fatalCompileTimeAttribute(
            $node,
            'Override',
            "{$className}::{$methodName}() has #[\\Override] attribute, " .
            'but no matching parent method exists',
        );
    }

    /** @param array<string, true> $visited */
    private function interfaceParentsHaveMethod(
        InterfaceDef $interfaceDef,
        string $methodName,
        array &$visited,
    ): bool {
        foreach ($interfaceDef->extendsList ?: ($interfaceDef->extends ? [$interfaceDef->extends] : []) as $parentName) {
            $key = strtolower($parentName);
            if (isset($visited[$key])) {
                continue;
            }
            $visited[$key] = true;
            if ($this->isInternalInterface($parentName)) {
                if (Reflection::hasMethod($parentName, $methodName)) {
                    return true;
                }
                continue;
            }
            if (!$this->hasInterface($parentName)) {
                continue;
            }
            $parent = $this->getInterface($parentName);
            if ($parent->hasMethod($methodName)
                || $this->interfaceParentsHaveMethod($parent, $methodName, $visited)) {
                return true;
            }
        }
        return false;
    }

    private function checkInterfaceImplementation(NodeAbstract $node, ClassDef $classDef, string $interfaceName): void
    {
        if ($this->isInternalInterface($interfaceName)) {
            return;
        }
        if (!$this->hasInterface($interfaceName)) {
            return;
        }

        $interfaceDef = $this->getInterface($interfaceName);
        foreach ($interfaceDef->methods as $methodName => $interfaceMethodDef) {
            $childMethodDef = $this->findClassMethodDef($classDef, $methodName, $classDef->isAbstract());
            if ($childMethodDef === null) {
                if ($classDef->isAbstract()) {
                    continue;
                }
                $this->fatalError($node, "Class `{$classDef->getNamespacedName(false)}` must implement method `{$interfaceName}::{$interfaceMethodDef->name}()`");
            }
            $this->validateMethodOverrideSignature(
                $node,
                $interfaceMethodDef->name,
                $childMethodDef,
                $interfaceMethodDef,
                $interfaceName
            );
        }

        foreach ($interfaceDef->extendsList ?: ($interfaceDef->extends ? [$interfaceDef->extends] : []) as $parentInterface) {
            $this->checkInterfaceImplementation($node, $classDef, $parentInterface);
        }
    }

    private function findClassMethodDef(ClassDef $classDef, string $methodName, bool $includeAbstract = true): ?MethodDef
    {
        $current = $classDef;
        while (true) {
            if ($current->hasMethod($methodName)) {
                return $current->getMethod($methodName);
            }
            if ($includeAbstract && $current->hasAbstractMethod($methodName) && isset($current->abstractMethodDefs[strtolower($methodName)])) {
                return $current->getAbstractMethod($methodName);
            }
            if (!$current->extends || !$this->hasClass($current->extends)) {
                return null;
            }
            $current = $this->getClass($current->extends);
        }
    }

    private function checkInheritedAbstractMethodsAreImplemented(NodeAbstract $node): void
    {
        $classDef = $this->classDef;
        if ($classDef->isAbstract()) {
            return;
        }

        $current = $classDef;
        while ($current->extends && $this->hasClass($current->extends)) {
            $parent = $this->getClass($current->extends);
            foreach ($parent->abstractMethodDefs as $methodName => $abstractMethodDef) {
                if ($this->findClassMethodDef($classDef, $methodName, false) === null) {
                    $this->fatalError(
                        $node,
                        "Class `{$classDef->getNamespacedName(false)}` must implement abstract method `{$parent->getNamespacedName(false)}::{$abstractMethodDef->name}()`"
                    );
                }
            }
            $current = $parent;
        }
    }

    private function getVisibilityRank(int $flags): int
    {
        if ($flags & Modifiers::PUBLIC) {
            return 3;
        }
        if ($flags & Modifiers::PROTECTED) {
            return 2;
        }
        if ($flags & Modifiers::PRIVATE) {
            return 1;
        }
        return 3;
    }

    private function checkPropertyOverride(Node\Stmt\Class_|Node\Stmt\Trait_|Node\Stmt\Enum_ $classStmt): void
    {
        $classDef = $this->classDef;
        $className = $this->getFullClassName();
        $chainNode = $classDef;
        while ($chainNode->extends && !$chainNode->inheritedFromInternalClass) {
            $parentClass = $chainNode->extends;
            $chainNode = $this->getClass($parentClass);
            if (!$chainNode) {
                break;
            }
            foreach ($this->classDef->properties as $name => $childProp) {
                if ($chainNode->hasProperty($name)) {
                    $parentProp = $chainNode->getProperty($name);
                    // A parent private property would be a separate PHP slot
                    // hidden by the child declaration. TypePHP forbids that
                    // dual-slot model. Public/protected declarations instead
                    // describe the same inherited property slot and must obey
                    // PHP-compatible type, visibility and readonly rules.
                    if ($parentProp->flags & Modifiers::PRIVATE) {
                        $this->fatalError($classStmt,
                            "Declaration of `{$className}::\${$name}` conflicts with private property " .
                            "`{$parentClass}::\${$name}`; property shadowing across inheritance is not allowed");
                    }
                    if ($childProp->type !== $parentProp->type || $childProp->class !== $parentProp->class) {
                        $this->fatalError($classStmt,
                            "Declaration of `{$className}::\${$name}` must be compatible " .
                            "with `{$parentClass}::\${$name}`");
                    }
                    if ($this->getVisibilityRank($childProp->flags) < $this->getVisibilityRank($parentProp->flags)) {
                        $this->fatalError($classStmt,
                            "Declaration of `{$className}::\${$name}` must be compatible " .
                            "with `{$parentClass}::\${$name}`");
                    }
                    if ($this->getPropertySetVisibilityRank($childProp) < $this->getPropertySetVisibilityRank($parentProp)) {
                        $this->fatalError($classStmt,
                            "Declaration of `{$className}::\${$name}` must not restrict set visibility " .
                            "of `{$parentClass}::\${$name}`");
                    }
                    if (($childProp->flags & Modifiers::READONLY) !== ($parentProp->flags & Modifiers::READONLY)) {
                        $this->fatalError($classStmt,
                            "Declaration of `{$className}::\${$name}` must be compatible " .
                            "with `{$parentClass}::\${$name}`");
                    }
                }
            }
        }
    }

    private function getPropertySetVisibilityRank(PropertyDef $property): int
    {
        if ($property->isPrivateSet()) {
            return 1;
        }
        if ($property->isProtectedSet()) {
            return 2;
        }
        return $this->getVisibilityRank($property->flags);
    }

    private function checkConstantOverride(Node\Stmt\Class_|Node\Stmt\Trait_|Node\Stmt\Enum_ $classStmt): void
    {
        $classDef = $this->classDef;
        $className = $this->getFullClassName();
        $chainNode = $classDef;
        while ($chainNode->extends && !$chainNode->inheritedFromInternalClass) {
            $parentClass = $chainNode->extends;
            $chainNode = $this->getClass($parentClass);
            if (!$chainNode) {
                break;
            }
            foreach ($this->classDef->constants as $name => $childConst) {
                if ($chainNode->hasConstant($name)) {
                    $parentConst = $chainNode->getConstant($name);
                    if ($parentConst->flags & Modifiers::PRIVATE) {
                        continue;
                    }
                    if ($parentConst->flags & Modifiers::FINAL) {
                        $this->fatalError($classStmt,
                            "Cannot override final constant `{$parentClass}::{$name}`");
                    }
                    // PHP only enforces type compatibility when the parent constant
                    // carries an explicit declared type. Overriding an untyped constant
                    // with a value of any type is permitted, so the type check is skipped
                    // in that case. Visibility is always enforced below.
                    if ($parentConst->declaredType !== null) {
                        if ($childConst->declaredType === null) {
                            $this->fatalError($classStmt,
                                "Declaration of `{$className}::{$name}` must be compatible " .
                                "with `{$parentClass}::{$name}`");
                        }
                        // An untyped child constant whose value is an expression (e.g.
                        // `X = ParentClass::Y`) is inferred as a variant. Resolve its real
                        // type from the referenced constant so the compatibility check uses
                        // the actual value type.
                        $childType = $childConst->type;
                        if ($childType === Type::VAR
                            && $childConst->valueExpr instanceof Node\Expr\ClassConstFetch) {
                            $resolved = $this->resolveReferencedConstantType($childConst->valueExpr, $this->getFullClassName());
                            if ($resolved !== null) {
                                $childType = $resolved;
                            }
                        }
                        if ($childType !== $parentConst->type || $childConst->class !== $parentConst->class) {
                            $this->fatalError($classStmt,
                                "Declaration of `{$className}::{$name}` must be compatible " .
                                "with `{$parentClass}::{$name}`");
                        }
                    }
                    if ($this->getVisibilityRank($childConst->flags) < $this->getVisibilityRank($parentConst->flags)) {
                        $this->fatalError($classStmt,
                            "Declaration of `{$className}::{$name}` must be compatible " .
                            "with `{$parentClass}::{$name}`");
                    }
                }
            }
        }
    }

    protected function parseClassMethod(Node\Stmt\ClassMethod $v, array &$methodCodes): void
    {
        $name = $this->getMethodName($v);
        $this->method = $name;
        $flags = $this->parseModifiers($v->flags);

        if (!($flags & Modifiers::ABSTRACT)) {
            $this->methodDef = $this->classDef->getMethod($name);
            // Keep the AST node so trait-composed methods can report accurate
            // line numbers when validated for override compatibility later.
            $this->methodDef->node = $v;
            if ($this->classDef->trait === null
                && $this->methodDef->functionDef?->overrideRequired
                && !$this->hasMatchingOverrideDeclaration($this->classDef, $name)) {
                $this->fatalMissingOverride($v, $this->classDef->getNamespacedName(false), $name);
            }
            // 预处理阶段没有父类的信息，只能在实现阶段检查
            $this->checkParentMethodCanBeOverridden($v, $name);
            $methodCodes[$name] = $this->parseFunction($v);
        }

        $this->resetMethod();
    }

    protected function parseTraitUse(Node\Stmt\TraitUse $v, array &$methodCodes): void
    {
        $classDef = $this->classDef;

        foreach ($v->traits as $trait) {
            $traitName = $this->parseIdentifier($trait);
            $traitFullName = $this->getNamespacedClassName($traitName);
            if (!$this->hasClass($traitFullName)) {
                $this->fatalError($v, $traitFullName . ' not found');
            }
            $traitDef = $this->getClass($traitFullName);
            // 将 Trait 中定义的 常量、静态常量、属性、方法、静态属性复制到当前类中
            foreach ($traitDef->constants as $const) {
                if ($classDef->hasConstant($const->name)) {
                    if (!$this->isCompatibleTraitConstant($classDef->getConstant($const->name), $const)) {
                        $this->fatalError($v, "Trait `{$traitFullName}` constant `{$const->name}` conflicts with class `{$classDef->getNamespacedName(false)}`");
                    }
                    continue;
                }
                $classDef->constants[$const->name] = $const;
            }
            foreach ($traitDef->properties as $prop) {
                if ($classDef->hasProperty($prop->name)) {
                    if (!$this->isCompatibleTraitProperty($classDef->getProperty($prop->name), $prop)) {
                        $this->fatalError($v, "Trait `{$traitFullName}` property `{$prop->name}` conflicts with class `{$classDef->getNamespacedName(false)}`");
                    }
                    continue;
                }
                $classDef->properties[$prop->name] = $prop;
            }
            foreach ($traitDef->methods as $methodDef) {
                $classMethodName = $traitMethodName = $methodDef->name;
                $fullMethodName = $this->getFullMethodName($traitFullName, $traitMethodName);
                $originalMethodDef = $methodDef;
                $aliasMethodDefs = [];
                foreach ($classDef->traitAliases[$fullMethodName] ?? [] as $alias) {
                    if (strtolower($alias['newName']) === strtolower($traitMethodName)) {
                        if ($alias['newModifier']) {
                            if ($originalMethodDef === $methodDef) {
                                $originalMethodDef = clone $methodDef;
                            }
                            $originalMethodDef->flags = $this->parseModifiers($alias['newModifier']);
                        }
                        continue;
                    }
                    $aliasMethodDef = clone $methodDef;
                    $classMethodName = $aliasMethodDef->name = $alias['newName'];
                    if ($alias['newModifier']) {
                        $aliasMethodDef->flags = $this->parseModifiers($alias['newModifier']);
                    }
                    $aliasMethodDefs[strtolower($classMethodName)] = [$classMethodName, $aliasMethodDef];
                }
                // 设置了 insteadof 选项，此 Trait 的方法将不会被使用
                if (isset($classDef->traitIgnored[$fullMethodName])) {
                    foreach ($aliasMethodDefs as [$aliasMethodName, $aliasMethodDef]) {
                        if ($classDef->hasMethod($aliasMethodName)) {
                            continue;
                        }
                        $methodCodes[$aliasMethodName] = $this->addTraitMethodWrapper(
                            $classDef,
                            $traitDef,
                            $aliasMethodDef,
                            $traitMethodName,
                            $aliasMethodName
                        );
                    }
                    continue;
                }
                // 类中已经有同名方法，则不使用 Trait 中的方法
                if (!$classDef->hasMethod($traitMethodName)) {
                    $methodCodes[$traitMethodName] = $this->addTraitMethodWrapper(
                        $classDef,
                        $traitDef,
                        $originalMethodDef,
                        $traitMethodName,
                        $traitMethodName
                    );
                }

                foreach ($aliasMethodDefs as [$aliasMethodName, $aliasMethodDef]) {
                    if ($classDef->hasMethod($aliasMethodName)) {
                        continue;
                    }
                    $methodCodes[$aliasMethodName] = $this->addTraitMethodWrapper(
                        $classDef,
                        $traitDef,
                        $aliasMethodDef,
                        $traitMethodName,
                        $aliasMethodName
                    );
                }
            }
        }
    }

    private function addTraitMethodWrapper(
        ClassDef $classDef,
        ClassDef $traitDef,
        MethodDef $methodDef,
        string $traitMethodName,
        string $classMethodName
    ): string {
        // A trait method's `self`/`static`/`parent` return and parameter types
        // refer to the class that uses the trait, not the trait itself. Re-resolve
        // them to the consuming class so signature-compatibility checks (against
        // parent classes and interfaces) and `detectClassOfExpr()` observe the
        // correct type. The cloned FunctionDef keeps the trait's own native
        // function untouched.
        $this->reresolveTraitLateBoundTypes($classDef, $methodDef);

        // Validate `parent::` calls emitted from this trait method against the
        // parent of the class that is composing the trait. The trait itself has
        // no parent at compile time, so this is the only place the parent class
        // (and therefore the visibility of its methods) is known.
        foreach ($methodDef->parentMethodCalls as $parentCall) {
            $this->validateTraitParentCall($traitDef, $classDef, $parentCall['method'], $parentCall['node']);
        }

        // A trait method flattened into a class participates in the inheritance
        // hierarchy: it must remain signature-compatible with any same-named
        // parent method, exactly as a directly-declared override would. PHP
        // enforces this at class declaration time ("Declaration of X::m() must
        // be compatible with Y::m()"); without this check the incompatibility
        // only surfaces as a runtime fatal error that the compiled binary would
        // otherwise ignore and keep executing past.
        $this->checkTraitMethodOverrideCompatibility($classDef, $methodDef, $classMethodName);

        $classDef->addMethod($methodDef);
        $traitMethodNativeName = $this->getNativeName($traitMethodName, $traitDef->namespace, $traitDef->name);
        $classMethodNativeName = $this->getNativeName($classMethodName, $classDef->namespace, $classDef->name);
        $argList = ['this_'];
        if ($methodDef->parentMethodCalls) {
            // Bind parent:: to the class that actually composes the trait. This
            // remains correct when the generated wrapper is inherited further.
            $argList[] = $this->getClassEntryPtr($classDef->extends);
        }
        foreach ($methodDef->functionDef->argInfoList as $argInfo) {
            $argList[] = $argInfo->name;
        }
        $argv = implode(', ', $argList);

        $cppReturnType = $methodDef->functionDef->returnsByRef ? Type::REF : $methodDef->getReturnType();
        $code = $cppReturnType . ' ' . self::PREFIX . $classMethodNativeName . '(';
        if ($this->class) {
            $code .= Type::OBJECT . ' &this_';
            if ($methodDef->functionDef->params) {
                $code .= ', ';
            }
        }

        $this->addFunction($classMethodNativeName, $methodDef->functionDef);

        $code .= $methodDef->functionDef->params . ')';
        $code .= '{' . PHP_EOL;
        $this->indentLevel++;
        $methodCall = self::PREFIX . $traitMethodNativeName . '(' . $argv . ')';
        if ($cppReturnType !== Type::VOID) {
            $methodCall = 'return ' . $methodCall;
        }
        $code .= $this->getIndent() . $methodCall . ';' . PHP_EOL;
        $this->indentLevel--;
        $code .= $this->getIndent() . '}' . PHP_EOL;
        return $code;
    }

    /**
     * Re-resolve a trait method's late-bound `self`/`static`/`parent` return and
     * parameter types to the class that is composing the trait.
     *
     * In PHP, `self` (and `static`) inside a trait refers to the using class, and
     * `parent` refers to the using class's parent. The compiler records these as
     * the trait's own name at parse time, which is wrong once the method is
     * flattened into a class: interface/trait `self` comparisons and
     * `detectClassOfExpr()` would otherwise observe the trait name instead of the
     * consuming class. We clone the FunctionDef so the trait's standalone native
     * function keeps its original (trait-context) types.
     */
    private function reresolveTraitLateBoundTypes(ClassDef $usingClassDef, MethodDef $methodDef): void
    {
        $fn = $methodDef->functionDef;
        $needsClone = false;

        if ($fn->returnTypeKeyword !== '') {
            $resolved = $this->resolveLateBoundClass($usingClassDef, $fn->returnTypeKeyword);
            if ($resolved !== null && $resolved !== $fn->returnClass) {
                $needsClone = true;
            }
        }
        foreach ($fn->argInfoList as $arg) {
            if ($arg->typeKeyword !== '') {
                $resolved = $this->resolveLateBoundClass($usingClassDef, $arg->typeKeyword);
                if ($resolved !== null && ($resolved !== $arg->class || $resolved !== $arg->declaredClass)) {
                    $needsClone = true;
                    break;
                }
            }
        }

        if (!$needsClone) {
            return;
        }

        $newFn = clone $fn;
        if ($fn->returnTypeKeyword !== '') {
            $resolved = $this->resolveLateBoundClass($usingClassDef, $fn->returnTypeKeyword);
            if ($resolved !== null && $resolved !== $newFn->returnClass) {
                $newFn->returnClass = $resolved;
            }
        }
        $newArgs = [];
        foreach ($newFn->argInfoList as $arg) {
            $newArg = clone $arg;
            if ($newArg->typeKeyword !== '') {
                $resolved = $this->resolveLateBoundClass($usingClassDef, $newArg->typeKeyword);
                if ($resolved !== null) {
                    if ($newArg->class !== '') {
                        $newArg->class = $resolved;
                    }
                    if ($newArg->declaredClass !== '') {
                        $newArg->declaredClass = $resolved;
                    }
                }
            }
            $newArgs[] = $newArg;
        }
        $newFn->argInfoList = $newArgs;
        $methodDef->functionDef = $newFn;
    }

    private function resolveLateBoundClass(ClassDef $usingClassDef, string $keyword): ?string
    {
        if ($keyword === 'self') {
            return $usingClassDef->getNamespacedName(false);
        }
        if ($keyword === 'parent') {
            return $usingClassDef->extends !== '' ? $usingClassDef->extends : null;
        }
        // `static` is late-static-bound and resolved to the concrete class only at
        // call time, so it must keep an empty class (matching a directly-declared
        // `: static` method). Resolving it to the consuming class here would break
        // interface/trait signature-compatibility checks, which compare the empty
        // `static` class on both sides.
        return null;
    }

    /**
     * Validate a `parent::method()` call recorded inside a trait method.
     *
     * The trait has no parent of its own, so the only point at which the parent
     * class is known is when a class actually uses the trait. At that moment we
     * can statically resolve the parent method and reject private methods, which
     * PHP would otherwise only report as a runtime "Call to private method" error.
     */
    private function validateTraitParentCall(ClassDef $traitDef, ClassDef $usingClassDef, string $method, NodeAbstract $node): void
    {
        if (!$usingClassDef->extends) {
            $this->fatalError(
                $node,
                "Cannot access parent when class `{$usingClassDef->getNamespacedName(false)}` has no parent"
            );
        }
        $parentClass = $usingClassDef->extends;
        // Internal / not-compiled parents are opaque to the compiler; let the
        // runtime enforce visibility for those.
        if (!$this->hasClass($parentClass)) {
            return;
        }
        if ($this->getMethodFlags($parentClass, $method) & Modifiers::PRIVATE) {
            $this->fatalError(
                $node,
                "Cannot access private method `{$parentClass}::{$method}()` via parent:: in trait `{$traitDef->name}`"
            );
        }
    }

    /**
     * Validate that a trait method being flattened into a class remains
     * signature-compatible with any same-named method declared up the parent
     * chain — the same compatibility contract a directly-declared override must
     * satisfy (see `checkParentMethodCanBeOverridden`).
     *
     * Only the signature contract is enforced here (not the "cannot override
     * private/final" rule), because a trait method is flattened into the class
     * and, like a normal subclass method, is allowed to shadow a private parent
     * method. PHP reports the incompatibility as a class-declaration fatal error
     * ("Declaration of X::m() must be compatible with Y::m()"), which we surface
     * at compile time so the broken program is rejected instead of being emitted
     * and executed past a runtime fatal error.
     */
    private function checkTraitMethodOverrideCompatibility(ClassDef $usingClassDef, MethodDef $methodDef, string $methodName): void
    {
        if ($methodName === '__construct' || $methodDef->node === null) {
            return;
        }
        $classDef = $usingClassDef;
        while (true) {
            $extends = $classDef->extends;
            if (!$extends) {
                break;
            }
            if ($classDef->inheritedFromInternalClass) {
                $modifiers = Reflection::getClassMethodModifiers($extends, $methodName);
                if ($modifiers !== null && ($modifiers & \ReflectionMethod::IS_FINAL)) {
                    $this->fatalError($methodDef->node, "Cannot override final method `{$extends}::{$methodName}()`");
                }
                break;
            }
            // Dynamically supplied parents are opaque to the compiler.
            if (!$this->hasClass($extends)) {
                break;
            }
            $classDef = $this->getClass($extends);
            if ($classDef->hasMethod($methodName)) {
                $parentMethodDef = $classDef->getMethod($methodName);
                // A private method is a separate slot and may be shadowed by the
                // method imported from the trait.
                if ($parentMethodDef->flags & Modifiers::PRIVATE) {
                    break;
                }
                if ($parentMethodDef->flags & Modifiers::FINAL) {
                    $this->fatalError($methodDef->node, "Cannot override final method `{$extends}::{$methodName}()`");
                }
                $this->validateMethodOverrideSignature(
                    $methodDef->node,
                    $methodName,
                    $methodDef,
                    $parentMethodDef,
                    $extends
                );
                break;
            }
            if ($classDef->hasAbstractMethod($methodName) && isset($classDef->abstractMethodDefs[strtolower($methodName)])) {
                $this->validateMethodOverrideSignature(
                    $methodDef->node,
                    $methodName,
                    $methodDef,
                    $classDef->getAbstractMethod($methodName),
                    $extends
                );
                break;
            }
        }
    }

    private function isCompatibleTraitConstant(ConstantDef $existing, ConstantDef $incoming): bool
    {
        return $existing->flags === $incoming->flags &&
            $existing->type === $incoming->type &&
            $existing->class === $incoming->class &&
            $existing->value === $incoming->value;
    }

    private function isCompatibleTraitProperty(PropertyDef $existing, PropertyDef $incoming): bool
    {
        return $existing->flags === $incoming->flags &&
            $existing->type === $incoming->type &&
            $existing->class === $incoming->class &&
            $existing->nullable === $incoming->nullable &&
            $existing->default === $incoming->default;
    }

    private function getRegisterClassFunctionArgDef(ClassDef|InterfaceDef $classDef): string
    {
        $depsCeList = $this->getRegisterClassFunctionCeList($classDef);
        if (empty($depsCeList)) {
            return '';
        }

        return 'zend_class_entry *' . implode(', zend_class_entry *', $depsCeList);
    }

    private function getImplementCe(ClassDef $classDef): array
    {
        $list = [];
        foreach ($classDef->implements as $interface) {
            $list[] = self::PREFIX . 'class_entry_' . $this->escapeCeName($interface);
        }

        return $list;
    }

    private function genFunctionWrapper(FunctionDef $functionDef): string
    {
        $name = $this->escapeZendFnName($functionDef->getNamespacedName());
        $cppCode = 'ZEND_FUNCTION(' . $name . '){' . PHP_EOL;
        $fn = self::PREFIX . $this->getNativeName($functionDef->name, $functionDef->namespace);
        $cppCode .= $this->genWrapperFunctionArgs($fn, $functionDef, $functionDef->getNamespacedName());

        return $cppCode;
    }

    /** Return the generated C++ symbol for a hidden runtime-attribute factory. */
    public function getRuntimeAttributeFactoryNativeName(string $fullName): string
    {
        $fullName = ltrim($fullName, '\\');
        $separator = strrpos($fullName, '\\');
        if ($separator === false) {
            return self::PREFIX . $this->getNativeName($fullName);
        }
        return self::PREFIX . $this->getNativeName(
            substr($fullName, $separator + 1),
            substr($fullName, 0, $separator),
        );
    }

    private function genClassNative(): string
    {
        $code = 'class ' . $this->class . ' { ';

        $publicMethods = [];
        $protectedMethods = [];
        $privateMethods = [];
        $publicConstants = [];
        $protectedConstants = [];
        $privateConstants = [];
        $publicProperties = [];
        $protectedProperties = [];
        $privateProperties = [];

        foreach ($this->classDef->constants as $const) {
            if ($const->flags & Modifiers::PUBLIC) {
                $publicConstants[] = $const;
            }
            if ($const->flags & Modifiers::PROTECTED) {
                $protectedConstants[] = $const;
            }
            if ($const->flags & Modifiers::PRIVATE) {
                $privateConstants[] = $const;
            }
        }
        foreach ($this->classDef->methods as $method) {
            if ($method->flags & Modifiers::PUBLIC) {
                $publicMethods[] = $method;
            }
            if ($method->flags & Modifiers::PROTECTED) {
                $protectedMethods[] = $method;
            }
            if ($method->flags & Modifiers::PRIVATE) {
                $privateMethods[] = $method;
            }
        }
        foreach ($this->classDef->properties as $property) {
            if ($property->flags & Modifiers::PUBLIC) {
                $publicProperties[] = $property;
            }
            if ($property->flags & Modifiers::PROTECTED) {
                $protectedProperties[] = $property;
            }
            if ($property->flags & Modifiers::PRIVATE) {
                $privateProperties[] = $property;
            }
        }

        if ($privateConstants) {
            $code .= 'private:' . PHP_EOL;
            $code .= $this->genClassConstantList($privateConstants);
        }

        if ($protectedConstants) {
            $code .= 'protected:' . PHP_EOL;
            $code .= $this->genClassConstantList($protectedConstants);
        }

        if ($publicConstants) {
            $code .= 'public:' . PHP_EOL;
            $code .= $this->genClassConstantList($publicConstants);
        }

        if ($privateProperties) {
            $code .= 'private:' . PHP_EOL;
            $code .= $this->genClassPropertyList($privateProperties);
        }

        if ($protectedProperties) {
            $code .= 'protected:' . PHP_EOL;
            $code .= $this->genClassPropertyList($protectedProperties);
        }

        if ($publicProperties) {
            $code .= 'public:' . PHP_EOL;
            $code .= $this->genClassPropertyList($publicProperties);
        }

        $code .= '};' . PHP_EOL . PHP_EOL;

        return $code;
    }
}
