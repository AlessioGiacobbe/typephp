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
    public bool $requireCtor = false;
    public int $flags;

    public function __construct(string $name, int $flags, string $namespace = '')
    {
        $this->flags = $flags;
        parent::__construct($name, $namespace);
    }

    public function hasMethod(string $method): bool
    {
        return isset($this->methods[$method]);
    }
}