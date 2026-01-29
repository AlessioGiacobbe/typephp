<?php
/**
 * This file is part of Swoole-Compiler(AOT).
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

declare(strict_types=1);

namespace PhpAot\Php;

class InterfaceDef extends ClassLikeDef
{
    public function __construct(string $name, string $namespace = '')
    {
        parent::__construct($name, $namespace);
    }
}
