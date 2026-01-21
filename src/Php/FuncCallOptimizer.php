<?php

namespace PhpAot\Php;

use PhpParser\Node;

trait FuncCallOptimizer
{
    protected function parseFuncCallWithOptimizer(string $name, Node\Expr\FuncCall $expr): string|false
    {
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
        if ($name === 'abs') {
            return 'php::math::abs(' . $this->parseIdentifier($expr->args[0]->value) . ')';
        }
        return false;
    }
}