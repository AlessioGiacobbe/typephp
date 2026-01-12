<?php

namespace PhpAot\Php;

class ClassDef
{
    public string $name;
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