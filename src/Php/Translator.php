<?php

namespace PhpAot\Php;

use PhpParser\Node;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;

class Translator extends \PhpAot\Core\Translator
{
    protected array $stmts;
    protected string $phpxDir = '~/workspace/phpx';
    protected string $lang = 'PHP';
    protected array $typeMap = [];
    protected array $zendTypeMap = [
        'int' => 'php::Int',
        'float' => 'php::Float',
        'bool' => 'bool',
    ];
    protected array $headers = [
        'phpx.h',
    ];

    const PREFIX = 'php_';

    public function __construct(array $stmts)
    {
        $this->stmts = $stmts;
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

    public function convert()
    {
        $code = '';
        $code .= $this->parseHeaders();
        $code .= $this->parseStmts($this->stmts);

        return $code;
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

    public function getZendType(string $type): string
    {
        return $this->zendTypeMap[$type] ?? 'zval *';
    }

    private function parseFunctionDef($v)
    {
        $name = $this->parseIdentifier($v->name);
        if ($v->returnType) {
            $returnType = $this->getZendType($this->parseIdentifier($v->returnType));
        } else {
            $returnType = 'php::Variant';
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

    protected function parseIdentifier($expr)
    {
        $type = $expr->getType();
        switch ($type) {
            case 'Name':
            case 'Identifier':
            case 'Expr_Variable':
                return $expr->name;
            case 'Scalar_Int':
            case 'Scalar_Float':
                return $expr->value;
            case 'Scalar_String':
                return '"' . str_replace("\n", '\n', $expr->value) . '"';
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
            switch ($class) {
                case 'Stmt_Function':
                    $lines[] = $this->parseFunctionDef($v);
                    break;
                case 'Stmt_Expression':
                    $lines[] = $this->parseExpr($v->expr) . ';';
                    break;
                case 'Stmt_Echo':
                    $lines[] = $this->parseEcho($v) . ';';
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
            case 'Scalar_Int':
            case 'Scalar_Float':
            case 'Scalar_String':
            case 'Expr_Variable':
                return $this->parseIdentifier($expr);
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

    private function parseEcho(mixed $v)
    {
        return 'php::echo(' . $this->parseExprs($v->exprs) . ')';
    }

    private function parseExprs($exprs)
    {
        $code = '';
        foreach ($exprs as $expr) {
            $code .= $this->parseExpr($expr);
        }
        return $code;
    }

    private function parseBinaryOpPlus(mixed $expr)
    {
        $left = $this->parseIdentifier($expr->left);
        $right = $this->parseIdentifier($expr->right);

        return $left . ' + ' . $right;
    }

    private function parseReturn(mixed $v)
    {
        return 'return ' . $this->parseExpr($v->expr);
    }

    private function parseBinaryOpMul(mixed $expr)
    {
        $left = $this->parseIdentifier($expr->left);
        $right = $this->parseIdentifier($expr->right);

        return $left . ' * ' . $right;
    }

    private function detectType($var, $expr)
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

    private function parseArray($node)
    {
        $items = $node->items;
        $list = [];
        $this->indentLevel++;
        foreach ($items as $item) {
            if ($item->key) {
                $list[] = $this->getIndent() . '{ php::Variant(' . $this->parseIdentifier($item->key) . '), php::Variant(' . $this->parseIdentifier($item->value) . ') }';
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
                return 'zend_long';
            case 'array':
                return 'php::Array';
            case 'float':
                return 'double';
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

    public function compileFile($file)
    {
        $cmd = 'g++ -c ' . $file . ' -o ' . $file . '.o ' . $this->parseIncludes() . $this->parseLdflags() . $this->parseLibs();
        echo $cmd . PHP_EOL;
        shell_exec($cmd);
    }

    private function parseBinaryOpConcat(mixed $expr)
    {
        $left = $this->parseIdentifier($expr->left);
        $right = $this->parseIdentifier($expr->right);

        return $left . ' + ' . $right;
    }

    private function parseFor(mixed $v)
    {
        $init = $v->init;
        $cond = $v->cond;
        $loop = $v->loop;
        $stmts = $v->stmts;

        $code = 'for (';

        $list_expr = [];
        foreach ($init as $expr) {
            $list_expr[] = $this->parseExpr($expr);
        }
        $code .= implode(', ', $list_expr);
        $code .= '; ';

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
        return $var . ' += ' . $this->parseIdentifier($expr->expr);
    }

    private function parseArrayDimFetch($node): string
    {
        $var = $this->parseIdentifier($node->var);
        $dim = $this->parseIdentifier($node->dim);
        return $var . '[' . $dim . ']';
    }

    private function parseBinaryOpShiftLeft($node)
    {
        $left = $this->parseIdentifier($node->left);
        $right = $this->parseIdentifier($node->right);

        return $left . ' << ' . $right;
    }

    private function parseBinaryOpShiftRight($node)
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
        return $name . '(' . $this->parseArgs($expr->args) . ')';
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

    private function parseBinaryOpLogicalAnd(mixed $expr): string
    {
        $left = $this->parseIdentifier($expr->left);
        $right = $this->parseIdentifier($expr->right);

        return $left . ' && ' . $right;
    }

    private function parseBinaryOpLogicalOr(mixed $expr): string
    {
        $left = $this->parseIdentifier($expr->left);
        $right = $this->parseIdentifier($expr->right);

        return $left . ' || ' . $right;
    }

    private function parseBinaryOpLogicalXor(mixed $expr): string
    {
        $left = $this->parseIdentifier($expr->left);
        $right = $this->parseIdentifier($expr->right);

        return $left . ' ^ ' . $right;
    }

    private function parseBooleanNot(mixed $expr): string
    {
        $expr = $this->parseExpr($expr->expr);
        return '!' . $expr;
    }

    private function parseWhile(mixed $v): string
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

    private function parseBinaryOpSmallerOrEqual(mixed $expr): string
    {
        $left = $this->parseIdentifier($expr->left);
        $right = $this->parseIdentifier($expr->right);

        return $left . ' <= ' . $right;
    }

    private function parseBinaryOpGreaterOrEqual(mixed $expr): string
    {
        $left = $this->parseIdentifier($expr->left);
        $right = $this->parseIdentifier($expr->right);

        return $left . ' >= ' . $right;
    }

    private function parsePrint(mixed $expr): string
    {
        return 'php::echo(' . $this->parseExpr($expr->expr) . ')';
    }

    private function parseDo(mixed $v): string
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

    private function parseBinaryOpIdentical(mixed $expr): string
    {
        $left = $this->parseIdentifier($expr->left);
        $right = $this->parseIdentifier($expr->right);

        return 'php::same(' . $left . ', ' . $right . ')';
    }
}
