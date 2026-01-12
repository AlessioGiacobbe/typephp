<?php

namespace PhpAot\Php;

class MethodDef
{
    public string $name;
    public int $flags;
    public string $returnType;

    public function __construct(string $name, int $flags, string $returnType)
    {
        $this->name = $name;
        $this->flags = $flags;
        $this->returnType = $returnType;
    }
}