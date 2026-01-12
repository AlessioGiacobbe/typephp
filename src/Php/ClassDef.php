<?php

namespace PhpAot\Php;

class ClassDef
{
    public string $name;
    /**
     * @var array< MethodDef>
     */
    public array $methods = [];
    /**
     * @var array<PropertyDef>
     */
    public array $properties = [];
    /**
     * @var array<ConstantDef>
     */
    public array $constants = [];
}