<?php
/**
 * This file is part of Swoole-Compiler(AOT).
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

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
                case 'strval':
                    return $this->convertStringExpr($this->parseExpr($expr->args[0]->value));
                default:
                    break;
            }
        } elseif (count($expr->args) == 2) {
            switch ($name) {
                case 'objval':
                    $arg1 = $expr->args[0]->value;
                    $arg2 = $expr->args[1]->value;

                    return $this->convertObjectExpr($this->parseExpr($arg1), $this->parseExpr($arg2));
                default:
                    break;
            }
        }
        if ($name === 'abs') {
            return 'php::math::abs(' . $this->parseIdentifier($expr->args[0]->value) . ')';
        }
        if ($name === 'function_exists') {
            return 'php::fn::function_exists(' . $this->parseIdentifier($expr->args[0]->value) . ')';
        }

        return false;
    }
}
