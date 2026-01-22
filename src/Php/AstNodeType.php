<?php

namespace PhpAot\Php;

use PhpParser\Node\Expr;
use PhpParser\NodeAbstract;
use PhpParser\Node;

trait AstNodeType
{
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
}