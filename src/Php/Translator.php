<?php

namespace PhpAot\Php;

use PhpParser\NodeTraverser;

class Translator extends Preprocessor
{
    protected bool $verbose = false;

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
        ]);

        $this->preprocessArgvAdvanced();
        $this->climate->arguments->parse();
        $this->optimizeLevel = $this->climate->arguments->get('optimize');
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
        $phpCode = $this->loadFile($file);
        while (true) {
            try {
                $cppCode = $this->doConvert($phpCode);
                $info = pathinfo($file);
                $cppFile = $this->buildDir . '/' . $this->removeCommonPrefix($this->buildDir, $info['dirname'] . '/' . $info['filename'] . '.cc');
                $this->save($cppCode, $cppFile);
                return $cppFile;
            } catch (RedoException $e) {
                continue;
            }
        }
    }

    protected function doConvert(string $phpCode): string
    {
        $this->climate->info('convert: ' . $this->file);

        $ast = $this->parser->parse($phpCode);
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new Visitor());

        $stmts = $traverser->traverse($ast);

        $this->indentLevel = 0;
        $this->strictTypes = false;
        $this->resetNamespace();

        $cppCode = '';
        foreach ($stmts as $v) {
            $type = $v->getType();
            switch ($type) {
                case 'Stmt_Declare':
                    $this->parseDeclare($v);
                    break;
                case 'Stmt_Namespace':
                    $cppCode .= $this->parseNamespaceDef($v);
                    break;
                case 'Stmt_Class':
                    $cppCode .= $this->parseClassDef($v);
                    break;
                case 'Stmt_Use':
                    $cppCode .= $this->parseUse($v) . PHP_EOL;
                    break;
                case 'Stmt_Function':
                    $cppCode .= $this->parseFunctionDef($v) . PHP_EOL;
                    break;
                case 'Stmt_Const':
                    $this->parseConstDef($v) . PHP_EOL;
                    break;
                default:
                    abort($v);
            }
        }
        // include + extern global vars + function impl
        return $this->genIncludeHeaderFiles() . $this->genExternGlobalVars() . $cppCode;
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

    public function genGlobalVars(string $file): void
    {
        $code = $this->genIncludeHeaderFiles();
        $lines = [];
        // 全局变量只能是 var 类型
        foreach ($this->globalVars as $name => $type) {
            $lines[] = self::TYPE_VAR . ' ' . $name . ';';
        }
        $code .= implode(PHP_EOL, $lines) . PHP_EOL;

        $code .= PHP_EOL;
        $literalStringsCount = count($this->literalStrings);
        $code .= 'php::Var ' . self::LITERAL_STRINGS . '[' . $literalStringsCount . '] = {' . PHP_EOL;
        $this->indentLevel++;
        foreach ($this->literalStrings as $str => $index) {
            $code .= $this->getIndent() . 'php::String{ZEND_STRL("' . $this->escapeString($str) . '"), true},' . PHP_EOL;
        }
        $this->indentLevel--;
        $code .= '};' . PHP_EOL;

        // 生成常量表
        $this->indentLevel++;
        foreach ($this->nativeConstants as $name => $constant) {
            $code .= $constant->type . ' ' . $name . ';' . PHP_EOL;
        }
        $this->indentLevel--;

        $code .= PHP_EOL;
        $this->indentLevel++;
        $lines = [];
        foreach ($this->nativeConstants as $name => $constant) {
            $lines[] = $this->getIndent() . $name . ' = ' . $constant->value . ';';
            // 注册到 PHP
            $lines[] = $this->getIndent() . 'php::define("' . $name . '", ' . $name . ');';
        }
        $this->indentLevel--;
        $code .= $this->genFunction(self::PREFIX . 'init_constant_vars', 'void', [], $lines);

        // 生成全局变量
        $code .= PHP_EOL;
        $this->indentLevel++;
        $lines = [];
        foreach ($this->globalVars as $name => $type) {
            $lines[] = $this->getIndent() . $name . ' = php::global("' . $name . '");';
        }
        $this->indentLevel--;
        $code .= $this->genFunction(self::PREFIX . 'init_global_vars', 'void', [], $lines);

        // 销毁全局变量
        $code .= PHP_EOL;
        $this->indentLevel++;
        $lines = [];
        foreach ($this->globalVars as $name => $type) {
            $lines[] = $this->getIndent() . $name . '.unset();';
        }
        foreach ($this->nativeConstants as $name => $constant) {
            if ($constant->type !== self::TYPE_VAR) {
                continue;
            }
            $lines[] = $this->getIndent() . $name . '.unset();';
        }
        $this->indentLevel--;
        $code .= $this->genFunction(self::PREFIX . 'unset_global_vars', 'void', [], $lines);

        $this->writeFile($file, $code);
        $this->formatCppCode($file);
    }

    public function compileFile($file): void
    {
        $cmd = $this->cppCompiler . ' -c ' . $file . ' -o ' . $file . '.o ';
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
        $code = '';
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
}