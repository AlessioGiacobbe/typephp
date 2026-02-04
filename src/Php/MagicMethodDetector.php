<?php
/**
 * This file is part of Swoole-Compiler(AOT).
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace PhpAot\Php;

use PhpAot\Php\Entity\MethodDef;
use PhpParser\NodeAbstract;

trait MagicMethodDetector
{
    public function checkRequiredArgNum(string $name, MethodDef $methodDef, NodeAbstract $v): void
    {
        if ($name == '__call' or $name == '__callStatic' or $name == '__set') {
            if (count($methodDef->functionDef->argInfoList) != 2) {
                $this->fatalError($v, 'Method ' . $this->class . "::{$name}() must take exactly 2 arguments");
            }
            if (!$methodDef->functionDef->argInfoList[0]->type or $methodDef->functionDef->argInfoList[0]->type != 'string') {
                $this->fatalError($v, 'Method ' . $this->class . "::{$name}() must take string as argument");
            }
        } elseif ($name == '__get') {
            if (count($methodDef->functionDef->argInfoList) != 1) {
                $this->fatalError($v, 'Method ' . $this->class . "::{$name}() must take exactly 1 argument");
            }
        } elseif ($name == '__toString') {
            if (!$methodDef->functionDef->returnType or $methodDef->functionDef->returnType != 'string') {
                $this->fatalError($v, 'Method ' . $this->class . "::{$name}() must return string");
            }
        } elseif ($name == '__serialize') {
            if (!$methodDef->functionDef->returnType or $methodDef->functionDef->returnType != 'array') {
                $this->fatalError($v, 'Method ' . $this->class . "::{$name}() must return array");
            }
        } elseif ($name == '__unserialize') {
            if (count($methodDef->functionDef->argInfoList) != 1) {
                $this->fatalError($v, 'Method ' . $this->class . "::{$name}() must take exactly 1 argument");
            } elseif (!$methodDef->functionDef->argInfoList[0]->type or $methodDef->functionDef->argInfoList[0]->type != 'array') {
                $this->fatalError($v, 'Method ' . $this->class . "::{$name}() must take array as argument");
            }
        } elseif ($name == '__isset' or $name == '__unset' or $name == '__set_state') {
            if (count($methodDef->functionDef->argInfoList) != 1) {
                $this->fatalError($v, 'Method ' . $this->class . "::{$name}() must take exactly 1 argument");
            }
            if (!$methodDef->functionDef->argInfoList[0]->type or $methodDef->functionDef->argInfoList[0]->type != 'string') {
                $this->fatalError($v, 'Method ' . $this->class . "::{$name}() must take string as argument");
            }
            if ($name == '__set_state') {
                if (!$methodDef->functionDef->returnType or $methodDef->functionDef->returnType != 'array') {
                    $this->fatalError($v, 'Method ' . $this->class . "::{$name}() must return array");
                }
            }
        } elseif ($name == '__debugInfo') {
            if (!$methodDef->functionDef->returnType or $methodDef->functionDef->returnType != 'array') {
                $this->fatalError($v, 'Method ' . $this->class . "::{$name}() must return array");
            }
        }
    }
}
