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
use PhpAot\Php\Entity\ClassLikeDef;
use PhpAot\Php\Entity\ConstantDef;
use PhpAot\Php\Entity\FunctionDef;
use PhpAot\Php\Entity\InterfaceDef;
use PhpAot\Php\Entity\MethodDef;
use PhpAot\Php\Entity\PropertyDef;
use PhpAot\Php\Exception\Redo;
use PhpAot\Php\Exception\SyntaxError;
use PhpAot\Php\Exception\Unsupported;
use PhpParser\Modifiers;
use PhpParser\Node;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\NodeAbstract;
use PhpParser\NodeTraverser;
use Symfony\Component\Yaml\Yaml;

class Translator extends Preprocessor
{
    public const string VERSION = '0.1.0';
    public const string APP_NAME = 'Swoole-Compiler (AOT)';
    protected string $targetName = 'app';
    protected array $sourceDirs = [];
    protected bool $verbose = false;
    protected array $phpSrcFiles = [];
    protected array $ignorePaths = [];
    protected array $ignoreExtensions = [];
    protected array $argInfoHeaderFiles = [];
    protected array $registerSymbols = [];

    // 类静态属性初始值
    protected array $defaultStaticPropertyList = [];

    // 类属性初始值
    protected array $defaultPropertyList = [];
    protected bool $useRegisterSymbolsFn = false;

    protected const string MODULE_NAME_PREFIX = 'app_';

