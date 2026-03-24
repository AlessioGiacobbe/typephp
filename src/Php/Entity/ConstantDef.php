<?php
/**
 * This file is part of Swoole-Compiler(AOT).
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace PhpAot\Php\Entity;

use PhpParser\Node\Expr;

class ConstantDef
{
    public string $name;
    public string $type;
    public string $flags;
    public string $value;
    public string $arrayExpr;

    public function __construct(string $name, string $flags, string $type, string $value, string $arrayExpr = '')
    {
        $this->name  = $name;
        $this->type  = $type;
        $this->flags = $flags;
        $this->value = $value;
        $this->arrayExpr  = $arrayExpr;
    }
}
