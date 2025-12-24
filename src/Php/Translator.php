<?php

namespace PhpAot\Php;

use PhpParser\Node;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpAot\Php\Visitor;
use PhpParser\Error;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter;

class Translator extends \PhpAot\Core\Translator
{
    const TYPE_VAR = 'php::Variant';
    const TYPE_INT = 'php::Int';
    const TYPE_FLOAT = 'php::Float';

    protected string $phpxDir = '~/workspace/projects/phpx';
    protected string $lang = 'PHP';
    protected array $typeMap = [];
    protected array $zendTypeMap = [
        'int' => self::TYPE_INT,
        'float' => self::TYPE_FLOAT,
        'bool' => 'bool',
    ];
    protected array $headers = [
        'phpx.h',
    ];

    protected int $optimizeLevel = 5;

    protected string $namespace = '';
    protected array $uses = [];
    protected string $class = '';

    const PREFIX = 'php_';


    public function __construct()
    {
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

    public function convert(string $file)
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $phpCode = file_get_contents($file);
        $ast = $parser->parse($phpCode);

        $traverser = new NodeTraverser;
        $prettyPrinter = new PrettyPrinter\Standard;

        $traverser->addVisitor(new Visitor());
        $stmts = $traverser->traverse($ast);

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
                    debug($v);
            }
        }
        return $cppCode;
    }

    public function save($code, $file)
    {
        file_put_contents($file, $code);
    }


    public function getLine($node): int
    {
        return $node->getLine();
    }

    public function getType($node): string
    {
        return $node->getType();
    }

    protected function getDetectedType($name)
    {
        return $this->typeMap[$name] ?? 'php::Variant';
    }

    public function getZendType(string $type): string
    {
        return $this->zendTypeMap[$type] ?? 'zval *';
    }

    private function parseFunctionDef($v): string
    {
        $names[] = $this->parseIdentifier($v->name);
        if ($this->class) {
            $names[] = strtolower($this->class);
        }
        if ($this->namespace) {
            $names[] = strtolower(str_replace('\\', '_', $this->namespace));
        }

        $name = implode('__', array_reverse($names));

        if ($v->returnType) {
            $returnType = $this->getZendType($this->parseIdentifier($v->returnType));
        } else {
            $returnType = 'void';
        }

        $params = $this->parseParams($v->params);
        $code = $returnType . ' ' . self::PREFIX . $name . '(' . $params . ') {' . PHP_EOL;
        $this->indentLevel++;
        $stmts = $this->parseStmts($v->stmts);
        $this->indentLevel--;
        $code .= $stmts;
        $code .= "}";

        return $code;
    }

    protected function writeLog($msg)
    {
        echo $msg . PHP_EOL;
    }

    protected function parseIdentifier(Node $expr)
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
            default:
                return $this->parseExpr($expr);
        }
    }

    private function parseParams($params)
    {
        $list = [];
        foreach ($params as $param) {
            $type = $this->parseType($param->type);
            $name = $this->parseIdentifier($param->var);
            $list[] = $type . ' ' . $name;
            $this->typeMap[$name] = $type;
        }
        return implode(', ', $list);
    }

    private function parseStmts(array $stmts): string
    {
        $lines = [];
        foreach ($stmts as $v) {
            $class = $v->getType();
            // $this->writeLog('Line ' . $this->getLine($v) . ': ' . $class);
            switch ($class) {
                case 'Stmt_Expression':
                    $lines[] = $this->parseExpr($v->expr) . ';';
                    break;
                case 'Stmt_Echo':
                    $this->parseEcho($v, $lines);
                    break;
                case 'Stmt_Return':
                    $lines[] = $this->parseReturn($v) . ';';
                    break;
                case 'Stmt_For':
                    $lines[] = $this->parseFor($v);
                    break;
                case 'Stmt_While':
                    $lines[] = $this->parseWhile($v);
                    break;
                case 'Stmt_Do':
                    $lines[] = $this->parseDo($v);
                    break;
                case 'Stmt_If':
                    $lines[] = $this->parseIf($v);
                    break;
                case 'Stmt_Break':
                    $lines[] = 'break;';
                    break;
                case 'Stmt_Continue':
                    $lines[] = 'continue;';
                    break;
                default:
                    debug($v);
            }
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
            case 'Expr_BinaryOp_Mul':
                return $this->parseBinaryOpMul($expr);
            case 'Expr_AssignOp_Mod':
                return $this->parseAssignOpMod($expr);
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
                return $this->parseArrayDimFetch($expr);
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
            default:
                debug($expr);
        }
    }

    private function parseAssign(mixed $v)
    {
        $var = $this->parseIdentifier($v->var);
        $expr = $this->parseExpr($v->expr);

        if (!isset($this->typeMap[$var])) {
            $type = $this->detectType($v->var, $v->expr);
            $this->typeMap[$var] = $type;
            return $type . ' ' . $var . ' = ' . $expr;
        } else {
            return $var . ' = ' . $expr;
        }
    }

    private function parseEcho(mixed $v, &$lines): void
    {
        foreach ($v->exprs as $expr) {
            $lines[] = 'php::echo(' . $this->parseExpr($expr) . ');';
        }
    }

    private function parseBinaryOpPlus(mixed $expr): string
    {
        $left = $this->parseIdentifier($expr->left);
        $right = $this->parseIdentifier($expr->right);

        return $left . ' + ' . $right;
    }

    private function parseReturn(mixed $v): string
    {
        return 'return ' . $this->parseExpr($v->expr);
    }

    private function parseBinaryOpMul(mixed $expr): string
    {
        $left = $this->parseIdentifier($expr->left);
        $right = $this->parseIdentifier($expr->right);

        return '(' . $left . ') * (' . $right . ')';
    }

    private function detectType($var, $expr): string
    {
        $exprType = $expr->getType();
        switch ($exprType) {
            case 'Scalar_Int':
                return $this->getZendType('int');
            case 'Scalar_Float':
                return $this->getZendType('float');
            case 'Scalar_Bool':
                return $this->getZendType('bool');
            case 'Expr_Array':
                return 'php::Array';
            case 'Scalar_String':
            default:
                return 'php::Variant';
        }
    }

    private function parseArray($node): string
    {
        $items = $node->items;
        $list = [];
        $this->indentLevel++;
        foreach ($items as $item) {
            if ($item->key) {
                $list[] = $this->getIndent() . '{ ' . $this->parseIdentifier($item->key) . ', php::Variant(' . $this->parseIdentifier($item->value) . ') }';
            } else {
                $list[] = $this->getIndent() . 'php::Variant(' . $this->parseIdentifier($item->value) . ')';
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
        $name = $type->name;
        switch ($name) {
            case 'int':
                return 'php::Int';
            case 'array':
                return 'php::Array';
            case 'float':
                return 'php::Float';
            default:
                debug($type);
        }
    }

    private function parseIncludes()
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

    private function parseLdflags()
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
        $cmd = 'g++ -c ' . $file . ' -o ' . $file . '.o ' . $this->parseIncludes() . $this->parseLdflags() . $this->parseLibs();
        $cmd .= ' -O' . $this->optimizeLevel;
        echo $cmd . PHP_EOL;
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
        $left = $this->parseIdentifier($expr->left);
        $right = $this->parseIdentifier($expr->right);

        return $left . ' < ' . $right;
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
        return $var . '[' . $dim . ']';
    }

    private function parseBinaryOpShiftLeft($node): string
    {
        $left = $this->parseIdentifier($node->left);
        $right = $this->parseIdentifier($node->right);

        return $left . ' << ' . $right;
    }

    private function parseBinaryOpShiftRight($node): string
    {
        $left = $this->parseIdentifier($node->left);
        $right = $this->parseIdentifier($node->right);

        return $left . ' >> ' . $right;
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
        $left = $this->parseExpr($expr->left);
        $right = $this->parseExpr($expr->right);
        return $left . ' % ' . $right;
    }

    private function parseFuncCall(mixed $expr): string
    {
        $name = $this->parseIdentifier($expr->name);
        if (empty($expr->args)) {
            return 'php::call("' . $name . '")';
        } else {
            return 'php::call("' . $name . '", {' . $this->parseArgs($expr->args) . '})';
        }
    }

    private function parseArgs($args): string
    {
        $list_args = [];
        foreach ($args as $arg) {
            $list_args[] = $this->parseArg($arg);
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
        return $this->parseExpr($cond) . ' ? ' . $this->parseExpr($if) . ' : ' . $this->parseExpr($else);
    }

    private function parseBinaryOpGreater(mixed $expr): string
    {
        $left = $this->parseIdentifier($expr->left);
        $right = $this->parseIdentifier($expr->right);

        return $left . ' > ' . $right;
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
        $left = $this->parseIdentifier($expr->left);
        $right = $this->parseIdentifier($expr->right);

        return $left . ' & ' . $right;
    }

    private function parseBinaryOpBitwiseOr(mixed $expr): string
    {
        $left = $this->parseIdentifier($expr->left);
        $right = $this->parseIdentifier($expr->right);

        return $left . ' | ' . $right;
    }

    private function parseBinaryOpBitwiseXor(mixed $expr)
    {
        $left = $this->parseIdentifier($expr->left);
        $right = $this->parseIdentifier($expr->right);

        return $left . ' ^ ' . $right;
    }

    private function parseBitwiseNot(mixed $expr)
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
        $left = $this->parseIdentifier($expr->left);
        $right = $this->parseIdentifier($expr->right);

        return $left . ' == ' . $right;
    }

    private function parseBinaryOpNotEqual(mixed $expr)
    {
        $left = $this->parseIdentifier($expr->left);
        $right = $this->parseIdentifier($expr->right);

        return $left . ' != ' . $right;
    }

    private function parseBinaryOpLogicalAnd(Node $expr): string
    {
        $left = $this->parseIdentifier($expr->left);
        $right = $this->parseIdentifier($expr->right);

        return $left . ' && ' . $right;
    }

    private function parseBinaryOpLogicalOr(Node $expr): string
    {
        $left = $this->parseIdentifier($expr->left);
        $right = $this->parseIdentifier($expr->right);

        return $left . ' || ' . $right;
    }

    private function parseBinaryOpLogicalXor(Node $expr): string
    {
        $left = $this->parseIdentifier($expr->left);
        $right = $this->parseIdentifier($expr->right);

        return $left . ' ^ ' . $right;
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

    private function optimizeBinaryOpCompare(string $left, string $right, string $op): string
    {
        if ($this->getDetectedType($left) == self::TYPE_INT and $this->getDetectedType($right) == self::TYPE_VAR) {
            return $left . ' ' . $op . ' ' . $right . '.toInt()';
        }
        if ($this->getDetectedType($left) == self::TYPE_FLOAT and $this->getDetectedType($right) == self::TYPE_VAR) {
            return $left . ' ' . $op . ' ' . $right . '.toFloat()';
        }
        return '';
    }

    private function parseBinaryOpSmallerOrEqual(Node $expr): string
    {
        $left = $this->parseIdentifier($expr->left);
        $right = $this->parseIdentifier($expr->right);

        $optimized = $this->optimizeBinaryOpCompare($left, $right, '<=');
        if (!$optimized) {
            return $left . ' <= ' . $right;
        } else {
            return $optimized;
        }
    }

    private function parseBinaryOpGreaterOrEqual(Node $expr): string
    {
        $left = $this->parseIdentifier($expr->left);
        $right = $this->parseIdentifier($expr->right);

        return $left . ' >= ' . $right;
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

        return 'php::same(' . $left . ', ' . $right . ')';
    }

    private function parseNew(Node $expr): string
    {
        $className = $this->parseIdentifier($expr->class);
        $args = $expr->args;
        if (empty($args)) {
            return 'new ' . $className . '()';
        } else {
            return 'new ' . $className . '(' . $this->parseArgs($args) . ')';
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
                    debug($v2);
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
                    debug($v);
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
        return $this->parseIdentifier($expr->name);
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
        $left = $this->parseIdentifier($expr->left);
        $right = $this->parseIdentifier($expr->right);
        return $left . ' - (' . $right . ')';
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
}
