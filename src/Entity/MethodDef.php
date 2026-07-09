<?php
/**
 * This file is part of TypePHP.
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace TypePhp\Entity;

class MethodDef
{
    public int $flags;
    public string $name;
    public ?FunctionDef $functionDef = null;
    public bool $hasDynamicCall = false;

    public function __construct(int $flags, string $name)
    {
        $this->flags = $flags;
        $this->name = $name;
    }

    public function getReturnType(): string
    {
        return $this->functionDef->returnType;
    }
}
