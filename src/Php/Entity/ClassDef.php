<?php
/**
 * This file is part of Swoole-Compiler(AOT).
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace PhpAot\Php\Entity;

use PhpAot\Php\Context\FunctionContext;
use PhpParser\Modifiers;
use PhpParser\Node\Stmt\Trait_;

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
    public bool $enum = false;

    /**
     * Backing type for backed enums ('int' or 'string'), null for pure enums.
     */
    public ?string $enumBackingType = null;

    /**
     * Enum cases: case name => backing value (int/string for backed enums, null for pure enums).
     * @var array<string, int|string|null>
     */
    public array $enumCases = [];
    public ?Trait_ $trait = null;

    /**
     * FullMethodName -> NewMethodName
     * @var array<string, array>
     */
    public array $traitAliases = [];

    /**
     * FullMethodName -> true
     * @var array<string, bool>
     */
    public array $traitIgnored = [];
    public int $flags;
    public bool $inheritedFromInternalClass = false;
    public string $ctorInit = '';
    public string $ctorClean = '';
    public FunctionContext $propertyContext;

    public function __construct(string $name, int $flags, string $namespace = '')
    {
        $this->flags = $flags;
        $this->propertyContext = new FunctionContext();
        parent::__construct($name, $namespace);
    }

    public function addMethod(MethodDef $method): void
    {
        $this->methods[strtolower($method->name)] = $method;
    }

    public function hasMethod(string $method): bool
    {
        return isset($this->methods[strtolower($method)]);
    }

    public function hasProperty(string $property): bool
    {
        return isset($this->properties[$property]);
    }

    public function hasConstant(string $name): bool
    {
        return isset($this->constants[$name]);
    }

    public function getProperty($property): PropertyDef
    {
        return $this->properties[$property];
    }

    public function getMethod($method): MethodDef
    {
        return $this->methods[strtolower($method)];
    }

    public function getConstant($name): ConstantDef
    {
        return $this->constants[$name];
    }

    public function isAbstract(): bool
    {
        return $this->flags & Modifiers::ABSTRACT;
    }
}
