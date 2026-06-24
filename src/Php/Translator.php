<?php
/**
 * This file is part of Swoole-Compiler(AOT).
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace PhpAot\Php;

use MJS\TopSort\Implementations\StringSort;
use PhpAot\Php\Analysis\SsaBuilder;
use PhpAot\Php\Backend\CompilerFactory;
use PhpAot\Php\Entity\ArrayInitPlan;
use PhpAot\Php\Entity\ClassDef;
use PhpAot\Php\Entity\ClassLikeDef;
use PhpAot\Php\Entity\ConstantDef;
use PhpAot\Php\Entity\FunctionDef;
use PhpAot\Php\Entity\InterfaceDef;
use PhpAot\Php\Entity\MethodDef;
use PhpAot\Php\Entity\PropertyDef;
use PhpAot\Php\Exception\Redo;
use PhpAot\Php\Exception\Skip;
use PhpAot\Php\Exception\SyntaxError;
use PhpAot\Php\Exception\Unsupported;
use PhpAot\Php\Generator\ResourceFileGenerator;
use PhpAot\Php\Platform\PlatformFactory;
use PhpAot\Php\Platform\Windows;
use PhpParser\Modifiers;
use PhpParser\Node;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr\List_;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\NodeAbstract;
use PhpParser\NodeTraverser;
use Ajaxray\AnsiKit\AnsiTerminal;
use Ajaxray\AnsiKit\Components\Progressbar;
use Symfony\Component\Yaml\Yaml;

class Translator extends Preprocessor
{
    public const string VERSION = '0.2.3';
    public const string APP_NAME = 'Swoole-Compiler (AOT)';
    protected string $targetName = 'app';
    protected array $sourceDirs = [];
    protected bool $verbose = false;
    protected array $phpSrcFiles = [];
    protected array $ignorePaths = [];
    protected array $ignoreExtensions = [];
    protected array $argInfoHeaderFiles = [];
    protected array $registerSymbols = [];

    // Windows 资源文件配置（图标、版本信息等）
    protected array $resourceConfig = [];

    protected bool $useRegisterSymbolsFn = false;

    protected const string MODULE_NAME_PREFIX = 'app_';

    protected function isConstructorNativeFunction(FunctionDef $func): bool
    {
        return $func->method && str_ends_with($func->name, self::NAMESPACE_SEPARATOR . '__construct');
    }

    protected function getDefaultArgumentType(ArgInfo $argInfo): string
    {
        $type = $argInfo->type;
        if ($type === self::TYPE_STREAM || $type === self::TYPE_BOX) {
            return self::TYPE_VAR;
        }
        return $type;
    }

    protected function getDefaultArgumentHelperName(FunctionDef $func, ArgInfo $argInfo): string
    {
        return self::PREFIX . 'default_arg_' . $func->name . '_' . $argInfo->name;
    }

    protected function genDefaultArgumentExpr(FunctionDef $func, ArgInfo $argInfo): string
    {
        if (!$argInfo->arrayInitPlan || !$argInfo->arrayInitPlan->requiresRuntimeInit()) {
            return $argInfo->default;
        }

        return $this->getDefaultArgumentHelperName($func, $argInfo) . '()';
    }

    protected function wrapArrayInitPlan(ArrayInitPlan $plan, string $body): string
    {
        return "do {\n" . $plan->init . $body . $plan->clean . "} while (0);\n";
    }

    protected function genDefaultArgumentHelpers(): string
    {
        $code = '';
        foreach ($this->functions as $func) {
            foreach ($func->argInfoList as $argInfo) {
                $plan = $argInfo->arrayInitPlan;
                if (!$plan || !$plan->requiresRuntimeInit()) {
                    continue;
                }

                $type = $this->getDefaultArgumentType($argInfo);
                $helper = $this->getDefaultArgumentHelperName($func, $argInfo);
                $code .= 'static inline ' . $type . ' ' . $helper . "() {\n";
                $code .= $plan->init;
                if ($plan->clean) {
                    $code .= $type . ' retval = ' . $plan->expr . ';' . PHP_EOL;
                    $code .= $plan->clean;
                    $code .= 'return retval;' . PHP_EOL;
                } else {
                    $code .= 'return ' . $plan->expr . ';' . PHP_EOL;
                }
                $code .= '}' . PHP_EOL;
            }
        }

        return $code ? $code . PHP_EOL : '';
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
        $this->internalConstants = get_defined_constants();
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
        $climate->tab()->out($cmd . ' <file/dir/project.yml> [options]');
        $climate->br();

        $climate->bold('ARGUMENTS:');
        $climate->tab()->out('<file>    Input PHP file/directory/project.yml to compile');
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
        $climate->tab()->out('-m, --mode <mode>    Compilation mode, -m bin(binary) or -m ext(extension), default: bin');
        $climate->tab()->out('-r, --run           Run the compiled binary after build');
        $climate->tab()->out('-j, --job <num>      Number of parallel compilation jobs (default: 4)');
        $climate->tab()->out('--cxx-std <ver>      C++ standard version (c++17, c++20, etc., default: c++17)');
        $climate->tab()->out('--march <arch>       Target CPU instruction set (e.g. native, x86-64-v3, armv8-a)');
        $climate->tab()->out('--target-platform <triple> Cross-compilation target triple (e.g. aarch64-linux-gnu)');
        $climate->tab()->out('--lto                Enable Link Time Optimization (-flto)');
        $climate->tab()->out('--no-literal-strings Disable literal strings optimization');
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
        // 优化级别
        if ($this->climate->arguments->defined('optimize')) {
            $this->optimizeLevel = $this->climate->arguments->get('optimize');
        }

        // 构建模式
        if ($this->climate->arguments->defined('mode')) {
            $this->buildMode = $this->climate->arguments->get('mode');
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
        $this->userIncludePaths = $this->parseRepeatableArgv(['-I', '--include-path']);
        // 用户自定义预处理器宏（直接从 argv 解析以支持多值）
        $this->userDefines = $this->parseRepeatableArgv(['-D', '--define']);

        // 链接时优化
        if ($this->climate->arguments->defined('lto')) {
            $this->enableLto = true;
        }

        // clang-format 代码格式化（默认关闭，需显式 --format 开启）
        if ($this->climate->arguments->defined('format')) {
            $clangFormatVersion = shell_exec('clang-format --version');
            if (!empty($clangFormatVersion)) {
                $this->formatCode = true;
            } else {
                $this->climate->warning('--format requested but clang-format not found, skipping formatting');
            }
        }

        // 用户自定义链接库（直接从 argv 解析以支持多值）
        $this->linkLibs = $this->parseRepeatableArgv(['-l', '--link-lib']);
        // 用户自定义库搜索路径（直接从 argv 解析以支持多值）
        $this->linkPaths = $this->parseRepeatableArgv(['-L', '--link-path']);
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

    public function save(string $code, string $file): void
    {
        $this->writeFile($file, $code);
        $this->formatCppCode($file);
    }

    public function convertFile(string $file): string
    {
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
        $this->buildMode = $mode;
    }

    public function setTargetName(string $name): void
    {
        if ($this->climate->arguments->defined('output')) {
            $name = $this->climate->arguments->get('output');
        }
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
        if (in_array($name, Constants::CPP_RESERVED_NAMES)) {
            $this->climate->red('The target name `' . $name . '` must not be a reserved keyword');
            exit(1);
        }
        $realTargetPath = $this->rootPath . '/' . $name;
        if (is_dir($realTargetPath)) {
            $this->climate->red('The target name `' . $name . '` must not be a directory');
            exit(1);
        }
        $this->targetName = $name;
    }

    public function addFiles(array $files): void
    {
        $this->sourceDirs = array_merge($this->sourceDirs, $files);
    }

    public function getFiles(string $path): array
    {
        $realpath = realpath($path);
        if ($realpath === false) {
            $this->error("path not exists: {$path}");
        }
        $path = $realpath;

        if (is_dir($path)) {
            // 目录模式：不解析 YAML
            $list = $this->getFilesFromDir($path);
            $targetName = basename($path);
            $this->setTargetName($targetName);
            $this->sourceDirs[] = $path;
        } else {
            $ext = pathinfo($path, PATHINFO_EXTENSION);
            if ($ext === 'yml') {
                // YAML 配置模式：先解析 YAML
                $list = $this->parseProjectYaml($path);
            } elseif ($ext === 'php') {
                // 单文件模式：不解析 YAML
                $list = [$path];
                $targetName = FileScanner::getFileName($path);
                $this->setTargetName($targetName);
                $this->sourceDirs[] = dirname($path);
            } else {
                $this->error('Unsupported file type: ' . $path);
            }
        }

        // 在所有配置加载完成后，应用命令行参数（确保优先级最高）
        $this->applyCommandLineArguments();

        return $list;
    }

    public function prepare(string $path): array
    {
        // shell_exec 和 define 已通过 php::fn:: 直接调用，无需动态符号表

        // 根据平台检查库文件（仅在构建二进制文件时需要）
        if ($this->isBuildModeBin()) {
            foreach ($this->getPlatform()->getBuildLibraryWarnings($this->getPhpDir(), $this->getPhpxDir(), $this->buildMode) as $message) {
                $this->climate->warning($message['warning']);
                if (!empty($message['info'])) {
                    $this->climate->info($message['info']);
                }
            }
        }

        $files = $this->getFiles($path);
        // 应用 ignorePaths 过滤
        if (!empty($this->ignorePaths)) {
            $files = array_filter($files, function ($file) {
                foreach ($this->ignorePaths as $ignorePath) {
                    if ($file === $ignorePath) {
                        return false;
                    }
                    if (is_dir($ignorePath) && str_starts_with($file, $ignorePath . DIRECTORY_SEPARATOR)) {
                        return false;
                    }
                }
                return true;
            });
        }
        // 分析 PHP 文件，预处理
        foreach ($files as $k => $file) {
            if (FileScanner::isPhpFile($file)) {
                try {
                    $this->prepareFile($file);
                } catch (Unsupported $e) {
                    $this->output(' unsupported syntax: ' . $e->getMessage() . "\n" . ' skip: ' . $file . "\n", 'error');
                    unset($files[$k]);
                } catch (SyntaxError $e) {
                    $this->output(' syntax error: ' . $e->getMessage() . "\n" . ' skip: ' . $file . "\n", 'error');
                    unset($files[$k]);
                }
            }
        }
        $this->sortFiles($files);
        return $files;
    }

    public function convert(array $files): array
    {
        $sourceFiles = [];
        // 生成 C++ 文件
        foreach ($files as $k => $file) {
            try {
                if (FileScanner::isPhpFile($file)) {
                    $cppFile = $this->convertFile($file);
                } elseif (FileScanner::isNativeSourceFile($file)) {
                    $cppFile = $file;
                } else {
                    continue;
                }
                $sourceFiles[] = $cppFile;
            } catch (Unsupported $e) {
                echo ' unsupported syntax: ' . $e->getMessage() . "\n";
                echo ' skip: ' . $file . "\n";
                unset($files[$k]);
            }
        }

        if (empty($sourceFiles)) {
            $this->stop('No valid source file found');
        }

        // 生成头文件：函数声明、全局变量声明
        $this->genFunctionDeclaration($this->getIncludeDir() . "/php_{$this->targetName}_func_decl.h");
        $this->genExternGlobalVars($this->getIncludeDir() . "/php_{$this->targetName}_global_var_decl.h");
        // 生成扩展模块源文件
        $sourceFiles[] = $this->genExtension();

        return $sourceFiles;
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

    public function genExternGlobalVars(string $file): void
    {
        $lines[] = '#include <phpx.h>';
        $lines[] = PHP_EOL;
        foreach ($this->globalVars as $name => $type) {
            $lines[] = 'extern THREAD_LOCAL ' . self::TYPE_VAR . ' ' . $this->escapeGlobalVar($name) . ';';
        }

        if ($this->literalStrings) {
            $literalStringsCount = count($this->literalStrings);
            $lines[] = 'extern ' . self::TYPE_STR . ' ' . self::LITERAL_STRINGS . '[' . $literalStringsCount . '];' . PHP_EOL;
        }

        // 确保数组大小至少为 1，避免 C/C++ 编译错误
        $classCount = max(1, count($this->classMap));
        $lines[] = 'extern THREAD_LOCAL zend_class_entry *' . self::PREFIX . self::CLASS_MAP . '[' . $classCount . '];' . PHP_EOL;

        $funcCount = max(1, count($this->funcMap));
        $lines[] = 'extern THREAD_LOCAL zend_function *' . self::PREFIX . self::FUNC_MAP . '[' . $funcCount . '];' . PHP_EOL;

        $propCount = max(1, count($this->propMap));
        $lines[] = 'extern THREAD_LOCAL uint32_t ' . self::PREFIX . self::PROP_MAP . '[' . $propCount . '];' . PHP_EOL;

        foreach ($this->classes as $classDef) {
            foreach ($classDef->constants as $constant) {
                if ($constant->type === self::TYPE_ARRAY) {
                    $constName = self::PREFIX . $this->getNativeName($constant->name, $classDef->namespace, $classDef->name);
                    $lines[] = 'extern ' . self::TYPE_VAR . ' ' . $constName . ';' . PHP_EOL;
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

        $code = $this->genIncludeHeaderFiles();

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
            $code .= 'THREAD_LOCAL ' . self::TYPE_VAR . ' ' . $this->escapeGlobalVar($name) . ';' . PHP_EOL;
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
            $code .= self::TYPE_STR . ' ' . self::LITERAL_STRINGS . '[] = {' . PHP_EOL;
            foreach ($this->literalStrings as $str => $index) {
                $code .= self::TYPE_STR . '{ZEND_STRL("' . $this->escapeString($str) . '"), true}, // [' . $index . ']' . PHP_EOL;
            }
            $code .= '};' . PHP_EOL . PHP_EOL;
        } else {
            $code .= PHP_EOL;
        }

        $code .= "// constants \n";
        foreach ($this->constants as $name => $const) {
            $code .= $const->type . ' ' . $name . ";\n";
        }

        $code .= "// class \n";
        foreach ($this->classes as $classDef) {
            if ($classDef->requireCtor) {
                $code .= 'static zend_object* (*create_object_' . $classDef->getNamespacedName() . ")(zend_class_entry *class_type);\n";
            }
            foreach ($classDef->constants as $constant) {
                if ($constant->type === self::TYPE_ARRAY) {
                    $constName = self::PREFIX . $this->getNativeName($constant->name, $classDef->namespace, $classDef->name);
                    $code .= self::TYPE_VAR . ' ' . $constName . ";\n";
                }
            }
        }

        $code .= "// clang-format off\n";
        $code .= "static const zend_function_entry ext_functions[] = {\n";
        if ($this->isBuildModeBin()) {
            $code .= $this->getIndent() . "PHP_FE(cli_set_process_title,        arginfo_cli_set_process_title)\n";
            $code .= $this->getIndent() . "PHP_FE(cli_get_process_title,        arginfo_cli_get_process_title)\n";
        }

        foreach ($this->functions as $functionDef) {
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
        $code .= $this->genClassPropertyInit() . PHP_EOL;

        $code .= '// register symbols' . PHP_EOL;
        foreach ($this->registerSymbols as $registerSymbolFn) {
            $code .= $registerSymbolFn . '(module_number);' . PHP_EOL;
        }
        $code .= '} zend_end_try();' . PHP_EOL;
        $code .= 'return SUCCESS;' . PHP_EOL;
        $code .= '}' . PHP_EOL . PHP_EOL;
        // minit end

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
        foreach ($this->classes as $classDef) {
            foreach ($classDef->properties as $property) {
                if (!$property->isStatic() || !$property->arrayInitPlan || !$property->default) {
                    continue;
                }
                $statement = 'php::setStaticProperty('
                    . $this->genCharPtr($classDef->getNamespacedName(false), true) . ', '
                    . $this->genCharPtr($property->name) . ', '
                    . $property->arrayInitPlan->expr . ');' . PHP_EOL;
                $code .= $this->wrapArrayInitPlan($property->arrayInitPlan, $statement);
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
            if ($const->type !== self::TYPE_VAR) {
                continue;
            }
            $code .= $name . '.unset();' . PHP_EOL;
        }

        $code .= '// class array constants' . PHP_EOL;
        foreach ($this->classes as $classDef) {
            foreach ($classDef->constants as $constant) {
                if ($constant->type === self::TYPE_ARRAY) {
                    $constName = self::PREFIX . $this->getNativeName($constant->name, $classDef->namespace, $classDef->name);
                    $code .= $constName . ".unset();\n";

                    $classNameStr = $this->genCharPtr($classDef->getNamespacedName(false), true);
                    $classConstStr = $this->genCharPtr($constant->name);
                    $code .= "php::updateConstant($classNameStr, $classConstStr, php::null);\n";
                }
            }
        }

        // Clean up inherited array constants from child classes
        foreach ($this->classes as $className => $classDef) {
            $ownConstNames = [];
            foreach ($classDef->constants as $constant) {
                if ($constant->type === self::TYPE_ARRAY) {
                    $ownConstNames[$constant->name] = true;
                }
            }

            $parentName = $this->escapeClass($classDef->extends);
            while ($parentName && isset($this->classes[$parentName])) {
                $parentDef = $this->classes[$parentName];
                foreach ($parentDef->constants as $constant) {
                    if ($constant->type === self::TYPE_ARRAY && !isset($ownConstNames[$constant->name])) {
                        $ownConstNames[$constant->name] = true;
                        $classNameStr = $this->genCharPtr($classDef->getNamespacedName(false), true);
                        $classConstStr = $this->genCharPtr($constant->name);
                        $code .= "php::updateConstant($classNameStr, $classConstStr, php::null);\n";
                    }
                }
                $parentName = $this->escapeClass($parentDef->extends);
            }
        }

        // 扩展模式，需要在 RSHUTDOWN 阶段中清理函数、类、属性表
        if ($this->isBuildModeExt()) {
            $code .= self::FUNC_MAP." = {}\n";
            $code .= self::CLASS_MAP." = {}\n";
            $code .= self::PROP_MAP." = {}\n";
        }

        $code .= '}' . PHP_EOL . PHP_EOL;
        // php_app_clean end

        $moduleName = $this->getModuleName();
        // rinit begin
        $code .= 'PHP_RINIT_FUNCTION(' . $moduleName . ') {' . PHP_EOL;
        $code .= 'php::request_init();' . PHP_EOL;
        $code .= 'php_app_init();' . PHP_EOL;

        if ($this->isBuildModeBin()) {
            if (count($this->functions[self::ENTRY_FUNCTION]->argInfoList) == 2) {
                $code .= 'php::eval("global $argc, $argv; main($argc, $argv);");' . PHP_EOL;
            } else {
                $code .= 'php::eval("main();");' . PHP_EOL;
            }
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
    nullptr,
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
     * 仅检查 .o 文件是否比源文件和 phpx 头文件更新。
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

        // 检测文件语言类型
        $language = $this->getLanguageFromExtension($cppFile);

        // 使用 Backend 层构建编译命令
        if ($language === null) {
            // C++ 文件：使用标准的编译命令构建
            $cmd = $this->getCompilerBackend()->buildCompileCommand(
                $cppFile,
                $objectFile,
                $this->getCompileCommandOptions()
            );
        } elseif ($language === 'c') {
            // C 文件：使用 buildCCompileCommand，后端自动添加 -x c 或 /TC
            $cmd = $this->getCompilerBackend()->buildCCompileCommand(
                $cppFile,
                $objectFile,
                $this->getCCompileCommandOptions()
            );
        } else {
            // 其他原生源文件（assembler, objective-c, objective-c++）
            // 使用 buildNativeCompileCommand，传入语言类型以添加 -x 标志
            $cmd = $this->getCompilerBackend()->buildNativeCompileCommand(
                $cppFile,
                $objectFile,
                $this->getNativeCompileCommandOptions($language),
                $language
            );
        }

        if (!$parallel) {
            $this->climate->comment($cmd);
        }
        // 在并行模式下，抑制 passthru 的输出
        if ($parallel) {
            exec($cmd . ' 2>&1', $output, $ret);
        } else {
            passthru($cmd, $ret);
        }
        if ($ret !== 0) {
            if ($parallel && !empty($output)) {
                foreach ($output as $line) {
                    $this->climate->red($line);
                }
            }
            $this->error('compile failed: ' . $cppFile);
        }
    }

    public function compile(array $sourceFiles): array
    {
        $job = $this->maxJob;

        // embed 需要 main 函数，以及 cli 的内置函数定义
        if ($this->isBuildModeBin()) {
            $sourceFiles[] = $this->getPhpxDir() . '/src/misc/main.cc';
            $sourceFiles[] = $this->getPhpxDir() . '/src/misc/php_cli_process_title.c';
            $sourceFiles[] = $this->getPhpxDir() . '/src/misc/ps_title.c';
        }

        // Windows 平台：编译资源文件（图标、版本信息等）
        $this->compileResourceFile();

        if (!$this->getPlatform()->supportsPcntlParallelCompile() or $job <= 1) {
            return $this->compileSourceFile($sourceFiles);
        }

        // Unix/Linux/macOS 使用 pcntl 并行编译
        return $this->compileWithPcntl($sourceFiles, $job);
    }

    protected function compileSourceFile(array $sourceFiles): array
    {
        $objectFiles = [];
        $totalFiles = count($sourceFiles);
        $failedFiles = [];

        $this->climate->lightBlue("Starting compilation for {$totalFiles} files");

        foreach ($sourceFiles as $cppFile) {
            $objectFile = $this->getObjectFile($cppFile);

            try {
                $this->compileFile($cppFile, $objectFile, false);
                if (is_file($objectFile)) {
                    $objectFiles[] = $objectFile;
                } else {
                    $failedFiles[] = $cppFile;
                    $this->climate->red("Compilation failed: {$cppFile}");
                }
            } catch (\Throwable $e) {
                $failedFiles[] = $cppFile;
                $this->climate->red("Compilation error: {$cppFile} - " . $e->getMessage());
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
    protected function compileWithPcntl(array $sourceFiles, int $job): array
    {
        // 检查 pcntl 扩展是否可用
        if (!function_exists('pcntl_fork')) {
            $this->climate->warning('pcntl extension not available, using sequential compilation');
            return $this->compileSourceFile($sourceFiles);
        }

        $objectFiles = [];
        $totalFiles = count($sourceFiles);
        $runningProcesses = 0;
        $processPipes = [];
        $fileQueue = $sourceFiles;
        $compiledCount = 0;
        $failedFiles = [];

        $this->climate->lightBlue("Starting parallel compilation with {$job} jobs for {$totalFiles} files");

        $progress = new Progressbar();
        $progress->barStyle([AnsiTerminal::FG_GREEN])
            ->percentageStyle([AnsiTerminal::TEXT_BOLD])
            ->labelStyle([AnsiTerminal::FG_CYAN]);
        $progress->renderInPlace(0, $totalFiles, 'Compiling');

        while ($compiledCount < $totalFiles) {
            // 启动新进程，直到达到最大并发数
            while ($runningProcesses < $job && !empty($fileQueue)) {
                $cppFile = array_shift($fileQueue);
                $objectFile = $this->getObjectFile($cppFile);

                $pid = pcntl_fork();
                if ($pid == -1) {
                    throw new \Exception('Failed to fork process');
                }
                if ($pid === 0) {
                    // 子进程：执行编译
                    try {
                        $this->compileFile($cppFile, $objectFile, true);
                        if (!is_file($objectFile)) {
                            exit(1);
                        }
                        exit(0);
                    } catch (\Throwable $e) {
                        // 在子进程中不抛出异常，直接退出
                        exit(1);
                    }
                } else {
                    // 父进程：记录子进程
                    $processPipes[$pid] = ['file' => $cppFile, 'object' => $objectFile];
                    $runningProcesses++;
                }
            }

            // 等待任意一个子进程完成
            if ($runningProcesses > 0) {
                $status = null;
                $pid = pcntl_wait($status);
                if ($pid > 0) {
                    $processInfo = $processPipes[$pid] ?? null;
                    unset($processPipes[$pid]);
                    $runningProcesses--;

                    $exitCode = pcntl_wexitstatus($status);
                    if ($exitCode !== 0) {
                        $failedFile = $processInfo['file'] ?? 'unknown';
                        $failedFiles[] = $failedFile;
                        echo PHP_EOL;
                        $this->climate->red("Compilation failed: {$failedFile}");
                        echo PHP_EOL;
                    } else {
                        if ($processInfo) {
                            $objectFiles[] = $processInfo['object'];
                        }
                    }
                    $compiledCount++;
                    $progress->renderInPlace($compiledCount, $totalFiles, 'Compiling');
                }
            }
        }

        // 确保所有子进程都已结束
        while ($runningProcesses > 0) {
            $status = null;
            $pid = pcntl_wait($status);
            if ($pid > 0) {
                unset($processPipes[$pid]);
                $runningProcesses--;
                $compiledCount++;
            }
        }

        echo PHP_EOL;

        if (!empty($failedFiles)) {
            throw new \Exception('Compilation failed for: ' . implode(', ', $failedFiles));
        }

        $this->climate->green("Successfully compiled {$totalFiles} files");
        return $objectFiles;
    }

    public function output(string $message, string $style = 'out'): void
    {
        $this->climate->{$style}($message);
    }

    // ========================================================================
    // Windows 资源文件支持
    // ========================================================================

    /**
     * 检查是否配置了 Windows 资源信息
     */
    public function hasResourceFile(): bool
    {
        if (!$this->isWindows()) {
            return false;
        }
        $generator = $this->createResourceGenerator();
        return $generator !== null && $generator->hasResource();
    }

    /**
     * 获取 .rc 资源文件路径
     */
    public function getResourceRcFile(): string
    {
        return $this->getBuildDir() . DIRECTORY_SEPARATOR . 'app_resource.rc';
    }

    /**
     * 获取 .res 编译后的资源文件路径
     */
    public function getResourceResFile(): string
    {
        return $this->getBuildDir() . DIRECTORY_SEPARATOR . 'app_resource.res';
    }

    /**
     * 创建资源文件生成器
     */
    protected function createResourceGenerator(): ?ResourceFileGenerator
    {
        if (empty($this->resourceConfig)) {
            return null;
        }
        $projectDir = $this->resourceConfig['_projectDir'] ?? getcwd();
        return new ResourceFileGenerator($this->resourceConfig, $projectDir);
    }

    /**
     * 编译 Windows 资源文件
     *
     * 如果配置了 resource 选项，生成 .rc 文件并使用 rc.exe 编译为 .res
     * .res 文件会在 build 阶段被链接到最终的 exe 中
     */
    protected function compileResourceFile(): void
    {
        if (!$this->isWindows()) {
            return;
        }

        $generator = $this->createResourceGenerator();
        if ($generator === null || !$generator->hasResource()) {
            return;
        }

        // 生成 .rc 文件
        $rcFile = $this->getResourceRcFile();
        $rcContent = $generator->generate();
        // 写入 UTF-8 BOM，确保 rc.exe 正确识别编码，避免中文乱码
        $this->writeFile($rcFile, "\xEF\xBB\xBF" . $rcContent);
        $this->climate->info('Generated resource file: ' . $rcFile);

        // 使用 MSVC 的 rc.exe 编译 .rc -> .res
        $backend = $this->getCompilerBackend();
        if ($backend instanceof \PhpAot\Php\Backend\Msvc) {
            $resFile = $this->getResourceResFile();
            $cmd = $backend->compileResourceFile($rcFile, $resFile);
            $this->climate->comment($cmd);

            exec($cmd . ' 2>&1', $output, $ret);

            if (!empty($output)) {
                foreach ($output as $line) {
                    $this->climate->out($line);
                }
            }

            if ($ret !== 0) {
                $this->error('Resource compilation failed: ' . $rcFile);
            }

            if (!file_exists($resFile)) {
                $this->error('Resource file not generated: ' . $resFile);
            }

            $this->climate->green('Resource compiled: ' . $resFile);
        } else {
            $this->climate->warning('Resource files are only supported with MSVC backend on Windows');
        }
    }

    protected function getCompileCommandOptions(): array
    {
        // 包含路径：系统路径 + 用户自定义路径
        $includePaths = $this->getIncludePaths();
        if (!empty($this->userIncludePaths)) {
            $includePaths = array_merge($includePaths, $this->userIncludePaths);
        }

        return [
            'include_paths' => $includePaths,
            'optimize' => $this->optimizeLevel,
            'debug' => $this->debug,
            'sanitize' => $this->sanitize,
            'cpp_std' => $this->cxxStd,
            'march' => $this->march,
            'target_platform' => $this->targetPlatform,
            'is_zts' => $this->isPhpZts,
            'build_mode' => $this->buildMode,
            'enable_profiler' => $this->enableProfiler,
            'prof_output' => $this->targetName . '.prof',
            'suppressed_warnings' => Constants::MSVC_SUPPRESSED_WARNINGS ?? [],
            'cxxflags' => $this->cxxFlags,
            'user_defines' => $this->userDefines,
            'lto' => $this->enableLto,
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
            'march' => $this->march,
            'target_platform' => $this->targetPlatform,
        ];
    }

    protected function getLinkCommandOptions(): array
    {
        $ldflags = $this->ldflags;

        if ($this->enableProfiler) {
            $ldflags .= ' -lprofiler';
        }

        // 用户通过 --link-lib / -l 指定的链接库
        foreach ($this->linkLibs as $lib) {
            $ldflags .= ' -l' . $lib;
        }

        // 用户通过 --link-path / -L 指定的库搜索路径
        foreach ($this->linkPaths as $path) {
            $ldflags .= ' -L' . escapeshellarg($path);
        }

        $options = [
            'library_paths' => $this->getLibraryPaths(),
            'libraries' => $this->getLibraries(),
            'ldflags' => $ldflags,
            'debug' => $this->debug,
            'no_console' => $this->noConsole,
            'build_mode' => $this->buildMode,
            'sanitize' => $this->sanitize,
            'lto' => $this->enableLto,
            'target_platform' => $this->targetPlatform,
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

    protected function buildLinkCommand(array $objectFiles, string $targetFile): string
    {
        return $this->getCompilerBackend()->buildLinkCommand(
            $objectFiles,
            $targetFile,
            $this->getLinkCommandOptions()
        );
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

        $linkCmd = $this->buildLinkCommand($objectFiles, $targetFile);
        $this->climate->comment($linkCmd);

        // 执行链接并捕获输出
        exec($linkCmd . ' 2>&1', $output, $ret);

        // 显示输出（如果有）
        if (!empty($output)) {
            foreach ($output as $line) {
                $this->climate->out($line);
            }
        }

        // 检查链接是否成功
        if ($ret !== 0) {
            $this->error('link failed: ' . $targetFile);
        }

        // 验证目标文件是否生成
        if (!file_exists($targetFile)) {
            $this->error('target file not generated: ' . $targetFile);
        }

        $this->climate->green('Build successful: ' . $targetFile);

        return $targetFile;
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
            $this->climate->error('--run is only supported in binary mode (-m bin), not extension mode (-m ext)');
            exit(1);
        }

        if (DIRECTORY_SEPARATOR !== '\\' && !str_starts_with($targetFile, '/')) {
            $targetFile = './' . $targetFile;
        }

        $targetArgs = $this->getTargetArgs();
        $command = escapeshellcmd($targetFile);
        if (!empty($targetArgs)) {
            $command .= ' ' . implode(' ', array_map('escapeshellarg', $targetArgs));
        }

        fwrite(STDERR, "Running: {$command}\n");
        passthru($command, $exitCode);
        exit($exitCode);
    }

    public function getTargetArgs(): array
    {
        return $this->climate->arguments->trailingArray() ?? [];
    }

    public function genFunctionDeclaration(string $file): void
    {
        $code = '#include <phpx.h>' . PHP_EOL;

        // 函数的默认值可能会使用字符串字面量，需要提前声明
        if ($this->literalStrings) {
            $literalStringsCount = count($this->literalStrings);
            $code .= 'extern ' . self::TYPE_STR . ' ' . self::LITERAL_STRINGS . '[' . $literalStringsCount . '];' . PHP_EOL;
        }
        $code .= $this->genDefaultArgumentHelpers();

        foreach ($this->functions as $name => $func) {
            $code .= 'extern ' . $func->returnType . ' ' . self::PREFIX . $name . '(';
            $list = [];
            if ($func->method) {
                $list[] = self::TYPE_OBJECT . ' &this_';
            }
            $argInfoList = $func->argInfoList;
            if ($argInfoList) {
                foreach ($argInfoList as $argInfo) {
                    if ($argInfo->variadic) {
                        $arg = self::TYPE_ARRAY . ' ' . $argInfo->name . ' = {}';
                    } else {
                        $arg = $this->genArgumentDeclaration($argInfo);
                        if ($argInfo->default && !$this->isConstructorNativeFunction($func)) {
                            $arg .= ' = ' . $this->genDefaultArgumentExpr($func, $argInfo);
                        }
                    }
                    $list[] = $arg;
                }
            }
            $code .= implode(', ', $list);
            $code .= ');' . PHP_EOL;
        }

        $code .= PHP_EOL;
        foreach ($this->constants as $name => $constant) {
            $code .= 'extern ' . $constant->type . ' ' . $name . ';' . PHP_EOL;
        }
        $this->writeFile($file, $code);
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
            "php_{$this->targetName}_global_var_decl.h",
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
            if ($classDef and $classDef->requireCtor) {
                $className = $classDef->getNamespacedName();
                $code .= "create_object_{$className} = php_get_create_object_fn({$ce});\n";
                $code .= "{$ce}->create_object = [](zend_class_entry *class_type) -> zend_object* {\n";
                $code .= $classDef->ctorInit;
                $code .= "auto obj = create_object_{$className}(class_type);\n";
                foreach ($classDef->properties as $property) {
                    if (!$property->isStatic() && $property->arrayInitPlan && $property->default) {
                        $body = "auto value = {$property->arrayInitPlan->expr};\n";
                        $body .= 'zend_update_property(obj->ce, obj, ' . $this->genZendStrl($property->name) . ", value.ptr());\n";
                        $code .= $this->wrapArrayInitPlan($property->arrayInitPlan, $body);
                    }
                }
                $code .= $classDef->ctorClean;
                $code .= "return obj;\n};\n";
            }
        }
        return $code;
    }

    public function getDefinedConstants(): array
    {
        return $this->internalConstants;
    }

    /**
     * 仅用于 gen_stub 脚本
     * @throws \Exception
     */
    public function getClassConstValue(NodeAbstract $expr, string $_class, string $name, string $currentClass = ''): mixed
    {
        $namespace = $this->namespace;
        if (!$namespace and $currentClass and !str_contains($_class, '\\')) {
            $namespace = $this->getNamespaceOfClass($currentClass);
        }
        $class = $this->getNamespacedClassName($_class, $namespace);
        $nativeConst = $this->findNativeClassConst($expr, $class, $name);
        if ($nativeConst and $expr->hasAttribute('nativeConst')) {
            $constDef = $expr->getAttribute('nativeConst');
            return $this->genCValue($constDef->valueExpr->value);
        }
        if ($this->isInternalClass($class)) {
            $constName = $class . '::' . $name;
            if (defined($constName)) {
                return $this->genCValue(constant($constName));
            }
        }
        // Resolve enum case references. Enum cases are runtime objects; their
        // actual values in class constant arrays are set at runtime by
        // genClassArrayConstants() via php::getEnumCase(). Return the backing
        // value as a placeholder so gen_stub.php's ConstExprEvaluator can complete.
        if ($this->hasClass($class)) {
            $classDef = $this->getClass($class);
            if ($classDef->enum && array_key_exists($name, $classDef->enumCases)) {
                $caseValue = $classDef->enumCases[$name];
                return $this->genCValue($caseValue ?? $name);
            }
        }
        $this->fatalError($expr, "Class constant `{$class}::{$name}` not found");
    }

    public function getConstValue(string $name): mixed
    {
        if ($this->isInternalConstant($name)) {
            $value = $this->internalConstants[$name];
            if (is_int($value)) {
                $expr = strval($value);
                if ($value === PHP_INT_MIN) {
                    $expr = 'LONG_MIN';
                } elseif ($value === PHP_INT_MAX) {
                    $expr = 'LONG_MAX';
                } else {
                    $expr = $expr . 'L';
                }
            } elseif (is_float($value)) {
                return $value;
            } elseif (is_bool($value)) {
                return $value ? 1 : 0;
            } elseif (is_string($value)) {
                return $this->genCharPtr($value);
            } else {
                $this->error('Unsupported constant type: ' . gettype($value));
            }
            return $expr;
        }
        throw new \Exception('Constant ' . $name . ' not found');
    }

    protected function getRegisterClassFunction(string $name): string
    {
        return self::PREFIX . 'register_class_' . $name;
    }

    protected function getRegisterClassFunctionCeList(ClassDef|InterfaceDef $classDef): array
    {
        $list = [];
        $parentCe = $this->getParentClassCe($classDef);
        if ($parentCe !== '') {
            $list = [$parentCe];
        }
        //  interface 没有 implements
        if ($classDef instanceof InterfaceDef) {
            return $list;
        }
        $implements = $this->getImplementCe($classDef);

        return array_merge($list, $implements);
    }

    protected function getClassCe(ClassLikeDef $classDef): string
    {
        return self::PREFIX . 'class_entry_' . $this->escapeCeName($classDef->getNamespacedName());
    }

    protected function getFilesFromDir(string $path): array
    {
        $scanner = new FileScanner($path);

        return $scanner->scan();
    }

    protected function genClassArrayConstants(): string
    {
        $code = '';
        foreach ($this->classes as $classDef) {
            foreach ($classDef->constants as $constant) {
                if ($constant->type === self::TYPE_ARRAY) {
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
        foreach ($this->classes as $className => $classDef) {
            $ownConstNames = [];
            foreach ($classDef->constants as $constant) {
                if ($constant->type === self::TYPE_ARRAY) {
                    $ownConstNames[$constant->name] = true;
                }
            }

            $parentName = $this->escapeClass($classDef->extends);
            while ($parentName && isset($this->classes[$parentName])) {
                $parentDef = $this->classes[$parentName];
                foreach ($parentDef->constants as $constant) {
                    if ($constant->type === self::TYPE_ARRAY && !isset($ownConstNames[$constant->name])) {
                        $ownConstNames[$constant->name] = true;
                        $constName = self::PREFIX . $this->getNativeName($constant->name, $parentDef->namespace, $parentDef->name);
                        $classNameStr = $this->genCharPtr($classDef->getNamespacedName(false), true);
                        $classConstStr = $this->genCharPtr($constant->name);
                        $code .= "php::updateConstant($classNameStr, $classConstStr, {$constName});\n";
                    }
                }
                $parentName = $this->escapeClass($parentDef->extends);
            }
        }

        return $code;
    }

    protected function getAbsolutePath(string $path, string $projectDir): string
    {
        $path = trim($path);
        if ($path === '') {
            $this->error('Source path must not be empty');
        }
        if ($path[0] !== '/') {
            $absPath = $projectDir . '/' . $path;
        } else {
            $absPath = $path;
        }
        return realpath($absPath);
    }

    protected function parseProjectYaml(string $path): array
    {
        $cfg = Yaml::parseFile($path);
        $projectDir = dirname($path);

        if (!empty($cfg['sources'])) {
            $sources = $cfg['sources'];
            if (!is_array($sources)) {
                $this->error('`sources` must be array');
            }
            $list = [];
            foreach ($sources as $src) {
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

        // 读取 ld-flags
        $ldflags = $cfg['ld-flags'] ?? null;
        if (!empty($ldflags)) {
            if (is_array($ldflags)) {
                $this->ldflags = implode(' ', $ldflags);
            } else {
                $this->ldflags = str_replace("\n", ' ', $ldflags);
            }
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
                $this->linkPaths[] = (string)$linkPath;
            }
        }

        // 读取 name
        if (!empty($cfg['name'])) {
            $this->setTargetName($cfg['name']);
        }

        // 读取 cpp-compiler
        $cppCompiler = $cfg['cpp-compiler'] ?? null;
        if (!empty($cppCompiler)) {
            $this->setCppCompiler($cppCompiler);
        }

        // 读取 type/build-mode（支持中横线和下划线）
        $buildMode = $cfg['build-mode'] ?? $cfg['type'] ?? null;
        if (!empty($buildMode)) {
            // 映射常见的类型名称到内部 buildMode
            $modeMap = [
                'extension' => self::BUILD_MODE_EXT,
                'ext' => self::BUILD_MODE_EXT,
                'binary' => self::BUILD_MODE_BIN,
                'bin' => self::BUILD_MODE_BIN,
                'cli' => self::BUILD_MODE_BIN,
            ];
            $mappedMode = $modeMap[strtolower($buildMode)] ?? $buildMode;
            $this->setBuildMode($mappedMode);
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

        // 读取 resource（Windows 资源配置：图标、版本信息等）
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

        return $list;
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
        $traverser->addVisitor(new Visitor());

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
        if (empty($this->interfaces) and empty($this->classes)) {
            return;
        }

        $sorter = new StringSort();

        foreach ($this->interfaces as $interfaceDef) {
            $parent = $interfaceDef->extends;
            $ce = $this->getClassCe($interfaceDef);
            $deps = [];

            if ($parent) {
                // 不存在的接口，说明可能是内置接口
                $tmpCe = $this->getParentClassCe($interfaceDef);
                if (!isset($this->interfaces[$parent])) {
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

        foreach ($this->classes as $classDef) {
            $ce = $this->getClassCe($classDef);
            $deps = [];
            $parent = $classDef->extends;
            if ($parent) {
                // 不存在的父类，说明可能是内置类
                $tmpCe = $this->getParentClassCe($classDef);
                if (!isset($this->classes[$parent])) {
                    $sorter->add($tmpCe);
                }
                $deps[] = $tmpCe;
            }

            $implements = $classDef->implements;
            if ($implements) {
                foreach ($implements as $interface) {
                    $tmpCe = self::PREFIX . 'class_entry_' . $this->escapeCeName($interface);
                    if (!isset($this->interfaces[$interface])) {
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
            $value = $this->parseIdentifier($declare->value);
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
        generateStubFile($file, $this->getIncludeDir() . '/' . $headerFile, true);

        if ($this->useRegisterSymbolsFn) {
            preg_match('/php_(.*)_arginfo.h/', $headerFile, $matches);
            $registerSymbolFn = 'register_' . $matches[1] . '_symbols';
            $registerSymbol = PHP_EOL . 'static void ' . $registerSymbolFn . '(int module_number)' . PHP_EOL;
            if (str_contains(file_get_contents($this->getBuildDir() . '/include/' . $headerFile), $registerSymbol)) {
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
                foreach ($traitStmts as $k1 => $traitStmt) {
                    if ($traitStmt instanceof Node\Stmt\ClassMethod) {
                        $methodName = strtolower($traitStmt->name->toString());
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
                        $fullMethodName = $this->getFullMethodName($traitFullName, $methodName);
                        if (isset($classDef->traitAliases[$fullMethodName])) {
                            $alias = $classDef->traitAliases[$fullMethodName];
                            $methodName = $alias['newName'];
                            $traitStmt->name = new Node\Identifier($methodName);
                            if ($alias['newModifier']) {
                                $traitStmt->flags = $alias['newModifier'];
                            }
                        }
                        if (isset($classDef->traitIgnored[$fullMethodName])) {
                            unset($traitStmts[$k1]);
                            continue;
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
                                unset($traitStmts[$k1][$k2]);
                            }
                            if (isset($traitConstants[$constName])) {
                                $this->fatalError($classStmt, "Trait `{$traitFullName}` constant `{$constName}` already exists");
                            }
                            $traitConstants[$constName] = $const;
                        }
                    }
                    if ($traitStmt instanceof Node\Stmt\Property) {
                        foreach ($traitStmt->props as $k2 => $prop) {
                            $propName = strtolower($prop->name->toString());
                            if (isset($properties[$propName])) {
                                unset($traitStmts[$k1][$k2]);
                            }
                            if (isset($traitProperties[$propName])) {
                                $this->fatalError($classStmt, "Trait `{$traitFullName}` property `{$propName}` already exists");
                            }
                            $traitProperties[$propName] = $prop;
                        }
                    }
                }

                $stmt->stmts = array_merge($stmt->stmts, $traitStmts);
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

    protected function parseClass(Node\Stmt\Class_|Node\Stmt\Trait_|Node\Stmt\Enum_ $class): string
    {
        $this->class = $this->parseIdentifier($class->name);
        $fullName = $this->getFullClassName();
        if (!$this->hasClass($fullName)) {
            $this->fatalError($class, "class {$fullName} not found");
        }
        $this->classDef = $this->getClass($fullName);

        // 如果不是继承自内置类，需要检查父类是否存在，在预处理阶段只需检查了是否继承内置类
        // 目前不允许继承自动态加载的自定义类
        if ($this->classDef->extends and !$this->classDef->inheritedFromInternalClass) {
            $parentClass = $this->getParentClass($class->extends);
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

    protected function genWrapperFunctionArgs(string $fn, FunctionDef $functionDef): string
    {
        $cppCode = '';
        $callParams = '';
        foreach ($functionDef->argInfoList as $k => $argInfo) {
            $var = 'arg_' . $argInfo->name;
            if ($argInfo->variadic) {
                $cppCode .= $this->getIndent() . self::TYPE_ARRAY . ' ' . $var . ';' . PHP_EOL;
                $cppCode .= $this->getIndent() . 'for (uint32_t i = ' . $k . '; i < php::getCallArgNum(); i++) {' . PHP_EOL;
                $this->indentLevel++;
                $cppCode .= $this->getIndent() . $var . '.append(php::getCallArg(i));' . PHP_EOL;
                $this->indentLevel--;
                $cppCode .= '}' . PHP_EOL;
            } else {
                if ($argInfo->default) {
                    $defaultExpr = $this->genDefaultArgumentExpr($functionDef, $argInfo);
                    if ($argInfo->byRef) {
                        $argExpr = 'php::getCallArgByRef(' . $k . ', ' . $defaultExpr . ')';
                    } else {
                        $argExpr = 'php::getCallArg(' . $k . ', ' . $defaultExpr . ')';
                    }
                } else {
                    if ($argInfo->byRef) {
                        $argExpr = 'php::getCallArgByRef(' . $k . ')';
                    } elseif ($argInfo->nullable) {
                        $argExpr = 'php::getCallArg(' . $k . ', php::null)';
                    } else {
                        $argExpr = 'php::getCallArg(' . $k . ')';
                    }
                }
                $cppType = $this->getDefaultArgumentType($argInfo);
                $expr = $this->convertExprFromType($argInfo->type, $argExpr);
                $cppCode .= $this->getIndent() . $cppType . ' ' . $var . ' = ' . $expr . ';' . PHP_EOL;
            }
            $callParams .= 'arg_' . $argInfo->name . ',';
        }

        if ($functionDef->method) {
            $callParams = $functionDef->argInfoList ? 'this_, ' . rtrim($callParams, ',') : 'this_';
        } else {
            $callParams = $functionDef->argInfoList ? rtrim($callParams, ',') : '';
        }

        if ($functionDef->returnType !== self::TYPE_VOID) {
            $cppCode .= $this->getIndent() . 'auto retval = ' . $fn . '(' . $callParams . ');' . PHP_EOL;
            $cppCode .= $this->getIndent() . 'php::move(retval, return_value);' . PHP_EOL;
            $cppCode .= $this->getIndent() . 'php::deref(return_value);' . PHP_EOL;
        } else {
            $cppCode .= $this->getIndent() . $fn . '(' . $callParams . ');' . PHP_EOL;
        }
        $cppCode .= '}' . PHP_EOL . PHP_EOL;

        return $cppCode;
    }

    protected function genMethodWrapper(ClassDef $classDef, MethodDef $methodDef): string
    {
        $name = $classDef->getNamespacedName();
        $cppCode = 'ZEND_METHOD(' . $name . ', ' . $methodDef->name . '){' . PHP_EOL;
        $cppCode .= $this->getIndent() . self::TYPE_OBJECT . ' this_(&execute_data->This);' . PHP_EOL;
        $fn = self::PREFIX . $this->getNativeMethodName($classDef, $methodDef);
        $cppCode .= $this->genWrapperFunctionArgs($fn, $methodDef->functionDef);

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
            $arrayPropCount = 0;
            foreach ($classDef->properties as $property) {
                if ($property->type === self::TYPE_ARRAY && $property->arrayInitPlan && $property->default && !$property->isStatic()) {
                    $arrayPropCount++;
                }
            }
            if ($arrayPropCount > 0) {
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
            $this->addArgument('this_', self::TYPE_OBJECT);
        }
        foreach ($this->functionDef->argInfoList as $argInfo) {
            $this->addArgument($argInfo->name, $argInfo->variadic ? self::TYPE_ARRAY : $argInfo->type);
            if (!$argInfo->variadic and $argInfo->class) {
                $this->addObject($argInfo->name, $argInfo->class);
            }
        }

        // Build SSA/e-SSA analysis for this function
        if ($v->stmts) {
            $oriLocalVars = $this->context->localVars;
            $oriTmpVarIndex = $this->context->tmpVarIndex;
            /** SSA/e-SSA analysis for the current function. Built once per function, discarded with the context. */
            $ssaBuilder = new SsaBuilder($v->stmts, $this->functionDef->argInfoList);
            $ssaBuilder->build();
            $this->context->ssaBuilder = $ssaBuilder;
            // Narrow local variable types based on SSA analysis
            $this->optimizeVarTypes($ssaBuilder);
            // Narrow range-proven loop counters independent of native_types
            $this->optimizeLoopVars($ssaBuilder);
            // Analyze object stability for property reference hoisting
            $this->optimizeObjectProps($ssaBuilder);
            $this->context->resetAnalysisTemporaries($oriLocalVars, $oriTmpVarIndex);
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
        // Runtime union/nullable parameter type checks
        foreach ($this->functionDef->argInfoList as $i => $argInfo) {
            if (!empty($argInfo->typeCheck)) {
                $code .= $this->genUnionParamCheck($argInfo, $i);
            }
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
                if (Reflection::getClassMethodModifiers($extends, $name) & \ReflectionMethod::IS_PRIVATE) {
                    goto _error;
                }
                break;
            }
            $classDef = $this->getClass($extends);
            if ($classDef->hasMethod($name)) {
                $methodDef = $classDef->getMethod($name);
                if ($methodDef->flags & Modifiers::PRIVATE) {
                    _error:
                    $this->fatalError($v,
                        'Cannot override private method `' .
                        $extends . '::' . $name . '()`');
                }
                $parentFuncDef = $methodDef->functionDef;
                $this->validateMethodOverrideSignature($v, $name, $childFuncDef, $methodDef, $extends);
                break;
            }
        }
    }

    private function validateMethodOverrideSignature(
        Node\Stmt\ClassMethod $v,
        string $methodName,
        FunctionDef $childFuncDef,
        MethodDef $parentMethodDef,
        string $parentClass
    ): void {
        $className = $this->getFullClassName();
        $error = function (string $detail) use ($v, $className, $methodName, $parentClass) {
            $this->fatalError($v,
                "Declaration of `{$className}::{$methodName}()` must be compatible " .
                "with `{$parentClass}::{$methodName}()`");
        };

        // Compare visibility (public/protected/private)
        if (($this->methodDef->flags & Modifiers::VISIBILITY_MASK) !== ($parentMethodDef->flags & Modifiers::VISIBILITY_MASK)) {
            $error('visibility mismatch');
        }

        $parentFuncDef = $parentMethodDef->functionDef;
        if (!$parentFuncDef) {
            return;
        }

        // Compare parameter count
        if (count($childFuncDef->argInfoList) !== count($parentFuncDef->argInfoList)) {
            $error('parameter count mismatch');
        }

        // Compare return type
        if ($childFuncDef->returnType !== $parentFuncDef->returnType ||
            $childFuncDef->returnClass !== $parentFuncDef->returnClass) {
            $error('return type mismatch');
        }

        // Compare each parameter
        foreach ($parentFuncDef->argInfoList as $i => $parentArg) {
            $childArg = $childFuncDef->argInfoList[$i];
            if ($childArg->type !== $parentArg->type || $childArg->class !== $parentArg->class) {
                $error("parameter #{$i} type mismatch");
            }
            if ($childArg->byRef !== $parentArg->byRef) {
                $error("parameter #{$i} by-reference mismatch");
            }
            if ($childArg->variadic !== $parentArg->variadic) {
                $error("parameter #{$i} variadic mismatch");
            }
        }
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
                    if ($childProp->type !== $parentProp->type || $childProp->class !== $parentProp->class) {
                        $this->fatalError($classStmt,
                            "Declaration of `{$className}::\${$name}` must be compatible " .
                            "with `{$parentClass}::\${$name}`");
                    }
                    if (($childProp->flags & Modifiers::VISIBILITY_MASK) !== ($parentProp->flags & Modifiers::VISIBILITY_MASK)) {
                        $this->fatalError($classStmt,
                            "Declaration of `{$className}::\${$name}` must be compatible " .
                            "with `{$parentClass}::\${$name}`");
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
                    if ($childConst->type !== $parentConst->type || $childConst->class !== $parentConst->class) {
                        $this->fatalError($classStmt,
                            "Declaration of `{$className}::{$name}` must be compatible " .
                            "with `{$parentClass}::{$name}`");
                    }
                    if (($childConst->flags & Modifiers::VISIBILITY_MASK) !== ($parentConst->flags & Modifiers::VISIBILITY_MASK)) {
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
                    continue;
                }
                $classDef->constants[$const->name] = $const;
            }
            foreach ($traitDef->properties as $prop) {
                if ($classDef->hasProperty($prop->name)) {
                    continue;
                }
                $classDef->properties[$prop->name] = $prop;
            }
            foreach ($traitDef->methods as $methodDef) {
                $classMethodName = $traitMethodName = $methodDef->name;
                $fullMethodName = $this->getFullMethodName($traitFullName, $traitMethodName);
                // Trait 设置了别名
                if (isset($classDef->traitAliases[$fullMethodName])) {
                    $alias = $classDef->traitAliases[$fullMethodName];
                    $methodDef = clone $methodDef;
                    $classMethodName = $methodDef->name = $alias['newName'];
                    if ($alias['newModifier']) {
                        $methodDef->flags = $this->parseModifiers($alias['newModifier']);
                    }
                }
                // 设置了 insteadof 选项，此 Trait 的方法将不会被使用
                if (isset($classDef->traitIgnored[$fullMethodName])) {
                    continue;
                }
                // 类中已经有同名方法，则不使用 Trait 中的方法
                if ($classDef->hasMethod($classMethodName)) {
                    continue;
                }

                $classDef->addMethod($methodDef);
                $traitMethodNativeName = $this->getNativeName($traitMethodName, $traitDef->namespace, $traitDef->name);
                $classMethodNativeName = $this->getNativeName($classMethodName, $classDef->namespace, $classDef->name);
                $argList = ['this_'];
                foreach ($methodDef->functionDef->argInfoList as $argInfo) {
                    $argList[] = $argInfo->name;
                }
                $argv = implode(', ', $argList);

                $code = $methodDef->getReturnType() . ' ' . self::PREFIX . $classMethodNativeName . '(';
                if ($this->class) {
                    $code .= self::TYPE_OBJECT . ' &this_';
                    if ($methodDef->functionDef->params) {
                        $code .= ', ';
                    }
                }

                $this->addFunction($classMethodNativeName, $methodDef->functionDef);

                $code .= $methodDef->functionDef->params . ')';
                $code .= '{' . PHP_EOL;
                $this->indentLevel++;
                $methodCall = self::PREFIX . $traitMethodNativeName . '(' . $argv . ')';
                if ($methodDef->getReturnType() !== self::TYPE_VOID) {
                    $methodCall = 'return ' . $methodCall;
                }
                $code .= $this->getIndent() . $methodCall . ';' . PHP_EOL;
                $this->indentLevel--;
                $code .= $this->getIndent() . '}' . PHP_EOL;
                $methodCodes[$classMethodName] = $code;
            }
        }
    }

    protected function parseForeachObject(Foreach_ $node): string
    {
        $obj = $this->parseIdentifier($node->expr);
        $tmpVar = $this->genTmpVarName();
        $this->addLocalVar($tmpVar, self::TYPE_OBJECT);

        $tmpArrayVar = $this->genTmpVarName();
        $this->addLocalVar($tmpArrayVar, self::TYPE_ARRAY);

        $IteratorAggregateCe = $this->getClassEntryPtr('IteratorAggregate');
        $IteratorCe = $this->getClassEntryPtr('Iterator');
        $getIteratorStr = $this->getLiteralString('getIterator');
        $validStr = $this->getLiteralString('valid');
        $currentStr = $this->getLiteralString('current');
        $keyStr = $this->getLiteralString('key');
        $nextStr = $this->getLiteralString('next');
        $rewindStr = $this->getLiteralString('rewind');

        $code = 'if (' . $obj . '.instanceOf(' . $IteratorAggregateCe . ')) {' . PHP_EOL;
        $code .= $this->getIndent() . $tmpVar . ' = ' . $obj . '.call(' . $getIteratorStr . ');' . PHP_EOL . '}' . PHP_EOL;
        $code .= 'else if (' . $obj . '.instanceOf(' . $IteratorCe . ')) {' . PHP_EOL;
        $code .= $this->getIndent() . $tmpVar . ' = ' . $obj . ';' . PHP_EOL . '}' . PHP_EOL;

        $code .= 'if (' . $tmpVar . ') {' . PHP_EOL;

        $this->indentLevel++;
        $code .= $this->getIndent() . $tmpVar . '.call(' . $rewindStr . ');' . PHP_EOL;
        $code .= $this->getIndent() . 'for (;' . $tmpVar . '.call(' . $validStr . ');  ' . $tmpVar . '.call(' . $nextStr . ')) {' . PHP_EOL;
        $this->indentLevel++;

        if ($node->valueVar instanceof List_) {
            $listTmpVar = $this->genTmpVarName();
            $this->addLocalVar($listTmpVar, self::TYPE_VAR);
            $code .= $this->getIndent() . ' ' . $listTmpVar . ' = ' . $tmpVar . '.call(' . $currentStr . ');' . PHP_EOL;
            if ($node->keyVar) {
                $keyVar = $this->parseIdentifier($node->keyVar);
                $this->checkVar($node, $keyVar);
                $code .= $this->getIndent() . ' ' . $keyVar . ' = ' . $tmpVar . '.call(' . $keyStr . ');' . PHP_EOL;
            }
            $code .= $this->parseForeachItemAsList($listTmpVar, $node->valueVar->items);
        } else {
            $valueVar = $this->parseIdentifier($node->valueVar);
            $this->checkVar($node, $valueVar);

            $code .= $this->getIndent() . ' ' . $valueVar . ' = ' . $tmpVar . '.call(' . $currentStr . ');' . PHP_EOL;
            if ($node->keyVar) {
                $keyVar = $this->parseIdentifier($node->keyVar);
                $this->checkVar($node, $keyVar);
                $code .= $this->getIndent() . ' ' . $keyVar . ' = ' . $tmpVar . '.call(' . $keyStr . ');' . PHP_EOL;
            }
        }
        $code .= $this->parseStmts($node->stmts);
        $code .= $this->genLoopEndFlagCheck();
        $code .= '}' . PHP_EOL;
        $this->indentLevel--;
        $code .= $this->getIndent() . '} else {' . PHP_EOL;
        $code .= $this->getIndent() . $tmpArrayVar . ' = php::call(' . $this->getFuncPtr('get_object_vars') . ', {' . $obj . '});' . PHP_EOL;
        $code .= $this->parseForeachArray($node, $tmpArrayVar);
        $this->indentLevel--;
        $code .= '}' . PHP_EOL;

        return $code;
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
        $cppCode .= $this->genWrapperFunctionArgs($fn, $functionDef);

        return $cppCode;
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
