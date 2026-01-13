<?php

namespace PhpAot\Php;

class MethodDef
{
    public int $flags;
    public string $name;
    public FunctionDef $functionDef;

    public function __construct(int $flags, string $name, FunctionDef $def)
    {
        $this->flags = $flags;
        $this->name = $name;
        $this->functionDef = $def;
    }

    public function getReturnType(): string
    {
        return $this->functionDef->returnType;
    }
}
