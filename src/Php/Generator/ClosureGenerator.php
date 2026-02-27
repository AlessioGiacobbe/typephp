<?php

namespace PhpAot\Php\Generator;

use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\NodeAbstract;

trait ClosureGenerator
{
    /**
     * @param NodeAbstract $expr
     * @param array $params
     * @param callable $bodyGenCb
     * @param array $uses
     * @param $useCurrentScope bool 直接使用当前作用域，C++ 函数将使用 & 捕获所有闭包变量
     * @return string
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

        $oriArgs = $this->arguments;
        $this->arguments = [];
        $oriInClosure = $this->inClosure;
        $this->inClosure = true;

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

        $this->beforeStmtLines[] = $code;
        if (!$useCurrentScope) {
            $this->localVars = $oriLocalVars;
        }
        $this->arguments = $oriArgs;
        $this->inClosure = $oriInClosure;

        $useVars = [];
        if ($uses) {
            foreach ($uses as $useItem) {
                $var = $this->parseIdentifier($useItem->var);
                if ($this->isVarExpr($useItem->var) and !$this->hasVar($var)) {
                    $this->errorUndefinedVariable($useItem->var);
                }
                if ($useItem->byRef) {
                    $useVars [] = $this->convertToRef($useItem->var);
                } else {
                    $useVars [] = $var;
                }
            }
        }

        if ($this->methodDef) {
            return 'php::newClosure(' . $tmpVar . ', { ' . implode(', ', $useVars) . ' }, this_)';
        } else {
            return 'php::newClosure(' . $tmpVar . ', { ' . implode(', ', $useVars) . ' })';
        }
    }
}
