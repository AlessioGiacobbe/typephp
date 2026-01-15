<?php

namespace PhpAot\Php;

use MJS\TopSort\Implementations\StringSort;
use PhpParser\Modifiers;
use PhpParser\Node;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\NodeTraverser;

class Translator extends Preprocessor
{
    protected bool $verbose = false;
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
        $this->climate->arguments->add([
            'optimize' => [
                'prefix'      => 'O',
                'longPrefix'  => 'optimize',
                'description' => 'Set the optimization level of the gcc compiler to 0 by default',
                'required'    => false,
                'castTo'      => 'int',
                'defaultValue' => 0,
            ],
            'output' => [
                'prefix'      => 'o',
                'longPrefix'  => 'output',
                'description' => 'Output file',
            ],
            'help' => [
                'prefix'      => 'h',
                'longPrefix'  => 'help',
                'description' => 'Show help',
                'noValue'     => true,
            ],
            'profile' => [
                'longPrefix'  => 'profile',
                'description' => 'Enable performance profiling',
                'required'    => false,
                'noValue'     => true,
            ],
            'noLiteralStrings' => [
                'longPrefix'  => 'no-literal-strings',
                'description' => 'Disable literal strings optimization',
                'required'    => false,
                'noValue'     => true,
            ],
            'force' => [
                'prefix'      => 'f',
                'longPrefix'  => 'force',
                'description' => 'Force compile even if cache exists',
                'required'    => false,
                'noValue'     => true,
            ],
        ]);

        $this->preprocessArgvAdvanced();
        $this->climate->arguments->parse();
        $this->optimizeLevel = $this->climate->arguments->get('optimize');
//        $this->noLiteralStrings = $this->climate->arguments->get('noLiteralStrings');
        $this->noLiteralStrings = true;
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
        $climate->tab()->out('--no-literal-strings Disable literal strings optimization');
        $climate->br();

        $climate->bold('EXAMPLES:');
        $climate->tab()->out('./bin/compiler.php examples/hello.php');
        $climate->tab()->out('./bin/compiler.php examples/bench.php -O2');
        $climate->tab()->out('./bin/compiler.php examples/bench.php -O2 -p');
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
        $this->stubFileIncluded = false;
        $this->localHeaders = [];
        while (true) {
            try {
                $cppCode = $this->doConvert($phpCode);
                $cppFile = $this->getCppFile($file);
                $this->save($cppCode, $cppFile);
                return $cppFile;
            } catch (RedoException $e) {
                continue;
            }
        }
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

    public function getRegisterClassFunctionArgs(ClassDef|InterfaceDef $classDef): string
    {
        return implode(', ', $this->getRegisterClassFunctionCeList($classDef));
    }

    private function getRegisterClassFunctionArgDef(ClassDef|InterfaceDef $classDef): string
    {
        $depsCeList = $this->getRegisterClassFunctionCeList($classDef);
        if (empty($depsCeList)) {
            return '';
        }
        return 'zend_class_entry *' . implode(', zend_class_entry *', $depsCeList);
    }

    protected function getClassCe(ClassLikeDef $classDef): string
    {
        return self::PREFIX . 'class_entry_' . $classDef->getNamespacedName();
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

    private function getImplementCe(ClassDef $classDef): array
    {
        $list = [];
        foreach ($classDef->implements as $interface) {
            $list[] = self::PREFIX . 'class_entry_' . $interface;
        }
        return $list;
    }

    protected function doConvert(string $phpCode): string
    {
        $this->climate->info('convert: ' . $this->file);

        $ast = $this->parser->parse($phpCode);
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new Visitor());

        $stmts = $traverser->traverse($ast);

        $this->resetFile();
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

        return $this->genIncludeHeaderFiles() . $cppCode;
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
            $lines[] = 'extern ' . self::TYPE_VAR . ' ' . $name . ';';
        }

