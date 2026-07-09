<?php
/**
 * This file is part of TypePHP.
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
        $methodName = $this->class . "::{$name}";
        $isStatic = (bool) ($methodDef->flags & \PhpParser\Modifiers::STATIC);

        $mustBeStatic = ['__callstatic' => true, '__set_state' => true];
        $mustNotBeStatic = [
            '__construct' => true,
            '__destruct' => true,
            '__clone' => true,
            '__call' => true,
            '__get' => true,
            '__set' => true,
            '__isset' => true,
            '__unset' => true,
            '__sleep' => true,
            '__wakeup' => true,
            '__serialize' => true,
            '__unserialize' => true,
            '__debuginfo' => true,
            '__tostring' => true,
            '__invoke' => true,
        ];
        if (isset($mustBeStatic[$nameLower]) && !$isStatic) {
            $this->fatalError($v, 'Method ' . $methodName . '() must be static');
        }
        if (isset($mustNotBeStatic[$nameLower]) && $isStatic) {
            $this->fatalError($v, 'Method ' . $methodName . '() cannot be static');
        }

        $mustBePublic = [
            '__call' => true,
            '__callstatic' => true,
            '__get' => true,
            '__set' => true,
            '__isset' => true,
            '__unset' => true,
            '__sleep' => true,
            '__wakeup' => true,
            '__serialize' => true,
            '__unserialize' => true,
            '__debuginfo' => true,
            '__tostring' => true,
            '__invoke' => true,
            '__set_state' => true,
        ];
        if (isset($mustBePublic[$nameLower]) && !($methodDef->flags & \PhpParser\Modifiers::PUBLIC)) {
            $this->fatalError($v, 'Method ' . $methodName . '() must have public visibility');
        }

        $exactArgCount = [
            '__destruct' => 0,
            '__clone' => 0,
            '__tostring' => 0,
            '__sleep' => 0,
            '__wakeup' => 0,
            '__serialize' => 0,
            '__debuginfo' => 0,
            '__call' => 2,
            '__callstatic' => 2,
            '__set' => 2,
            '__get' => 1,
            '__isset' => 1,
            '__unset' => 1,
            '__set_state' => 1,
            '__unserialize' => 1,
        ];
        if (array_key_exists($nameLower, $exactArgCount) && count($argInfoList) !== $exactArgCount[$nameLower]) {
            $this->fatalError($v, 'Method ' . $methodName . '() must take exactly ' . $exactArgCount[$nameLower] . ' arguments');
        }

        if ($nameLower == '__call' or $nameLower == '__callstatic') {
            if ($argInfoList[0]->undeclared) {
                $argInfoList[0]->type = self::TYPE_STR;
            } elseif ($argInfoList[0]->type !== self::TYPE_STR) {
                $this->fatalError($v, 'Method ' . $methodName . '() must take string as first argument');
            }
            if ($argInfoList[1]->undeclared) {
                $argInfoList[1]->type = self::TYPE_ARRAY;
            } elseif ($argInfoList[1]->type !== self::TYPE_ARRAY) {
                $this->fatalError($v, 'Method ' . $methodName . '() must take array as second argument');
            }
        } elseif ($nameLower == '__set') {
            if ($argInfoList[0]->undeclared) {
                $argInfoList[0]->type = self::TYPE_STR;
            } elseif ($argInfoList[0]->type !== self::TYPE_STR) {
                $this->fatalError($v, 'Method ' . $methodName . '() must take string as first argument');
            }
            if ($returnTypeUndeclared) {
                $fnDef->returnType = self::TYPE_VOID;
            } elseif ($fnDef->returnType !== self::TYPE_VOID) {
                $this->fatalError($v, 'Method ' . $methodName . '() must return void');
            }
        } elseif ($nameLower == '__get') {
            if ($argInfoList[0]->undeclared) {
                $argInfoList[0]->type = self::TYPE_STR;
            } elseif ($argInfoList[0]->type !== self::TYPE_STR) {
                $this->fatalError($v, 'Method ' . $methodName . '() must take string as argument');
            }
        } elseif ($nameLower == '__tostring') {
            if ($returnTypeUndeclared) {
                $fnDef->returnType = self::TYPE_STR;
            } elseif ($fnDef->returnType !== self::TYPE_STR) {
                $this->fatalError($v, 'Method ' . $methodName . '() must return string');
            }
        } elseif ($nameLower == '__serialize') {
            if ($returnTypeUndeclared) {
                $fnDef->returnType = self::TYPE_ARRAY;
            } elseif ($fnDef->returnType !== self::TYPE_ARRAY) {
                $this->fatalError($v, 'Method ' . $methodName . '() must return array');
            }
        } elseif ($nameLower == '__unserialize') {
            if ($argInfoList[0]->undeclared) {
                $argInfoList[0]->type = self::TYPE_ARRAY;
            } elseif ($argInfoList[0]->type !== self::TYPE_ARRAY) {
                $this->fatalError($v, 'Method ' . $methodName . '() must take array as argument');
            }
            if ($returnTypeUndeclared) {
                $fnDef->returnType = self::TYPE_VOID;
            } elseif ($fnDef->returnType !== self::TYPE_VOID) {
                $this->fatalError($v, 'Method ' . $methodName . '() must return void');
            }
        } elseif ($nameLower == '__isset') {
            if ($argInfoList[0]->undeclared) {
                $argInfoList[0]->type = self::TYPE_STR;
            } elseif ($argInfoList[0]->type !== self::TYPE_STR) {
                $this->fatalError($v, 'Method ' . $methodName . '() must take string as argument');
            }
            if ($returnTypeUndeclared) {
                $fnDef->returnType = self::TYPE_BOOL;
            } elseif ($fnDef->returnType !== self::TYPE_BOOL) {
                $this->fatalError($v, 'Method ' . $methodName . '() must return bool');
            }
        } elseif ($nameLower == '__unset') {
            if ($argInfoList[0]->undeclared) {
                $argInfoList[0]->type = self::TYPE_STR;
            } elseif ($argInfoList[0]->type !== self::TYPE_STR) {
                $this->fatalError($v, 'Method ' . $methodName . '() must take string as argument');
            }
            if ($returnTypeUndeclared) {
                $fnDef->returnType = self::TYPE_VOID;
            } elseif ($fnDef->returnType !== self::TYPE_VOID) {
                $this->fatalError($v, 'Method ' . $methodName . '() must return void');
            }
        } elseif ($nameLower == '__set_state') {
            if ($argInfoList[0]->undeclared) {
                $argInfoList[0]->type = self::TYPE_ARRAY;
            } elseif ($argInfoList[0]->type !== self::TYPE_ARRAY) {
                $this->fatalError($v, 'Method ' . $methodName . '() must take array as argument');
            }
            if ($returnTypeUndeclared) {
                $fnDef->returnType = self::TYPE_OBJECT;
            } elseif ($fnDef->returnType !== self::TYPE_OBJECT) {
                $this->fatalError($v, 'Method ' . $methodName . '() must return object');
            }
        } elseif ($nameLower == '__debuginfo') {
            if ($returnTypeUndeclared) {
                $fnDef->returnType = self::TYPE_ARRAY;
            } elseif ($fnDef->returnType !== self::TYPE_ARRAY) {
                $this->fatalError($v, 'Method ' . $methodName . '() must return array');
            }
        } elseif ($nameLower == '__sleep') {
            if ($returnTypeUndeclared) {
                $fnDef->returnType = self::TYPE_ARRAY;
            } elseif ($fnDef->returnType !== self::TYPE_ARRAY) {
                $this->fatalError($v, 'Method ' . $methodName . '() must return array');
            }
        } elseif ($nameLower == '__wakeup') {
            if ($returnTypeUndeclared) {
                $fnDef->returnType = self::TYPE_VOID;
            } elseif ($fnDef->returnType !== self::TYPE_VOID) {
                $this->fatalError($v, 'Method ' . $methodName . '() must return void');
            }
        } elseif ($nameLower == '__clone') {
            if ($returnTypeUndeclared) {
                $fnDef->returnType = self::TYPE_VOID;
            } elseif ($fnDef->returnType !== self::TYPE_VOID) {
                $this->fatalError($v, 'Method ' . $methodName . '() must return void');
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