    public function __construct(string $rootPath)
    {
        parent::__construct($rootPath);
        $this->climate->arguments->add(Constants::COMPILER_OPTIONS);
        $this->preprocessArgvAdvanced();
        $this->climate->arguments->parse();

        // 只读取命令行参数，不立即应用（等待 YAML 解析后再应用）
        // 这样可以确保优先级：命令行 > YAML > 默认值
        $this->internalFunctions = array_flip(get_defined_functions()['internal']);
        unset($this->internalFunctions['main']);
        $this->internalConstants = get_defined_constants();
        if ($this->climate->arguments->defined('help')) {
            $this->showUsage();
            exit(0);
        }
        if ($this->climate->arguments->defined('version')) {
            $this->showVersion();
            exit(0);
        }
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

        $climate->bold('OPTIONS:');
        $climate->tab()->out('-O <level>           Optimization level (0-3, default: 0)');
        $climate->tab()->out('-p, --profile        Enable performance profiling');
        $climate->tab()->out('-d, --debug-info     Enable debug info (auto-disable optimizations, add -g/-Zi)');
        $climate->tab()->out('--cxx-std <version>  C++ standard version (c++17, c++20, etc.)');
        $climate->tab()->out('-o, --output <file>  Output binary name (default: input basename)');
        $climate->tab()->out('-v, --version        Show version');
        $climate->tab()->out('-h, --help           Show this help message');
        $climate->tab()->out('-f, --force          Force compile even if cache exists');
        $climate->tab()->out('-m, --mode <mode>    Compilation mode, -m bin(binary) or -m ext(extension), default: bin');
        $climate->tab()->out('-j, --job <num>      Number of parallel compilation jobs (default: 4)');
        $climate->tab()->out('--no-literal-strings Disable literal strings optimization');
        $climate->tab()->out('--no-console         Hide console window (Windows only, GUI application)');
        $climate->tab()->out('--sanitize <type>    Enable sanitizers (address, undefined, etc.)');
        $climate->br();

        $climate->bold('EXAMPLES:');
        $climate->tab()->out($cmd . ' hello.php');
        $climate->tab()->out($cmd . ' bench.php -O2');
        $climate->tab()->out($cmd . ' project/config.yml -O2');
        $climate->tab()->out($cmd . ' my-ext/ -O2 -o myapp -m ext');
        $climate->tab()->out($cmd . ' app.php -O3 -o myapp -v');
        $climate->tab()->out($cmd . ' gui-app.php --no-console  (Windows GUI app, no console)');
        $climate->tab()->out($cmd . ' app.php --sanitize=address  (Enable AddressSanitizer)');
        $climate->tab()->out($cmd . ' app.php --cxx-std=c++17  (Use C++17 standard)');
        $climate->tab()->out($cmd . ' app.php --no-literal-strings  (Disable string optimization)');
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

        // 调试信息
        if ($this->climate->arguments->defined('debug-info')) {
            $this->debugInfo = true;
        }

        // 禁用字面量字符串优化
        if ($this->climate->arguments->defined('no-literal-strings')) {
            $this->noLiteralStrings = true;
        }

        // 启用性能分析
        if ($this->climate->arguments->defined('profile')) {
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
    }

    private function showVersion(): void
    {
        $this->climate->bold()->out(self::APP_NAME . ' v' . self::VERSION);
    }

    public function convertFile(string $file): string
    {
        $file = realpath($file);
        if ($this->hasCppFileCache($file)) {
            $this->climate->darkGray('skip: ' . $file . ', cache exists');

            return $this->getCppFile($file);
        }
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

    public function setBuildMode(string $mode): void
    {
        $this->buildMode = $mode;
    }

    public function setTargetName(string $name): void
    {
        if ($this->climate->arguments->defined('output')) {
            $name = $this->climate->arguments->get('output');
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
        // 根据平台检查库文件（仅在构建二进制文件时需要）
        if ($this->buildMode === 'bin') {
            if ($this->isWindows()) {
                // Windows 平台检查 dll 和 lib 文件
                // 按照新的路径规则检查：SDK/lib, lib, 根目录
                $phpDirs = [
                    $this->getPhpDir() . '\SDK\lib',
                    $this->getPhpDir() . '\lib',
                ];

                $foundLib = false;
                foreach ($phpDirs as $phpDir) {
                    if (is_dir($phpDir)) {
                        if (is_file($phpDir . '\php8.lib') || is_file($phpDir . '\php8ts.lib')) {
                            $foundLib = true;
                            break;
                        }
                    }
                }

                // 如果没找到 lib，检查根目录的 DLL
                if (!$foundLib) {
                    $phpDll = $this->getPhpDir() . '\php8.dll';
                    $phpTsDll = $this->getPhpDir() . '\php8ts.dll';
                    if (!is_file($phpDll) && !is_file($phpTsDll)) {
                        $this->climate->warning('The `php8.lib` or `php8.dll` is not found in PHP directory, please check your PHP installation');
                    }
                }

                $phpxLib = $this->getPhpxDir() . '\lib\phpx.lib';
                $phpxDll = $this->getPhpxDir() . '\lib\phpx.dll';
                if (!is_file($phpxLib) && !is_file($phpxDll)) {
                    $this->climate->warning('The `phpx.lib` or `phpx.dll` is not found in PHX directory');
                    $this->climate->info('Note: If you are building an extension (-m ext), this is OK. For binary mode, please run `make` to build it');
                }
            } else {
                // Unix/Linux/macOS 平台检查 .so 或 .dylib 文件
                $ext = $this->isMacos() ? 'dylib' : 'so';
                $phpLib = $this->getPhpDir() . '/lib/libphp.' . $ext;
                $phpxLib = $this->getPhpxDir() . '/lib/libphpx.' . $ext;

                if (!is_file($phpLib)) {
                    $this->climate->warning("The `libphp.{$ext}` is not found");
                    $this->climate->info('Note: If you are building an extension (-m ext), this is OK. For binary mode, please run `make` to build it');
                }
                if (!is_file($phpxLib)) {
                    $this->climate->warning("The `libphpx.{$ext}` is not found");
                    $this->climate->info('Note: If you are building an extension (-m ext), this is OK. For binary mode, please run `make` to build it');
                }
            }
        }

        $clangFormatVersion = shell_exec('clang-format --version');
        if (empty($clangFormatVersion)) {
            $this->formatCode = false;
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
                } elseif (FileScanner::isCppFile($file)) {
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
            $lines[] = 'extern ' . self::TYPE_VAR . ' ' . $this->escapeGlobalVar($name) . ';';
        }

        $literalStringsCount = count($this->literalStrings);
        $lines[] = 'extern ' . self::TYPE_STR . ' ' . self::LITERAL_STRINGS . '[' . $literalStringsCount . '];' . PHP_EOL;

        // 确保数组大小至少为 1，避免 C/C++ 编译错误
        $classCount = max(1, count($this->classMap));
        $lines[] = 'extern zend_class_entry *' . self::PREFIX . self::CLASS_MAP . '[' . $classCount . '];' . PHP_EOL;

        $funcCount = max(1, count($this->funcMap));
        $lines[] = 'extern zend_function *' . self::PREFIX . self::FUNC_MAP . '[' . $funcCount . '];' . PHP_EOL;

        $propCount = max(1, count($this->propMap));
        $lines[] = 'extern uint32_t ' . self::PREFIX . self::PROP_MAP . '[' . $propCount . '];' . PHP_EOL;

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
        if ($this->buildMode == 'bin') {
            if (!$this->hasFunction('main')) {
                $this->climate->red('When the build mode is a binary executable file, the `main()` function must be defined');
                exit(1);
            }
        }
        $file = $this->getBuildDir() . '/extension-' . $this->getModuleName() . '.cc';
        $this->localHeaders = $this->argInfoHeaderFiles;
        $this->genClassCeList();
        $this->indentLevel++;

        $code = $this->genIncludeHeaderFiles();

        if ($this->buildMode === 'bin') {
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
            $code .= self::TYPE_VAR . ' ' . $this->escapeGlobalVar($name) . ';' . PHP_EOL;
        }

        $code .= "// class register functions \n";
        foreach ($this->classCeList as $ce) {
            $code .= 'zend_class_entry *' . $ce . ';' . PHP_EOL;
        }

        $code .= "// class entry \n";
        // 确保数组大小至少为 1，避免 C/C++ 编译错误
        $code .= 'zend_class_entry *' . self::PREFIX . self::CLASS_MAP . '[' . max(1, count($this->classMap)) . '];' . PHP_EOL;

        $code .= "// func \n";
        $code .= 'zend_function *' . self::PREFIX . self::FUNC_MAP . '[' . max(1, count($this->funcMap)) . '];' . PHP_EOL;

        $code .= "// property \n";
        $code .= 'uint32_t ' . self::PREFIX . self::PROP_MAP . '[' . max(1, count($this->propMap)) . '];' . PHP_EOL;

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
        $code .= self::TYPE_STR . ' ' . self::LITERAL_STRINGS . '[] = {' . PHP_EOL;
        foreach ($this->literalStrings as $str => $index) {
            $code .= self::TYPE_STR . '{ZEND_STRL("' . $this->escapeString($str) . '"), true}, // [' . $index . ']' . PHP_EOL;
        }
        $code .= '};' . PHP_EOL . PHP_EOL;

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
        if ($this->buildMode === 'bin') {
            $code .= $this->getIndent() . "PHP_FE(cli_set_process_title,        arginfo_cli_set_process_title)\n";
            $code .= $this->getIndent() . "PHP_FE(cli_get_process_title,        arginfo_cli_get_process_title)\n";
        }

        foreach ($this->functions as $functionDef) {
            if ($this->buildMode === 'ext' and $functionDef->name === 'main') {
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

        // php_app_init begin
        $code .= 'void php_app_init() {' . PHP_EOL;
        $code .= '// register constants' . PHP_EOL;
        foreach ($this->constants as $name => $const) {
            $code .= "{$name} = {$const->value};\n";
            $code .= 'php::define(' . $this->genCharPtr($const->name, true) . ', ' . $name . ');' . PHP_EOL;
        }
        $code .= '// global vars ' . PHP_EOL;
        foreach ($this->globalVars as $name => $type) {
            $code .= 'php::initGlobal(' . $this->genCharPtr($name) . ', ' . $this->escapeGlobalVar($name) . ');' . PHP_EOL;
        }

        $code .= '// static property ' . PHP_EOL;
        foreach ($this->defaultStaticPropertyList as $prop) {
            $code .= 'php::setStaticProperty(' . $this->genCharPtr($prop->class, true) . ', ' . $this->genCharPtr($prop->name) . ', ' . $prop->default . ');' . PHP_EOL;
        }

        $code .= '// class array constants' . PHP_EOL;
        $code .= $this->genClassArrayConstants();
        $code .= '}' . PHP_EOL . PHP_EOL;
        // php_app_init end

        // php_app_clean begin
        $code .= 'void php_app_clean() {' . PHP_EOL;
        foreach ($this->globalVars as $name => $type) {
            $code .= $this->escapeGlobalVar($name) . '.unset();' . PHP_EOL;
            $code .= 'php::unsetGlobal("' . $name . '");' . PHP_EOL;
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
                }
            }
        }
        $code .= '}' . PHP_EOL . PHP_EOL;
        // php_app_clean end

        $moduleName = $this->getModuleName();
        // rinit begin
        $code .= 'PHP_RINIT_FUNCTION(' . $moduleName . ') {' . PHP_EOL;
        $code .= 'php::request_init();' . PHP_EOL;
        $code .= 'php_app_init();' . PHP_EOL;

        if ($this->buildMode === 'bin') {
            if (count($this->functions['main']->argInfoList) == 2) {
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

        if ($this->buildMode === 'ext') {
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

    public function hasObjectFileCache(string $cppFile): bool
    {
        if (!$this->enableCache or $this->climate->arguments->defined('force')) {
            return false;
        }
        $objectFile = $this->getObjectFile($cppFile);
        if (file_exists($objectFile) and filemtime($objectFile) > filemtime($cppFile)) {
            return true;
        }

        return false;
    }

    public function compileFile(string $cppFile, string $objectFile, bool $parallel = false): void
    {
        if ($this->hasObjectFileCache($cppFile)) {
            if (!$parallel) {
                $this->climate->darkGray('skip: ' . $cppFile . ', cache exists');
            }

            return;
        }

        // 根据平台和编译器类型构建编译命令
        if ($this->isWindows()) {
            // C 文件和 C++ 文件使用不同的选项
            $isCppFile = (pathinfo($cppFile, PATHINFO_EXTENSION) === 'cc'
                         || pathinfo($cppFile, PATHINFO_EXTENSION) === 'cpp'
                         || pathinfo($cppFile, PATHINFO_EXTENSION) === 'cxx');

            // Windows 下根据编译器类型选择参数格式
            if ($this->cppCompiler === 'clang++' || $this->cppCompiler === 'clang') {
                // Clang on Windows 使用 GCC 风格的参数
                if (!$isCppFile) {
                    // C 文件：在源文件前添加 -x c 标志
                    $cmd = $this->cppCompiler . ' -x c -c ' . $cppFile . ' -o ' . $objectFile;
                } else {
                    $cmd = $this->cppCompiler . ' -c ' . $cppFile . ' -o ' . $objectFile;
                }
            } else {
                // MSVC 使用 /c 和 /Fo 参数
                $cmd = $this->cppCompiler . ' /c ' . $cppFile . ' /Fo' . $objectFile;
            }

            // 添加编译选项（C 文件不需要 /EHsc 和 /std:c++17）
            if ($isCppFile) {
                $this->addCompilationOption($cmd, false);
            } else {
                // C 文件：只添加基本选项，不添加 C++ 特定选项
                if ($this->cppCompiler === 'clang++' || $this->cppCompiler === 'clang') {
                    // Clang C 文件选项
                    $cmd .= ' ' . $this->parseWindowsIncludes();
                    $cmd .= ' -DZEND_WIN32 -DPHP_WIN32 -DZEND_DEBUG=0';
                    if ($this->isPhpZts) {
                        $cmd .= ' -DZTS';
                    }
                    $cmd .= ' -O0 -Wall';
                } else {
                    // MSVC C 文件选项
                    $cmd .= ' ' . $this->parseWindowsIncludes();
                    $cmd .= ' /DZEND_WIN32 /DPHP_WIN32 /DZEND_DEBUG=0';
                    if ($this->isPhpZts) {
                        $cmd .= ' /DZTS';
                    }
                    $cmd .= ' /Od /W3 /wd4244 /wd4146 /nologo';
                }
            }
        } else {
            // Unix/Linux/macOS GCC 编译命令
            $cmd = $this->cppCompiler . ' -c ' . $cppFile . ' -o ' . $objectFile;
            $this->addCompilationOption($cmd, false);
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

        // 生成所有函数声明、全局变量声明的头文件
        $this->genFunctionDeclaration($this->getIncludeDir() . '/php_func_decl.h');
        $this->genExternGlobalVars($this->getIncludeDir() . '/php_global_var_decl.h');

        // 生成扩展模块的源文件
        $sourceFiles[] = $this->genExtension();

        // embed 需要 main 函数，以及 cli 的内置函数定义
        if ($this->getBuildMode() == 'bin') {
            $sourceFiles[] = $this->getPhpxDir() . '/src/misc/main.cc';
            $sourceFiles[] = $this->getPhpxDir() . '/src/misc/php_cli_process_title.c';
            $sourceFiles[] = $this->getPhpxDir() . '/src/misc/ps_title.c';
        }

        // Windows 不支持 pcntl_fork，使用串行编译或 proc_open
        if ($this->isWindows() or $job <= 1) {
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
                        $this->climate->red("Compilation failed: {$failedFile}");
                        $failedFiles[] = $failedFile;
                    } else {
                        if ($processInfo) {
                            $objectFiles[] = $processInfo['object'];
                        }
                    }
                    $compiledCount++;
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

    public function build(array $objectFiles): void
    {
        $targetFile = $this->targetName;

        // 根据平台设置目标文件扩展名
        if ($this->isWindows()) {
            if ($this->buildMode == 'ext') {
                if (!str_ends_with($targetFile, '.dll')) {
                    $targetFile .= '.dll';
                }
            } else {
                if (!str_ends_with($targetFile, '.exe')) {
                    $targetFile .= '.exe';
                }
            }
        } else {
            if ($this->buildMode == 'ext' and !str_ends_with($targetFile, '.so')) {
                $targetFile .= '.so';
            }
        }

        // 根据平台构建链接命令
        if ($this->isWindows()) {
            // Windows 使用 link.exe 或 lld-link 进行链接
            $objectList = implode(' ', $objectFiles);

            // 使用检测到的链接器（lld-link 或 link）
            $linkerCmd = $this->linker;
            $linkCmd = "{$linkerCmd} /nologo {$objectList} /OUT:{$targetFile}";
        } else {
            // Unix/Linux/macOS GCC 链接命令
            $objectList = implode(' ', $objectFiles);
            $linkCmd = $this->cppCompiler . ' ' . $objectList . ' -o ' . $targetFile;
        }

        $this->addCompilationOption($linkCmd, true);
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
    }

    public function genFunctionDeclaration(string $file): void
    {
        $code = '#include <phpx.h>' . PHP_EOL;

        // 函数的默认值可能会使用字符串字面量，需要提前声明
        $literalStringsCount = count($this->literalStrings);
        $code .= 'extern ' . self::TYPE_STR . ' ' . self::LITERAL_STRINGS . '[' . $literalStringsCount . '];' . PHP_EOL;

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
                        if ($argInfo->default) {
                            $arg .= ' = ' . $argInfo->default;
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
        $headers = array_merge($this->globalHeaders, $this->localHeaders);
        $lines = [];
        foreach ($headers as $header) {
            $lines[] = '#include <' . $header . '>';
        }

        return implode(PHP_EOL, $lines) . PHP_EOL . PHP_EOL;
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
                    $fullPropName = $classDef->getNamespacedName(false) . '::' . $property->name;
                    if (isset($this->defaultPropertyList[$fullPropName])) {
                        $code .= "do {\n";
                        $code .= "auto value = {$this->defaultPropertyList[$fullPropName]};\n";
                        $code .= 'zend_update_property(obj->ce, obj, ' . $this->genZendStrl($property->name) . ", value.ptr());\n";
                        $code .= "} while(0);\n";
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
    public function getClassConstValue(NodeAbstract $expr, string $class, string $name): mixed
    {
        $class = $this->getNamespacedClassName($class);
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
                    $code .= "} while(0);\n";
                }
            }
        }
        return $code;
    }

    protected function getAbsolutePath(string $path, string $projectDir): string
    {
        $path = trim($path);
        if ($path[0] != '/') {
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

        // 读取 cxxflags（支持中横线和下划线）
        $cxxflags = $cfg['cxx-flags'] ?? $cfg['cxxflags'] ?? null;
        if (!empty($cxxflags)) {
            if (is_array($cxxflags)) {
                $this->cxxflags = implode(' ', $cxxflags);
            } else {
                $this->cxxflags = str_replace("\n", ' ', $cxxflags);
            }
        }

        // 读取 C++ 标准版本（支持中横线和下划线）
        $cxxStd = $cfg['cxx-std'] ?? $cfg['cxx_std'] ?? null;
        if (!empty($cxxStd)) {
            $this->cxxStd = $cxxStd;
        }

        // 读取 ldflags（支持中横线和下划线）
        $ldflags = $cfg['ld-flags'] ?? $cfg['ldflags'] ?? null;
        if (!empty($ldflags)) {
            if (is_array($ldflags)) {
                $this->ldflags = implode(' ', $ldflags);
            } else {
                $this->ldflags = str_replace("\n", ' ', $ldflags);
            }
        }

        // 读取 name
        if (!empty($cfg['name'])) {
            $this->setTargetName($cfg['name']);
        }

        // 读取 cpp-compiler（支持中横线和下划线）
        $cppCompiler = $cfg['cpp-compiler'] ?? $cfg['cpp_compiler'] ?? null;
        if (!empty($cppCompiler)) {
            $this->setCppCompiler($cppCompiler);
        }

        // 读取 type/build-mode（支持中横线和下划线）
        $buildMode = $cfg['build-mode'] ?? $cfg['type'] ?? null;
        if (!empty($buildMode)) {
            $this->setBuildMode($buildMode);
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
        $classDef = $this->getClass($className);

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
                        $traitMethods[$methodName] = $traitStmt;
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
                    if ($argInfo->byRef) {
                        $argExpr = 'php::getCallArgByRef(' . $k . ', ' . $argInfo->default . ')';
                    } else {
                        $argExpr = 'php::getCallArg(' . $k . ', ' . $argInfo->default . ')';
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
                $expr = $this->convertExprFromType($argInfo->type, $argExpr);
                $cppCode .= $this->getIndent() . $argInfo->type . ' ' . $var . ' = ' . $expr . ';' . PHP_EOL;
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
                if ($property->type === self::TYPE_ARRAY and $property->default and $property->default !== self::TYPE_ARRAY . '{}') {
                    $fullClassName = $classDef->getNamespacedName(false);
                    $fullPropName = $fullClassName . '::' . $property->name;
                    if ($property->isStatic()) {
                        $prop = new \stdClass();
                        $prop->class = $fullClassName;
                        $prop->name = $property->name;
                        $prop->default = $property->default;
                        $this->defaultStaticPropertyList[$fullPropName] = $prop;
                    } else {
                        $this->defaultPropertyList[$fullPropName] = $property->default;
                        $arrayPropCount++;
                    }
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

        $code = 'if (' . $obj . '.instanceOf("IteratorAggregate")) {' . PHP_EOL;
        $code .= $this->getIndent() . $tmpVar . ' = ' . $obj . '.call("getIterator");' . PHP_EOL . '}' . PHP_EOL;
        $code .= 'else if (' . $obj . '.instanceOf("Iterator")) {' . PHP_EOL;
        $code .= $this->getIndent() . $tmpVar . ' = ' . $obj . ';' . PHP_EOL . '}' . PHP_EOL;

        $code .= 'if (' . $tmpVar . ') {' . PHP_EOL;

        $this->indentLevel++;
        $code .= $this->getIndent() . $tmpVar . '.call("rewind");' . PHP_EOL;
        $code .= $this->getIndent() . 'for (;' . $tmpVar . '.call("valid");  ' . $tmpVar . '.call("next")) {' . PHP_EOL;
        $this->indentLevel++;

        $valueVar = $this->parseIdentifier($node->valueVar);
        $this->checkVar($node, $valueVar);

        $code .= $this->getIndent() . ' ' . $valueVar . ' = ' . $tmpVar . '.call("current");' . PHP_EOL;
        if ($node->keyVar) {
            $keyVar = $this->parseIdentifier($node->keyVar);
            $this->checkVar($node, $keyVar);
            $code .= $this->getIndent() . ' ' . $keyVar . ' = ' . $tmpVar . '.call("key");' . PHP_EOL;
        }
        $code .= $this->parseStmts($node->stmts);
        $code .= '}' . PHP_EOL;
        $this->indentLevel--;
        $code .= $this->getIndent() . '} else {' . PHP_EOL;
        $code .= $this->getIndent() . $tmpArrayVar . ' = php::call("get_object_vars", {' . $obj . '});' . PHP_EOL;
        $code .= $this->parseForeachArray($node, $tmpArrayVar);
        $this->indentLevel--;
        $code .= '}' . PHP_EOL;

        return $code;
    }

    protected function parseTrait(Node\Stmt\Trait_ $trait)
    {
        throw new Unsupported('Unsupported Trait ');
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
