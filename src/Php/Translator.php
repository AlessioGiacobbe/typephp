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
    protected array $headers = [
        'phpx.h',
    ];

    function __construct(array $stmts)
    {
        $this->stmts = $stmts;
    }

    function parseHeaders(): string
    {
        $lines = [];
        foreach ($this->headers as $header) {
            $lines[] = '#include <' . $header . '>';
        }
        return implode(PHP_EOL, $lines) . PHP_EOL . PHP_EOL;
    }

    function setPhpxDir($dir): void
    {
        $this->phpxDir = $dir;
    }

    function convert()
    {
        $code = '';
        $code .= $this->parseHeaders();
        $code .= $this->parseStmts($this->stmts);

        return $code;
    }

    function save($code, $file)
    {
        file_put_contents($file, $code);
    }


    function getLine($node): int
    {
        return $node->getLine();
    }

    function getType($node): string
    {
        return $node->getType();
    }

    private function parseFunctionDef($v)
    {
        $name = $this->parseIdentifier($v->name);
        $return = $this->parseIdentifier($v->returnType);
        $params = $this->parseParams($v->params);
        $code = $return . ' ' . $name . '(' . $params . ') {' . PHP_EOL;
        $this->indentLevel++;
        $stmts = $this->parseStmts($v->stmts);
        $this->indentLevel--;
        $code .= $stmts;
        $code .= "}";

        return $code;
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
            case 'Expr_BinaryOp_Mul':
                return '(' . $this->parseBinaryOpMul($node) . ')';
            case 'Expr_BinaryOp_Concat':
                return '(' . $this->parseBinaryOpConcat($node) . ')';
            case 'Expr_BinaryOp_Plus':
                return '(' . $this->parseBinaryOpPlus($node) . ')';
            default:
                debug($node);
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
                    $lines[] = $this->parseReturn($v) . ';';
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
            case 'Expr_BinaryOp_Plus':
                return $this->parseBinaryOpPlus($expr);
            case 'Expr_BinaryOp_Mul':
                return $this->parseBinaryOpMul($expr);
            case 'Expr_BinaryOp_Concat':
                return $this->parseBinaryOpConcat($expr);
            default:
                debug($expr);
        }
    }

    private function parseAssign(mixed $v)
    {
        $var = $this->parseIdentifier($v->var);
        $expr = $this->parseIdentifier($v->expr);

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
                return 'zend_long';
            case 'Scalar_Float':
                return 'double';
            case 'Scalar_String':
                return 'php::Variant';
            case 'Expr_Array':
                return 'php::Array';
            default:
                debug($expr);
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
}

