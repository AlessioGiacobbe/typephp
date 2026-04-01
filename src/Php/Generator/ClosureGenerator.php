<?php
/**
 * This file is part of Swoole-Compiler(AOT).
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace PhpAot\Php\Generator;

use PhpAot\Php\Context\FunctionContext;
use PhpParser\NodeAbstract;

trait ClosureGenerator
{
    protected function genScopeSwitchCode(): string
    {
        $tmpScope = $this->genTmpVarName();
        $code = "auto {$tmpScope} = php_switch_scope(this_);" . PHP_EOL;
        $code .= "ON_SCOPE_EXIT({ php_restore_scope({$tmpScope}); });" . PHP_EOL;
        return $code;
    }

    protected function genClosure(NodeAbstract $expr, array $params, callable $bodyGenCb, array $uses = []): string
    {
        $tmpVar = $this->genTmpVarName();

        $code = $this->getIndent() .
            'php::ClosureFn ' . $tmpVar . ' = []('
            . 'INTERNAL_FUNCTION_PARAMETERS, '
            . self::TYPE_OBJECT . ' &this_, '
            . self::TYPE_ARGS . ' &vars_) ' .
            '-> ' . self::TYPE_VAR . ' {' . PHP_EOL;

        $oriContext = $this->context;
        $this->context = new FunctionContext();

        $this->context->inClosure = true;
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

        $body = $bodyGenCb();
        $code .= $this->genLocalVarDecl() . $body;

        $this->indentLevel--;
        $this->context->inClosure = false;
        $code .= '};' . PHP_EOL;

        $useVars = [];
        if ($uses) {
            foreach ($uses as $useItem) {
                $var = $this->parseIdentifier($useItem->var);
                if (!$this->isVarExpr($useItem->var)) {
                    $this->fatalError($useItem->var, 'Incorrect Closure use syntax, only variable names are allowed');
                }
                if ($useItem->byRef) {
                    // 闭包的 use 语法，若为引用类型，可以就地创建变量
                    if (!isset($oriContext->localVars[$var])) {
                        $oriContext->localVars[$var] = self::TYPE_REF;
                    }
                    $useVars[] = $this->convertToRef($useItem->var);
                } else {
                    $this->checkVarMustExist($useItem->var, $var);
                    $useVars[] = $var;
                }
            }
        }

        $this->context = $oriContext;
        $this->context->beforeStmtLines[] = $code;

        if ($this->methodDef) {
            return 'php::newClosure(' . $tmpVar . ', { ' . implode(', ', $useVars) . ' }, this_)';
        } else {
            return 'php::newClosure(' . $tmpVar . ', { ' . implode(', ', $useVars) . ' })';
        }
    }
}