        $literalStringsCount = count($this->literalStrings);
        $lines[] = 'extern php::Var ' . self::LITERAL_STRINGS . '[' . $literalStringsCount . '];' . PHP_EOL;
        $code = implode(PHP_EOL, $lines) . PHP_EOL . PHP_EOL;
        $this->writeFile($file, $code);
    }

    private function render(string $template): string
    {
        ob_start();
        include __DIR__ . '/../template/' . $template;
        return ob_get_clean();
    }

    protected function genClassCeList(): void
    {
        if (empty($this->interfacesDefineInFile) and empty($this->classesDefineInFile)) {
            return;
        }

        $sorter = new StringSort();

        foreach ($this->interfacesDefineInFile as $interfaceDef) {
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

        foreach ($this->classesDefineInFile as $classDef) {
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
                    $tmpCe = self::PREFIX . 'class_entry_' . $interface;
                    if (!isset($this->interfaces[$interface])) {
                        $sorter->add($tmpCe);
                    }
                    $deps[] = $tmpCe;
                }
            }

            $this->classCeInfo[$ce] = [
                'deps' => $deps,
                'func' => $this->getRegisterClassFunction($classDef->getNamespacedName()),
                'args' => $this->getRegisterClassFunctionArgs($classDef),
                'argDef' => $this->getRegisterClassFunctionArgDef($classDef),
            ];
            $sorter->add($ce, $deps);
        }

        $this->classCeList = $sorter->sort();
    }

    public function genExtension(string $file): void
    {
        $this->localHeaders = [];
        $this->genClassCeList();
        $code = $this->render('extension.cc.php');
        $this->writeFile($file, $code);
        $this->formatCppCode($file);
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
        $this->addCompilationOption($cmd);
        $this->climate->comment($cmd);
        shell_exec($cmd);
    }

    public function compileBinary(string $targetFile, array $objectFiles): void
    {
        if ($this->climate->arguments->defined('output')) {
            $targetFile = $this->climate->arguments->get('output');
        }
        $objectList = implode(' ', $objectFiles);
        $linkCmd = $this->cppCompiler . ' ' . $objectList . ' -o ' . $targetFile . ' ' . $this->parseLdflags() . $this->parseLibs();
        $this->addCompilationOption($linkCmd);
        $this->climate->comment($linkCmd);
        shell_exec($linkCmd);
    }

    public function genFunctionDeclaration(string $file): void
    {
        $code = '#include <phpx.h>' . PHP_EOL;
        /**
         * @var FunctionDef $func
         */
        foreach ($this->nativeFunctions as $name => $func) {
            $code .= 'extern ' . $func->returnType . ' ' . self::PREFIX . $name . '(';
            $argInfoList = $func->argInfoList;
            if ($argInfoList) {
                $list = [];
                foreach ($argInfoList as $argInfo) {
                    $arg = $argInfo->type . ' ' . $argInfo->name;
                    if ($argInfo->default) {
                        $arg .= ' = ' . $argInfo->default;
                    }
                    $list[] = $arg;
                }
                $code .= implode(', ', $list);
            }
            $code .= ');' . PHP_EOL;
        }

        $code .= PHP_EOL;
        foreach ($this->nativeConstants as $name => $constant) {
            $code .= 'extern ' . $constant->type . ' ' . $name . ';' . PHP_EOL;
        }

        $this->writeFile($file, $code);
    }

    protected function getMethodName(Node\Stmt\ClassMethod $v): string
    {
        return $this->parseIdentifier($v->name);
    }

    protected function getNativeMethodName(ClassDef $classDef, MethodDef $methodDef): string
    {
        return $this->getNativeFunctionName($methodDef->name, $classDef->namespace, $classDef->name);
    }

    protected function parseNamespace(Node\Stmt\Namespace_ $node): string
    {
        $ns = $this->parseIdentifier($node->name);
        $code = '';

        $this->resetNamespace();

        if ($this->useCppNamespace) {
            $ns = explode('\\', $ns);
            $ns = array_filter($ns, function ($v) {
                return $v !== '';
            });
            foreach ($ns as $name) {
                $code .= 'namespace ' . $name . ' {' . PHP_EOL;
            }
            $ns_end = str_repeat('}', count($ns));
            $this->namespace = implode('::', $ns);
        } else {
            $this->namespace = $ns;
            $ns_end = '';
        }

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

    protected function genClassStubFile(Node\Stmt\Class_ $class, string $file): void
    {
        $genStubCmd = PHP_BINARY. ' ' . $this->rootPath . '/bin/gen_stub.php --gen-class-info -f ' . $file;
        $output = shell_exec($genStubCmd);
        $this->climate->info('generate stub file: ' . $file);
        $this->climate->comment($genStubCmd);
        $stubFilenameWithoutExtension = str_replace([".stub.php", '.php'], "", $file);
        $headerFile = $this->getArgInfoHeaderFile($stubFilenameWithoutExtension, true);
        if (!str_starts_with($output, "Saved")) {
            $this->fatalError($class, "failed to generate arginfo header file: `$headerFile`, output: $output");
        }
        $this->localHeaders[] = $headerFile;
        $this->stubFileIncluded = true;
    }

    protected function parseClass(Node\Stmt\Class_ $class): string
    {
        $this->class = $this->parseIdentifier($class->name);
        if (!$this->stubFileIncluded) {
            $this->genClassStubFile($class, $this->file);
        }

        $this->classDef = new ClassDef($this->class, $this->namespace);
        if ($class->extends) {
            $this->classDef->extends = $this->parseIdentifier($class->extends);
        }
        $this->classDef->implements = $this->parseIdentifierList($class->implements);

        $className = $this->classDef->getNamespacedName();
        $this->classes[$className] = $this->classDef;
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
                default:
                    abort($v);
            }
        }
        $code = $this->genNativeMethod($methodCodes);
        $this->class = '';
        return $code;
    }

    public function getArgInfoHeaderFile(string $stubFilenameWithoutExtension, bool $relative = false): string
    {
        $basename = basename($stubFilenameWithoutExtension);
        $absPath = $this->getIncludeDir() . "/{$basename}_arginfo.h";
        if ($relative) {
            return ltrim($this->removeCommonPrefix($this->getIncludeDir(), $absPath), '/');
        } else {
            return $absPath;
        }
    }

    protected function genNativeMethod($methodCodes): string
    {
        $code = '';
        $classDef = $this->classDef;
        foreach ($classDef->methods as $method) {
            $code .= $methodCodes[$method->name] . PHP_EOL;
        }
        return $code;
    }

    protected function genMethodWrapper(ClassDef $classDef, MethodDef $methodDef): string
    {
        $name = $classDef->getNamespacedName();
        $cppCode = 'ZEND_METHOD(' . $name . ', ' . $methodDef->name . '){' . PHP_EOL;
        $cppCode .= $this->getIndent() . self::TYPE_OBJECT . ' this_(&execute_data->This);' . PHP_EOL;
        $fn = self::PREFIX . $this->getNativeMethodName($classDef, $methodDef);

        $callParams = '';
        foreach ($methodDef->functionDef->argInfoList as $k => $argInfo) {
            $expr = $this->convertExprFromType($argInfo->type, 'ZEND_CALL_ARG(EG(current_execute_data), ' . ($k + 1) . ')');
            $cppCode .= $this->getIndent() . $argInfo->type . ' arg_' . $argInfo->name . ' = ' . $expr . ';' . PHP_EOL;
            $callParams .= 'arg_' . $argInfo->name . ',';
        }
        $callParams = $methodDef->functionDef->argInfoList ? 'this_, ' . rtrim($callParams, ',') : 'this_';

        if ($methodDef->getReturnType() !== self::TYPE_VOID) {
            $cppCode .= $this->getIndent() . 'auto retval = ' . $fn . '(' . $callParams . ');' . PHP_EOL;
            $cppCode .= $this->getIndent() . 'php::move(retval, return_value);' . PHP_EOL;
        } else {
            $cppCode .= $this->getIndent() . $fn . '(' . $callParams . ');' . PHP_EOL;
        }
        $cppCode .= '}' . PHP_EOL . PHP_EOL;

        return $cppCode;
    }


    protected function genClassWrapper(ClassDef|InterfaceDef $classDef): string
    {
        $cppCode = '';
        $name = $classDef->getNamespacedName();
        $argsDef = $this->getRegisterClassFunctionArgDef($classDef);
        $param = $this->getRegisterClassFunctionArgs($classDef);
        $cppCode .= 'zend_class_entry *' . $this->getRegisterClassFunction($name) . '(' . $argsDef . ') {' . PHP_EOL;
        $cppCode .= $this->getIndent() . 'return register_class_' . $name . '(' . $param . ');' . PHP_EOL;
        $cppCode .= '}' . PHP_EOL . PHP_EOL;

        // 接口没有方法实体
        if ($classDef instanceof ClassDef) {
            $methods = $classDef->methods;
            foreach ($methods as $methodDef) {
                $cppCode .= $this->genMethodWrapper($classDef, $methodDef);
            }
        }

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

    public function genIncludeHeaderFiles(): string
    {
        $headers = array_merge($this->globalHeaders, $this->localHeaders);
        $lines = [];
        foreach ($headers as $header) {
            $lines[] = '#include <' . $header . '>';
        }
        return implode(PHP_EOL, $lines) . PHP_EOL . PHP_EOL;
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
        $type = $v->type ? $this->getTypeFromZendType($this->parseIdentifier($v->type)) : self::TYPE_VAR;
        foreach ($v->consts as $const) {
            $constInfo = new ConstantDef($this->parseIdentifier($const->name), $flags, $type, $this->parseIdentifier($const->value));
            $this->classDef->constants[$constInfo->name] = $constInfo;
        }
    }

    protected function parsePropertyDef(Node\Stmt\Property $v): void
    {
        $flags = $v->flags;
        $type = $v->type ? $this->getTypeFromZendType($this->parseIdentifier($v->type)) : self::TYPE_VAR;

        foreach ($v->props as $prop) {
            $propDef = new PropertyDef($this->parseIdentifier($prop->name), $flags, $type);
            if ($prop->default) {
                $propDef->default = $this->parseIdentifier($prop->default);
            }
            $this->classDef->properties[$propDef->name] = $propDef;
        }
    }

    protected function parseClassMethod(Node\Stmt\ClassMethod $v, array &$methodCodes): void
    {
        $name = $this->getMethodName($v);
        $this->method = $name;
        $methodCodes[$name] = $this->parseFunction($v);
        $methodDef = new MethodDef($v->flags, $name, $this->functionDef);
        if ($name == '__call') {
            if (count($methodDef->functionDef->argInfoList) != 2) {
                $this->fatalError($v, 'Method ' . $this->class . '::__call() must take exactly 2 arguments');
            }
        }
        $this->classDef->methods[$name] = $methodDef;
        $this->method = '';
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
        $name = $this->parseIdentifier($v->name);
        $this->interface = $name;
        $this->interfaceDef = new InterfaceDef($name, $this->namespace);
        $interfaceName = $this->interfaceDef->getNamespacedName();
        $this->interfaces[$interfaceName] = $this->interfaceDef;
        $this->interfacesDefineInFile[$interfaceName] = $this->interfaceDef;
    }

    protected function parseForeachObject(Foreach_ $node): string
    {
        $obj = $this->parseIdentifier($node->expr);
        $tmpVar = $this->genTmpVarName();
        $this->addLocalVar($tmpVar, self::TYPE_OBJECT);
        $code = 'if (' . $obj . '.instanceOf("IteratorAggregate")) {' . PHP_EOL;
        $code .= $this->getIndent() . $tmpVar . ' = ' . $obj . '.exec("getIterator");' . PHP_EOL . '}' . PHP_EOL;
        $code .= 'else if (' . $obj . '.instanceOf("Iterator")) {' . PHP_EOL;
        $code .= $this->getIndent() . $tmpVar . ' = ' . $obj . ';' . PHP_EOL . '}'. PHP_EOL;

        $code .= 'if (' . $tmpVar . ') {'. PHP_EOL;

        $this->indentLevel++;
        $code .= $this->getIndent() . $tmpVar . '.exec("rewind");' . PHP_EOL;
        $code .= $this->getIndent() . 'for (;' . $tmpVar . '.exec("valid");  ' . $tmpVar . '.exec("next")) {' . PHP_EOL;
        $this->indentLevel++;
        $valueVar = $this->parseIdentifier($node->valueVar);
        $code .= $this->getIndent() . self::TYPE_VAR . ' ' . $valueVar . ' = ' . $tmpVar . '.exec("current");' . PHP_EOL;
        if ($node->keyVar) {
            $keyVar = $this->parseIdentifier($node->keyVar);
            $code .= $this->getIndent() . self::TYPE_VAR . ' ' . $keyVar . ' = ' . $tmpVar . '.exec("key");' . PHP_EOL;
        }
        $code .= $this->parseStmts($node->stmts);
        $code .= '}' . PHP_EOL;
        $this->indentLevel--;
        $this->indentLevel--;
        $code .= '}' . PHP_EOL;

        return $code;
    }
}