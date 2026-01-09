<?php

namespace PhpAot\Php;

class PropertyDef
{
    public string $name;
    public string $type;
    public string $flags;
    public ?string $default =  null;

    public function __construct(string $name, string $flags, string $type, ?string $default = null)
    {
        $this->flags = $flags;
        $this->name = $name;
        $this->type = $type;
        $this->default = $default;
    }
}