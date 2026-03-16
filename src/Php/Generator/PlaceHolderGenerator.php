<?php
/**
 * This file is part of Swoole-Compiler(AOT).
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace PhpAot\Php\Generator;

trait PlaceHolderGenerator
{
    protected function genPlaceHolder(string $callable): string
    {
        $ce = $this->getClassEntryPtr(\Closure::class);
        $fn = $ce . ', ' . $this->getFuncPtr('Closure::fromCallable');
        $tmpVar = $this->genTmpVarName();
        if ($this->classDef) {
            $this->beforeStmtLines[] = "auto $tmpVar = php_switch_scope(this_);";
            $this->afterStmtLines[] = "php_restore_scope($tmpVar);";
        }
        return 'php::call(' . $fn . ', {' . $callable . '})';
    }
}
