<?php
/**
 * This file is part of Swoole-Compiler(AOT).
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace PhpAot\Php;

use PhpParser\Node\Expr;

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
    public bool $unsafePtr = false;
}
