<?php
/**
 * This file is part of Swoole-Compiler(AOT).
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace PhpAot\Php;

class ArgInfo
{
    public string $name;
    public string $type;
    public string $default = '';
    public bool $byRef = false;
    public bool $variadic = false;
}
