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
    public function genFuncGetArgs(string $name, Node\Expr\FuncCall $expr): string
    {
        $funcDef = $this->functionDef;
        $list = [];
        foreach ($funcDef->argInfoList as $i => $argInfo) {
            if ($argInfo->variadic) {
                $tmpVar = $this->addTmpVar(self::TYPE_ARRAY);
                $this->context->beforeStmtLines[] = $this->genArray($list) . ';';
                $this->context->beforeStmtLines[] = $tmpVar . '.merge(' . $argInfo->name . ');';
                return $tmpVar;
            }
            $list[] = $argInfo->name;
        }
        return $this->genArray($list);
    }

    public function genFuncGetArg(string $name, Node\Expr\FuncCall $expr)
    {
        $position = $expr->args[0]->value;
        if ($this->isScalarInt($position)) {
            $funcDef = $this->functionDef;
            $posInt = intval($position->value);
            foreach ($funcDef->argInfoList as $i => $argInfo) {
                if ($argInfo->variadic) {
                    return $argInfo->name . '.offsetGet(' . ($posInt - $i) . ')';
                }
                if ($i == $posInt) {
                    return $argInfo->name;
                }
            }
            $this->fatalError($expr, 'wrong parameter position `' . $posInt . '`');
        } else {
            $this->fatalError($expr, 'func_get_arg() only support scalar int');
        }
    }

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
        if ($name === 'pow') {
            return 'php::math::pow(' . $getArg(0) . ', ' . $getArg(1) . ')';
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
        if ($name === 'func_get_arg') {
            return $this->genFuncGetArg($name, $expr);
        }
        if ($name === 'func_get_args') {
            return $this->genFuncGetArgs($name, $expr);
        }
        if ($name === 'func_num_args') {
            return $this->genFuncNumArgs($name, $expr);
        }
        if ($name === 'function_exists') {
            return $this->genFunctionExists($name, $expr);
        }
        return false;
    }

    protected function genFuncNumArgs(string $name, Node\Expr\FuncCall $expr): string
    {
        $funcDef = $this->functionDef;
        foreach ($funcDef->argInfoList as $i => $argInfo) {
            if ($argInfo->variadic) {
                return '(' . $argInfo->name . '.count() + ' . $i . ')';
            }
        }
        return count($funcDef->argInfoList);
    }

    protected function genFunctionExists(string $name, Node\Expr\FuncCall $expr): string
    {
        $funcName = $expr->args[0]->value;
        if ($this->isScalarString($funcName)) {
            $funcName = $this->getLiteralString(strtolower(trim($funcName->value, '\\')));
            return 'php::fn::function_exists(' . $funcName . ', true)';
        }
        return 'php::fn::function_exists(' . $this->parseIdentifier($funcName) . ')';
    }
}
