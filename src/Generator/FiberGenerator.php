<?php
/**
 * This file is part of TypePHP.
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace TypePhp\Generator;

use PhpParser\Node;
use PhpParser\Node\Expr\Yield_;
use PhpParser\Node\Expr\YieldFrom;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use TypePhp\Context\FunctionContext;
use TypePhp\Entity\FunctionDef;

trait FiberGenerator
{
    protected function containsYield(Function_|ClassMethod $v): bool
    {
        return $this->containsYieldInNodes($v->stmts ?? []);
    }

    protected function containsYieldInNodes(array $nodes): bool
    {
        foreach ($nodes as $node) {
            if ($node instanceof Node && $this->containsYieldInNode($node)) {
                return true;
            }
        }
        return false;
    }

    protected function containsYieldInNode(Node $node): bool
    {
        if ($node instanceof Yield_ || $node instanceof YieldFrom) {
            return true;
        }
        if ($node instanceof Node\FunctionLike || $node instanceof Node\Stmt\ClassLike) {
            return false;
        }
        foreach ($node->getSubNodeNames() as $name) {
            $subNode = $node->{$name};
            if ($subNode instanceof Node) {
                if ($this->containsYieldInNode($subNode)) {
                    return true;
                }
            } elseif (is_array($subNode) && $this->containsYieldInNodes($subNode)) {
                return true;
            }
        }
        return false;
    }

    protected function prepareGeneratorFunction(Function_|ClassMethod $v, FunctionDef $functionDef): void
    {
        if ($v->byRef) {
            $this->fatalError($v, 'Generators returning by reference are not supported yet');
        }
        foreach ($v->params as $param) {
            if ($param->byRef || $param->variadic) {
                $this->fatalError($param, 'Generators with by-reference or variadic parameters are not supported yet');
            }
        }
        if ($functionDef->returnClass === 'Generator') {
            $this->fatalError($v, 'Generator return type is not supported by TypePHP Fiber generators yet; use Iterator, Traversable, iterable, mixed, or omit the return type');
        }
        $functionDef->generator = true;
        $functionDef->returnType = self::TYPE_VAR;
        $functionDef->returnClass = '';
        $functionDef->returnTypeCheck = null;
        $functionDef->returnTypeStr = '';
        $functionDef->returnTypeNode = null;
    }

    protected function parseYieldExpr(Yield_ $expr): string
    {
        if (!$this->inGeneratorBody) {
            $this->fatalError($expr, 'The `Expr_Yield` is not supported outside generator functions');
        }
        return 'typephp_fiber_suspend(' . $this->genYieldPayload($expr) . ', nullptr)';
    }

    protected function parseYieldStmt(Yield_ $expr): string
    {
        $payload = $this->genYieldPayload($expr);
        $closed = $this->genTmpVarName();
        $this->addLocalVar($closed, self::TYPE_BOOL);
        return $closed . ' = false;' . PHP_EOL
            . $this->getIndent() . $closed . ' = typephp_fiber_yield(' . $payload . ');' . PHP_EOL
            . $this->getIndent() . 'if (' . $closed . ') {' . PHP_EOL
            . $this->getIndent() . '    return ' . self::VALUE_NULL . ';' . PHP_EOL
            . $this->getIndent() . '}';
    }

    protected function parseYieldFromStmt(YieldFrom $expr): string
    {
        $closed = $this->genTmpVarName();
        $this->addLocalVar($closed, self::TYPE_BOOL);
        return $closed . ' = false;' . PHP_EOL
            . $this->getIndent() . 'typephp_fiber_yield_from(' . $this->parseExprAsValue($expr->expr) . ', &' . $closed . ');' . PHP_EOL
            . $this->getIndent() . 'if (' . $closed . ') {' . PHP_EOL
            . $this->getIndent() . '    return ' . self::VALUE_NULL . ';' . PHP_EOL
            . $this->getIndent() . '}';
    }

    protected function genYieldPayload(Yield_ $expr): string
    {
        $value = $expr->value ? $this->parseExprAsValue($expr->value) : self::VALUE_NULL;
        if ($expr->key) {
            $key = $this->parseExprAsValue($expr->key);
            return 'php::Array(php::StdStrKeyMap{{"key", ' . $key . '}, {"value", ' . $value . '}, {"has_key", true}})';
        }
        return 'php::Array(php::StdStrKeyMap{{"value", ' . $value . '}, {"has_key", false}})';
    }

    protected function parseYieldFromExpr(YieldFrom $expr): string
    {
        if (!$this->inGeneratorBody) {
            $this->fatalError($expr, 'The `Expr_YieldFrom` is not supported outside generator functions');
        }
        return 'typephp_fiber_yield_from(' . $this->parseExprAsValue($expr->expr) . ', nullptr)';
    }

    protected function genFiberGeneratorFunction(Function_|ClassMethod $v, FunctionDef $functionDef, string $nativeName): string
    {
        $functionDeclCode = self::TYPE_VAR . ' ' . self::PREFIX . $nativeName . '(';
        if ($this->class) {
            $functionDeclCode .= self::TYPE_OBJECT . ' &this_';
            if ($functionDef->params) {
                $functionDeclCode .= ', ';
            }
        }
        $functionDeclCode .= $functionDef->params . ')';

        $uses = [];
        foreach ($functionDef->argInfoList as $argInfo) {
            $uses[] = $argInfo->name;
        }

        $code = $functionDeclCode . ' {' . PHP_EOL;
        $this->indentLevel++;
        $closureVar = $this->genTmpVarName();
        $code .= $this->getIndent() . 'php::ClosureFn ' . $closureVar . ' = []('
            . 'INTERNAL_FUNCTION_PARAMETERS, '
            . self::TYPE_OBJECT . ' &this_, '
            . self::TYPE_ARGS . ' &vars_) -> ' . self::TYPE_VAR . ' {' . PHP_EOL;

        $outerContext = $this->context;
        $outerIndent = $this->indentLevel;
        $outerInGeneratorBody = $this->inGeneratorBody;
        $this->context = new FunctionContext();
        $this->context->inClosure = true;
        $this->inGeneratorBody = true;
        $this->indentLevel++;

        foreach ($functionDef->argInfoList as $i => $argInfo) {
            $code .= $this->getIndent() . self::TYPE_VAR . ' ' . $argInfo->name . ' = vars_.get(' . $i . ');' . PHP_EOL;
            $this->addArgument($argInfo->name, self::TYPE_VAR);
        }
        if ($this->class) {
            $this->addArgument('this_', self::TYPE_OBJECT);
        }

        $body = '';
        if ($v->stmts) {
            $body = $this->parseStmts($v->stmts);
        }
        $body .= $this->getIndent() . 'return ' . self::VALUE_NULL . ';' . PHP_EOL;
        $code .= $this->genScopeVarDecl() . $body;

        $this->indentLevel = $outerIndent;
        $this->inGeneratorBody = $outerInGeneratorBody;
        $this->context = $outerContext;

        $code .= $this->getIndent() . '};' . PHP_EOL;
        $args = $uses ? '{ ' . implode(', ', $uses) . ' }' : '{}';
        $closureExpr = $this->class
            ? 'php::newClosure(' . $closureVar . ', ' . $args . ', this_)'
            : 'php::newClosure(' . $closureVar . ', ' . $args . ')';
        $code .= $this->getIndent() . 'return php::newObject(typephp_fiber_generator_ce, {' . $closureExpr . '});' . PHP_EOL;
        $this->indentLevel--;
        $code .= '}' . PHP_EOL;

        return $code;
    }
}
