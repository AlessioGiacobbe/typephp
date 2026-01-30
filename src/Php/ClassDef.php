<?php
/**
 * This file is part of Swoole-Compiler(AOT).
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

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

    public function hasProperty(string $property): bool
    {
        return isset($this->properties[$property]);
    }

    public function getProperty($property): PropertyDef
    {
        return $this->properties[$property];
    }
}
