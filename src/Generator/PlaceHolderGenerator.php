<?php
/**
 * This file is part of TypePHP.
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace TypePhp\Generator;

trait PlaceHolderGenerator
{
    protected function genPlaceHolder(string $callable): string
    {
        $ce = $this->getClassEntryPtr(\Closure::class);
        $fn = $ce . ', ' . $this->getFuncPtr('Closure::fromCallable');
        $tmpVar = $this->genTmpVarName();
        if ($this->classDef) {
            $this->context->beforeStmtLines[] = "auto {$tmpVar} = php_switch_scope(this_);";
            $this->context->afterStmtLines[] = "php_restore_scope({$tmpVar});";
        }
        return 'php::call(' . $fn . ', {' . $callable . '})';
    }
}
