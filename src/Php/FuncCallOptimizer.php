<?php

namespace PhpAot\Php;

use PhpParser\Node;

trait FuncCallOptimizer
{
    protected function parseFuncCallWithOptimizer(string $name, Node\Expr\FuncCall $expr): string|false
    {
        if ('strlen' === $name or 'sizeof' === $name or 'count' === $name) {
            return 'php::len('.$this->parseIdentifier($expr->args[0]->value).')';
        }
        if (1 == count($expr->args)) {
            switch ($name) {
                case 'intval':
                    return $this->convertIntExpr($this->parseExpr($expr->args[0]->value));
                case 'floatval':
                    return $this->convertFloatExpr($this->parseExpr($expr->args[0]->value));
                case 'boolval':
                    return $this->convertBoolExpr($this->parseExpr($expr->args[0]->value));
                case 'strval':
                    return $this->convertStringExpr($this->parseExpr($expr->args[0]->value));
                default:
                    break;
            }
        } elseif (2 == count($expr->args)) {
            switch ($name) {
                case 'objval':
                    $arg1 = $expr->args[0]->value;
                    $arg2 = $expr->args[1]->value;

                    return $this->convertObjectExpr($this->parseExpr($arg1), $this->parseExpr($arg2));
                default:
                    break;
            }
        }
        if ('abs' === $name) {
            return 'php::math::abs('.$this->parseIdentifier($expr->args[0]->value).')';
        }

        return false;
    }
}
