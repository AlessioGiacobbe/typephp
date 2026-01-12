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
    public string $namespace = '';
    public array $implements = [];
    public string $extends = '';

    public function getNamespacedName(): string
    {
        if ($this->namespace === '') {
            return $this->name;
        }
        return str_replace('\\', '_', $this->namespace . '_' . $this->name);
    }
}