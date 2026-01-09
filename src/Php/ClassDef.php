<?php

namespace PhpAot\Php;

class ClassDef
{
    public string $name;
    public array $methods;
    /**
     * @var array<PropertyDef>
     */
    public array $properties;
    public array $constants;
}