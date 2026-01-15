<?php

namespace PhpAot\Php;

use PhpParser\Node\Expr;

trait AstNodeType
{
    protected function isVarExpr(Expr $expr): bool
    {
        return $expr instanceof Expr\Variable;
    }
}