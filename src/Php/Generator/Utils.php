<?php

namespace PhpAot\Php\Generator;

use PhpAot\Php\CompilerBase;

trait Utils
{
    protected function genCharPtr(string $str): string
    {
        return '"' . $str . '"';
    }

    protected function genArray(array $elements): string
    {
        return CompilerBase::TYPE_ARRAY . '{' . implode(', ', $elements) . ' }';
    }
}
