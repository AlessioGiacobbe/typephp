<?php

namespace PhpAot\Php;

use League\CLImate\CLImate;
use PhpAot\Php\Visitor;
use PhpParser\Node;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Error;
use PhpParser\Node\NullableType;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter;
use SimplePie\Exception;

class Translator extends \PhpAot\Core\Translator
{
    const string TYPE_VAR = 'php::Var';
    const string TYPE_BOOL = 'php::Bool';
    const string TYPE_INT = 'php::Int';
    const string TYPE_FLOAT = 'php::Float';
    const string TYPE_OBJECT = 'php::Object';
    const string TYPE_ARRAY = 'php::Array';
    const string TYPE_STR = 'php::Str';
    const string TYPE_REF = 'php::Var';

    const string VALUE_NAN = 'std::numeric_limits<double>::quiet_NaN()';
    const string VALUE_INF = 'std::numeric_limits<double>::infinity()';
    const string LITERAL_STRINGS = '_literal_strings';
    const string EXPR_VARIABLE = 'Expr_Variable';
    const string EXPR_NEW = 'Expr_New';
    const string EXPR_ARRAY_DIM_FETCH = 'Expr_ArrayDimFetch';

    private string $phpxDir = '~/workspace/projects/phpx';
    protected string $lang = 'PHP';
    private string $cppCompiler = 'g++';
    private array $arguments = [];
    private array $literalStrings = [];
    private int $literalStringIndex = 0;
    private int $tmpVarIndex = 0;
    private array $zendTypeMap = [
        'int' => self::TYPE_INT,
        'float' => self::TYPE_FLOAT,
        'bool' => self::TYPE_BOOL,
    ];

    private array $headers = [
        'phpx.h',
        'phpx_helper.h',
        'phpx_func.h',
        'php_func_decl.h',
    ];

    private array $reservedNames = [
        'auto',
        'break',
        'case',
        'catch',
        'class',
        'struct',
        'const',
        'continue',
        'default',
        'do',
        'else',
        'elseif',
        'enum',
        'extends',
        'final',
        'finally',
        'for',
        'function',
        'global',
        'if',
        'int',
        'double',
        'float',
        'false',
        'for',
        'if',
        'int',
        'new',
        'null',
        'or',
        'and',
        'private',
        'protected',
        'public',
        'return',
        'static',
        'pipe',
     ];

    private array $unsupportedFunctions = [
        'compact',
        'extract'
    ];

    private array $nativeFunctions = [];
    private array $internalFunctions = [];
    private int $optimizeLevel = 0;
    private int $floatPrecision = 17;
    private bool $debugInfo = true;
    private bool $noLiteralStrings = false;
    private bool $verbose = false;
    private string $file;
    private string $dir;
    private string $namespace = '';
    private array $uses = [];
    private string $class = '';
    private FunctionDef $functionDef;
    private array $globalVars = [
        '_GET' => self::TYPE_ARRAY,
        '_POST' => self::TYPE_ARRAY,
        '_COOKIE' => self::TYPE_ARRAY,
        '_SERVER' => self::TYPE_ARRAY,
        '_FILES' => self::TYPE_ARRAY,
        '_SESSION' => self::TYPE_ARRAY,
        '_REQUEST' => self::TYPE_ARRAY,
        'GLOBALS' => self::TYPE_ARRAY,
        'argc' => self::TYPE_INT,
        'argv' => self::TYPE_ARRAY,
    ];
    private array $localVars = [];
    private array $objectWrappers = [];

    const string PREFIX = 'php_';
    private string $rootPath;
    private int $debugLine = 0;
    private CLImate $climate;
    private array $beforeStmtLines = [];
    private array $afterStmtLines = [];
    private bool $inLoop = false;
    private bool $inSwitch = false;

