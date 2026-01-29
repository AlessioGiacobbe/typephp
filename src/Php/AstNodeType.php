<?php

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

    protected function isNewExpr(NodeAbstract $expr): bool
    {
        return $expr instanceof Expr\New_;
    }

    protected function isNameExpr(NodeAbstract $expr): bool
    {
        return $expr instanceof Node\Name;
    }

    protected function isScalarString(NodeAbstract $expr): bool
    {
        return $expr instanceof Node\Scalar\String_;
    }

    protected function isFuncCallExpr(NodeAbstract $expr): bool
    {
        return $expr instanceof Expr\FuncCall;
    }
}
