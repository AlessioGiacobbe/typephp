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
        $fnDef = $methodDef->functionDef;
        $returnTypeUndeclared = $fnDef->returnTypeUndeclared;

        $nameLower = strtolower($name);

        if ($nameLower == '__call' or $nameLower == '__callstatic') {
            if (count($argInfoList) != 2) {
                $this->fatalError($v, 'Method ' . $this->class . "::{$name}() must take exactly 2 arguments");
            }
            if ($argInfoList[0]->undeclared) {
                $argInfoList[0]->type = self::TYPE_STR;
            } elseif ($argInfoList[0]->type !== self::TYPE_STR) {
                $this->fatalError($v, 'Method ' . $this->class . "::{$name}() must take string as first argument");
            }
            if ($argInfoList[1]->undeclared) {
                $argInfoList[1]->type = self::TYPE_ARRAY;
            } elseif ($argInfoList[1]->type !== self::TYPE_ARRAY) {
                $this->fatalError($v, 'Method ' . $this->class . "::{$name}() must take array as second argument");
            }
        } elseif ($nameLower == '__set') {
            if (count($argInfoList) != 2) {
                $this->fatalError($v, 'Method ' . $this->class . "::{$name}() must take exactly 2 arguments");
            }
            if ($argInfoList[0]->undeclared) {
                $argInfoList[0]->type = self::TYPE_STR;
            } elseif ($argInfoList[0]->type !== self::TYPE_STR) {
                $this->fatalError($v, 'Method ' . $this->class . "::{$name}() must take string as first argument");
            }
            if ($returnTypeUndeclared) {
                $fnDef->returnType = self::TYPE_VOID;
            } elseif ($fnDef->returnType !== self::TYPE_VOID) {
                $this->fatalError($v, 'Method ' . $this->class . "::{$name}() must return void");
            }
        } elseif ($nameLower == '__get') {
            if (count($argInfoList) != 1) {
                $this->fatalError($v, 'Method ' . $this->class . "::{$name}() must take exactly 1 argument");
            }
        } elseif ($nameLower == '__tostring') {
            if ($returnTypeUndeclared) {
                $fnDef->returnType = self::TYPE_STR;
            } elseif ($fnDef->returnType !== self::TYPE_STR) {
                $this->fatalError($v, 'Method ' . $this->class . "::{$name}() must return string");
            }
        } elseif ($nameLower == '__serialize') {
            if ($returnTypeUndeclared) {
                $fnDef->returnType = self::TYPE_ARRAY;
            } elseif ($fnDef->returnType !== self::TYPE_ARRAY) {
                $this->fatalError($v, 'Method ' . $this->class . "::{$name}() must return array");
            }
        } elseif ($nameLower == '__unserialize') {
            if (count($argInfoList) != 1) {
                $this->fatalError($v, 'Method ' . $this->class . "::{$name}() must take exactly 1 argument");
            }
            if ($argInfoList[0]->undeclared) {
                $argInfoList[0]->type = self::TYPE_ARRAY;
            } elseif ($argInfoList[0]->type !== self::TYPE_ARRAY) {
                $this->fatalError($v, 'Method ' . $this->class . "::{$name}() must take array as argument");
            }
            if ($returnTypeUndeclared) {
                $fnDef->returnType = self::TYPE_VOID;
            } elseif ($fnDef->returnType !== self::TYPE_VOID) {
                $this->fatalError($v, 'Method ' . $this->class . "::{$name}() must return void");
            }
        } elseif ($nameLower == '__isset') {
            if (count($argInfoList) != 1) {
                $this->fatalError($v, 'Method ' . $this->class . "::{$name}() must take exactly 1 argument");
            }
            if ($argInfoList[0]->undeclared) {
                $argInfoList[0]->type = self::TYPE_STR;
            } elseif ($argInfoList[0]->type !== self::TYPE_STR) {
                $this->fatalError($v, 'Method ' . $this->class . "::{$name}() must take string as argument");
            }
            if ($returnTypeUndeclared) {
                $fnDef->returnType = self::TYPE_BOOL;
            } elseif ($fnDef->returnType !== self::TYPE_BOOL) {
                $this->fatalError($v, 'Method ' . $this->class . "::{$name}() must return bool");
            }
        } elseif ($nameLower == '__unset') {
            if (count($argInfoList) != 1) {
                $this->fatalError($v, 'Method ' . $this->class . "::{$name}() must take exactly 1 argument");
            }
            if ($argInfoList[0]->undeclared) {
                $argInfoList[0]->type = self::TYPE_STR;
            } elseif ($argInfoList[0]->type !== self::TYPE_STR) {
                $this->fatalError($v, 'Method ' . $this->class . "::{$name}() must take string as argument");
            }
            if ($returnTypeUndeclared) {
                $fnDef->returnType = self::TYPE_VOID;
            } elseif ($fnDef->returnType !== self::TYPE_VOID) {
                $this->fatalError($v, 'Method ' . $this->class . "::{$name}() must return void");
            }
        } elseif ($nameLower == '__set_state') {
            if (count($argInfoList) != 1) {
                $this->fatalError($v, 'Method ' . $this->class . "::{$name}() must take exactly 1 argument");
            }
            if ($argInfoList[0]->undeclared) {
                $argInfoList[0]->type = self::TYPE_ARRAY;
            } elseif ($argInfoList[0]->type !== self::TYPE_ARRAY) {
                $this->fatalError($v, 'Method ' . $this->class . "::{$name}() must take array as argument");
            }
            if ($returnTypeUndeclared) {
                $fnDef->returnType = self::TYPE_OBJECT;
            } elseif ($fnDef->returnType !== self::TYPE_OBJECT) {
                $this->fatalError($v, 'Method ' . $this->class . "::{$name}() must return object");
            }
        } elseif ($nameLower == '__debuginfo') {
            if ($returnTypeUndeclared) {
                $fnDef->returnType = self::TYPE_ARRAY;
            } elseif ($fnDef->returnType !== self::TYPE_ARRAY) {
                $this->fatalError($v, 'Method ' . $this->class . "::{$name}() must return array");
            }
        } elseif ($nameLower == '__sleep') {
            if ($returnTypeUndeclared) {
                $fnDef->returnType = self::TYPE_ARRAY;
            } elseif ($fnDef->returnType !== self::TYPE_ARRAY) {
                $this->fatalError($v, 'Method ' . $this->class . "::{$name}() must return array");
            }
        } elseif ($nameLower == '__wakeup') {
            if ($returnTypeUndeclared) {
                $fnDef->returnType = self::TYPE_VOID;
            } elseif ($fnDef->returnType !== self::TYPE_VOID) {
                $this->fatalError($v, 'Method ' . $this->class . "::{$name}() must return void");
            }
        } elseif ($nameLower == '__clone') {
            if ($returnTypeUndeclared) {
                $fnDef->returnType = self::TYPE_VOID;
            } elseif ($fnDef->returnType !== self::TYPE_VOID) {
                $this->fatalError($v, 'Method ' . $this->class . "::{$name}() must return void");
            }
        }

        // 重建 params 字符串，使 C++ 函数签名使用 auto-fill 后的类型
        $list = [];
        foreach ($fnDef->argInfoList as $argInfo) {
            if ($argInfo->variadic) {
                $list[] = self::TYPE_ARRAY . ' ' . $argInfo->name;
            } else {
                $type = $argInfo->type;
                if ($type === self::TYPE_STREAM || $type === self::TYPE_BOX) {
                    $type = self::TYPE_VAR;
                }
                $list[] = $type . ' ' . $argInfo->name;
            }
        }
        $fnDef->params = implode(', ', $list);
    }

    protected function checkArgType(string $givenType, string $expectType, bool $canBeVar = true): bool
    {
        if ($canBeVar and $givenType == self::TYPE_VAR) {
            return true;
        }
        return $givenType == $expectType;
    }
}
