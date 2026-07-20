<?php
/**
 * This file is part of TypePHP.
 *
 * Resolves pipe targets and ordinary function calls.
 */

namespace TypePhp\Parser;

use TypePhp\Type;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\Variable;
use PhpParser\NodeAbstract;
use TypePhp\Metadata\Constants;
use TypePhp\Exception\PlaceHolder;

trait FunctionCallTrait
{
    protected function parsePipeOperator(Expr\BinaryOp\Pipe $expr): string
    {
        $this->assertExprCanBeUsedAsValue($expr->left, 'pipe left operand');
        $this->assertExprCanBeUsedAsValue($expr->right, 'pipe callable');

        [$leftExpr, $beforeStmts, $afterStmts] = $this->parseExprWithCapturedStmts($expr->left);
        $this->appendCapturedStmtLinesToContext($beforeStmts);
        $value = $this->addTmpVar(Type::VAR);
        $this->context->beforeStmtLines[] = $value . ' = ' . $leftExpr . ';';
        $this->appendCapturedStmtLinesToContext($afterStmts);

        $directCall = $this->parsePipeFirstClassCallable($expr->right, $value);
        if ($directCall !== null) {
            return $directCall;
        }

        $callable = $this->parseExprAsValue($expr->right);
        return 'php::call(' . $callable . ', {' . $value . '})';
    }

    /**
     * Lower a first-class callable used as a pipe target to its direct call.
     *
     * `trim(...)`, `ClassName::method(...)`, and `$object->method(...)` do
     * not need a Closure when the pipe immediately invokes them. Reusing the
     * ordinary call parsers preserves native-call optimization, argument
     * validation, visibility checks, and the left-to-right evaluation order.
     */
    protected function parsePipeFirstClassCallable(NodeAbstract $callable, string $value): ?string
    {
        if (!$callable instanceof CallLike || !$callable->isFirstClassCallable()) {
            return null;
        }

        $directCall = clone $callable;
        $directCall->args = [new Node\Arg(new Variable($value))];

        if ($directCall instanceof Expr\FuncCall) {
            return $this->parseFuncCall($directCall);
        }
        if ($directCall instanceof Expr\StaticCall) {
            return $this->parseStaticCall($directCall);
        }
        if ($directCall instanceof Expr\MethodCall) {
            return $this->parseMethodCall($directCall);
        }

        return null;
    }

    protected function parseFuncCall(Expr\FuncCall $expr): string
    {
        if ($this->isVarExpr($expr->name)) {
            $fn   = $this->parseIdentifier($expr->name);
            $placeHolder = $fn;
            $name = '';
        } elseif ($expr->name->getType() === 'Name' or $expr->name->getType() === 'Name_FullyQualified') {
            $name = $this->parseIdentifier($expr->name);
            $globalName = ltrim($name, '\\');
            if (in_array($name, Constants::UNSUPPORTED_FUNCTIONS)) {
                $this->fatalError($expr, 'Unsupported function: `' . $name . '`');
            }
            if ($name === 'any') {
                if (count($expr->args) !== 1 || $expr->args[0]->unpack) {
                    $this->fatalError($expr, 'The any function expects exactly one non-unpacked argument');
                }
                return $this->parseExprAsValue($expr->args[0]->value);
            }
            if ($globalName === 'expected' || $globalName === 'unexpected') {
                if (count($expr->args) !== 1 || $expr->args[0]->unpack) {
                    $this->fatalError($expr, "The {$globalName} function expects exactly one non-unpacked argument");
                }
                $condition = $this->parseExprAsValue($expr->args[0]->value);
                return 'static_cast<bool>(' . strtoupper($globalName) . '((' . $condition . ')))';
            }
            if ($name === 'objval') {
                return $this->genObjvalCall($expr);
            }
            $nativeFn = $this->findNativeFunction($name);
            if ($nativeFn) {
                $expr->setAttribute('nativeCall', $nativeFn);
                // 函数调用占位符，不是真实的函数调用
                if (count($expr->args) === 1 and $this->isPlaceholderExpr($expr->args[0])) {
                    return $this->genPlaceHolder($this->identifierToStr($expr->name));
                }
                $this->checkNativeCallArgs($expr, $this->getFunction($nativeFn), $expr->args, $name);
                if ($this->shouldUseDynamicCallForNativeArgs($nativeFn, $expr->args)) {
                    $functionDef = $this->getFunction($nativeFn);
                    return $this->genRuntimeFunctionCall($this->getFuncPtr($functionDef->getNamespacedName()), $expr->args, $name);
                }
                try {
                    $callee = $expr->getAttribute(self::ATTR_MULTI_RETURN_IMPL, false)
                        ? $this->getMultiReturnImplName($nativeFn)
                        : self::PREFIX . $nativeFn;
                    return $callee . '(' . $this->parseNativeCallArgs($expr->args, $nativeFn) . ')';
                } catch (PlaceHolder) {
                    return $this->genPlaceHolder($this->identifierToStr($expr->name));
                }
            }
            // 动态调用的函数，转换函数名为带有命名空间的全限定名称
            $name = $this->getNamespacedFuncName($name);
            $this->checkInternalFunctionArgCount($name, $expr);
            $code = $this->parseFuncCallWithOptimizer($name, $expr);
            if ($code !== false) {
                return $code;
            }
            $placeHolder = $this->identifierToStr($expr->name);
            $fn = $this->getFuncPtr($name);
            $this->context->beforeStmtLines[] = $this->formatCppLineComment('Func Call: ', $name . '()');
        } else {
            $tmpVar = $this->addTmpVar(Type::VAR);
            $this->context->beforeStmtLines[] = $tmpVar . ' = ' . $this->parseExpr($expr->name) . ';';
            $placeHolder = $fn = $tmpVar;
            $name = '';
        }
        if (empty($expr->args)) {
            return 'php::call(' . $fn . ')';
        }
        try {
            return $this->genRuntimeFunctionCall($fn, $expr->args, $name);
        } catch (PlaceHolder) {
            return $this->genPlaceHolder($placeHolder);
        }
    }
}
