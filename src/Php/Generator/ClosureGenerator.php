<?php
/**
 * This file is part of Swoole-Compiler(AOT).
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace PhpAot\Php\Generator;

use PhpAot\Php\ArgInfo;
use PhpAot\Php\Context\FunctionContext;
use PhpParser\Node;
use PhpParser\Node\IntersectionType;
use PhpParser\Node\NullableType;
use PhpParser\Node\UnionType;
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
        if ($expr->returnType instanceof NullableType || $expr->returnType instanceof UnionType || $expr->returnType instanceof IntersectionType) {
            $returnTypeInfo = $this->buildTypeCheckFromNode($expr->returnType);
            if (!empty($returnTypeInfo['check'])) {
                $this->context->closureReturnTypeCheck = $returnTypeInfo['check'];
                $this->context->closureReturnTypeStr = $returnTypeInfo['typeStr'];
            }
        }
        $this->indentLevel++;

        $requiredArgCount = 0;
        foreach ($params as $param) {
            if ($param->variadic || $param->default !== null) {
                break;
            }
            $requiredArgCount++;
        }
        if ($requiredArgCount > 0) {
            $expected = $requiredArgCount === count($params) ? 'exactly' : 'at least';
            $message = 'php::concat({'
                . 'php::Str(' . $this->genCharPtr('Too few arguments to function {closure}(), ', true) . '), '
                . 'php::toString(php::getCallArgNum()), '
                . 'php::Str(' . $this->genCharPtr(' passed and ' . $expected . ' ' . $requiredArgCount . ' expected', true) . ')'
                . '})';
            $code .= $this->getIndent() . 'if (UNEXPECTED(php::getCallArgNum() < ' . $requiredArgCount . ')) {' . PHP_EOL;
            $this->indentLevel++;
            $code .= $this->getIndent() . 'return php::throwException(zend_ce_argument_count_error, (' . $message . ').toCString());' . PHP_EOL;
            $this->indentLevel--;
            $code .= $this->getIndent() . '}' . PHP_EOL;
        }

        foreach ($params as $i => $param) {
            if ($param->byRef) {
                $this->fatalError($expr, 'Closure cannot use reference parameter');
            }
            $var = $this->parseIdentifier($param->var);
            $phpName = is_string($param->var->name) ? $param->var->name : $this->unescapeVarName($var);
            if ($param->variadic) {
                $code .= $this->getIndent() . self::TYPE_ARRAY . ' ' . $var . ';' . PHP_EOL;
                $code .= $this->getIndent() . 'for (uint32_t i = ' . $i . '; i < php::getCallArgNum(); i++) {' . PHP_EOL;
                $this->indentLevel++;
                $code .= $this->getIndent() . $var . '.append(php::getCallArg(i));' . PHP_EOL;
                $this->indentLevel--;
                $code .= $this->getIndent() . '}' . PHP_EOL;
                $this->addArgument($var, self::TYPE_ARRAY);
                $code .= $this->genClosureParamTypeCheck($param, $var, $phpName, $i, true);
                continue;
            }
            $argExpr = $param->default === null
                ? 'php::getCallArg(' . $i . ')'
                : 'php::getCallArg(' . $i . ', ' . $this->parseParamDefaultValue($param->default) . ')';
            $code .= $this->getIndent() . 'auto ' . $var . ' = ' . $argExpr . ';' . PHP_EOL;
            $this->addArgument($var, self::TYPE_VAR);
            $code .= $this->genClosureParamTypeCheck($param, $var, $phpName, $i, false);
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
        $code .= $this->genScopeVarDecl() . $body;

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

    protected function genLambdaCall(callable $cb): string
    {
        $code = '';
        $oriCtx = $this->context;

        // 使用 lambda 函数来对 static 变量进行赋值
        $this->context = new FunctionContext();
        // C++ lambda 使用 & 捕获了当前函数的所有局部变量，可直接使用，不需要再声明，将其作为 arguments 来处理，隐式使用
        $this->context->arguments = $oriCtx->localVars;

        $code .= '([&](){' . PHP_EOL;
        $body = $cb();
        $code .= $this->genScopeVarDecl();
        $code .= $this->parseBeforeStmtLines();
        $code .= $body;
        $code .= $this->parseAfterStmtLines();
        $code .= '})();' . PHP_EOL;

        $this->context = $oriCtx;

        return $code;
    }

    private function genClosureParamTypeCheck(Node\Param $param, string $var, string $phpName, int $index, bool $variadic): string
    {
        if (!$param->type instanceof NullableType && !$param->type instanceof UnionType && !$param->type instanceof IntersectionType) {
            return '';
        }

        $typeInfo = $this->buildTypeCheckFromNode($param->type);
        if (empty($typeInfo['check'])) {
            return '';
        }

        $argInfo = new ArgInfo();
        $argInfo->name = $var;
        $argInfo->phpName = $phpName;
        $argInfo->type = self::TYPE_VAR;
        $argInfo->variadic = $variadic;
        $argInfo->typeCheck = $typeInfo['check'];
        $argInfo->typeStr = $typeInfo['typeStr'];
        $argInfo->typeNode = $param->type;

        return $this->genClosureParamCheck($argInfo, $index);
    }
}
