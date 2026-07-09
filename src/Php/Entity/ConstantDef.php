<?php
/**
 * This file is part of TypePHP.
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace PhpAot\Php\Entity;

use PhpParser\NodeAbstract;

class ConstantDef
{
    public string $name;
    public string $type;
    public int $flags;
    public string $value;
    public string $arrayExpr = '';
    public string $class = '';
    public ?NodeAbstract $valueExpr = null;

    public function __construct(string $name, int $flags, string $type, string $value)
    {
        $this->name  = $name;
        $this->type  = $type;
        $this->flags = $flags;
        $this->value = $value;
    }
}
