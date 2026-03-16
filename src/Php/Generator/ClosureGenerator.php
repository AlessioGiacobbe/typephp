<?php
/**
 * This file is part of Swoole-Compiler(AOT).
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace PhpAot\Php\Generator;

use PhpParser\NodeAbstract;

trait ClosureGenerator
{
    protected function genScopeSwitchCode(): string
    {
        $tmpScope = $this->genTmpVarName();
        $code = "auto $tmpScope = php_switch_scope(this_);" . PHP_EOL;
        $code .= "ON_SCOPE_EXIT({ php_restore_scope($tmpScope); });" . PHP_EOL;
        return $code;
    }

    /**
     * @param $useCurrentScope bool 直接使用当前作用域，C++ 函数将使用 & 捕获所有闭包变量
     */
    protected function genClosure(NodeAbstract $expr, array $params, callable $bodyGenCb, array $uses = [], bool $useCurrentScope = false): string
    {
        $tmpVar = $this->genTmpVarName();
        $capture = $useCurrentScope ? '&' : '';

        $code = $this->getIndent() .
            'php::ClosureFn ' . $tmpVar . ' = [' . $capture . ']('
            . 'INTERNAL_FUNCTION_PARAMETERS, '
            . self::TYPE_OBJECT . ' &this_, '
            . self::TYPE_ARGS . ' &vars_) ' .
            '-> ' . self::TYPE_VAR . ' {' . PHP_EOL;

        if (!$useCurrentScope) {
            $oriLocalVars = $this->localVars;
            $this->localVars = [];
        }

        $oriObjects = $this->objects;
        $oriObjectWrappers = $this->objectWrappers;
        $oriCeWrappers = $this->ceWrappers;
        $oriArgs = $this->arguments;
        $oriInClosure = $this->inClosure;
        $oriBeforeStmtLines = $this->beforeStmtLines;
        $oriAfterStmtLines = $this->afterStmtLines;

        $this->objects = [];
        $this->objectWrappers = [];
        $this->ceWrappers = [];
        $this->arguments = [];
        $this->inClosure = true;
        $this->beforeStmtLines = [];
        $this->afterStmtLines = [];

        $this->indentLevel++;

        foreach ($params as $i => $param) {
            if ($param->byRef) {
                $this->fatalError($expr, 'Closure cannot use reference parameter');
            }
            $var = $this->parseIdentifier($param->var);
            $code .= 'auto ' . $var . ' = php::getCallArg(' . $i . ');' . PHP_EOL;
            $this->addArgument($var, self::TYPE_VAR);
        }

        foreach ($uses as $i => $useItem) {
            $var = $this->parseIdentifier($useItem->var);
            $code .= 'auto ' . $var . ' = vars_.get(' . $i . ');' . PHP_EOL;
            $this->addArgument($var, self::TYPE_VAR);
        }

        if ($this->methodDef) {
            $this->addArgument('this_', self::TYPE_OBJECT);
        }

        $code .= $bodyGenCb();

        $this->indentLevel--;
        $code .= '};' . PHP_EOL;

        $useVars = [];
        if ($uses) {
            foreach ($uses as $useItem) {
                $var = $this->parseIdentifier($useItem->var);
                if ($this->isVarExpr($useItem->var) and !$this->hasVar($var)) {
                    $this->errorUndefinedVariable($useItem->var);
                }
                if ($useItem->byRef) {
                    $useVars[] = $this->convertToRef($useItem->var);
                } else {
                    $useVars[] = $var;
                }
            }
        }

        if (!$useCurrentScope) {
            $this->localVars = $oriLocalVars;
        }
        $this->objects = $oriObjects;
        $this->objectWrappers = $oriObjectWrappers;
        $this->ceWrappers = $oriCeWrappers;
        $this->arguments = $oriArgs;
        $this->inClosure = $oriInClosure;
        $this->beforeStmtLines = $oriBeforeStmtLines;
        $this->afterStmtLines = $oriAfterStmtLines;
        $this->beforeStmtLines[] = $code;

        if ($this->methodDef) {
            return 'php::newClosure(' . $tmpVar . ', { ' . implode(', ', $useVars) . ' }, this_)';
        } else {
            return 'php::newClosure(' . $tmpVar . ', { ' . implode(', ', $useVars) . ' })';
        }
    }
}
