<?php
/**
 * This file is part of Swoole-Compiler(AOT).
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace PhpAot\Php\Resolver;

use PhpAot\Php\Entity\ClassDef;
use PhpParser\NodeAbstract;

interface PropertyAccessContext
{
    public function getClassDef(string $name): ?ClassDef;

    public function getParentClass(string $class): string;

    public function fatalError(NodeAbstract $node, string $msg): never;
}
