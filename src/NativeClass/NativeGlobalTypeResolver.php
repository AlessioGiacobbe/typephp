<?php
/**
 * This file is part of Swoole-Compiler(AOT).
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace TypePhp\NativeClass;

use TypePhp\Entity\ClassDef;

/**
 * Immutable class metadata used by the Native global pre-pass.
 *
 * The analyzer needs no reference back to the compiler or its mutable
 * per-function context. This keeps the project-level pass deterministic and
 * prevents a short-lived analysis object from retaining the Translator.
 */
final class NativeGlobalTypeResolver
{
    /** @var array<string, string> */
    private array $classes = [];

    /** @var array<string, true> */
    private array $nativeClasses = [];

    /** @var array<string, string> */
    private array $parents = [];

    /** @var array<string, array<string, ?string>> */
    private array $methodReturns = [];

    /** @var array<string, array<string, ?string>> */
    private array $propertyClasses = [];

    /** @param array<string, ClassDef> $classes */
    public function __construct(array $classes)
    {
        foreach ($classes as $class) {
            $name = $class->getNamespacedName(false);
            $key = strtolower(ltrim($name, '\\'));
            $this->classes[$key] = $name;
            if ($class->nativeObject) {
                $this->nativeClasses[$key] = true;
            }
        }

        foreach ($classes as $class) {
            $name = $class->getNamespacedName(false);
            $key = strtolower(ltrim($name, '\\'));
            $parent = $this->canonicalClass($class->extends);
            if ($parent !== null) {
                $this->parents[$key] = $parent;
            }

            foreach ($class->methods as $method => $definition) {
                $this->methodReturns[$key][$method]
                    = $this->canonicalClass($definition->functionDef->returnClass);
            }
            foreach ($class->abstractMethodDefs as $method => $definition) {
                $this->methodReturns[$key][$method]
                    = $this->canonicalClass($definition->functionDef->returnClass);
            }
            foreach ($class->properties as $property => $definition) {
                $this->propertyClasses[$key][$property]
                    = $this->canonicalClass($definition->class);
            }
        }
    }

    public function canonicalClass(string $class): ?string
    {
        return $this->classes[strtolower(ltrim($class, '\\'))] ?? null;
    }

    public function nativeClass(string $class): ?string
    {
        $key = strtolower(ltrim($class, '\\'));
        return isset($this->nativeClasses[$key]) ? $this->classes[$key] : null;
    }

    public function methodReturn(string $class, string $method): ?string
    {
        $key = strtolower(ltrim($class, '\\'));
        $method = strtolower($method);
        while (isset($this->classes[$key])) {
            if (array_key_exists($method, $this->methodReturns[$key] ?? [])) {
                return $this->methodReturns[$key][$method];
            }
            $parent = $this->parents[$key] ?? null;
            if ($parent === null) {
                return null;
            }
            $key = strtolower($parent);
        }
        return null;
    }

    public function propertyClass(string $class, string $property): ?string
    {
        $key = strtolower(ltrim($class, '\\'));
        while (isset($this->classes[$key])) {
            if (array_key_exists($property, $this->propertyClasses[$key] ?? [])) {
                return $this->propertyClasses[$key][$property];
            }
            $parent = $this->parents[$key] ?? null;
            if ($parent === null) {
                return null;
            }
            $key = strtolower($parent);
        }
        return null;
    }

    public function commonClass(string $left, string $right): ?string
    {
        $leftKey = strtolower(ltrim($left, '\\'));
        $rightKey = strtolower(ltrim($right, '\\'));
        $leftAncestors = [];
        while (isset($this->classes[$leftKey])) {
            $leftAncestors[$leftKey] = true;
            $parent = $this->parents[$leftKey] ?? null;
            if ($parent === null) {
                break;
            }
            $leftKey = strtolower($parent);
        }

        while (isset($this->classes[$rightKey])) {
            if (isset($leftAncestors[$rightKey])) {
                return $this->classes[$rightKey];
            }
            $parent = $this->parents[$rightKey] ?? null;
            if ($parent === null) {
                break;
            }
            $rightKey = strtolower($parent);
        }
        return null;
    }

    public function parentClass(string $class): ?string
    {
        return $this->parents[strtolower(ltrim($class, '\\'))] ?? null;
    }
}
