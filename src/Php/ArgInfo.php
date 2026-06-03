<?php
/**
 * This file is part of Swoole-Compiler(AOT).
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace PhpAot\Php;

use PhpParser\Node\Expr;
use PhpParser\NodeAbstract;

class ArgInfo
{
    public string $name;
    public string $type;
    public string $default = '';
    public ?Expr $defaultValue = null;
    public string $class = '';
    public bool $byRef = false;
    public bool $variadic = false;
    public bool $nullable = false;
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
