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
use PhpParser\Modifiers;
use PhpParser\Node;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\NodeTraverser;
use Symfony\Component\Yaml\Yaml;

class Translator extends Preprocessor
{
    use MagicMethodDetector;
    protected string $targetName = 'app';
    protected bool $verbose = false;
    protected array $phpSrcFiles = [];
    protected array $argInfoHeaderFiles = [];
    protected array $registerSymbols = [];
    protected bool $useRegisterSymbolsFn = false;
    protected array $unsupportedFunctions = [
        'compact',
        'extract',
        'func_num_args',
        'func_get_arg',
        'func_get_args',
    ];

    public function __construct(string $rootPath)
    {
        parent::__construct($rootPath);
        $this->climate->arguments->add(require __DIR__ . '/../config/compiler_options.php');
        $this->preprocessArgvAdvanced();
        $this->climate->arguments->parse();

        $this->optimizeLevel = $this->climate->arguments->get('optimize');
        $this->buildMode     = $this->climate->arguments->get('mode');
        $this->debugLine     = intval($this->climate->arguments->get('debug-line'));
        $this->debugInfo     = $this->climate->arguments->defined('debug-info');
        $this->noLiteralStrings = $this->climate->arguments->get('noLiteralStrings');
        $this->enableProfiler    = $this->climate->arguments->defined('profile');
        $this->internalFunctions = array_flip(get_defined_functions()['internal']);
        if ($this->climate->arguments->defined('help')) {
            $this->showUsage();
            exit(0);
        }
    }

    public function showUsage(): void
    {
        $climate = $this->climate;
        $climate->bold()->green('PHP AOT Compiler v1.0.0');
        $climate->br();

        $climate->bold('USAGE:');
        $climate->tab()->out('./bin/compiler.php <file/dir> [options]');
        $climate->br();

        $climate->bold('ARGUMENTS:');
        $climate->tab()->out('<file>    Input PHP file/directory to compile');
        $climate->br();

        $climate->bold('OPTIONS:');
        $climate->tab()->out('-O <level>           Optimization level (0-3, default: 0)');
        $climate->tab()->out('-p, --profile        Enable performance profiling');
        $climate->tab()->out('-o, --output <file>  Output binary name (default: input basename)');
        $climate->tab()->out('-v, --verbose        Verbose output');
        $climate->tab()->out('-h, --help           Show this help message');
        $climate->tab()->out('-f, --force          Force compile even if cache exists');
        $climate->tab()->out('-m, --mode <mode>    Compilation mode, -m bin(binary) or -m ext(extension), default: bin');
        $climate->tab()->out('--no-literal-strings Disable literal strings optimization');
        $climate->br();

        $climate->bold('EXAMPLES:');
        $climate->tab()->out('./bin/compiler.php examples/hello.php');
        $climate->tab()->out('./bin/compiler.php examples/bench.php -O2');
        $climate->tab()->out('./bin/compiler.php examples/bench.php -O2 ');
        $climate->tab()->out('./bin/compiler.php examples/extension -O2 -o myapp -m ext');
        $climate->tab()->out('./bin/compiler.php examples/app.php -O3 -o myapp -v');
        $climate->br();
    }

