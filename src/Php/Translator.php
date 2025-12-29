<?php

namespace PhpAot\Php;

use PhpAot\Php\Visitor;
use PhpParser\Node;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Error;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter;

class Translator extends \PhpAot\Core\Translator
{
    const string TYPE_VAR = 'php::Var';
    const string TYPE_BOOL = 'php::Bool';
    const string TYPE_INT = 'php::Int';
    const string TYPE_FLOAT = 'php::Float';
    const string TYPE_OBJECT = 'php::Object';
    const string TYPE_ARRAY = 'php::Array';
    const string TYPE_STR = 'php::Str';

    private string $phpxDir = '~/workspace/projects/phpx';
    protected string $lang = 'PHP';
    private array $scope = [];
    /**
     * 插入到语句之前
     * @var array
     */
    private array $beforeStmtLines = [];
    private int $tmpVarIndex = 0;
    // 需要插入到函数头部的变量定义代码
    private array $definitions = [];
    private array $zendTypeMap = [
        'int' => self::TYPE_INT,
        'float' => self::TYPE_FLOAT,
        'bool' => self::TYPE_BOOL,
    ];

    private array $headers = [
        'phpx.h',
    ];

    private array $internalFunctions = [];

    private int $optimizeLevel = 5;
    private bool $verbose = false;
    private string $file;
    private string $dir;
    private string $namespace = '';
    private array $uses = [];
    private string $class = '';
    private FunctionDef $functionDef;

    const string PREFIX = 'php_';
    private string $rootPath;
    private int $debugLine = 0;
    // 回归赋值
    private bool $regressionDetection = false;


    public function __construct(string $rootPath)
    {
        $this->rootPath = $rootPath;
    }

    public function parseHeaders(): string
    {
        $lines = [];
        foreach ($this->headers as $header) {
            $lines[] = '#include <' . $header . '>';
        }
        return implode(PHP_EOL, $lines) . PHP_EOL . PHP_EOL;
    }

    public function setPhpxDir($dir): void
    {
        $this->phpxDir = $dir;
    }

    private function doConvert(string $phpCode)
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $ast = $parser->parse($phpCode);

        $traverser = new NodeTraverser;
        $prettyPrinter = new PrettyPrinter\Standard;

        $traverser->addVisitor(new Visitor());
        $stmts = $traverser->traverse($ast);

