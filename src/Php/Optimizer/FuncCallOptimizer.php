<?php
/**
 * This file is part of Swoole-Compiler(AOT).
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace PhpAot\Php\Optimizer;

use PhpParser\Node;

trait FuncCallOptimizer
{
    public function genFuncGetArgs(string $name, Node\Expr\FuncCall $expr): string
    {
        $this->warningUndefinedBehavior($expr);
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
        $this->warningUndefinedBehavior($expr);
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

    protected function isValidDefineName(string $name): bool
    {
        return preg_match('/^(?!\d)[\p{L}_][\p{L}\p{N}_]*$/u', $name) === 1;
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
                    $arg = $expr->args[0]->value;
                    $type = $this->detectTypeOfExpr($arg);
                    $parsed = $this->parseExpr($arg);
                    if ($type === self::TYPE_BIGINT) {
                        return 'php::BigInt::toString(' . $parsed . ')';
                    }
                    if ($type === self::TYPE_BIGFLOAT) {
                        return 'php::BigFloat::toString(' . $parsed . ')';
                    }
                    if ($type === self::TYPE_DECIMAL) {
                        return 'php::Decimal::toString(' . $parsed . ')';
                    }
                    return $this->convertStringExpr($parsed);
                default:
                    break;
            }
        } elseif (count($expr->args) == 2) {
            switch ($name) {
                case 'define':
                    $arg1 = $expr->args[0]->value;
                    if ($this->isScalarString($arg1) and !$this->isValidDefineName($arg1->value)) {
                        $this->fatalError($expr, 'Invalid define name `' . $arg1->value . '`');
                    }
                    break;
                default:
                    break;
            }
        }
        if ($name === 'abs') {
            $type = $this->detectTypeOfExpr($expr->args[0]->value);
            if ($type === self::TYPE_BIGINT) {
                return 'php::BigInt::abs(' . $this->parseExpr($expr->args[0]->value) . ')';
            }
            if ($type === self::TYPE_BIGFLOAT) {
                return 'php::BigFloat::abs(' . $this->parseExpr($expr->args[0]->value) . ')';
            }
            if ($type === self::TYPE_DECIMAL) {
                return 'php::Decimal::abs(' . $this->parseExpr($expr->args[0]->value) . ')';
            }
            return 'php::math::abs(' . $getArg(0) . ')';
        }
        if ($name === 'pow') {
            $type = $this->detectTypeOfExpr($expr->args[0]->value);
            if ($type === self::TYPE_BIGINT) {
                return 'php::BigInt::pow(' . $this->parseExpr($expr->args[0]->value) . ', ' . $this->parseExpr($expr->args[1]->value) . ')';
            }
            if ($type === self::TYPE_DECIMAL) {
                return 'php::Decimal::pow(' . $this->parseExpr($expr->args[0]->value) . ', ' . $this->parseExpr($expr->args[1]->value) . ')';
            }
            return 'php::math::pow(' . $getArg(0) . ', ' . $getArg(1) . ')';
        }
        if ($name === 'sqrt') {
            $type = $this->detectTypeOfExpr($expr->args[0]->value);
            if ($type === self::TYPE_BIGINT) {
                return 'php::BigInt::sqrt(' . $this->parseExpr($expr->args[0]->value) . ')';
            }
            if ($type === self::TYPE_DECIMAL) {
                return 'php::Decimal::sqrt(' . $this->parseExpr($expr->args[0]->value) . ')';
            }
            if ($type === self::TYPE_BIGFLOAT) {
                return 'php::BigFloat::sqrt(' . $this->parseExpr($expr->args[0]->value) . ')';
            }
        }
        if ($name === 'floor') {
            $type = $this->detectTypeOfExpr($expr->args[0]->value);
            if ($type === self::TYPE_DECIMAL) {
                return 'php::Decimal::floor(' . $this->parseExpr($expr->args[0]->value) . ')';
            }
        }
        if ($name === 'ceil') {
            $type = $this->detectTypeOfExpr($expr->args[0]->value);
            if ($type === self::TYPE_DECIMAL) {
                return 'php::Decimal::ceil(' . $this->parseExpr($expr->args[0]->value) . ')';
            }
        }
        if ($name === 'round') {
            $type = $this->detectTypeOfExpr($expr->args[0]->value);
            if ($type === self::TYPE_DECIMAL) {
                $arg0 = $this->parseExpr($expr->args[0]->value);
                if (count($expr->args) >= 2) {
                    return 'php::Decimal::round(' . $arg0 . ', ' . $this->parseExpr($expr->args[1]->value) . ')';
                }
                return 'php::Decimal::round(' . $arg0 . ')';
            }
        }
        if ($name === 'strlen') {
            if ($expr->args[0]->value instanceof Node\Scalar\String_) {
                return strlen($expr->args[0]->value->value) . $this->getPlatform()->getIntegerLiteralSuffix();
            }
            return 'php::fn::strlen(' . $getArg(0) . ')';
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
        if ($name === 'class_exists') {
            $className = $expr->args[0]->value;
            if ($this->isScalarString($className) and $this->hasClass($className->value)) {
                return 'true';
            }
        }
        if ($name === 'compact') {
            return $this->genCompact($expr);
        }
        if ($name === 'get_class') {
            return $this->genGetClass($expr);
        }
        return false;
    }

    protected function genFuncNumArgs(string $name, Node\Expr\FuncCall $expr): string
    {
        $this->warningUndefinedBehavior($expr);
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
            $nameLower = strtolower(trim($funcName->value, '\\'));
            if ($this->findNativeFunction($nameLower)) {
                return 'true';
            }
            $funcName = $this->getLiteralString($nameLower);
            return 'php::fn::function_exists(' . $funcName . ', true)';
        }
        return 'php::fn::function_exists(' . $this->parseIdentifier($funcName) . ')';
    }

    protected function genGetClass(Node\Expr\FuncCall $expr): string
    {
        $object = $expr->args[0]->value;
        if ($this->isVarExpr($object) and $this->isTypedObject($object->name)) {
            return $this->getLiteralString($this->getObjectType($object->name));
        }
        return 'php::fn::get_class(' . $this->parseIdentifier($object) . ')';
    }

    protected function genCompact(Node\Expr\FuncCall $expr): string
    {
        $list = [];

        $this->indentLevel++;
        foreach ($expr->args as $arg) {
            if (!$this->isScalarString($arg->value)) {
                $this->fatalError($expr, 'The argument of compact function can only be literal string');
            }
            $var = $arg->value->value;
            if (!$this->hasVar($var)) {
                $this->errorUndefinedVariable($var);
            }
            if ($this->isSuperGlobal($var)) {
                $this->fatalError($expr, 'Cannot use super global variable `' . $var . '` in compact function');
            }

            $key = $this->getLiteralString($var);
            $list[] = $this->getIndent() . '{ ' . $key . '.str(), ' . $var . ' }';
        }
        $this->indentLevel--;

        return $this->genArray($list);
    }
}
