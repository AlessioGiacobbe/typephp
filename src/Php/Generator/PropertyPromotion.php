<?php
/**
 * This file is part of Swoole-Compiler(AOT).
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace PhpAot\Php\Generator;

use PhpAot\Php\ArgInfo;

trait PropertyPromotion
{
    protected function genPropertyPromotion(ArgInfo $argInfo): string
    {
        $code = '';
        $code .= 'this_.setProperty(' . $this->genCharPtr($argInfo->name) . ', ' . $argInfo->name . ')';
        $code .= ";\n";
        return $code;
    }
}
