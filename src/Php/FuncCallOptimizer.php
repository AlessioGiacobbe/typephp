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
        $getArg = function ($i) use ($expr) {
            return $this->parseIdentifier($expr->args[$i]->value);
        };
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
            return 'php::math::abs(' . $getArg(0) . ')';
        }
        if ($name === 'ord') {
            return 'php::fn::ord(' . $getArg(0) . ')';
        }
        if ($name === 'chr') {
            return 'php::fn::chr(' . $this->convertIntExpr($getArg(0)) . ')';
        }
        if ($name === 'array_key_exists') {
            return $getArg(1) . '.offsetExists(' . $getArg(0) . ')';
        }
        if ($name === 'function_exists') {
            $funcName = $expr->args[0]->value;
            if ($this->isScalarString($funcName)) {
                $funcName = $this->getLiteralString(strtolower(trim($funcName->value, '\\')));
                return 'php::fn::function_exists(' . $funcName . ', true)';
            }
            return 'php::fn::function_exists(' . $this->parseIdentifier($funcName) . ')';
        }

        return false;
    }
}