        $this->indentLevel = 0;
        $cppCode = $this->parseHeaders();

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
        return $cppCode;
    }

    public function convert(string $file): string
    {
        $phpCode = file_get_contents($file);
        $this->file = realpath($file);
        $this->dir = dirname($this->file);

        while (true) {
            try {
                return $this->doConvert($phpCode);
            } catch (ReturnTypeChanged $e) {
                // 某些情况，例如返回值变更，需要重新解析
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

    private function getDetectedType($name)
    {
        return $this->scope[$name] ?? 'php::Variant';
    }

    public function getZendType(string $type): string
    {
        return $this->zendTypeMap[$type] ?? 'zval *';
    }

    public function isInternalFunction(string $name): bool
    {
        return isset($this->internalFunctions[$name]);
    }

    private function resetScope(): void
    {
        $this->scope = [];
        $this->definitions = [];
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
        if (isset($this->internalFunctions[$name])) {
            $this->functionDef = $this->internalFunctions[$name];
        } else {
            $this->functionDef = new FunctionDef();
            $this->functionDef->name = $name;
            if ($v->returnType) {
                $this->functionDef->returnType = $this->getZendType($this->parseIdentifier($v->returnType));
            } else {
                $this->functionDef->returnType = 'void';
            }
            $this->internalFunctions[$name] = $this->functionDef;
        }

        $params = $this->parseParams($v->params);
        $this->indentLevel++;
        $stmts = $this->parseStmts($v->stmts);
        $this->indentLevel--;

        $code = $this->getReturnType() . ' ' . self::PREFIX . $name . '(' . $params . ') {' . PHP_EOL;
        $this->indentLevel++;
        foreach ($this->definitions as $definition) {
            $code .= $this->getIndent() . $definition . PHP_EOL;
        }
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

    private function parseScalarValue($expr): string
    {
        return $expr->getAttribute('rawValue');
    }

    private function parseIdentifier(Node $expr)
    {
        $type = $expr->getType();
        switch ($type) {
            case 'Name':
            case 'Identifier':
            case 'Expr_Variable':
                return $expr->name;
            case 'Scalar_Int':
                return $expr->value;
            case 'Scalar_Float':
                return $expr->getAttribute('rawValue');
            case 'Scalar_String':
                return '"' . $this->escapeString($expr->value) . '"';
            case 'Expr_ConstFetch':
                return $this->parseConstFetch($expr);
            case 'Expr_Assign':
                if ($this->regressionDetection) {
                    $var = $this->parseIdentifier($expr->var);
                    $this->beforeStmtLines[] = $this->parseExpr($expr) . ";\n";
                    return '(' . $var . ')';
                }
                return $this->parseExpr($expr);
            default:
                return $this->parseExpr($expr);
        }
    }

    private function parseParams($params): string
    {
        $list = [];
        $this->functionDef->argumentCountRequired = count($params);
        foreach ($params as $param) {
            $type = $this->parseType($param->type);
            $name = $this->parseIdentifier($param->var);
            $list[] = $type . ' ' . $name;
            $this->scope[$name] = $type;

            $argInfo = new ArgInfo();
            $argInfo->name = $name;
            $argInfo->type = $type;
            if (isset($param->default)) {
                $this->functionDef->argumentCountRequired = count($list) - 1;
                $argInfo->default = $param->default->getAttribute('rawValue');
            }
            $this->functionDef->arguments[] = $argInfo;
        }
        return implode(', ', $list);
    }

    private function parseStmts(array $stmts): string
    {
        $lines = [];
        foreach ($stmts as $v) {
            $class = $v->getType();
            $this->beforeStmtLines = [];
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
                    $result = $this->parseFor($v);
                    break;
                case 'Stmt_Foreach':
                    $result = $this->parseForeach($v);
                    break;
                case 'Stmt_Switch':
                    $result = $this->parseSwitch($v);
                    break;
                case 'Stmt_While':
                    $result = $this->parseWhile($v);
                    break;
                case 'Stmt_Do':
                    $result = $this->parseDo($v);
                    break;
                case 'Stmt_If':
                    $result = $this->parseIf($v);
                    break;
                case 'Stmt_Break':
                    $result = $this->parseBreak($v);
                    break;
                case 'Stmt_Continue':
                    $result = 'continue;';
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
                default:
                    abort($v);
            }
            if ($this->beforeStmtLines) {
                $lines = array_merge($lines, $this->beforeStmtLines);
            }
            $lines[] = $result;
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
            case 'Expr_Assign':
                return $this->parseAssign($expr);
            case 'Expr_Print':
                return $this->parsePrint($expr);
            case 'Expr_BinaryOp_Equal':
                return $this->parseBinaryOpEqual($expr);
            case 'Expr_BinaryOp_NotEqual':
                return $this->parseBinaryOpNotEqual($expr);
            case 'Expr_BinaryOp_Identical':
                return $this->parseBinaryOpIdentical($expr);
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
            case 'Expr_AssignOp_ShiftRight':
                return $this->parseAssignOpShiftRight($expr);
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
            case 'Expr_ArrayDimFetch':
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
            case 'Expr_Include':
                return $this->parseInclude($expr);
            case 'Expr_Eval':
                return $this->parseEval($expr);
            case 'Expr_New':
                return $this->parseNew($expr);
            case 'Expr_Clone':
                return $this->parseClone($expr);
            case 'Name_FullyQualified':
                return $expr->name;
            case 'Scalar_Int':
            case 'Scalar_Float':
            case 'Scalar_String':
            case 'Expr_Variable':
                return $this->parseIdentifier($expr);
            case 'Scalar_MagicConst_File':
                return $this->parseMagicConstFile($expr);
            case 'Scalar_MagicConst_Dir':
                return $this->parseMagicConstDir($expr);
            case 'Scalar_InterpolatedString':
                return $this->parseInterpolatedString($expr);
            case 'Expr_Cast_Int':
                return $this->parseCastInt($expr);
            case 'Expr_ConstFetch':
                return $this->parseConstFetch($expr);
            case 'Expr_UnaryMinus':
                return $this->parseUnaryMinus($expr);
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

        if ($left->getType() === 'Expr_ArrayDimFetch') {
            $array = $this->parseIdentifier($left->var);
            $code = '';
            // 这是 PHP 的初始化+赋值写法，需要先创建数组
            if (!$this->hasVar($array)) {
                $this->addVar($array, self::TYPE_VAR);
                $code .= self::TYPE_ARRAY . " $array;\n" . $this->getIndent();
            }
            if ($left->dim === null) {
                return $code . "$array.offsetSet(php::null, " . $this->parseExpr($right) . ")";
            } else {
                $dim = $this->parseIdentifier($left->dim);
                return $code . "$array.offsetSet($dim, " . $this->parseExpr($right) . ")";
            }
        } elseif ($left->getType() === 'Expr_PropertyFetch') {
            $array = $this->parseIdentifier($left->var);
            $propName = $this->parseIdentifier($left->name);
            return "$array.setProperty(\"$propName\", " . $this->parseExpr($right) . ")";
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
            $this->addVar($var, $type);
            return $type . ' ' . $var . ' = ' . $expr;
        } else {
            return $var . ' = ' . $this->convertExprType($expr, $this->detectExprType($left), $this->detectExprType($right));
        }
    }

    private function parseEcho(mixed $v): string
    {
        foreach ($v->exprs as $expr) {
            $lines[] = 'php::echo(' . $this->parseExpr($expr) . ');';
        }
        return implode("\n" . $this->getIndent(), $lines);
    }

    private function parseBinaryOp($left, $right, $op): string
    {
        $leftExpr = $this->parseIdentifier($left);
        $rightExpr = $this->parseIdentifier($right);

        $leftType = $this->detectExprType($left);
        $rightType = $this->detectExprType($right);

        if ($leftType === self::TYPE_FLOAT) {
            $rightExpr = $this->convertExprType($rightExpr, self::TYPE_FLOAT, $rightType);
        } elseif ($rightType === self::TYPE_FLOAT) {
            $leftExpr = $this->convertExprType($leftExpr, self::TYPE_FLOAT, $leftType);
        } elseif ($leftType === self::TYPE_INT) {
            $rightExpr = $this->convertExprType($rightExpr, self::TYPE_INT, $rightType);
        } elseif ($rightType === self::TYPE_INT) {
            $leftExpr = $this->convertExprType($leftExpr, self::TYPE_INT, $leftType);
        }

        return $leftExpr . ' ' . $op . ' ' . $rightExpr;
    }

    private function parseBinaryOpPlus(mixed $expr): string
    {
        return $this->parseBinaryOp($expr->left, $expr->right, '+');
    }

    private function parseReturn(mixed $v): string
    {
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

    private function addVar(string $name, string $type): void
    {
        $this->scope[$name] = $type;
    }

    private function hasVar(string $name)
    {
        return isset($this->scope[$name]);
    }

    private function resetReturnType(string $type): void
    {
        $this->functionDef->returnType = $type;
        throw new ReturnTypeChanged;
    }

    private function detectVarType($var): string
    {
        $name = $this->parseIdentifier($var);
        if ($this->hasVar($name)) {
            return $this->scope[$name];
        }
        return self::TYPE_VAR;
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
            case 'Scalar_Float':
                return self::TYPE_FLOAT;
            case 'Scalar_Bool':
                return self::TYPE_BOOL;
            case 'Expr_Array':
                return 'php::Array';
            case 'Expr_BinaryOp_Plus':
            case 'Expr_BinaryOp_Minus':
                $leftType = $this->detectExprType($expr->left);
                $rightType = $this->detectExprType($expr->right);
                if ($leftType === self::TYPE_INT || $rightType === self::TYPE_INT) {
                    return self::TYPE_INT;
                }
                break;
            case 'Expr_FuncCall':
                $name = $this->parseIdentifier($expr->name);
                if ($this->isInternalFunction($name)) {
                    return $this->internalFunctions[$name]->returnType;
                }
                break;
            case 'Expr_New':
                return self::TYPE_OBJECT;
            case 'Expr_Assign':
                $var = $this->parseIdentifier($expr->var);
                if ($this->hasVar($var)) {
                    return $this->scope[$var];
                }
                break;
            case 'Expr_Variable':
                return $this->detectVarType($expr);
            case 'Expr_ConstFetch':
                return $this->detectConstType($expr);
            case 'Scalar_String':
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
            return '{}';
        }
        $list = [];
        $this->indentLevel++;
        foreach ($items as $item) {
            if ($item->key) {
                $list[] = $this->getIndent() . '{ ' . $this->parseIdentifier($item->key) . ', php::Variant(' . $this->parseIdentifier($item->value) . ') }';
            } else {
                $value = $this->parseIdentifier($item->value);
                $list[] = $this->getIndent() . 'php::Variant(' . $value . ')';
            }
        }
        $this->indentLevel--;
        return '{' . PHP_EOL .
            implode(', ' . PHP_EOL, $list) . PHP_EOL .
            $this->getIndent() .
            '}';
    }

    private function parseType($type)
    {
        if ($type == null) {
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

    public function compileFile($file): void
    {
        $cmd = 'g++ -c ' . $file . ' -o ' . $file . '.o ' . $this->parseIncludes() . ' -O' . $this->optimizeLevel;
        echo $cmd . PHP_EOL;
        shell_exec($cmd);
    }

    public function compileBinary($targetFile, $objectFile): void
    {
        $cmd = 'g++ main.cc ' . $objectFile . ' -o ' . $targetFile . ' ' . $this->parseIncludes() . $this->parseLdflags() . $this->parseLibs();
        $cmd .= ' -O' . $this->optimizeLevel;
        echo $cmd . PHP_EOL;
        shell_exec($cmd);
    }

    private function parseBinaryOpConcat(mixed $expr): string
    {
        $this->regressionDetection = true;
        $left = $this->parseIdentifier($expr->left);
        $right = $this->parseIdentifier($expr->right);
        $this->regressionDetection = false;

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

        $code .= 'for (;';
        $list_cond = [];
        foreach ($cond as $expr) {
            $list_cond[] = $this->parseExpr($expr);
        }
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
        return $this->parseBinaryOp($expr->left, $expr->right, '<');
    }

    private function parsePreInc(mixed $expr): string
    {
        return '++' . $this->parseIdentifier($expr->var);
    }

    private function parseAssignOpPlus(mixed $expr): string
    {
        $var = $this->parseIdentifier($expr->var);
        return $var . ' += (' . $this->parseIdentifier($expr->expr) . ')';
    }

    private function parseArrayDimFetch($node): string
    {
        $var = $this->parseIdentifier($node->var);
        $dim = $this->parseIdentifier($node->dim);
        return $var . '.offsetGet(' . $dim . ')';
    }

    private function parseBinaryOpShiftLeft($expr): string
    {
        return $this->parseBinaryOp($expr->left, $expr->right, '<<');
    }

    private function parseBinaryOpShiftRight($expr): string
    {
        return $this->parseBinaryOp($expr->left, $expr->right, '>>');
    }

    private function parseAssignOpMinus(mixed $expr): string
    {
        $var = $this->parseIdentifier($expr->var);
        return $var . ' -= ' . $this->parseIdentifier($expr->expr);
    }

    private function parseAssignOpMul(mixed $expr): string
    {
        $var = $this->parseIdentifier($expr->var);
        return $var . ' *= ' . $this->parseIdentifier($expr->expr);
    }

    private function parseAssignOpDiv(mixed $expr): string
    {
        $var = $this->parseIdentifier($expr->var);
        return $var . ' /= ' . $this->parseIdentifier($expr->expr);
    }

    private function parseBinaryOpMod(mixed $expr): string
    {
        return $this->parseBinaryOp($expr->left, $expr->right, '%');
    }

    private function parseFuncCall(mixed $expr): string
    {
        $name = $this->parseIdentifier($expr->name);
        if ($this->isInternalFunction($name)) {
            return self::PREFIX . $name . '(' . $this->parseCallArgs($expr->args, $name) . ')';
        }
        if (empty($expr->args)) {
            return 'php::call("' . $name . '")';
        } else {
            return 'php::call("' . $name . '", {' . $this->parseCallArgs($expr->args, $name) . '})';
        }
    }

    private function parseCallArgs($args, string $funcName): string
    {
        $internalFunction = $this->isInternalFunction($funcName);
        $list_args = [];
        foreach ($args as $i => $arg) {
            if ($internalFunction) {
                $argInfo = $this->getArgInfo($funcName, $i);
                $list_args[] = $this->getTypeConvertedArg($arg, $argInfo);
            } else {
                $list_args[] = $this->parseArg($arg);
            }

            // 调用了不存在的变量，可能是引用
            if ($arg->value->getType() === 'Expr_Variable') {
                $name = $this->parseIdentifier($arg->value);
                if (!$this->hasVar($name)) {
                    $this->definitions[] = self::TYPE_VAR . ' ' . $name . ' = php::newReference();';
                }
            }
        }
        return implode(', ', $list_args);
    }

    private function parseArg($arg)
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
        return $this->parseBinaryOp($expr->left, $expr->right, '>');
    }

    private function parseAssignOpMod(mixed $expr): string
    {
        $var = $this->parseIdentifier($expr->var);
        return $var . ' % ' . $this->parseIdentifier($expr->expr);
    }

    private function parseBinaryOpPow(mixed $expr): string
    {
        $left = $this->parseIdentifier($expr->left);
        $right = $this->parseIdentifier($expr->right);

        return 'pow(' . $left . ', ' . $right . ')';
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
        return $this->parseBinaryOp($expr->left, $expr->right, '==');
    }

    private function parseBinaryOpNotEqual(mixed $expr): string
    {
        return $this->parseBinaryOp($expr->left, $expr->right, '!=');
    }

    private function parseBinaryOpLogicalAnd(Node $expr): string
    {
        return $this->parseBinaryOp($expr->left, $expr->right, '&&');
    }

    private function parseBinaryOpLogicalOr(Node $expr): string
    {
        return $this->parseBinaryOp($expr->left, $expr->right, '||');
    }

    private function parseBinaryOpLogicalXor(Node $expr): string
    {
        return $this->parseBinaryOp($expr->left, $expr->right, '^');
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

    private function convertIntExpr(string $expr): string
    {
        return 'php::to_int(' . $expr . ')';
    }

    private function convertFloatExpr(string $expr): string
    {
        return 'php::to_float(' . $expr . ')';
    }

    private function convertBoolExpr(string $expr): string
    {
        return 'php::to_bool(' . $expr . ')';
    }

    private function parseBinaryOpSmallerOrEqual(Node $expr): string
    {
        return $this->parseBinaryOp($expr->left, $expr->right, '<=');
    }

    private function parseBinaryOpGreaterOrEqual(Node $expr): string
    {
        return $this->parseBinaryOp($expr->left, $expr->right, '>=');
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

    private function parseNew(Node $expr): string
    {
        $className = $this->parseIdentifier($expr->class);
        $args = $expr->args;
        if (empty($args)) {
            return 'php::newObject("' . $className . '")';
        } else {
            return 'php::newObject("' . $className . '"(' . $this->parseCallArgs($args) . ')';
        }
    }

    private function parseClone(Node $expr): string
    {
        $var = $this->parseIdentifier($expr->expr);
        return $var . '.clone()';
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
        $code = $this->parseExpr($node->expr);
        return '(' . $code . ').toInt()';
    }

    private function parseConstFetch(Node $expr)
    {
        $name = $this->parseIdentifier($expr->name);
        if ($name === 'null') {
            return 'nullptr';
        }
        return $name;
    }

    private function parseUnaryMinus(Node $expr): string
    {
        $code = $this->parseExpr($expr->expr);
        return '-' . $code;
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
        $search = ["\n", "\r", "\t", "\""];
        $replace = ['\n', '\r', '\t', '\"'];
        return str_replace($search, $replace, $str);
    }

    private function parseInterpolatedStringPart(Node $expr): string
    {
        return '"' . $this->escapeString($expr->value) . '"';
    }

    private function parseGlobal(Node $v): string
    {
        $out = [];
        foreach ($v->vars as $v) {
            $out[] = self::TYPE_VAR . ' ' . $v->name . ' = php::global("' . $v->name . '");';
            $this->addVar($v->name, self::TYPE_VAR);
        }
        return implode(PHP_EOL . $this->getIndent(), $out);
    }

    private function getArgInfo(string $funcName, int $index): ArgInfo
    {
        $funcDef = $this->internalFunctions[$funcName];
        return $funcDef->arguments[$index];
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
        if ($leftType === self::TYPE_FLOAT && $rightType !== self::TYPE_FLOAT) {
            return $this->convertFloatExpr($expr);
        }
        if ($leftType === self::TYPE_INT && $rightType !== self::TYPE_INT) {
            return $this->convertIntExpr($expr);
        }
        if ($leftType === self::TYPE_BOOL && $rightType !== self::TYPE_BOOL) {
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
            if ($type === 'Expr_ArrayDimFetch') {
                $array = $this->parseIdentifier($var->var);
                $dim = $this->parseIdentifier($var->dim);
                $lines[] = $array . '.offsetUnset(' . $dim . ');';
            } elseif ($type === 'Expr_PropertyFetch') {
                $object = $this->parseIdentifier($var->var);
                $propName = $this->parseIdentifier($var->name);
                $lines[] = $object . '.unsetProperty("' . $propName . '");';
            } elseif ($type === 'Expr_Variable') {
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
        return $this->parseIdentifier($expr->var) . '.getProperty(' . $this->parseIdentifier($expr->name) . ')';
    }

    private function parseAssignOpShiftRight(Node $node): string
    {
        $var = $this->parseIdentifier($node->var);
        return $var . ' >>= ' . $this->parseIdentifier($node->expr);
    }

    private function parseMagicConstFile(mixed $expr): string
    {
        return '"' . $this->escapeString($this->file) . '"';
    }

    private function parseForeach(Node $node)
    {
        $expr = $this->parseIdentifier($node->expr);
        if ($node->keyVar) {
            $keyVar = $this->parseIdentifier($node->keyVar);
        }
        $valueVar = $this->parseIdentifier($node->valueVar);

        $iteratorVar = $this->addTmpVar();

        $stmts = $node->stmts;
        $code = '';

        $code .= self::TYPE_ARRAY . " $iteratorVar = " . $expr . ';' . PHP_EOL;

        $code .= 'for (auto iter = ' . $iteratorVar . '.begin(); iter != ' . $iteratorVar . '.end(); ++iter) {' . PHP_EOL;
        $this->indentLevel++;
        if ($node->keyVar) {
            $code .= $this->getIndent() . self::TYPE_VAR . ' ' . $keyVar . ' = iter.key();' . PHP_EOL;
            $this->addVar($keyVar, self::TYPE_VAR);
        }
        $code .= $this->getIndent() . self::TYPE_VAR . ' ' . $valueVar . ' = iter.value();' . PHP_EOL;
        $this->addVar($valueVar, self::TYPE_VAR);
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

    private function addTmpVar(): string
    {
        return 'tmp_var_' . $this->tmpVarIndex++;
    }

    private function detectConstType($expr): string
    {
        $name = $this->parseIdentifier($expr->name);
        if ($name === 'true') {
            return self::TYPE_BOOL;
        }
        return $name;
    }

    private function parseSwitch(mixed $v): string
    {
        $cond = $v->cond;
        $tmp_var = $this->addTmpVar();
        $type = $this->detectExprType($cond);
        $var_def = $type . ' ' . $tmp_var . ' = ' . $this->parseExpr($cond) . ';' . PHP_EOL;

        // 保存作用域，switch 可能会解析失败，在这个过程中会增加变量，需重置
        $scope = $this->scope;

        if ($type === self::TYPE_INT or $type === self::TYPE_FLOAT) {
            $code = 'switch (' . $tmp_var . ') {' . PHP_EOL;
            $this->indentLevel++;
            foreach ($v->cases as $case) {
                if (empty($case->cond)) {
                    $code .= $this->getIndent() . 'default: {' . PHP_EOL;
                } else {
                    $condType = $case->cond->getType();
                    if ($condType !== 'Scalar_Int' and $condType !== 'Scalar_Float') {
                        $this->scope = $scope;
                        goto _fail;
                    }
                    $code .= $this->getIndent() . 'case ' . $this->parseScalarValue($case->cond) . ': {' . PHP_EOL;
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
            $type = $this->detectExprType($var->default);
            $list[] = 'static ' . $type . ' ' . $this->parseIdentifier($var->var) . ' = ' . $this->parseIdentifier($var->default) . ';';
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
        $num = $v->num;
        if ($num) {
            $value = $this->parseIdentifier($num);
            if ($value > 1) {
                abort($v);
            }
        }
        return 'break;';
    }
}
