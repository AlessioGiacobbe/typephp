<?php
/**
 * This file is part of Swoole-Compiler(AOT).
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace PhpAot\Php;

use PhpAot\Php\Entity\ArrayInitPlan;
use PhpParser\Node\Expr;
use PhpParser\NodeAbstract;

class ArgInfo
{
    public string $name;
    public string $phpName = '';
    public string $type;
    public string $default = '';
    public ?ArrayInitPlan $arrayInitPlan = null;
    public ?Expr $defaultValue = null;
    public string $class = '';

    /**
     * Object type declared in the PHP signature, including interfaces.
     * Unlike $class, this is only an assignment/type-check constraint and must
     * not be used for typed-object native-call dispatch.
     */
    public string $declaredClass = '';
    public bool $byRef = false;
    public bool $variadic = false;
    public bool $nullable = false;
    public bool $undeclared = false;
    public bool $explicitMixed = false;
    public bool $property = false;

    /**
     * Each element: ['kind' => 'isInt'|'isFloat'|...|'instanceof', 'class' => '']
     * Null means no runtime type check needed.
     */
    public ?array $typeCheck = null;

    /** Human-readable type string for error messages, e.g. "int|string", "?int" */
    public string $typeStr = '';

    /** Original union/nullable AST node. Only set when typeCheck is non-null. */
    public ?NodeAbstract $typeNode = null;
}
