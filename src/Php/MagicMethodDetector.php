<?php
/**
 * This file is part of Swoole-Compiler(AOT).
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

declare(strict_types=1);

namespace PhpAot\Php;

use PhpParser\NodeAbstract;

trait MagicMethodDetector
{
    public function checkRequiredArgNum(string $name, MethodDef $methodDef, NodeAbstract $v): void
    {
        if ($name == '__call' or $name == '__callStatic' or $name == '__set') {
            if (count($methodDef->functionDef->argInfoList) != 2) {
                $this->fatalError($v, 'Method ' . $this->class . "::{$name}() must take exactly 2 arguments");
            }
        } elseif ($name == '__get') {
            if (count($methodDef->functionDef->argInfoList) != 1) {
                $this->fatalError($v, 'Method ' . $this->class . "::{$name}() must take exactly 1 argument");
            }
        }
    }
}
