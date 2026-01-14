<?php

namespace PhpAot\Php;

class ClassDef extends ClassLikeDef
{
    /**
     * @var array<string, MethodDef>
     */
    public array $methods = [];
    /**
     * @var array<string, PropertyDef>
     */
    public array $properties = [];
    /**
     * @var array<string, ConstantDef>
     */
    public array $constants = [];
    public array $implements = [];
    public string $extends = '';

    public function __construct(string $name, string $namespace = '')
    {
        parent::__construct($name, $namespace);
    }
}