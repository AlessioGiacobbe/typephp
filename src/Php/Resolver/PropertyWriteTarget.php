<?php
/**
 * This file is part of Swoole-Compiler(AOT).
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace PhpAot\Php\Resolver;

use PhpParser\NodeAbstract;

final readonly class PropertyWriteTarget
{
    public function __construct(
        public NodeAbstract $node,
        public string $label,
    ) {
    }
}
