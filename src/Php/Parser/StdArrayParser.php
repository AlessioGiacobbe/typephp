<?php
/**
 * This file is part of Swoole-Compiler(AOT).
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace PhpAot\Php\Parser;

use PhpAot\Php\Symbol;
use PhpParser\Node\Expr;
use PhpParser\NodeAbstract;

trait StdArrayParser
{
    protected function isStdArray(string $var): bool
    {
        return $this->hasLocalVar($var) and $this->getVarType($var) === self::TYPE_STD_ARRAY;
    }

    protected function isStdArrayExpr(Expr\ArrayDimFetch $expr): bool
    {
        $info = $this->getStdArrayInfo($expr);
        return $info !== null;
    }

    protected function fillStdArray(Expr\StaticCall $expr): string
    {
        if (!$this->isVarExpr($expr->args[0]->value) or !$this->isStdArray($this->parseIdentifier($expr->args[0]->value))) {
            $this->fatalError($expr, 'fill() only support std::array');
        }
        $array = $this->parseIdentifier($expr->args[0]->value);
        $valueExpr = $this->parseExpr($expr->args[1]->value);
        $type = $this->context->stdArrays[$array]['type'];
        $value = $this->convertExprFromType($type, $valueExpr);
        return "{$array}.fill({$value})";
    }

    protected function getStdArrayInfo(Expr\ArrayDimFetch $expr): ?array
    {
        $tmp = $expr->var;
        while (true) {
            if ($this->isArrayDimFetch($tmp)) {
                $tmp = $tmp->var;
            } elseif ($this->isVarExpr($tmp) and $this->isStdArray($this->parseVariable($tmp))) {
                return $this->context->stdArrays[$this->parseVariable($tmp)];
            } else {
                return null;
            }
        }
    }

    protected function parseStdArrayAssign(NodeAbstract $left, NodeAbstract $right): string
    {
        $info = $this->getStdArrayInfo($left);
        $arrayDimFetch = $this->parseStdArrayDimFetch($left);
        return $arrayDimFetch . ' = ' . $this->convertExprFromType($info['type'], $this->parseExpr($right));
    }

    protected function parseStdArrayAssignOp(Expr\AssignOp $expr, string $op): string
    {
        $binaryOp = $this->removeAssignOp($op);
        if ($binaryOp === '.') {
            $this->fatalError($expr, 'Cannot concat string to std::array');
        }

        $info = $this->getStdArrayInfo($expr->var);
        $arrayDimFetch = $this->parseStdArrayDimFetch($expr->var);
        return $arrayDimFetch . ' ' . $binaryOp . '= ' . $this->convertExprFromType($info['type'], $this->parseExpr($expr->expr));
    }

    protected function parseStdArrayDimFetch(Expr\ArrayDimFetch $expr): string
    {
        $tmp = $expr;
        $nesting = [];
        $level = 0;
        $info = $this->getStdArrayInfo($expr);

        while (true) {
            if ($this->isArrayDimFetch($tmp)) {
                if ($tmp->dim === null) {
                    $this->fatalError($tmp, 'std::array expects an index');
                }
                $size = $info['sizes'][$level];
                if ($this->isScalarInt($tmp->dim)) {
                    if ($tmp->dim->value < 0 || $tmp->dim->value >= $size) {
                        $this->fatalError($tmp, "std::array index out of bounds: index {$tmp->dim->value}, size {$size}");
                    }
                }
                $index = $this->parseExpr($tmp->dim);
                $nesting[] = '[' . Symbol::safeIndex($this->convertIntExpr($index), $info['sizes'][$level]) . ']';
                $tmp = $tmp->var;
                $level++;
            } else {
                $nesting[] = $this->parseVariable($tmp);
                break;
            }
        }

        $nesting = array_reverse($nesting);
        $expr->setAttribute('stdArrayDimFetch', ['var' => $nesting[0], 'accessLevel' => $level, 'totalLevel' => count($info['sizes'])]);

        return implode('', $nesting);
    }

    protected function parseStdArray(string $var, Expr\StaticCall $expr): string
    {
        $tmp = $expr;
        $nesting = [];
        $totalBytes = 0;

        while (true) {
            if (count($tmp->args) !== 2) {
                $this->fatalError($tmp, 'std::array() expects two arguments');
            }
            if (!$this->isScalarInt($tmp->args[1]->value)) {
                $this->fatalError($tmp, 'std::array() expects second argument to be an integer');
            }
            $byte = 0;
            $size = $tmp->args[1]->value->value;
            $nesting[] = $size;
            $typeExpr = $tmp->args[0]->value;
            if ($this->isClassConstFetch($typeExpr)) {
                if (!$this->isNameExpr($typeExpr->class) || !$this->isIdExpr($typeExpr->name) || $typeExpr->class->toString() !== 'native_types') {
                    $this->fatalError($tmp, 'An incorrect `std::array` definition');
                }
                switch ($typeExpr->name->name) {
                    case 'type_int':
                        $type = self::TYPE_INT;
                        $byte = 8;
                        break;
                    case 'type_float':
                        $type = self::TYPE_FLOAT;
                        $byte = 8;
                        break;
                    case 'type_bool':
                        $type = self::TYPE_BOOL;
                        $byte = 1;
                        break;
                    default:
                        $this->fatalError($tmp, 'An incorrect `std::array` definition');
                        break;
                }
                break;
            }
            $totalBytes += $size * $byte;
            if ($this->isStaticCall($typeExpr)) {
                $tmp = $typeExpr;
                if (!$this->isNameExpr($tmp->class) || !$this->isIdExpr($tmp->name) || $tmp->class->toString() !== 'std' || $tmp->name->toString() !== 'array') {
                    $this->fatalError($tmp, 'An incorrect `std::array` definition');
                }
            } else {
                $this->fatalError($tmp, 'std::array() expects first argument to be a class constant');
            }
        }

        $decl = str_repeat(self::TYPE_STD_ARRAY . '<', count($nesting));
        $decl .= $type;
        for ($i = count($nesting) - 1; $i >= 0; $i--) {
            $decl .= ', ' . $nesting[$i] . '>';
        }
        $this->context->stdArrays[$var] = [
            'decl' => $decl,
            'type' => $type,
            'sizes' => array_reverse($nesting),
            'bytes' => $totalBytes,
        ];
        return '// ' . $decl;
    }
}
