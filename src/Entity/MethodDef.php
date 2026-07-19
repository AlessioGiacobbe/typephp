<?php
/**
 * This file is part of TypePHP.
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace TypePhp\Entity;

class MethodDef
{
    public int $flags;
    public string $name;
    public ?FunctionDef $functionDef = null;
    public bool $hasDynamicCall = false;

    /**
     * The original `ClassMethod` AST node this definition was parsed from.
     * Stored so that later validation (e.g. trait method override compatibility
     * checks performed at the `use` site) can report accurate line information.
     */
    public ?\PhpParser\Node\Stmt\ClassMethod $node = null;

    /**
     * For methods defined inside a trait, records `parent::method()` calls so
     * the compiler can validate their visibility against the parent of each
     * class that uses the trait (the trait itself has no parent at compile time).
     *
     * @var array<int, array{method: string, node: \PhpParser\NodeAbstract}>
     */
    public array $parentMethodCalls = [];

    public function __construct(int $flags, string $name)
    {
        $this->flags = $flags;
        $this->name = $name;
    }

    public function getReturnType(): string
    {
        return $this->functionDef->returnType;
    }
}
