<?php

namespace PhpAot\Php;

class MethodDef
{
    public int $flags;
    public string $name;
    public FunctionDef $def;

    public function __construct(int $flags, string $name, FunctionDef $def)
    {
        $this->flags = $flags;
        $this->name = $name;
        $this->def = $def;
    }

    public function getFlags(): int
    {
        return $this->flags;
    }

    public function getReturnType(): string
    {
        return $this->def->returnType;
    }
}
