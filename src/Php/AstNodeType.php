<?php

namespace PhpAot\Php;

use PhpParser\Node\Expr;
use PhpParser\NodeAbstract;

trait AstNodeType
{
    protected function isVarExpr(NodeAbstract $expr): bool
    {
        return $expr instanceof Expr\Variable;
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