    public function convert(string $file): string
    {
        if ($this->hasCppFileCache($file)) {
            $this->climate->darkGray('skip: ' . $file . ', cache exists');

            return $this->getCppFile($file);
        }
        $phpCode = $this->loadFile($file);
        $this->genStubFile($this->file);
        $this->localHeaders = [];
        while (true) {
            try {
                $cppCode = $this->doConvert($phpCode);
                $cppFile = $this->getCppFile($file);
                $this->save($cppCode, $cppFile);
                $this->phpSrcFiles[] = $file;

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

    public function setTargetName(string $name): void
    {
        if ($this->climate->arguments->defined('output')) {
            $name = $this->climate->arguments->get('output');
        }
        $name = str_replace(['-', '*'], '_', $name);
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $name)) {
            $this->climate->red('The target name must be a valid identifier');
            exit(1);
        }
        if (in_array($name, Constants::CPP_RESERVED_NAMES)) {
            $this->climate->red('The target name [' . $name . '] must not be a reserved keyword');
            exit(1);
        }
        if (is_numeric($name)) {
            $name = 'app_' . $name;
        }
        $this->targetName = $name;
    }

    public function getFiles(string $path): array
    {
        $realpath = realpath($path);
        if ($realpath === false) {
            exit("path not exists: {$path}\n");
        }
        $path = $realpath;

        if (is_dir($path)) {
            $list       = $this->getFilesFromDir($path);
            $targetName = basename($path);
            $this->setTargetName($targetName);
        } else {
            $ext = pathinfo($path, PATHINFO_EXTENSION);
            if ($ext === 'yml') {
                $list = $this->parseProjectYaml($path);
            } elseif ($ext === 'php') {
                $list       = [$path];
                $targetName = FileScanner::getFileName($path);
                $this->setTargetName($targetName);
            } else {
                $this->error('Unsupported file type: ' . $path);
            }
        }

        return $list;
    }

    public function preprocessArgvAdvanced(): void
    {
        global $argv;
        $processed = [$argv[0]];

        for ($i = 1; $i < count($argv); $i++) {
            $arg = $argv[$i];
            if (preg_match('/^-([a-zA-Z])(.+)$/', $arg, $matches)) {
                $option      = $matches[1];
                $value       = $matches[2];
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
            $lines[] = 'extern ' . self::TYPE_VAR . ' ' . $name . ';';
        }

        // property offset
        foreach ($this->classes as $classDef) {
            foreach ($classDef->properties as $propertyDef) {
                $lines[] = 'extern uint32_t ' . self::PREFIX . $this->getPropertyOffset($propertyDef->name, $classDef->name, $classDef->namespace) . ';';
            }
        }

        $literalStringsCount = count($this->literalStrings);
        $lines[] = 'extern ' . self::TYPE_STR . ' ' . self::LITERAL_STRINGS . '[' . $literalStringsCount . '];' . PHP_EOL;

        $classCount = count($this->classMap);
        $lines[]         = 'extern zend_class_entry *' . self::PREFIX . self::CLASS_MAP . '[' . $classCount . '];' . PHP_EOL;

        $funcCount = count($this->funcMap);
        $lines[]         = 'extern zend_function *' . self::PREFIX . self::FUNC_MAP . '[' . $funcCount . '];' . PHP_EOL;

        $code = implode(PHP_EOL, $lines) . PHP_EOL . PHP_EOL;
        $this->writeFile($file, $code);
    }

    public function genExtension(string $file): void
    {
        if ($this->buildMode == 'bin') {
            if (!isset($this->nativeFunctions['main'])) {
                $this->climate->red('When the build mode is a binary executable file, the `main()` function must be defined');
                exit(1);
            }
        }
        $this->localHeaders = $this->argInfoHeaderFiles;
        $this->genClassCeList();
        $code = $this->render('extension.cc.php');
        $this->writeFile($file, $code);
        $this->formatCppCode($file);
        $this->localHeaders = [];
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

    public function compileFile(string $cppFile, string $objectFile): void
    {
        if ($this->hasObjectFileCache($cppFile)) {
            $this->climate->darkGray('skip: ' . $cppFile . ', cache exists');

            return;
        }
        $cmd = $this->cppCompiler . ' -c ' . $cppFile . ' -o ' . $objectFile;
        $this->addCompilationOption($cmd, false);
        $this->climate->comment($cmd);
        passthru($cmd, $ret);
        if ($ret !== 0) {
            $this->error('compile failed: ' . $cppFile);
        }
    }

    public function build(array $objectFiles): void
    {
        $objectList = implode(' ', $objectFiles);
        $targetFile = $this->targetName;
        if ($this->buildMode == 'ext' and !str_ends_with($targetFile, '.so')) {
            $targetFile .= '.so';
        }
        $linkCmd = $this->cppCompiler . ' ' . $objectList . ' -o ' . $targetFile;
        $this->addCompilationOption($linkCmd, true);
        $this->climate->comment($linkCmd);
        shell_exec($linkCmd);
    }

    public function genFunctionDeclaration(string $file): void
    {
        $code = '#include <phpx.h>' . PHP_EOL;

        $literalStringsCount = count($this->literalStrings);
        $code .= 'extern ' . self::TYPE_STR . ' ' . self::LITERAL_STRINGS . '[' . $literalStringsCount . '];' . PHP_EOL;

        /**
         * @var FunctionDef $func
         */
        foreach ($this->nativeFunctions as $name => $func) {
            $code .= 'extern ' . $func->returnType . ' ' . self::PREFIX . $name . '(';
            $list = [];
            if ($func->method) {
                $list[] = 'php::Object &this_';
            }
            $argInfoList = $func->argInfoList;
            if ($argInfoList) {
                foreach ($argInfoList as $argInfo) {
                    $arg = $argInfo->type . ' ' . $argInfo->name;
                    if ($argInfo->default) {
                        $arg .= ' = ' . $argInfo->default;
                    }
                    $list[] = $arg;
                }
            }
            $code .= implode(', ', $list);
            $code .= ');' . PHP_EOL;
        }

        $code .= PHP_EOL;
        foreach ($this->nativeConstants as $name => $constant) {
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
        $rs = str_replace([".stub.php", '.php'], "", $stubFile);
        $rs = str_replace('-', '_', $rs);
        return $rs;
    }

    public function getArgInfoHeaderFile(string $stubFilenameWithoutExtension, bool $relative = false): string
    {
        $basename = self::PREFIX . basename($stubFilenameWithoutExtension);
        $basename = $this->escapeFileName($basename);
        $absPath  = $this->getIncludeDir() . "/{$basename}_arginfo.h";
        if ($relative) {
            return ltrim($this->removeCommonPrefix($this->getIncludeDir(), $absPath), '/');
        }
        return $absPath;
    }

    public function genIncludeHeaderFiles(): string
    {
        $headers = array_merge($this->globalHeaders, $this->localHeaders);
        $lines   = [];
        foreach ($headers as $header) {
            $lines[] = '#include <' . $header . '>';
        }

        return implode(PHP_EOL, $lines) . PHP_EOL . PHP_EOL;
    }

    protected function getRegisterClassFunction(string $name): string
    {
        return self::PREFIX . 'register_class_' . $name;
    }

    protected function getRegisterClassFunctionCeList(ClassDef|InterfaceDef $classDef): array
    {
        $list     = [];
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
        return self::PREFIX . 'class_entry_' . $classDef->getNamespacedName();
    }

    protected function getFilesFromDir(string $path): array
    {
        $scanner = new FileScanner($path);

        return $scanner->scan();
    }

    protected function parseProjectYaml(string $path): array
    {
        $cfg        = Yaml::parseFile($path);
        $projectDir = dirname($path);

        if (!empty($cfg['sources'])) {
            $sources = $cfg['sources'];
            $list    = [];
            foreach ($sources as $src) {
                $src = trim($src);
                if ($src[0] != '/') {
                    $absPath = $projectDir . '/' . $src;
                } else {
                    $absPath = $src;
                }
                $realPath = realpath($absPath);
                if (!$realPath) {
                    $this->error('Source file not exists: `' . $src . '`');
                }
                if (is_file($realPath)) {
                    $list[] = $realPath;
                } else {
                    $tmp  = $this->getFilesFromDir($realPath);
                    $list = array_merge($list, $tmp);
                }
            }
        } else {
            $list = $this->getFilesFromDir($projectDir);
        }
        if (!empty($cfg['cxxflags'])) {
            if (is_array($cfg['cxxflags'])) {
                $this->cxxflags = implode(' ', $cfg['cxxflags']);
            } else {
                $this->cxxflags = str_replace("\n", ' ', $cfg['cxxflags']);
            }
        }
        if (!empty($cfg['ldflags'])) {
            if (is_array($cfg['ldflags'])) {
                $this->ldflags = implode(' ', $cfg['ldflags']);
            } else {
                $this->ldflags = str_replace("\n", ' ', $cfg['ldflags']);
            }
        }
        if (!empty($cfg['name'])) {
            $this->setTargetName($cfg['name']);
        }

        return $list;
    }

    protected function getInternalCeInfo(string $ce): array
    {
        return [
            'func' => 'php::getClassEntry',
            'args' => '"' . substr($ce, strlen(self::PREFIX . 'class_entry_')) . '"',
        ];
    }

    protected function getParentClassCe(ClassLikeDef $classDef): string
    {
        if (!$classDef->extends) {
            return '';
        }

        return self::PREFIX . 'class_entry_' . $classDef->extends;
    }

    protected function doConvert(string $phpCode): string
    {
        $this->climate->info('convert: ' . $this->file);

        $ast       = $this->parser->parse($phpCode);
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new Visitor());

        $stmts = $traverser->traverse($ast);

        $this->resetFile();
        $this->resetFunction();
        $this->resetClass();
        $this->resetNamespace();

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
                    $this->parseConstDef($v) . PHP_EOL;
                    break;
                case 'Stmt_Interface':
                    $this->parseInterface($v) . PHP_EOL;
                    break;
                case 'Stmt_Nop':
                    break;
                default:
                    abort($v);
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

        return $this->genIncludeHeaderFiles() . $cppCode;
    }

    protected function genClassCeList(): void
    {
        if (empty($this->interfaces) and empty($this->classes)) {
            return;
        }

        $sorter = new StringSort();

        foreach ($this->interfaces as $interfaceDef) {
            $parent = $interfaceDef->extends;
            $ce     = $this->getClassCe($interfaceDef);
            $deps   = [];

            if ($parent) {
                // 不存在的接口，说明可能是内置接口
                $tmpCe = $this->getParentClassCe($interfaceDef);
                if (!isset($this->interfaces[$parent])) {
                    $sorter->add($tmpCe);
                }
                $deps[] = $tmpCe;
            }

            $this->classCeInfo[$ce] = [
                'deps'   => $deps,
                'func'   => $this->getRegisterClassFunction($interfaceDef->getNamespacedName()),
                'args'   => $this->getRegisterClassFunctionArgs($interfaceDef),
                'argDef' => $this->getRegisterClassFunctionArgDef($interfaceDef),
            ];
            $sorter->add($ce, $deps);
        }

        foreach ($this->classes as $classDef) {
            $ce     = $this->getClassCe($classDef);
            $deps   = [];
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
                    $tmpCe = self::PREFIX . 'class_entry_' . $interface;
                    if (!isset($this->interfaces[$interface])) {
                        $sorter->add($tmpCe);
                    }
                    $deps[] = $tmpCe;
                }
            }

            $this->classCeInfo[$ce] = [
                'deps'   => $deps,
                'func'   => $this->getRegisterClassFunction($classDef->getNamespacedName()),
                'args'   => $this->getRegisterClassFunctionArgs($classDef),
                'argDef' => $this->getRegisterClassFunctionArgDef($classDef),
            ];
            $sorter->add($ce, $deps);
        }

        $this->classCeList = $sorter->sort();
    }

    protected function getMethodName(Node\Stmt\ClassMethod $v): string
    {
        return $this->parseIdentifier($v->name);
    }

    protected function getNativeMethodName(ClassDef $classDef, MethodDef $methodDef): string
    {
        return $this->getNativeName($methodDef->name, $classDef->namespace, $classDef->name);
    }

    protected function parseNamespace(Node\Stmt\Namespace_ $node): string
    {
        $ns   = $node->name ? $this->parseIdentifier($node->name) : '';
        $code = '';

        $this->resetNamespace();

        $this->namespace = $ns;
        $ns_end          = '';

        foreach ($node->stmts as $v2) {
            $type2 = $v2->getType();
            switch ($type2) {
                case 'Stmt_Class':
                    $code .= $this->parseClass($v2);
                    break;
                case 'Stmt_Const':
                    $this->parseConstDef($v2) . PHP_EOL;
                    break;
                case 'Stmt_Function':
                    $code .= $this->parseFunction($v2) . PHP_EOL;
                    break;
                case 'Stmt_Use':
                    $code .= $this->parseUse($v2) . PHP_EOL;
                    break;
                default:
                    abort($v2);
            }
        }
        $code .= $ns_end;
        $this->resetNamespace();

        return $code;
    }

    protected function genStubFile(string $file): void
    {
        $genStubCmd = PHP_BINARY . ' ' . $this->rootPath . '/bin/gen_stub.php -f ' . $file;
        $output     = shell_exec($genStubCmd);
        $this->climate->info('generate stub file: ' . $file);
        $this->climate->comment($genStubCmd);
        $stubFilenameWithoutExtension = str_replace(['.stub.php', '.php'], '', $file);
        $headerFile = $this->getArgInfoHeaderFile($stubFilenameWithoutExtension, true);
        if (!str_contains($output, 'Saved')) {
            $this->error("failed to generate arginfo header file: `{$headerFile}`, output: {$output}");
        }
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

    protected function parseClass(Node\Stmt\Class_|Node\Stmt\Trait_|Node\Stmt\Enum_ $class): string
    {
        $this->class = $this->parseIdentifier($class->name);
        if ($class instanceof Node\Stmt\Enum_) {
            $flags = Modifiers::PUBLIC;
            $extends = '';
        } else {
            $flags = $class->flags;
            $extends = $class->extends;
        }

        $this->classDef = new ClassDef($this->class, $flags, $this->namespace);
        if ($class instanceof Node\Stmt\Enum_) {
            $this->classDef->enum = true;
        }

        if ($extends) {
            $this->classDef->extends = $this->parseIdentifier($class->extends);
            if (isset($this->classes[$this->classDef->extends])) {
                $parent = $this->classes[$this->classDef->extends];
                if ($parent->flags & Modifiers::FINAL) {
                    $this->fatalError($class, "Class `{$this->class}` cannot extend final class `{$this->classDef->extends}`");
                }
            }
        }
        $this->classDef->implements = $this->parseIdentifierList($class->implements);

        $className                             = $this->classDef->getNamespacedName();
        $this->classes[$className]             = $this->classDef;
        $this->classesDefineInFile[$className] = $this->classDef;

        $methodCodes = [];

        foreach ($class->stmts as $v) {
            $type = $v->getType();
            switch ($type) {
                case 'Stmt_ClassConst':
                    $this->parseClassConstDef($v);
                    break;
                case 'Stmt_Property':
                    $this->parsePropertyDef($v);
                    break;
                case 'Stmt_ClassMethod':
                    $this->parseClassMethod($v, $methodCodes);
                    break;
                case 'Stmt_EnumCase':
                case 'Stmt_Nop':
                    break;
                default:
                    abort($v);
            }
        }
        $code = $this->genNativeMethod($methodCodes);
        if ($this->classDef->requireCtor and !$this->classDef->hasMethod('__construct')) {
            $this->fatalError($class, "Class `{$this->class}` uses a non-empty array as the default value for an property, and the constructor must be set.");
        }
        $this->resetClass();

        return $code;
    }

    protected function genNativeMethod($methodCodes): string
    {
        $code     = '';
        $classDef = $this->classDef;
        foreach ($classDef->methods as $method) {
            $code .= $methodCodes[$method->name] . PHP_EOL;
        }
        $code .= PHP_EOL;

        return $code;
    }

    protected function genWrapperFunctionArgs(string $fn, FunctionDef $functionDef): string
    {
        $cppCode    = '';
        $callParams = '';
        foreach ($functionDef->argInfoList as $k => $argInfo) {
            if ($argInfo->default) {
                $argExpr = 'php::getCallArg(' . $k . ', ' . $argInfo->default . ')';
            } else {
                $argExpr = 'php::getCallArg(' . $k . ')';
            }
            $expr = $this->convertExprFromType($argInfo->type, $argExpr);
            $cppCode .= $this->getIndent() . $argInfo->type . ' arg_' . $argInfo->name . ' = ' . $expr . ';' . PHP_EOL;
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
        } else {
            $cppCode .= $this->getIndent() . $fn . '(' . $callParams . ');' . PHP_EOL;
        }
        $cppCode .= '}' . PHP_EOL . PHP_EOL;

        return $cppCode;
    }

    protected function genMethodWrapper(ClassDef $classDef, MethodDef $methodDef): string
    {
        $name    = $classDef->getNamespacedName();
        $cppCode = 'ZEND_METHOD(' . $name . ', ' . $methodDef->name . '){' . PHP_EOL;
        $cppCode .= $this->getIndent() . self::TYPE_OBJECT . ' this_(&execute_data->This);' . PHP_EOL;

        foreach ($classDef->properties as $property) {
            if ($property->type === self::TYPE_ARRAY and $property->default and $property->default !== self::TYPE_ARRAY . '{}') {
                $propOffset = self::PREFIX . $this->getPropertyOffset($property->name, $classDef->name, $classDef->namespace);
                $cppCode .= $this->getIndent() . 'this_.attr(' . $propOffset . ') = ' . $property->default . ';' . PHP_EOL;
            }
        }

        $fn = self::PREFIX . $this->getNativeMethodName($classDef, $methodDef);
        $cppCode .= $this->genWrapperFunctionArgs($fn, $methodDef->functionDef);

        return $cppCode;
    }

    protected function getClassRegisterCeFunc(ClassDef|InterfaceDef $classDef): void
    {
        $cppCode = '';
        $name    = $classDef->getNamespacedName();
        $argsDef = $this->getRegisterClassFunctionArgDef($classDef);
        $param   = $this->getRegisterClassFunctionArgs($classDef);
        $cppCode .= 'zend_class_entry *' . $this->getRegisterClassFunction($name) . '(' . $argsDef . ') {' . PHP_EOL;
        $cppCode .= $this->getIndent() . 'return register_class_' . $name . '(' . $param . ');' . PHP_EOL;
        $cppCode .= '}' . PHP_EOL . PHP_EOL;
    }

    protected function genClassWrapper(ClassDef|InterfaceDef $classDef): string
    {
        $cppCode = '';

        // 接口没有方法实体
        if ($classDef instanceof ClassDef) {
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
        if ($prop->default) {
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

    protected function parseClassConstDef(Node\Stmt\ClassConst $v): void
    {
        $flags = $v->flags;
        $type  = $v->type ? $this->getTypeFromZendType($this->parseIdentifier($v->type)) : self::TYPE_VAR;
        foreach ($v->consts as $const) {
            $constName = $this->parseIdentifier($const->name);
            if (isset($this->classDef->constants[$constName])) {
                $this->fatalError($const, 'Cannot redefine class constant ' . $this->class . '::' . $constName);
            }
            $constInfo                                   = new ConstantDef($constName, $flags, $type, $this->parseIdentifier($const->value));
            $this->classDef->constants[$constInfo->name] = $constInfo;
        }
    }

    protected function parsePropertyDef(Node\Stmt\Property $v): void
    {
        $flags = $v->flags;
        $type  = $v->type ? $this->getTypeFromZendType($this->parseIdentifier($v->type)) : self::TYPE_VAR;

        foreach ($v->props as $prop) {
            $propDef = new PropertyDef($this->parseIdentifier($prop->name), $flags, $type);
            if ($prop->default) {
                if ($prop->default->getType() == 'Expr_Array' and count($prop->default->items) > 0) {
                    $this->classDef->requireCtor = true;
                    $propDef->type               = self::TYPE_ARRAY;
                }
                $propDef->default = $this->parseIdentifier($prop->default);
            }
            $this->classDef->properties[$propDef->name] = $propDef;
        }
    }

    protected function parseClassMethod(Node\Stmt\ClassMethod $v, array &$methodCodes): void
    {
        $name = $this->getMethodName($v);
        $this->method = $name;

        $flags = $v->flags;
        if (!($flags & Modifiers::PRIVATE) and !($flags & Modifiers::PROTECTED)) {
            $flags |= Modifiers::PUBLIC;
        }

        $this->methodDef = new MethodDef($flags, $name);
        $methodCodes[$name] = $this->parseFunction($v);

        $this->checkRequiredArgNum($name, $this->methodDef, $v);
        $this->classDef->methods[$name] = $this->methodDef;
        $this->resetMethod();
    }

    protected function parseIdentifierList(array $implements): array
    {
        $list = [];
        foreach ($implements as $implement) {
            $list[] = $this->parseIdentifier($implement);
        }

        return $list;
    }

    protected function parseInterface(Node\Stmt\Interface_ $v): void
    {
        $name                                         = $this->parseIdentifier($v->name);
        $this->interface                              = $name;
        $this->interfaceDef                           = new InterfaceDef($name, $this->namespace);
        $interfaceName                                = $this->interfaceDef->getNamespacedName();
        $this->interfaces[$interfaceName]             = $this->interfaceDef;
        $this->interfacesDefineInFile[$interfaceName] = $this->interfaceDef;
    }

    protected function parseForeachObject(Foreach_ $node): string
    {
        $obj    = $this->parseIdentifier($node->expr);
        $tmpVar = $this->genTmpVarName();
        $this->addLocalVar($tmpVar, self::TYPE_OBJECT);

        $tmpArrayVar = $this->genTmpVarName();
        $this->addLocalVar($tmpArrayVar, self::TYPE_ARRAY);

        $code = 'if (' . $obj . '.instanceOf("IteratorAggregate")) {' . PHP_EOL;
        $code .= $this->getIndent() . $tmpVar . ' = ' . $obj . '.exec("getIterator");' . PHP_EOL . '}' . PHP_EOL;
        $code .= 'else if (' . $obj . '.instanceOf("Iterator")) {' . PHP_EOL;
        $code .= $this->getIndent() . $tmpVar . ' = ' . $obj . ';' . PHP_EOL . '}' . PHP_EOL;

        $code .= 'if (' . $tmpVar . ') {' . PHP_EOL;

        $this->indentLevel++;
        $code .= $this->getIndent() . $tmpVar . '.exec("rewind");' . PHP_EOL;
        $code .= $this->getIndent() . 'for (;' . $tmpVar . '.exec("valid");  ' . $tmpVar . '.exec("next")) {' . PHP_EOL;
        $this->indentLevel++;

        $valueVar = $this->parseIdentifier($node->valueVar);
        $this->checkVar($node, $valueVar);

        $code .= $this->getIndent() . ' ' . $valueVar . ' = ' . $tmpVar . '.exec("current");' . PHP_EOL;
        if ($node->keyVar) {
            $keyVar = $this->parseIdentifier($node->keyVar);
            $this->checkVar($node, $keyVar);
            $code .= $this->getIndent() . ' ' . $keyVar . ' = ' . $tmpVar . '.exec("key");' . PHP_EOL;
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
            $list[] = self::PREFIX . 'class_entry_' . $interface;
        }

        return $list;
    }

    private function render(string $template): string
    {
        ob_start();
        include __DIR__ . '/../template/' . $template;

        return ob_get_clean();
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

        $publicMethods       = [];
        $protectedMethods    = [];
        $privateMethods      = [];
        $publicConstants     = [];
        $protectedConstants  = [];
        $privateConstants    = [];
        $publicProperties    = [];
        $protectedProperties = [];
        $privateProperties   = [];

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
