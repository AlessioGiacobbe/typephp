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
}
