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
        $argInfoList = $methodDef->functionDef->argInfoList;
        $returnType = $methodDef->functionDef->returnType;

        $nameLower = strtolower($name);

        if ($nameLower == '__call' or $nameLower == '__callstatic' or $nameLower == '__set') {
            if (count($argInfoList) != 2) {
                $this->fatalError($v, 'Method ' . $this->class . "::{$name}() must take exactly 2 arguments");
            }
            if (!$this->checkArgType($argInfoList[0]->type, self::TYPE_STR)) {
                $this->fatalError($v, 'Method ' . $this->class . "::{$name}() must take string as argument");
            }
        } elseif ($nameLower == '__get') {
            if (count($argInfoList) != 1) {
                $this->fatalError($v, 'Method ' . $this->class . "::{$name}() must take exactly 1 argument");
            }
        } elseif ($nameLower == '__tostring') {
            if ($returnType != self::TYPE_VAR and $returnType != self::TYPE_STR) {
                $this->fatalError($v, 'Method ' . $this->class . "::{$name}() must return string");
            }
        } elseif ($nameLower == '__serialize') {
            if ($returnType != self::TYPE_VAR and $returnType != self::TYPE_ARRAY) {
                $this->fatalError($v, 'Method ' . $this->class . "::{$name}() must return array");
            }
        } elseif ($nameLower == '__unserialize') {
            if (count($argInfoList) != 1) {
                $this->fatalError($v, 'Method ' . $this->class . "::{$name}() must take exactly 1 argument");
            } elseif (!$argInfoList[0]->type or $argInfoList[0]->type != self::TYPE_ARRAY) {
                $this->fatalError($v, 'Method ' . $this->class . "::{$name}() must take array as argument");
            }
        } elseif ($nameLower == '__isset' or $nameLower == '__unset' or $nameLower == '__set_state') {
            if (count($argInfoList) != 1) {
                $this->fatalError($v, 'Method ' . $this->class . "::{$name}() must take exactly 1 argument");
            }
            if (!$argInfoList[0]->type or $argInfoList[0]->type != self::TYPE_STR) {
                $this->fatalError($v, 'Method ' . $this->class . "::{$name}() must take string as argument");
            }
            if ($nameLower == '__set_state') {
                if (!$returnType or $returnType != self::TYPE_ARRAY) {
                    $this->fatalError($v, 'Method ' . $this->class . "::{$name}() must return array");
                }
            }
        } elseif ($nameLower == '__debuginfo') {
            if (!$returnType or $returnType != self::TYPE_ARRAY) {
                $this->fatalError($v, 'Method ' . $this->class . "::{$name}() must return array");
            }
        }
    }

    protected function checkArgType(string $givenType, string $expectType, bool $canBeVar = true): bool
    {
        if ($canBeVar and $givenType == self::TYPE_VAR) {
            return true;
        }
        return $givenType == $expectType;
    }
}
