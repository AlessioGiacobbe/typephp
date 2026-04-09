<?php
/**
 * This file is part of Swoole-Compiler(AOT).
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace PhpAot\Php\Entity;

class ConstantDef
{
    public string $name;
    public string $type;
    public int $flags;
    public string $value;
    public string $arrayExpr = '';
    public string $class = '';

    public function __construct(string $name, int $flags, string $type, string $value)
    {
        $this->name  = $name;
        $this->type  = $type;
        $this->flags = $flags;
        $this->value = $value;
    }
}
