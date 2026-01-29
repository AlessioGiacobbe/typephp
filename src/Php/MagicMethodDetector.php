<?php

namespace PhpAot\Php;

use PhpParser\NodeAbstract;

trait MagicMethodDetector
{
    public function checkRequiredArgNum(string $name, MethodDef $methodDef, NodeAbstract $v): void
    {
        if ('__call' == $name or '__callStatic' == $name or '__set' == $name) {
            if (2 != count($methodDef->functionDef->argInfoList)) {
                $this->fatalError($v, 'Method '.$this->class."::$name() must take exactly 2 arguments");
            }
        } elseif ('__get' == $name) {
            if (1 != count($methodDef->functionDef->argInfoList)) {
                $this->fatalError($v, 'Method '.$this->class."::$name() must take exactly 1 argument");
            }
        }
    }
}
