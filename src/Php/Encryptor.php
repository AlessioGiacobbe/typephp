<?php
/**
 * This file is part of Swoole-Compiler(AOT).
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace PhpAot\Php;

use PhpParser\PrettyPrinter\Standard;

class Encryptor extends \PhpAot\Core\Translator
{
    protected array $stmts;
    protected string $phpxDir = '~/workspace/phpx';
    protected string $lang = 'PHP';
    protected array $typeMap = [];
    protected array $headers = [
        'phpx.h',
    ];
    protected array $encodeMap;
    protected array $decodeMap;
    protected array $constants;

    public function __construct(array $stmts)
    {
        $confDir         = __DIR__ . '/../../config';
        $this->stmts     = $stmts;
        $this->encodeMap = require $confDir . '/functions.php';
        $this->decodeMap = array_flip($this->encodeMap);
        $this->constants = require $confDir . '/constants.php';
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
        $this->parseStmts($this->stmts);
        $prettyPrinter = new Standard();

        return $prettyPrinter->prettyPrintFile($this->stmts);
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

    public function compileFile($file)
    {
        $cmd = 'g++ -c ' . $file . ' -o ' . $file . '.o ' . $this->parseIncludes() . $this->parseLdflags() . $this->parseLibs();
        echo $cmd . PHP_EOL;
        shell_exec($cmd);
    }

    protected function parseIdentifier($node)
    {
        $type = $node->getType();
        switch ($type) {
            case 'Identifier':
            case 'Expr_Variable':
                return $node->name;
            case 'Scalar_Int':
            case 'Scalar_Float':
                return $node->value;
            case 'Scalar_String':
                return '"' . $node->value . '"';
            case 'Expr_Array':
                return $this->parseArray($node);
            case 'Expr_FuncCall':
                $this->parseFuncCall($node->name);
                break;
            case 'Expr_Ternary':
            case 'Expr_ArrayDimFetch':
            case 'Expr_New':
            case 'Expr_ClassConstFetch':
            case 'Expr_BinaryOp_Identical':
            case 'Expr_BinaryOp_Mul':
            case 'Expr_BinaryOp_Concat':
            case 'Expr_BinaryOp_Plus':
            case 'Expr_Isset':
            case 'Stmt_Switch':
                break;
            default:
                abort($node);
        }
    }

    private function parseFunctionDef($v)
    {
        if ($v->name) {
            $name = $this->parseIdentifier($v->name);
        } else {
            $name = '';
        }

        if ($v->returnType) {
            $return = $this->parseIdentifier($v->returnType);
        } else {
            $return = '';
        }

        if ($v->params) {
            $params = $this->parseParams($v->params);
        } else {
            $params = '';
        }

        $code = $return . ' ' . $name . '(' . $params . ') {' . PHP_EOL;
        $this->indentLevel++;
        $stmts = $this->parseStmts($v->stmts);
        $this->indentLevel--;
        $code .= $stmts;
        $code .= '}';

        return $code;
    }

    private function parseParams($params)
    {
        $list = [];
        foreach ($params as $param) {
            $type                 = $param->type ? $this->parseType($param->type) : '';
            $name                 = $param->var ? $this->parseIdentifier($param->var) : '';
            $list[]               = $type . ' ' . $name;
            $this->typeMap[$name] = $type;
        }

        return implode(', ', $list);
    }

    private function parseStmts(array $stmts)
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
                    $this->parseReturn($v);
                    break;
                case 'Expr_Include':
                    $this->parseInclude($v);
                    break;
                case 'Stmt_If':
                    $this->parseIf($v);
                    break;
                case 'Stmt_Global':
                case 'Stmt_InlineHTML':
                case 'Stmt_Nop':
                case 'Stmt_Switch':
                    break;
                default:
                    abort($v);
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
            case 'Expr_BinaryOp_Plus':
                return $this->parseBinaryOpPlus($expr);
            case 'Expr_BinaryOp_Mul':
                return $this->parseBinaryOpMul($expr);
            case 'Expr_BinaryOp_Concat':
                return $this->parseBinaryOpConcat($expr);
            case 'Expr_FuncCall':
                return $this->parseFuncCall($expr->name);
            case 'Expr_Include':
                return $this->parseInclude($expr->expr);
            case 'Expr_BinaryOp_BooleanAnd':
                return $this->parseBinaryOpBooleanAnd($expr);
            case 'Expr_MethodCall':
            case 'Expr_Isset':
            case 'Expr_Variable':
            case 'Expr_BooleanNot':
            case 'Scalar_String':
            case 'Scalar_MagicConst_File':
            case 'Scalar_MagicConst_Dir':
                break;
            case 'Expr_BinaryOp_NotIdentical':
                $this->parseBinaryOpNotIdentical($expr);
                break;
            case 'Expr_BinaryOp_Identical':
                $this->parseBinaryOpIdentical($expr);
                break;
            case 'Expr_ConstFetch':
                $this->parseConstFetch($expr);
                break;
            default:
                abort($expr);
        }
    }

    private function parseAssign(mixed $v)
    {
        $this->parseExpr($v->var);
        $this->parseExpr($v->expr);
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
        $left  = $this->parseIdentifier($expr->left);
        $right = $this->parseIdentifier($expr->right);

        return $left . ' + ' . $right;
    }

    private function parseReturn(mixed $v)
    {
        return 'return ' . $this->parseExpr($v->expr);
    }

    private function parseBinaryOpMul(mixed $expr)
    {
        $left  = $this->parseIdentifier($expr->left);
        $right = $this->parseIdentifier($expr->right);

        return $left . ' * ' . $right;
    }

    private function detectType($var, $expr)
    {
        $exprType = $expr->getType();
        switch ($exprType) {
            case 'Scalar_Int':
                return 'zend_long';
            case 'Scalar_Float':
                return 'double';
            case 'Scalar_String':
                return 'php::Variant';
            case 'Expr_Array':
                return 'php::Array';
            default:
                abort($expr);
        }
    }

    private function parseArray($node)
    {
        $items = $node->items;
        $list  = [];
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
                abort($type);
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

    private function parseBinaryOpConcat(mixed $expr)
    {
        $left  = $this->parseIdentifier($expr->left);
        $right = $this->parseIdentifier($expr->right);

        return $left . ' + ' . $right;
    }

    private function parseFuncCall($expr)
    {
        if (isset($this->decodeMap[$expr->name])) {
            $expr->name = $this->decodeMap[$expr->name];
        }

        return $expr;
    }

    private function parseInclude(mixed $v)
    {
        return '';
    }

    private function parseIf(mixed $v)
    {
        $cond = $v->cond;
        $this->parseCond($cond);
    }

    private function parseCond(mixed $cond)
    {
        return $this->parseExpr($cond);
    }

    private function parseBinaryOpBooleanAnd(mixed $expr)
    {
        if (!empty($expr->left)) {
            $this->parseExpr($expr->left);
        }
        if (!empty($expr->right)) {
            $this->parseExpr($expr->right);
        }
    }

    private function parseBinaryOpNotIdentical(mixed $expr)
    {
        if (!empty($expr->left)) {
            $this->parseExpr($expr->left);
        }
        if (!empty($expr->right)) {
            $this->parseExpr($expr->right);
        }
    }

    private function parseBinaryOpIdentical(mixed $expr)
    {
        if (!empty($expr->left)) {
            $this->parseExpr($expr->left);
        }
        if (!empty($expr->right)) {
            $this->parseExpr($expr->right);
        }
    }

    private function parseConstFetch(mixed $expr)
    {
        $name = $expr->name->name;
        if (isset($this->constants[$name])) {
        }
    }
}
