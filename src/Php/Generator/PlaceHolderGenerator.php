<?php

namespace PhpAot\Php\Generator;

use PhpParser\Node\Expr\StaticCall;
use PhpParser\NodeAbstract;

trait PlaceHolderGenerator
{
    protected function genPlaceHolder(string $callable): string
    {
        $ce = $this->getClassEntryPtr(\Closure::class);
        $fn = $ce . ', ' . $this->getFuncPtr('Closure::fromCallable', false);
        return 'php::call(' . $fn . ', {' . $callable . '})';
    }
}