    public function __construct(string $rootPath)
    {
        $this->rootPath = $rootPath;
        $climate = new CLImate;
        $this->climate = $climate;
        $climate->arguments->add([
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
        $climate->arguments->parse();
        if ($climate->arguments->defined('help')) {
            $this->showUsage();
            exit(0);
        }
//        $this->noLiteralStrings = $climate->arguments->get('no-literal-strings');
        $this->noLiteralStrings = true;
        $this->optimizeLevel = $climate->arguments->get('optimize');
        $this->internalFunctions = array_flip(get_defined_functions()['internal']);
    }

    function showUsage(): void
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

    function preprocessArgvAdvanced(): void
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

    public function genIncludeHeaderFiles(): string
    {
        $lines = [];
        foreach ($this->headers as $header) {
            $lines[] = '#include <' . $header . '>';
        }
        return implode(PHP_EOL, $lines) . PHP_EOL . PHP_EOL;
    }

    public function genExternGlobalVars(): string
    {
        $lines[] = PHP_EOL;
        foreach ($this->globalVars as $name => $type) {
            $lines[] = 'extern ' . self::TYPE_VAR . ' ' . $name . ';';
        }

        $literalStringsCount = count($this->literalStrings);
        $lines[] = 'extern php::Var ' . self::LITERAL_STRINGS . '[' . $literalStringsCount . '];' . PHP_EOL;
        return implode(PHP_EOL, $lines) . PHP_EOL . PHP_EOL;
    }

    public function setPhpxDir($dir): void
    {
        $this->phpxDir = $dir;
    }

    private function doConvert(string $phpCode): string
    {
        $this->climate->info('do convert: ' . $this->file);
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $ast = $parser->parse($phpCode);

        $traverser = new NodeTraverser;
        $prettyPrinter = new PrettyPrinter\Standard;

        $traverser->addVisitor(new Visitor());
        $stmts = $traverser->traverse($ast);

        $this->indentLevel = 0;

        $cppCode = '';
        foreach($stmts as $v) {
            $type = $v->getType();
            switch ($type) {
                case 'Stmt_Namespace':
                    $cppCode .= $this->parseNamespaceDef($v);
                    break;
                case 'Stmt_Class':
                    $cppCode .= $this->parseClassDef($v);
                    break;
                case 'Stmt_Function':
                    $cppCode .= $this->parseFunctionDef($v) . PHP_EOL;
                    break;
                default:
                    abort($v);
            }
        }
        // include + extern global vars + function impl
        return $this->genIncludeHeaderFiles() . $this->genExternGlobalVars() . $cppCode;
    }

    public function convert(string $file): string
    {
        if (!file_exists($file)) {
            throw new \Exception('File not exists: ' . $file);
        }
        $phpCode = file_get_contents($file);
        $this->file = realpath($file);
        $this->dir = dirname($this->file);

        while (true) {
            try {
                return $this->doConvert($phpCode);
            } catch (ReturnTypeChanged $e) {
                // 某些情况，例如返回值变更，需要重新解析
                $this->climate->cyan('Return type changed, retrying...');
                continue;
            }
        }
    }

    public function save(string $code, string $file): void
    {
        file_put_contents($file, $code);
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

    private function getVarType(string $name): string
    {
        if ($this->hasLocalVar($name)) {
            return $this->localVars[$name];
        }
        if ($this->hasLocalVar($name)) {
            return $this->globalVars[$name];
        }
        return self::TYPE_VAR;
    }

    public function getTypeFromZendType(string $type): string
    {
        return $this->zendTypeMap[$type] ?? self::TYPE_VAR;
    }

    public function isNativeFunction(string $name): bool
    {
        return isset($this->nativeFunctions[$name]);
    }

    private function resetScope(): void
    {
        $this->localVars = [];
        $this->arguments = [];
        $this->tmpVarIndex = 0;
    }

    private function parseFunctionDef($v): string
    {
        $this->resetScope();
        $names[] = $this->parseIdentifier($v->name);
        if ($this->class) {
            $names[] = strtolower($this->class);
        }
        if ($this->namespace) {
            $names[] = strtolower(str_replace('\\', '_', $this->namespace));
        }

        $name = implode('__', array_reverse($names));
        if (isset($this->nativeFunctions[$name])) {
            $this->functionDef = $this->nativeFunctions[$name];
        } else {
            $this->functionDef = new FunctionDef();
            $this->functionDef->name = $name;
            if ($v->returnType) {
                $this->functionDef->returnType = $this->getTypeFromZendType($this->parseIdentifier($v->returnType));
            } else {
                $this->functionDef->returnType = 'void';
            }
            $this->parseParams($v->params);
            $this->nativeFunctions[$name] = $this->functionDef;
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

        $functionDeclCode = $this->getReturnType() . ' ' . self::PREFIX . $name . '(' . $this->functionDef->params . ')';

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

        return $code;
    }

    private function writeLog($msg)
    {
        if ($this->verbose) {
            echo $msg . PHP_EOL;
        }
    }

    private function parseScalar(Node $expr)
    {
        $type = $expr->getType();
        switch ($type) {
            case 'Scalar_Int':
                return $expr->value . 'L';
            case 'Scalar_Float':
                return $this->parseScalarFloat($expr);
            case 'Scalar_String':
                if ($this->noLiteralStrings) {
                    return '"' . $this->escapeString($expr->value) . '"';
                } else {
                    $index = $this->literalStrings[$expr->value] ?? $this->addLiteralString($expr->value);
                    return self::LITERAL_STRINGS . '[' . $index . ']';
                }
            default:
                abort($expr);
        }
    }

    private function parseIdentifier(Node $expr): string
    {
        $type = $expr->getType();
        switch ($type) {
            case self::EXPR_VARIABLE:
                return $this->escapeVarName($expr->name);
            case 'Name':
            case 'Identifier':
                return $expr->name;
            case 'Scalar_Int':
            case 'Scalar_Float':
            case 'Scalar_String':
                return $this->parseScalar($expr);
            case 'Expr_ConstFetch':
                return $this->parseConstFetch($expr);
            default:
                return $this->parseExpr($expr);
        }
    }

    private function parseParams($params): void
    {
        $list = [];
        $this->functionDef->argCountRequired = count($params);
        foreach ($params as $param) {
            $type = $this->parseType($param->type);
            $name = $this->parseIdentifier($param->var);
            $list[] = $type . ' ' . $name;
            $argInfo = new ArgInfo();
            $argInfo->name = $name;
            $argInfo->type = $type;
            if (isset($param->default)) {
                $this->functionDef->argCountRequired = count($list) - 1;
                $argInfo->default = $this->parseScalar($param->default);
            }
            $this->functionDef->argInfoList[] = $argInfo;
        }
        $this->functionDef->params = implode(', ', $list);
    }

    private function parseStmts(array $stmts): string
    {
        $lines = [];
        foreach ($stmts as $v) {
            $class = $v->getType();
            $this->beforeStmtLines = [];
            $this->afterStmtLines = [];
            $this->writeLog('Line ' . $this->getLine($v) . ': ' . $class);
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
                    $result = $this->parseFor($v);
                    $this->inLoop = false;
                    break;
                case 'Stmt_Foreach':
                    $this->inLoop = true;
                    $result = $this->parseForeach($v);
                    $this->inLoop = false;
                    break;
                case 'Stmt_Switch':
                    $this->inSwitch = true;
                    $result = $this->parseSwitch($v);
                    $this->inSwitch = false;
                    break;
                case 'Stmt_While':
                    $this->inLoop = true;
                    $result = $this->parseWhile($v);
                    $this->inLoop = false;
                    break;
                case 'Stmt_Do':
                    $this->inLoop = true;
                    $result = $this->parseDo($v);
                    $this->inLoop = false;
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
                    $result = 'continue;';
                    break;
                case 'Stmt_Nop':
                    $result = '// pass';
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
                default:
                    abort($v);
            }
            $lines = array_merge($lines, $this->beforeStmtLines);
            $this->beforeStmtLines = [];
            $lines[] = $result;
            $lines = array_merge($lines, $this->afterStmtLines);
            $this->afterStmtLines = [];
        }

        $code = '';
        foreach ($lines as $line) {
            $code .= $this->getIndent() . $line . PHP_EOL;
        }
        return $code;
    }

    private function parseExpr(mixed $expr)
    {
        $type = $expr->getType();
        $this->writeLog('Line ' . $this->getLine($expr) . ': ' . $type);
        if ($expr->getLine() === $this->debugLine) {
            var_dump($expr);
        }
        switch ($type) {
            case 'Expr_Isset':
                return $this->parseIsset($expr);
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
            // 减法
            case 'Expr_BinaryOp_Minus':
                return $this->parseBinaryOpMinus($expr);
            case 'Expr_Array':
                return $this->parseArray($expr);
            case self::EXPR_ARRAY_DIM_FETCH:
                return $this->parseArrayDimFetch($expr);
            case 'Expr_PropertyFetch':
                return $this->parsePropertyFetch($expr);
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
                return $this->parseMagicConstFile($expr);
            case 'Scalar_MagicConst_Dir':
                return $this->parseMagicConstDir($expr);
            case 'Scalar_MagicConst_Line':
                return $this->parseMagicConstLine($expr);
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
            case 'Expr_Exit':
                return $this->parseExit($expr);
            default:
                abort($expr);
        }
    }

    private function parseAssign(Node $v): string
    {
        $left = $v->var;
        $right = $v->expr;

        if ($left->getType() === self::EXPR_ARRAY_DIM_FETCH) {
            $array = $this->parseIdentifier($left->var);
            $code = '';
            // 这是 PHP 的初始化+赋值写法，需要先创建数组
            if (!$this->hasVar($array) and $left->var->getType() === self::EXPR_VARIABLE) {
                $this->addLocalVar($array, self::TYPE_ARRAY);
            }

            $value = $this->trimBrackets($this->parseExpr($right));
            if ($left->dim === null) {
                return $code . "$array.offsetSet(php::null, $value)";
            } else {
                $dim = $this->trimBrackets($this->parseIdentifier($left->dim));
                return $code . "$array.offsetSet($dim, $value)";
            }
        } elseif ($left->getType() === 'Expr_PropertyFetch') {
            $array = $this->parseIdentifier($left->var);
            $propName = $this->identifierToStr($left->name);
            return "$array.setProperty($propName, " . $this->trimBrackets($this->parseExpr($right)) . ")";
        } elseif ($right->getType() === 'Expr_Assign') {
            $chain[] = $left;
            while ($right->getType() === 'Expr_Assign') {
                $chain[] = $right->var;
                $right = $right->expr;
            }
            // 翻转赋值链
            $chain = array_reverse($chain);
            // 取最后一个变量作为第一行的 left，右值为表达式
            $left = array_shift($chain);
            $list[] = $this->parseFinallyAssign($left, $right);
            /**
             * 构造赋值链
             * a = b = c = d = (expr) -> d = (expr); c = d; b = c; a = b;
             */
            $right = $left;
            foreach ($chain as $left) {
                $list[] = $this->getIndent() . $this->parseFinallyAssign($left, $right);
                $right = $left;
            }
            return implode(";\n" . $this->getIndent(), $list);
        }
        return $this->parseFinallyAssign($left, $right);
    }

    private function parseFinallyAssign($left, $right): string
    {
        $var = $this->parseIdentifier($left);
        $expr = $this->parseExpr($right);

        if (!$this->hasVar($var)) {
            $type = $this->detectExprType($right);
            $this->addLocalVar($var, $type);
        }
        return $var . ' = ' . $this->convertExprType($expr, $this->detectExprType($left), $this->detectExprType($right));
    }

    private function parseEcho(mixed $v): string
    {
        foreach ($v->exprs as $expr) {
            $lines[] = 'php::echo(' . $this->parseExpr($expr) . ');';
        }
        return implode("\n" . $this->getIndent(), $lines);
    }

    private function isFloatStr(string $str)
    {
        return filter_var($str, FILTER_VALIDATE_FLOAT);
    }

    private function isIntStr(string $str)
    {
        return filter_var($str, FILTER_VALIDATE_INT);
    }

    private function isInternalFunction(string $fname): bool
    {
        return array_key_exists($fname, $this->internalFunctions);
    }

    private function isAssignOpConcat(string $op): bool
    {
        return $op === '.=';
    }

    private function isAssignOpPow(string $op): bool
    {
        return $op === '**=';
    }

    /**
     * 尽可能转为数字，优先级 浮点 > 整数 > 字符串
     */
    private function parseNumericIdentifier($expr)
    {
        if ($expr->getType() === 'Scalar_String') {
            if ($this->isFloatStr($expr->value)) {
               return floatval($expr->value);
            } else if ($this->isIntStr($expr->value)) {
                return intval($expr->value);
            } else if ($expr->value === '0') {
                return 0;
            }
        }
        return $this->parseIdentifier($expr);
    }

    private function parseBinaryOp($left, $right, $op): string
    {
        // 运算逻辑，优先转为数字
        $leftExpr = $this->parseNumericIdentifier($left);
        $rightExpr = $this->parseNumericIdentifier($right);

        $leftType = $this->detectExprType($left);
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

        return '(' . $leftExpr . ' ' . $op . ' ' . $rightExpr . ')';
    }

    private function parseBinaryOpPlus(mixed $expr): string
    {
        return $this->parseBinaryOp($expr->left, $expr->right, '+');
    }

    private function parseReturn(mixed $v): string
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
        }
        return 'return ' . $this->convertExprType($expr, $this->getReturnType(), $type);
    }

    private function parseBinaryOpMul(mixed $expr): string
    {
        return $this->parseBinaryOp($expr->left, $expr->right, '*');
    }

    private function addLocalVar(string $name, string $type): void
    {
        $this->localVars[$name] = $type;
    }

    private function addLiteralString(string $value): int
    {
        $index = $this->literalStringIndex++;
        $this->literalStrings[$value] = $index;
        return $index;
    }

    private function addGlobalVar(string $name, string $type): void
    {
        $this->globalVars[$name] = $type;
    }

    private function hasVar(string $name): bool
    {
        return $this->hasLocalVar($name) || $this->hasGlobalVar($name);
    }

    private function hasLocalVar(string $name): bool
    {
        return isset($this->localVars[$name]);
    }

    private function resetReturnType(string $type): void
    {
        $this->functionDef->returnType = $type;
        throw new ReturnTypeChanged;
    }

    private function detectVarType($var): string
    {
        $name = $this->parseIdentifier($var);
        return $this->getVarType($name);
    }

    private function detectExprType($expr): string
    {
        $exprType = $expr->getType();
        if ($this->debugLine === $expr->getLine()) {
            var_dump($exprType);
        }
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
                return 'php::Array';
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
                $leftType = $this->detectExprType($expr->left);
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
                return $this->detectFuncCallReturnType($expr);
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

    private function parseArray($node): string
    {
        $items = $node->items;
        // 优化代码风格，空数组直接返回{}，否则会产生一些空洞内容
        if (count($items) === 0) {
            return self::TYPE_ARRAY .'{}';
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
                    $key = "$key.toStdString()";
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

    private function parseType($type)
    {
        if ($type == null) {
            return self::TYPE_VAR;
        }
        if ($type instanceof NullableType) {
            return self::TYPE_VAR;
        }
        $name = $type->name;
        switch ($name) {
            case 'int':
                return self::TYPE_INT;
            case 'array':
                return 'php::Array';
            case 'float':
                return self::TYPE_FLOAT;
            case 'bool':
                return self::TYPE_BOOL;
            default:
                abort($type);
        }
    }

    private function parseIncludes(): string
    {
        $list = [
            $this->phpxDir . '/include',
            './',
        ];
        $out = '$(php-config --includes) ';
        foreach ($list as $li) {
            $out .= '-I ' . $li . ' ';
        }
        return $out;
    }

    private function parseLdflags(): string
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

    private function parseLibs()
    {
        $list = [
            'phpx',
            'php',
        ];
        $out = '';
        foreach ($list as $li) {
            $out .= '-l' . $li . ' ';
        }
        return $out;
    }

    private function addCompilationOption(string &$cmd): void
    {
        $cmd .= $this->parseIncludes();
        $cmd .= ' -O' . $this->optimizeLevel;
        $cmd .= ' -g';
        if ($this->climate->arguments->defined('profile')) {
            $cmd .= ' -lprofiler';
            $cmd .= ' -DPPROF_ON=1';
        }
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
        $this->genGlobalVars();
        if ($this->climate->arguments->defined('output')) {
            $targetFile = $this->climate->arguments->get('output');
        }
        $objectFile = implode(' ', $objectFiles);
        $cmd = $this->cppCompiler . ' main.cc global_vars.cc ' . $objectFile . ' -o ' . $targetFile . ' ' . $this->parseLdflags() . $this->parseLibs();
        $this->addCompilationOption($cmd);
        $this->climate->comment($cmd);
        shell_exec($cmd);
    }

    private function parseBinaryOpConcat(mixed $expr): string
    {
        $left = $this->parseIdentifier($expr->left);
        $right = $this->parseIdentifier($expr->right);

        return 'php::concat(' . $left . ', ' . $right . ')';
    }

    private function parseFor(mixed $v): string
    {
        $init = $v->init;
        $cond = $v->cond;
        $loop = $v->loop;
        $stmts = $v->stmts;
        $code = '';

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
                if (!$this->hasGlobalVar($name)) {
                    $this->fatalError($left, 'Cannot assign to global variable in for loop');
                }
                if (!$this->hasVar($name)) {
                    $type = $this->detectExprType($expr->expr);
                    $this->addLocalVar($name, $type);
                }
            }
            $list_cond[] = $this->parseExpr($expr);
        }

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

    private function parseBinaryOpSmaller(mixed $expr): string
    {
        return $this->convertBoolExpr($this->parseBinaryOp($expr->left, $expr->right, '<'));
    }

    private function parsePreInc(mixed $expr): string
    {
        return '++' . $this->parseIdentifier($expr->var);
    }

    private function removeAssignOp(string $op): string
    {
        return str_replace('=', '', $op);
    }

    private function parseAssignOp(mixed $node, string $op): string
    {
        $var = $this->parseIdentifier($node->var);
        $expr = $this->parseIdentifier($node->expr);
        $leftExprType = $node->var->getType();
        if ($leftExprType === self::EXPR_VARIABLE) {
            if (!$this->hasVar($var)) {
                $this->fatalError($node->var, 'Cannot assign to undefined variable');
            }
            $type = $this->detectVarType($node->var);
            $rightExprStr = $this->convertExprType($expr, $type, $this->detectExprType($node->expr));
            if ($this->isAssignOpConcat($op)) {
                return $var . '.append(' . $rightExprStr . ')';
            } elseif ($this->isAssignOpPow($op)) {
                $powExpr = 'php::call(php::pow, {' . $var . ', ' . $rightExprStr . '})';
                return $var . ' = ' . $this->convertVarType($var, $powExpr);
            } else {
                return $var . ' ' . $op . ' ' . $rightExprStr;
            }
        } elseif ($leftExprType === self::EXPR_ARRAY_DIM_FETCH) {
            /**
             * $count[$r] -= 1;
             * 需要转为下面语句：
             * $tmp_var = $count[$r] - 1;
             * $count[$r] = $tmp_var;
             */
            $type = $this->detectVarType($node->var);
            $rightType = $this->detectExprType($node->expr);
            $tmpVar = $this->genTmpVarName();
            $this->addLocalVar($tmpVar, $rightType);
            $dim = $this->parseIdentifier($node->var->dim);
            $binaryOp = $this->removeAssignOp($op);

            if ($binaryOp === '.') {
                $this->beforeStmtLines[] = "$tmpVar = php::concat(" .
                    $this->convertVarType($tmpVar, $var) . ', ' .
                    $this->convertExprType($expr, $type, $rightType) . ');';
            } else {
                $this->beforeStmtLines[] = "$tmpVar = " .
                    $this->convertVarType($tmpVar, $var) . ' ' .
                    $binaryOp . ' ' .
                    $this->convertExprType($expr, $type, $rightType) . ';';
            }
            return $this->parseArrayDimStore($node->var->var, $dim, $tmpVar);
        } else {
            return $var . ' ' . $op . ' (' . $expr . ')';
        }

        // TODO 属性设置
    }

    private function parseAssignOpConcat(mixed $expr): string
    {
        return $this->parseAssignOp($expr, '.=');
    }

    private function parseAssignOpPlus(mixed $expr): string
    {
        return $this->parseAssignOp($expr, '+=');
    }

    private function parseAssignOpMinus(mixed $expr): string
    {
        return $this->parseAssignOp($expr, '-=');
    }

    private function parseAssignOpMod(mixed $expr): string
    {
        return $this->parseAssignOp($expr, '%=');
    }

    private function parseAssignOpMul(mixed $expr): string
    {
        return $this->parseAssignOp($expr, '*=');
    }

    private function parseAssignOpDiv(mixed $expr): string
    {
        return $this->parseAssignOp($expr, '/=');
    }

    private function parseAssignOpBitwiseAnd(mixed $expr): string
    {
        return $this->parseAssignOp($expr, '&=');
    }

    private function parseAssignOpPow(mixed $expr): string
    {
        return $this->parseAssignOp($expr, '**=');
    }

    private function fatalError(Node $node, string $msg): void
    {
        $this->climate->red("Fatal error: $msg in {$this->file}:" . $node->getStartLine());
        debug_print_backtrace();
        exit(255);
    }

    private function parseArrayDimFetch($node): string
    {
        $var = $this->parseIdentifier($node->var);
        if ($node->dim === null) {
            $this->fatalError($node, 'Unsupported operand types: null + array');
        }
        $dim = $this->parseIdentifier($node->dim);
        return $var . '.offsetGet(' . $this->trimBrackets($dim) . ')';
    }

    private function parseArrayDimStore($array, $dim, $var): string
    {
        $id = $this->parseIdentifier($array);
        return $id . '.offsetSet(' . $this->trimBrackets($dim) . ', ' . $this->trimBrackets($var) . ')';
    }

    private function parseBinaryOpShiftLeft($expr): string
    {
        return $this->parseBinaryOp($expr->left, $expr->right, '<<');
    }

    private function parseBinaryOpShiftRight($expr): string
    {
        return $this->parseBinaryOp($expr->left, $expr->right, '>>');
    }

    private function parseBinaryOpMod(mixed $expr): string
    {
        return $this->parseBinaryOp($expr->left, $expr->right, '%');
    }

    private function parseFuncCall(mixed $expr): string
    {
        if ($expr->name->getType() === self::EXPR_VARIABLE) {
            $fn = $this->parseIdentifier($expr->name);
            $name = '';
        } elseif ($expr->name->getType() === 'Name') {
            $name = $this->parseIdentifier($expr->name);
            if ($this->isNativeFunction($name)) {
                return self::PREFIX . $name . '(' . $this->parseCallArgs($expr->args, $name) . ')';
            }
            if ($this->isInternalFunction($name)) {
                $fn = 'php::' . $name;
            } else {
                $fn = '"' . $name . '"';
            }
            if ($name === 'strlen' or $name === 'sizeof' or $name === 'count') {
                return 'php::len(' . $this->parseIdentifier($expr->args[0]->value) . ')';
            }
            if (count($expr->args) == 1) {
                switch ($name) {
                    case 'intval':
                        return $this->convertIntExpr($this->parseExpr($expr->args[0]->value));
                    case 'floatval':
                        return $this->convertFloatExpr($this->parseExpr($expr->args[0]->value));
                    case 'boolval':
                        return $this->convertBoolExpr($this->parseExpr($expr->args[0]->value));
                    default:
                        break;
                }
            }
        } else {
            $tmpVar = $this->genTmpVarName();
            $this->addLocalVar($tmpVar, self::TYPE_VAR);
            $this->beforeStmtLines[] = $tmpVar . ' = ' . $this->parseExpr($expr->name) . ';';
            $fn = $tmpVar;
            $name = '';
        }
        if (empty($expr->args)) {
            return 'php::call(' . $fn . ')';
        } else {
            return 'php::call(' . $fn . ', {' . $this->parseCallArgs($expr->args, $name) . '})';
        }
    }

    private function parseCallArgs(array $args, string $funcName = '', string $className = ''): string
    {
        if (!$className) {
            $nativeFunction = $this->isNativeFunction($funcName);
        } else {
            $nativeFunction = false;
        }

        $list_args = [];
        foreach ($args as $i => $arg) {
            if ($arg->value->getType() === self::EXPR_VARIABLE) {
                $name = $this->parseIdentifier($arg->value);
                // 调用了不存在的变量，可能是引用
                if (!$this->hasVar($name)) {
                    $this->addLocalVar($name, self::TYPE_REF);
                    $this->beforeStmtLines[] = $name . ' = php::newReference();';
                } else if (!$nativeFunction and $funcName) {
                    $funcArg = Reflection::getFunctionParameter($funcName, $i);
                    // 需要引用类型的参数，使用临时变量作为引用，并替换掉实际的参数
                    if ($funcArg and $funcArg->isPassedByReference()) {
                        $tmpVar = $this->genTmpVarName();
                        $this->addLocalVar($tmpVar, self::TYPE_REF);
                        $this->beforeStmtLines[] = $tmpVar . ' = ' . $this->parseExpr($arg->value) . '.toReference();';
                        $this->afterStmtLines[] = $name . ' = *' . $tmpVar . ';';
                        $list_args[] = $tmpVar;
                        continue;
                    }
                }
            }
            // 不支持变长参数展开的语法，例如：array_merge(...$arr)
            if ($arg->unpack) {
                $this->fatalError($arg, "The syntax for variable parameter expansion is not supported");
            }
            if ($nativeFunction) {
                $argInfo = $this->getArgInfo($funcName, $i);
                $list_args[] = $this->getTypeConvertedArg($arg, $argInfo);
            } else {
                $list_args[] = $this->parseArg($arg);
            }
        }
        return implode(', ', $list_args);
    }

    private function parseArg($arg): string
    {
        return $this->parseIdentifier($arg->value);
    }

    private function parsePostInc($expr): string
    {
        return $this->parseIdentifier($expr->var) . '++';
    }

    private function parsePostDec($expr): string
    {
        return $this->parseIdentifier($expr->var) . '--';
    }

    private function parseTernary(mixed $expr): string
    {
        $cond = $expr->cond;
        $if = $expr->if;
        $else = $expr->else;
        return '(' . $this->parseExpr($cond) . ') ? (' . $this->parseExpr($if) . ') : (' . $this->parseExpr($else) . ')';
    }

    private function parseBinaryOpGreater(mixed $expr): string
    {
        return $this->convertBoolExpr($this->parseBinaryOp($expr->left, $expr->right, '>'));
    }

    private function parseBinaryOpPow(mixed $expr): string
    {
        $left = $this->parseIdentifier($expr->left);
        $right = $this->parseIdentifier($expr->right);

        return 'php::pow(' . $left . ', ' . $right . ')';
    }

    private function parsePreDec(mixed $expr): string
    {
        return '--' . $this->parseIdentifier($expr->var);
    }

    private function parseBinaryOpBitwiseAnd(mixed $expr): string
    {
        return $this->parseBinaryOp($expr->left, $expr->right, '&');
    }

    private function parseBinaryOpBitwiseOr(mixed $expr): string
    {
        return $this->parseBinaryOp($expr->left, $expr->right, '|');
    }

    private function parseBinaryOpBitwiseXor(mixed $expr): string
    {
        return $this->parseBinaryOp($expr->left, $expr->right, '^');
    }

    private function parseBitwiseNot(mixed $expr): string
    {
        $var = $this->parseIdentifier($expr->expr);
        return '~' . $var;
    }

    private function parseIf(mixed $v): string
    {
        $cond = $this->parseExpr($v->cond);

        $code = 'if (' . $cond . ') {' . PHP_EOL;
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

    private function parseBinaryOpEqual(mixed $expr): string
    {
        return 'php::equals(' . $this->parseExpr($expr->left) . ', ' . $this->parseExpr($expr->right) . ')';
    }

    private function parseBinaryOpNotEqual(mixed $expr): string
    {
        return '!php::equals(' . $this->parseExpr($expr->left) . ', ' . $this->parseExpr($expr->right) . ')';
    }

    /**
     * 逻辑比较的运算，必须返回 bool 类型
     */
    private function parseBinaryOpLogicalAnd(Node $expr): string
    {
        return $this->convertBoolExpr($this->parseBinaryOp($expr->left, $expr->right, '&&'));
    }

    private function parseBinaryOpLogicalOr(Node $expr): string
    {
        return $this->convertBoolExpr($this->parseBinaryOp($expr->left, $expr->right, '||'));
    }

    private function parseBinaryOpLogicalXor(Node $expr): string
    {
        return $this->convertBoolExpr($this->parseBinaryOp($expr->left, $expr->right, '^'));
    }

    private function parseBooleanNot(Node $expr): string
    {
        $expr = $this->parseExpr($expr->expr);
        return '!' . $expr;
    }

    private function parseWhile(Node $v): string
    {
        $cond = $this->parseExpr($v->cond);
        $stmts = $v->stmts;

        $code = 'while (' . $cond . ') {' . PHP_EOL;
        $this->indentLevel++;
        $code .= $this->parseStmts($stmts);
        $this->indentLevel--;
        $code .= $this->getIndent() . '}' . PHP_EOL;

        return $code;
    }

    function isClosedCall($expr, $call): bool
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
        $length = strlen($expr);

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

    private function trimBrackets(string $str): string
    {
        if ($this->isClosedCall($str, '')) {
            return substr($str, 1, -1);
        }
        return $str;
    }

    private function convertIntExpr(string $expr): string
    {
        if (!$this->isClosedCall($expr, 'php::to_int')) {
            return 'php::to_int(' . $this->trimBrackets($expr) . ')';
        }
        return $expr;
    }

    private function convertFloatExpr(string $expr): string
    {
        if (!$this->isClosedCall($expr, 'php::to_float')) {
            return 'php::to_float(' . $this->trimBrackets($expr) . ')';
        }
        return $expr;
    }

    public function stop(string $string): void
    {
        $this->climate->red($string . "\n");
        exit(1);
    }

    private function convertStringExpr(string $expr): string
    {
        if (!$this->isClosedCall($expr, 'php::to_string')) {
            return 'php::to_string(' . $this->trimBrackets($expr) . ')';
        }
        return $expr;
    }

    private function convertObjectExpr(string $expr): string
    {
        if (!$this->isClosedCall($expr, 'php::to_object')) {
            return 'php::to_object(' . $this->trimBrackets($expr) . ')';
        }
        return $expr;
    }

    private function convertArrayExpr(string $expr): string
    {
        if (!$this->isClosedCall($expr, 'php::to_array')) {
            return 'php::to_array(' . $this->trimBrackets($expr) . ')';
        }
        return $expr;
    }

    private function convertBoolExpr(string $expr): string
    {
        if (!$this->isClosedCall($expr, 'php::to_bool')) {
            return 'php::to_bool(' . $this->trimBrackets($expr) . ')';
        }
        return $expr;
    }

    private function parseBinaryOpSmallerOrEqual(Node $expr): string
    {
        return $this->convertBoolExpr($this->parseBinaryOp($expr->left, $expr->right, '<='));
    }

    private function parseBinaryOpGreaterOrEqual(Node $expr): string
    {
        return $this->convertBoolExpr($this->parseBinaryOp($expr->left, $expr->right, '>='));
    }

    private function parsePrint(Node $expr): string
    {
        return 'php::echo(' . $this->parseExpr($expr->expr) . ')';
    }

    private function parseDo(Node $v): string
    {
        $stmts = $v->stmts;
        $cond = $this->parseExpr($v->cond);

        $code = 'do {' . PHP_EOL;
        $this->indentLevel++;
        $code .= $this->parseStmts($stmts);
        $this->indentLevel--;
        $code .= $this->getIndent() . '} while (' . $cond . ');' . PHP_EOL;

        return $code;
    }

    private function parseBinaryOpIdentical(Node $expr): string
    {
        $left = $this->parseIdentifier($expr->left);
        $right = $this->parseIdentifier($expr->right);

        if ($right === 'nullptr') {
            return $left . '.isNull()';
        }

        return 'php::same(' . $left . ', ' . $right . ')';
    }

    private function parseBinaryOpSpaceship(mixed $expr): string
    {
        $left = $this->parseIdentifier($expr->left);
        $right = $this->parseIdentifier($expr->right);
        return 'php::compare(' . $left . ', ' . $right . ')';
    }

    private function parseBinaryOpNotIdentical(mixed $expr): string
    {
        return '!(' . $this->parseBinaryOpIdentical($expr) . ')';
    }

    private function parseNew(Node $expr): string
    {
        $className = $this->parseIdentifier($expr->class);
        $args = $expr->args;
        if (empty($args)) {
            return 'php::newObject("' . $className . '")';
        } else {
            return 'php::newObject("' . $className . '", ' . $this->parseCallArgs($args) . ')';
        }
    }

    private function parseClone(Node $expr): string
    {
        $var = $this->parseIdentifier($expr->expr);
        return $var . '.clone()';
    }

    private function parseInstanceof(Node $expr): string
    {
        $var = $this->parseIdentifier($expr->expr);
        return $var . '.instanceOf(' . $this->identifierToStr($expr->class) . ')';
    }

    private function parseNamespaceDef(Node $node): string
    {
        $this->namespace = $this->parseIdentifier($node->name);
        $code = '';
        foreach($node->stmts as $v2) {
            $type2 = $v2->getType();
            switch ($type2) {
                case 'Stmt_Class':
                    $code .= $this->parseClassDef($v2);
                    break;
                case 'Stmt_Function':
                    $code .= $this->parseFunctionDef($v2) . PHP_EOL;
                    break;
                case 'Stmt_Use':
                    foreach ($v2->uses as $use) {
                        $this->uses[] = $use->name->toString();
                    }
                    break;
                default:
                    abort($v2);
            }
        }
        $this->namespace = '';
        $this->uses = [];
        return $code;
    }

    private function parseClassDef(Node $v): string
    {
        $this->class = $this->parseIdentifier($v->name);
        $code = '';
        foreach($v->stmts as $v) {
            $type = $v->getType();
            switch ($type) {
                case 'Stmt_ClassConst':
                case 'Stmt_Property':
                    break;
                case 'Stmt_ClassMethod':
                    $code .= $this->parseFunctionDef($v) . PHP_EOL;
                    break;
                default:
                    abort($v);
            }
        }
        $this->class = '';
        return $code;
    }

    private function parseCastInt(Node $node): string
    {
        return $this->convertIntExpr($this->parseExpr($node->expr));
    }

    private function parseCastString(mixed $node): string
    {
        return $this->convertStringExpr($this->parseExpr($node->expr));
    }

    private function parseCastBool(mixed $node): string
    {
        return $this->convertBoolExpr($this->parseExpr($node->expr));
    }

    private function parseCastObject(mixed $node): string
    {
        return $this->convertObjectExpr($this->parseExpr($node->expr));
    }

    private function parseConstFetch(Node $expr): string
    {
        $name = $this->parseIdentifier($expr->name);
        if ($name === 'null') {
            return 'nullptr';
        } elseif ($name === 'true') {
            return 'true';
        } elseif ($name === 'false') {
            return 'false';
        }
        return 'php::constant("' . $name . '")';
    }

    private function parseUnaryMinus(Node $expr): string
    {
        $code = $this->parseExpr($expr->expr);
        return '-' . $code;
    }

    private function parseUnaryPlus(mixed $expr)
    {
        return $this->parseExpr($expr->expr);
    }

    private function parseBinaryOpDiv(Node $expr): string
    {
        $left = $this->parseIdentifier($expr->left);
        $right = $this->parseIdentifier($expr->right);
        return $left . ' / (' . $right . ')';
    }

    private function parseBinaryOpMinus(Node $expr): string
    {
        return $this->parseBinaryOp($expr->left, $expr->right, '-');
    }

    private function parseInterpolatedString(Node $expr): string
    {
        $parts = $expr->parts;
        $list = [];
        foreach ($parts as $part) {
            $list[] = $this->parseExpr($part);
        }
        return 'php::concat({' . implode(', ', $list) . '})';
    }

    private function escapeString($str): string
    {
        return addcslashes($str, "\\\"\n\r\t\v\f\0\x01..\x1f\x7f..\xff");
    }

    private function escapeVarName(string $name): string
    {
        if (in_array($name, $this->reservedNames)) {
            return '_php__var__' . $name;
        } else {
            return $name;
        }
    }

    private function unescapeVarName(string $name): string
    {
        return str_replace('_php__var__', '', $name);
    }

    private function parseInterpolatedStringPart(Node $expr): string
    {
        return '"' . $this->escapeString($expr->value) . '"';
    }

    private function parseGlobal(Node $v): string
    {
        foreach ($v->vars as $v) {
            $name = $this->escapeVarName($v->name);
            if (!$this->hasGlobalVar($name)) {
                $this->addGlobalVar($name, self::TYPE_VAR);
            }
        }
        return '';
    }

    private function getArgInfo(string $funcName, int $index): ArgInfo
    {
        $funcDef = $this->nativeFunctions[$funcName];
        return $funcDef->argInfoList[$index];
    }

    private function getReturnType(): string
    {
        return $this->functionDef->returnType;
    }

    private function getTypeConvertedArg($arg, $argInfo): string
    {
        $expr = $this->parseArg($arg);
        $type = $this->detectExprType($arg->value);
        return $this->convertExprType($expr, $argInfo->type, $type);
    }

    private function convertExprType(string $expr, $leftType, $rightType): string
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

    private function parseExit(Node $node): string
    {
        return 'php::exit(' . $this->parseIdentifier($node->expr) . ')';
    }

    private function parseUnset(Node $node): string
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
                $propName = $this->parseIdentifier($var->name);
                $lines[] = $object . '.unsetProperty("' . $propName . '");';
            } elseif ($type === self::EXPR_VARIABLE) {
                $name = $this->parseIdentifier($var);
                $lines[] = "$name.unset();";
            } else {
                abort($var);
            }
        }
        return implode(PHP_EOL . $this->getIndent(), $lines);
    }

    private function parsePropertyFetch(Node $expr): string
    {
        return $this->convertToObject($expr->var) . '.getProperty("' . $this->parseIdentifier($expr->name) . '")';
    }

    private function parseAssignOpShiftRight(Node $node): string
    {
        $var = $this->parseIdentifier($node->var);
        return $var . ' >>= ' . $this->parseIdentifier($node->expr);
    }

    private function parseAssignOpBitwiseXor(Node $node): string
    {
        $var = $this->parseIdentifier($node->var);
        return $var . ' ^= ' . $this->parseIdentifier($node->expr);
    }

    private function parseMagicConstFile(mixed $expr): string
    {
        return '"' . $this->escapeString($this->file) . '"';
    }

    private function parseForeach(Node $node): string
    {
        if ($node->byRef) {
            $this->fatalError($node, 'Cannot use & with foreach');
        }
        if ($node->keyVar) {
            $keyVar = $this->parseIdentifier($node->keyVar);
        }
        $valueVar = $this->parseIdentifier($node->valueVar);

        $iteratorVar = $this->genTmpVarName();

        $stmts = $node->stmts;
        $code = '';

        $expr = $this->parseIdentifier($node->expr);
        $code .= self::TYPE_ARRAY . " $iteratorVar = " . $expr . ';' . PHP_EOL;

        $code .= 'for (auto iter = ' . $iteratorVar . '.begin(); iter != ' . $iteratorVar . '.end(); ++iter) {' . PHP_EOL;
        $this->indentLevel++;
        if ($node->keyVar) {
            // foreach 的 key/value 不能与全局变量同名
            if ($this->hasGlobalVar($keyVar)) {
                $this->fatalError($node->keyVar, 'Cannot redefine key variable: ' . $this->unescapeVarName($keyVar));
            }
            $code .= $this->getIndent() . ' ' . $keyVar . ' = iter.key();' . PHP_EOL;
            if (!$this->hasVar($keyVar)) {
                $this->addLocalVar($keyVar, self::TYPE_VAR);
            }
        }

        if ($node->valueVar->getType() == self::EXPR_ARRAY_DIM_FETCH) {
            $array = $this->parseIdentifier($node->valueVar->var);
            if (!$this->hasVar($array) or $node->valueVar->dim === null) {
                abort($node->valueVar);
            }
            $dim = $this->parseIdentifier($node->valueVar->dim);
            $code .= $this->getIndent() . "$array.offsetSet($dim, iter.value());";
        } else {
            // foreach 的 key/value 不能与全局变量同名
            if ($this->hasGlobalVar($valueVar)) {
                $this->fatalError($node->valueVar, 'Cannot redefine value variable: ' . $this->unescapeVarName($valueVar));
            }
            $code .= $this->getIndent() . ' ' . $valueVar . ' = iter.value();' . PHP_EOL;
            if (!$this->hasVar($valueVar)) {
                $this->addLocalVar($valueVar, self::TYPE_VAR);
            }
        }
        $code .= $this->parseStmts($stmts);
        $this->indentLevel--;

        $code .= $this->getIndent() . '}';

        return $code;
    }

    private function formatCppCode(string $file): void
    {
        $cmd = 'cd ' . $this->rootPath . ' && clang-format -i ' . $file;
        $this->writeLog('formatting ' . $file . '...');
        $this->writeLog($cmd);
        shell_exec($cmd);
    }

    private function genTmpVarName(): string
    {
        return 'tmp_var_' . $this->tmpVarIndex++;
    }

    private function detectConstType($expr): string
    {
        $name = $this->parseIdentifier($expr->name);
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

    private function parseSwitch(mixed $v): string
    {
        $cond = $v->cond;
        $tmp_var = $this->genTmpVarName();
        $type = $this->detectExprType($cond);
        $var_def = $type . ' ' . $tmp_var . ' = ' . $this->parseExpr($cond) . ';' . PHP_EOL;

        // 保存作用域，switch 可能会解析失败，在这个过程中会增加变量，需重置
        $localVars = $this->localVars;

        if ($type === self::TYPE_INT or $type === self::TYPE_FLOAT) {
            $code = 'switch (' . $tmp_var . ') {' . PHP_EOL;
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
                $code .= $this->getIndent() . 'if (' . $tmp_var.'=='. $this->parseIdentifier($case->cond) . ') {' . PHP_EOL;
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

    private function parseStatic(mixed $v): string
    {
        $list = [];
        foreach ($v->vars as $var) {
            if ($var->default) {
                $type = $this->detectExprType($var->default);
                $list[] = 'static ' . $type . ' ' . $this->parseIdentifier($var->var) . ' = ' . $this->parseIdentifier($var->default) . ';';
            } else {
                $list[] = 'static ' . self::TYPE_VAR . ' ' . $this->parseIdentifier($var->var) . ';';
            }
        }
        return implode(PHP_EOL . $this->getIndent(), $list);
    }

    private function parseEval(mixed $expr): string
    {
        return 'php::eval(' . $this->parseIdentifier($expr->expr) . ')';
    }

    private function parseInclude(mixed $expr): string
    {
        return 'php::include(' . $this->parseIdentifier($expr->expr) . ')';
    }

    private function parseMagicConstDir(mixed $expr): string
    {
        return '"' . $this->escapeString($this->dir) . '"';
    }

    private function parseBreak(mixed $v): string
    {
        if (!$this->inLoop and !$this->inSwitch) {
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

    private function parseScalarFloat(Node $expr): string
    {
        $value = $expr->value;

        if (is_nan($value)) {
            return self::VALUE_NAN;
        } elseif (is_infinite($value)) {
            return $value > 0 ? self::VALUE_INF : '-' . self::VALUE_INF;
        } else if (floor($value) == $value && abs($value) < 1e15) {
            return number_format($value, 1, '.', '');
        } else {
            return sprintf('%.' . $this->floatPrecision . 'g', $value);
        }
    }

    private function parseIsset(mixed $expr)
    {
        $vars = $expr->vars;
        foreach($vars as $var) {
            $type = $var->getType();
            if ($type === self::EXPR_VARIABLE) {
                return $this->hasVar($var->name) ? 'true' : 'false';
            } elseif ($type === self::EXPR_ARRAY_DIM_FETCH) {
                return $this->parseIdentifier($var->var) . ".offsetExists(" . $this->parseIdentifier($var->dim) . ')';
            } else {
                abort($var);
            }
        }
    }

    private function parseCastArray(mixed $expr): string
    {
        return $this->convertArrayExpr($this->parseIdentifier($expr->expr));
    }

    private function hasGlobalVar($name): bool
    {
        return array_key_exists($name, $this->globalVars);
    }

    private function genGlobalVars(): void
    {
        $file = 'global_vars.cc';
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

        $code .= PHP_EOL;
        $code .= 'void ' . self::PREFIX . 'unset_all_global_vars() {' . PHP_EOL;
        $lines = [];
        $this->indentLevel++;
        foreach ($this->globalVars as $name => $type) {
            $lines[] = $this->getIndent() . $name . '.unset();';
        }
        $this->indentLevel--;
        $code .= implode(PHP_EOL, $lines) . PHP_EOL;
        $code .= '}' . PHP_EOL;

        file_put_contents($file, $code);
    }

    private function parseCastDouble(mixed $expr): string
    {
        return $this->convertFloatExpr($this->parseIdentifier($expr->expr));
    }

    private function detectFuncCallReturnType($expr): string
    {
        $name = $expr->name;
        $returnType = Reflection::getFunctionReturnType($name);
        if ($returnType) {
            return $this->getTypeFromZendType($returnType);
        } else {
            return self::TYPE_VAR;
        }
    }

    private function convertVarType($var, $expr): string
    {
        if ($this->hasVar($var)) {
            $type = $this->getVarType($var);
            if ($type === self::TYPE_FLOAT) {
                return $this->convertFloatExpr($expr);
            }
            if ($type === self::TYPE_INT) {
                return $this->convertIntExpr($expr);
            }
            if ($type === self::TYPE_BOOL) {
                return $this->convertBoolExpr($expr);
            }
        }
        return $expr;
    }

    private function convertToObject(Node $object): string
    {
        $id = $this->parseIdentifier($object);
        if (!$this->hasVar($id)) {
            $this->addLocalVar($id, self::TYPE_OBJECT);
            return $id;
        }

        $type = $this->getVarType($id);
        if ($type === self::TYPE_OBJECT) {
            return $id;
        }

        if (isset($this->objectWrappers[$id])) {
            return $this->objectWrappers[$id];
        }

        $tmpVar = $this->genTmpVarName();
        $this->addLocalVar($tmpVar, self::TYPE_OBJECT);
        $this->beforeStmtLines[] = $this->getIndent() . $tmpVar . ' = ' . $id . ';';
        $this->objectWrappers[$id] = $tmpVar;
        return $tmpVar;
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
        file_put_contents($file, $code);
    }

    private function parseAssignRef(mixed $expr)
    {
        if ($expr->var->getType() === self::EXPR_VARIABLE) {
            if ($expr->expr->getType() === self::EXPR_VARIABLE) {
                return $this->parseIdentifier($expr->var) . ' = &' . $this->parseIdentifier($expr->expr);
            } elseif ($expr->expr->getType() === self::EXPR_ARRAY_DIM_FETCH) {
                return $this->parseIdentifier($expr->var) . ' = ' . $this->parseIdentifier($expr->expr);
            }
        }
    }

    private function parseMethodCall(mixed $expr): string
    {
        $object = $this->convertToObject($expr->var);
        $method = $this->parseIdentifier($expr->name);
        if (empty($expr->args)) {
            return $object . '.exec("' . $method . '")';
        } else {
            return $object . '.exec("' . $method . '", ' . $this->parseCallArgs($expr->args) . ')';
        }
    }

    private function identifierToStr(Node $node, bool $require = true): string
    {
        $id = $this->parseIdentifier($node);
        if ($node->getType() === self::EXPR_VARIABLE) {
            if ($require) {
                $this->requireVar($node, $id);
            }
            return $id;
        } else {
            return '"'. $id . '"';
        }
    }

    private function requireVar($node, string $var): void
    {
        if (!$this->hasVar($var)) {
            $this->fatalError($node, 'The variable `' . $var . '` is not defined');
        }
    }

    private function parseStaticCall(mixed $expr): string
    {
        if ($expr->class->getType() === self::EXPR_VARIABLE or $expr->name->getType() === self::EXPR_VARIABLE) {
            $fn = 'php::concat({' . $this->identifierToStr($expr->class). ', "::", ' . $this->identifierToStr($expr->name) . '})';
        } else {
            $class = $this->parseIdentifier($expr->class);
            $method = $this->parseIdentifier($expr->name);
            $fn = '"'. $class . '::' . $method . '"';
        }
        if (empty($expr->args)) {
            return 'php::call(' . $fn . ')';
        } else {
            return 'php::call(' . $fn . ', {' . $this->parseCallArgs($expr->args) . '})';
        }
    }

    private function parseMagicConstLine(Node $expr): int
    {
        return $expr->getStartLine();
    }

    private function parseThrow(mixed $expr): string
    {
        if ($expr->expr->getType() != self::EXPR_VARIABLE and $expr->expr->getType() != self::EXPR_NEW) {
            $this->fatalError($expr, 'The throw statement only accepts a object variable');
        }
        return 'php::throwException(' . $this->parseIdentifier($expr->expr). ')';
    }

    private function parseTryCatch(mixed $v): string
    {
        $code = 'zend_try {';
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

        $code .= 'zend_catch {' . PHP_EOL;
        if ($catches) {
            $code .= $this->getIndent() . $exVar . ' = php::catchException();' . PHP_EOL;
            $this->indentLevel++;
            foreach ($catches as $catch) {
                $code .= $this->parseCatch($catch, $exVar);
            }
            $this->indentLevel--;
        }
        $code .= '}' . PHP_EOL . 'zend_end_try();' . PHP_EOL;
        if ($finally) {
            $code .= $this->parseStmts($finally->stmts);
            $code .= PHP_EOL;
            $code .= 'if (' . $exVar . ') {' . PHP_EOL . $this->getIndent() . 'php::throwException(' . $exVar . ');' . PHP_EOL . $this->getIndent() . '}';
        }
        return $code;
    }

    private function parseCatch(mixed $catch, string $exVar): string
    {
        $types = $catch->types;
        $var = $this->parseIdentifier($catch->var);
        if (!$this->hasVar($var)) {
            $this->addLocalVar($var, self::TYPE_OBJECT);
        }
        $code = $this->getIndent() . $var . ' = ' . $exVar . ';' . PHP_EOL;

        $code .= $this->getIndent() . 'if (' . $var . ' && ';
        foreach ($types as $type) {
            $code .= 'php::instanceOf(' . $var . ', "' . $this->parseIdentifier($type) . '")';
        }

        $code .= ') {' . PHP_EOL;
        $this->indentLevel++;
        $code .= $this->parseStmts($catch->stmts);
        $code .= $this->getIndent() . "$exVar.unset();" . PHP_EOL;
        $this->indentLevel--;
        $code .= $this->getIndent() . '}';

        return $code;
    }

    private function parseShellExec(mixed $expr): string
    {
        return 'php::call("shell_exec", {' . $this->parseInterpolatedString($expr) . '})';
    }

    private function parseGoto(Node $v): string
    {
        $this->fatalError($v, 'Goto statement is not supported');
        return 'goto ' . $v->name->name . ';';
    }

    private function parseLabel(Node $v): string
    {
        $this->fatalError($v, 'Label statement is not supported');
        return $v->name->name . ':';
    }
}
