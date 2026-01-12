<?php

namespace PhpAot\Php;

class MethodDef
{

    public string $name;
    public string $flags;

    public function __construct(string $name, string $flags)
    {
        $this->name = $name;
        $this->flags = $flags;
    }
}