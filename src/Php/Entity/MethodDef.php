<?php
/**
 * This file is part of Swoole-Compiler(AOT).
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace PhpAot\Php\Entity;

class MethodDef
{
    public int $flags;
    public string $name;
    public ?FunctionDef $functionDef = null;

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
