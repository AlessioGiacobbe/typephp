<?php
/**
 * This file is part of Swoole-Compiler(AOT).
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace PhpAot\Php;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\VariadicPlaceholder;
use PhpParser\NodeAbstract;

trait AstNodeType
{
    protected function isArrayDimFetch(NodeAbstract $expr): bool
    {
        return $expr instanceof Expr\ArrayDimFetch;
    }

    protected function isVarExpr(NodeAbstract $expr): bool
    {
        return $expr instanceof Expr\Variable;
    }

    protected function isIdExpr(NodeAbstract $expr): bool
    {
        return $expr instanceof Node\Identifier;
    }

    protected function isPropertyFetch(NodeAbstract $expr): bool
    {
        return $expr instanceof Expr\PropertyFetch;
    }

    protected function isStaticPropertyFetch(NodeAbstract $expr): bool
    {
        return $expr instanceof Expr\StaticPropertyFetch;
    }

    protected function isClassConstFetch(NodeAbstract $expr): bool
    {
        return $expr instanceof Expr\ClassConstFetch;
    }

    protected function isNewExpr(NodeAbstract $expr): bool
    {
        return $expr instanceof Expr\New_;
    }

    protected function isNameExpr(NodeAbstract $expr): bool
    {
        return $expr instanceof Node\Name;
    }

    protected function isNamedMethod(NodeAbstract $expr): bool
    {
        return $this->isIdExpr($expr);
    }

    protected function isScalarString(NodeAbstract $expr): bool
    {
        return $expr instanceof Node\Scalar\String_;
    }

    protected function isFuncCallExpr(NodeAbstract $expr): bool
    {
        return $expr instanceof Expr\FuncCall;
    }

    protected function isMethodCall(NodeAbstract $expr): bool
    {
        return $expr instanceof Expr\MethodCall;
    }

    protected function isStaticCall(NodeAbstract $expr): bool
    {
        return $expr instanceof Expr\StaticCall;
    }

    protected function isScalar(NodeAbstract $expr): bool
    {
        return $expr instanceof Node\Scalar;
    }

    protected function isMatchExpr(NodeAbstract $expr): bool
    {
        return $expr instanceof Expr\Match_;
    }

    protected function isConstFetch(NodeAbstract $expr): bool
    {
        return $expr instanceof Expr\ConstFetch;
    }

    protected function isAssignOp(NodeAbstract $expr): bool
    {
        return $expr instanceof Expr\AssignOp or $expr instanceof Expr\Assign;
    }

    protected function isCallExpr(NodeAbstract $expr): bool
    {
        return $expr instanceof Expr\FuncCall
            or $expr instanceof Expr\MethodCall
            or $expr instanceof Expr\StaticCall;
    }

    protected function isPlaceholderExpr(NodeAbstract $expr): bool
    {
        return $expr instanceof VariadicPlaceholder;
    }

    protected function isReturnExpr(NodeAbstract $expr): bool
    {
        return $expr instanceof Node\Stmt\Return_;
    }

    protected function isBreakExpr(NodeAbstract $expr): bool
    {
        return $expr instanceof Node\Stmt\Break_;
    }

    protected function isExitExpr(NodeAbstract $expr): bool
    {
        if ($expr instanceof Node\Stmt\Expression) {
            $expr = $expr->expr;
        }
        return $expr instanceof Expr\Exit_;
    }

    protected function isEmptyArray(NodeAbstract $expr): bool
    {
        return $expr instanceof Node\Expr\Array_ && count($expr->items) === 0;
    }
}
