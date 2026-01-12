<?php

namespace PhpAot\Php;

class ConstantDef
{
    public string $name;
    public string $type;
    public string $flags;
    public string $value;

    public function __construct(string $name, string $flags, string $type, string $value)
    {
        $this->name = $name;
        $this->type = $type;
        $this->flags = $flags;
        $this->value = $value;
    }
}