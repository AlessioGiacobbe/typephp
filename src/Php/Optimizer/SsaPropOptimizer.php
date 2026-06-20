<?php
/**
 * SSA-based object property reference hoisting optimizer.
 *
 * Extends the existing $this->intProp optimization to any SSA-proven stable
 * object. When an object variable has a single definition, no escape/reference/
 * kill flags, and its class has no magic methods that intercept property access,
 * int/float property accesses can be hoisted to C++ references aliasing the
 * zval's internal value slot.
 *
 * Prerequisites checked by this pass:
 *  1. Object has exactly one SSA definition (single assignment)
 *  2. No REFERENCE / ESCAPED / KILLED flags on the object's SSA vars
 *  3. Class has no __get / __set magic methods
 *  4. Property has a declared native type (int or float)
 *  5. No &$o->prop (reference capture of the property)
 *  6. No func(&$o->prop) (property passed by reference)
 *  7. First access is not inside a loop or nested block scope
 *
 * unset($o->prop) and exposing the object to dynamic calls are NOT dangerous:
 * the object handlers reject property unset, so a hoisted reference cannot be
 * invalidated by either path.
 */

namespace PhpAot\Php\Optimizer;

use PhpAot\Php\Analysis\SsaBuilder;
use PhpAot\Php\Analysis\SsaFlags;
use PhpParser\Node;
use PhpParser\Node\Expr;

trait SsaPropOptimizer
{
    /**
     * Analyze object stability and identify safe property accesses.
     * Called after SSA build and var type optimization in parseFunction().
     *
     * Scans the function body AST to find object assignments (e.g. $o = new Foo()),
     * resolves class names, and checks SSA stability for each object variable.
     * This must be done during analysis because $this->context->objects is only
     * populated during code generation (after analysis).
     */
    protected function optimizeObjectProps(): void
    {
        $ssa = $this->context->ssaBuilder;
        if (!$ssa || !$this->nativeTypes) {
            return;
        }

        if ($this->classDef && !$this->classDef->trait) {
            if ($this->isClassSafeForPropHoisting($this->getFullClassName())) {
                $unsafeProps = $this->collectDangerousPropOps('this_', $ssa->getStmts());
                if ($unsafeProps) {
                    $this->context->unsafeObjectProps['this_'] = $unsafeProps;
                }
            } else {
                $this->context->unsafeObjectProps['this_'] = ['*' => true];
            }
        }

        if (empty($ssa->ssaVars)) {
            return;
        }

        $objectAssigns = $this->collectObjectAssignments($ssa->getStmts());

        // Also check function parameters that are typed objects
        foreach ($this->context->objects as $objName => $className) {
            if ($objName === 'this_') {
                continue;
            }
            $objectAssigns[$objName] = $className;
        }

        foreach ($objectAssigns as $objName => $className) {
            if ($objName === 'this_') {
                continue;
            }

            if (!$className || $className === 'stdClass' || !$this->hasClass($className)) {
                continue;
            }

            if (!$this->isObjectSsaStable($ssa, $objName)) {
                continue;
            }

            if (!$this->isClassSafeForPropHoisting($className)) {
                continue;
            }

            $unsafeProps = $this->collectDangerousPropOps($objName, $ssa->getStmts());
            if ($unsafeProps) {
                $this->context->unsafeObjectProps[$objName] = $unsafeProps;
            }

            $this->context->stableObjects[$objName] = $className;
        }
    }

    /**
     * Walk the function body AST to find variable assignments that produce
     * typed objects. Returns map of varName => className.
     */
    protected function collectObjectAssignments(array $stmts): array
    {
        $result = [];
        foreach ($stmts as $stmt) {
            $this->scanStmtForObjectAssign($stmt, $result);
        }
        return $result;
    }

    protected function scanStmtForObjectAssign($stmt, array &$result): void
    {
        if (!$stmt instanceof Node) {
            return;
        }

        if ($stmt instanceof Node\Stmt\Expression && $stmt->expr instanceof Expr\Assign) {
            $assign = $stmt->expr;
            $var = $assign->var;
            if ($var instanceof Expr\Variable && is_string($var->name)) {
                $className = $this->resolveNewExprClass($assign->expr);
                if ($className) {
                    $result[$var->name] = $className;
                }
            }
            return;
        }

        // Recurse
        if ($stmt instanceof Node\Stmt\If_) {
            foreach ($stmt->stmts as $s) $this->scanStmtForObjectAssign($s, $result);
            foreach ($stmt->elseifs as $elseif) {
                foreach ($elseif->stmts as $s) $this->scanStmtForObjectAssign($s, $result);
            }
            if ($stmt->else) {
                foreach ($stmt->else->stmts as $s) $this->scanStmtForObjectAssign($s, $result);
            }
        } elseif ($stmt instanceof Node\Stmt\While_ || $stmt instanceof Node\Stmt\Do_) {
            foreach ($stmt->stmts as $s) $this->scanStmtForObjectAssign($s, $result);
        } elseif ($stmt instanceof Node\Stmt\For_ || $stmt instanceof Node\Stmt\Foreach_) {
            foreach ($stmt->stmts as $s) $this->scanStmtForObjectAssign($s, $result);
        } elseif ($stmt instanceof Node\Stmt\TryCatch) {
            foreach ($stmt->stmts as $s) $this->scanStmtForObjectAssign($s, $result);
            foreach ($stmt->catches as $catch) {
                foreach ($catch->stmts as $s) $this->scanStmtForObjectAssign($s, $result);
            }
            if ($stmt->finally) {
                foreach ($stmt->finally->stmts as $s) $this->scanStmtForObjectAssign($s, $result);
            }
        }
    }

    /**
     * Resolve the class name from a `new ClassName()` expression,
     * or a function/method call that returns a known object type.
     */
    protected function resolveNewExprClass(Expr $expr): ?string
    {
        if ($expr instanceof Expr\New_) {
            if ($expr->class instanceof Node\Name) {
                $className = $expr->class->toString();
                if ($className === 'self') {
                    if ($this->classDef) {
                        return $this->getFullClassName();
                    }
                    return null;
                }
                if ($className === 'static') {
                    return null;
                }
                return $this->getNamespacedClassName($className);
            }
        }

        // For function/method calls, try to detect the return class
        if ($expr instanceof Expr\FuncCall || $expr instanceof Expr\MethodCall
            || $expr instanceof Expr\StaticCall || $expr instanceof Expr\NullsafeMethodCall) {
            return $this->detectClassOfExpr($expr) ?: null;
        }

        return null;
    }

    /**
     * Check if an object variable has a single stable SSA definition.
     */
    protected function isObjectSsaStable(SsaBuilder $ssa, string $objName): bool
    {
        $foundDef = false;

        foreach ($ssa->ssaVars as $ssaVar) {
            if ($ssaVar->origName !== $objName) {
                continue;
            }

            if ($ssaVar->flags & SsaFlags::PHI) {
                continue;
            }

            if ($ssaVar->flags & (SsaFlags::REFERENCE | SsaFlags::ESCAPED | SsaFlags::KILLED)) {
                return false;
            }

            if ($foundDef) {
                return false; // Multiple definitions
            }

            if (!$this->isObjectDefinition($ssaVar)) {
                return false;
            }

            $foundDef = true;
        }

        return $foundDef;
    }

    /**
     * Check if an SSA definition sets the variable to an object value.
     * Accepts both `new ClassName()` and calls that return a typed object.
     */
    protected function isObjectDefinition($ssaVar): bool
    {
        $def = $ssaVar->definition;
        if (!$def) {
            return false;
        }

        if ($def instanceof Node\Stmt\Expression && $def->expr instanceof Expr\Assign) {
            $rhs = $def->expr->expr;
            if ($rhs instanceof Expr\New_) {
                return true;
            }
            // Allow function/method calls that return a matching typed object
            if ($rhs instanceof Expr\FuncCall || $rhs instanceof Expr\MethodCall
                || $rhs instanceof Expr\StaticCall || $rhs instanceof Expr\NullsafeMethodCall) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a class has no magic methods that intercept property access.
     */
    protected function isClassSafeForPropHoisting(string $className): bool
    {
        $classDef = $this->classes[$this->escapeClass($className)] ?? null;
        if (!$classDef) {
            return false;
        }

        if ($classDef->hasMethod('__get') || $classDef->hasMethod('__set')) {
            return false;
        }

        return true;
    }

    /**
     * Scan function body for dangerous operations on object properties.
     *
     * Detects:
     *  - $ref = &$o->prop — property becomes reference, zval type changes
     *  - func(&$o->prop) or $obj->method(&$o->prop) — property passed by ref
     *
     * unset($o->prop) and passing the object to dynamic calls are intentionally
     * not treated as dangerous: the object handlers reject property unset.
     */
    protected function hasDangerousPropOps(string $objName, array $stmts): bool
    {
        return $this->collectDangerousPropOps($objName, $stmts) !== [];
    }

    /**
     * @return array<string, bool> property name map; '*' means any property may be invalidated.
     */
    protected function collectDangerousPropOps(string $objName, array $stmts): array
    {
        $events = [];
        foreach ($stmts as $stmt) {
            $this->collectPropEvents($stmt, $objName, $events);
        }
        return $this->unsafePropsFromEvents($events);
    }

    protected function scanDangerousPropOp($stmt, string $objName): bool
    {
        $events = [];
        $this->collectPropEvents($stmt, $objName, $events);
        return $this->unsafePropsFromEvents($events) !== [];
    }

    /**
     * @param array<int, array{kind: string, prop: string}> $events
     */
    protected function collectPropEvents($node, string $objName, array &$events): void
    {
        if (!$node instanceof Node) {
            return;
        }

        if ($node instanceof Node\Stmt\Unset_) {
            foreach ($node->vars as $var) {
                // unset($o->prop) cannot destroy the slot: the object handlers
                // reject property unset, so a hoisted reference stays valid.
                $propName = $this->getPropNameOfObj($var, $objName);
                if ($propName !== null) {
                    $this->collectPropEventsInDynamicParts($var, $objName, $events);
                } else {
                    $this->collectPropEvents($var, $objName, $events);
                }
            }
            return;
        }

        if ($node instanceof Expr\AssignRef) {
            $leftProp = $this->getPropNameOfObj($node->var, $objName);
            if ($leftProp !== null) {
                // $o->prop =& $ref would parse the left property as a normal
                // assignment target, so it must never use a hoisted property var.
                $events[] = ['kind' => 'danger_always', 'prop' => $leftProp];
                $this->collectPropEventsInDynamicParts($node->var, $objName, $events);
            } else {
                $this->collectPropEvents($node->var, $objName, $events);
            }

            $rightProp = $this->getPropNameOfObj($node->expr, $objName);
            if ($rightProp !== null) {
                // $ref = &$o->prop changes the slot to a reference. Earlier
                // optimized accesses remain safe only if the property is not
                // touched again afterward.
                $events[] = ['kind' => 'danger', 'prop' => $rightProp];
                $this->collectPropEventsInDynamicParts($node->expr, $objName, $events);
            } else {
                $this->collectPropEvents($node->expr, $objName, $events);
            }
            return;
        }

        if ($node instanceof Expr\FuncCall
            && $node->name instanceof Node\Name
            && $node->name->toLowerString() === 'refval'
            && !empty($node->args)) {
            $propName = $this->getPropNameOfObj($node->args[0]->value, $objName);
            if ($propName !== null) {
                $events[] = ['kind' => 'danger', 'prop' => $propName];
                $this->collectPropEventsInDynamicParts($node->args[0]->value, $objName, $events);
                return;
            }
        }

        if ($node instanceof Expr\Eval_ || $node instanceof Expr\Include_) {
            $this->collectPropEvents($node->expr, $objName, $events);
            $events[] = ['kind' => 'danger', 'prop' => '*'];
            return;
        }

        if ($node instanceof Expr\FuncCall || $node instanceof Expr\MethodCall
            || $node instanceof Expr\StaticCall || $node instanceof Expr\NullsafeMethodCall) {
            if ($node instanceof Expr\StaticCall && $node->class instanceof Expr) {
                $this->collectPropEvents($node->class, $objName, $events);
            }
            if ($node instanceof Expr\MethodCall || $node instanceof Expr\NullsafeMethodCall) {
                $this->collectPropEvents($node->var, $objName, $events);
            }
            foreach ($node->args as $arg) {
                $propName = $arg->byRef ? $this->getPropNameOfObj($arg->value, $objName) : null;
                if ($propName !== null) {
                    $events[] = ['kind' => 'danger', 'prop' => $propName];
                    $this->collectPropEventsInDynamicParts($arg->value, $objName, $events);
                } else {
                    $this->collectPropEvents($arg->value, $objName, $events);
                }
            }
            // Exposing the object to a dynamic call (passing it as an argument or
            // invoking a non-internal method on it) can no longer invalidate a
            // hoisted property: the callee cannot unset the property, since the
            // object handlers reject property unset. Only explicit by-reference
            // captures (handled above) and direct &/refval on the property remain
            // dangerous, so the receiver/argument exposure check is unnecessary.
            return;
        }

        if ($node instanceof Expr\Assign) {
            $this->collectPropEvents($node->expr, $objName, $events);
            $this->collectPropEvents($node->var, $objName, $events);
            if ($this->isDynamicPropWriteOfObj($node->var, $objName)) {
                $events[] = ['kind' => 'danger', 'prop' => '*'];
            }
            if ($this->exprMayExposeObject($node->expr, $objName)) {
                $events[] = ['kind' => 'danger', 'prop' => '*'];
            }
            return;
        }

        if ($node instanceof Expr\AssignOp || $node instanceof Expr\PreInc || $node instanceof Expr\PreDec
            || $node instanceof Expr\PostInc || $node instanceof Expr\PostDec) {
            $target = $node instanceof Expr\AssignOp ? $node->var : $node->var;
            if ($node instanceof Expr\AssignOp) {
                $this->collectPropEvents($node->expr, $objName, $events);
            }
            $this->collectPropEvents($target, $objName, $events);
            if ($this->isDynamicPropWriteOfObj($target, $objName)) {
                $events[] = ['kind' => 'danger', 'prop' => '*'];
            }
            return;
        }

        if ($node instanceof Expr\Closure) {
            foreach ($node->uses as $use) {
                if ($this->isVarNamed($use->var, $objName)) {
                    $events[] = ['kind' => 'danger', 'prop' => '*'];
                }
            }
        }

        $propName = $this->getPropNameOfObj($node, $objName);
        if ($propName !== null && $propName !== '*') {
            $events[] = ['kind' => 'access', 'prop' => $propName];
        }

        foreach ($node->getSubNodeNames() as $subNodeName) {
            $subNode = $node->$subNodeName;
            if ($subNode instanceof Node) {
                $this->collectPropEvents($subNode, $objName, $events);
            } elseif (is_array($subNode)) {
                foreach ($subNode as $item) {
                    if ($item instanceof Node) {
                        $this->collectPropEvents($item, $objName, $events);
                    }
                }
            }
        }
    }

    /**
     * Dynamic property name expressions can contain normal property reads:
     * unset($o->{$other->name}) should still record $other->name if relevant.
     *
     * @param array<int, array{kind: string, prop: string}> $events
     */
    protected function collectPropEventsInDynamicParts($node, string $objName, array &$events): void
    {
        if (!$node instanceof Expr\PropertyFetch) {
            return;
        }
        if (!$node->name instanceof Node\Identifier) {
            $this->collectPropEvents($node->name, $objName, $events);
        }
    }

    /**
     * @param array<int, array{kind: string, prop: string}> $events
     * @return array<string, bool>
     */
    protected function unsafePropsFromEvents(array $events): array
    {
        $liveProps = [];
        $unsafeProps = [];

        for ($i = count($events) - 1; $i >= 0; $i--) {
            $event = $events[$i];
            $propName = $event['prop'];

            if ($event['kind'] === 'access') {
                $liveProps[$propName] = true;
                continue;
            }

            if ($event['kind'] === 'danger_always') {
                $unsafeProps[$propName] = true;
                continue;
            }

            if ($propName === '*') {
                foreach ($liveProps as $liveProp => $_) {
                    $unsafeProps[$liveProp] = true;
                }
            } elseif (isset($liveProps[$propName])) {
                $unsafeProps[$propName] = true;
            }
        }

        return $unsafeProps;
    }

    protected function exprHasDangerousPropOp($expr, string $objName): bool
    {
        $events = [];
        $this->collectPropEvents($expr, $objName, $events);
        return $this->unsafePropsFromEvents($events) !== [];
    }

    /**
     * Check if an expression is a property fetch on a specific object.
     */
    protected function isPropOfObj($node, string $objName): bool
    {
        return $this->getPropNameOfObj($node, $objName) !== null;
    }

    protected function getPropNameOfObj($node, string $objName): ?string
    {
        if (!$node instanceof Expr\PropertyFetch
            || !$node->var instanceof Expr\Variable
            || !is_string($node->var->name)
            || !$this->isVarNamed($node->var, $objName)) {
            return null;
        }

        if ($node->name instanceof Node\Identifier) {
            return $node->name->toString();
        }

        return '*';
    }

    protected function exprMayExposeObject($node, string $objName): bool
    {
        if (!$node instanceof Node) {
            return false;
        }

        if ($this->isVarNamed($node, $objName)) {
            return true;
        }

        if ($node instanceof Expr\PropertyFetch
            && $node->var instanceof Expr\Variable
            && is_string($node->var->name)
            && $this->isVarNamed($node->var, $objName)) {
            return false;
        }

        if ($node instanceof Expr\BinaryOp || $node instanceof Expr\BooleanNot
            || $node instanceof Expr\Cast || $node instanceof Expr\UnaryMinus
            || $node instanceof Expr\UnaryPlus) {
            return false;
        }

        foreach ($node->getSubNodeNames() as $subNodeName) {
            $subNode = $node->$subNodeName;
            if ($subNode instanceof Node) {
                if ($this->exprMayExposeObject($subNode, $objName)) {
                    return true;
                }
            } elseif (is_array($subNode)) {
                foreach ($subNode as $item) {
                    if ($this->exprMayExposeObject($item, $objName)) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    protected function isDynamicPropWriteOfObj($node, string $objName): bool
    {
        if ($node instanceof Expr\PropertyFetch
            && $node->var instanceof Expr\Variable
            && is_string($node->var->name)
            && $this->isVarNamed($node->var, $objName)) {
            return $this->getPropNameOfObj($node, $objName) === '*';
        }

        if ($node instanceof Expr\ArrayDimFetch) {
            return $this->isDynamicPropWriteOfObj($node->var, $objName);
        }

        return false;
    }

    /**
     * Check if an object variable is SSA-stable.
     * Public for use by parsePropertyFetch() in code generation.
     */
    public function isStableObject(string $objName): bool
    {
        return isset($this->context->stableObjects[$objName]);
    }

    public function canHoistStableObjectProp(string $objName, string $propName): bool
    {
        if (!$this->isStableObject($objName)) {
            return false;
        }
        return $this->canHoistObjectPropBySafety($objName, $propName);
    }

    public function canHoistObjectProp(string $objName, string $propName): bool
    {
        if ($objName !== 'this_' && !$this->isStableObject($objName)) {
            return false;
        }
        return $this->canHoistObjectPropBySafety($objName, $propName);
    }

    protected function canHoistObjectPropBySafety(string $objName, string $propName): bool
    {
        $unsafeProps = $this->context->unsafeObjectProps[$objName] ?? [];
        return !isset($unsafeProps['*']) && !isset($unsafeProps[$propName]);
    }

    /**
     * @return array{type: string, kind: string}
     */
    protected function getHoistedObjectPropInfo(string $declaredType): array
    {
        if ($declaredType === self::TYPE_INT || $declaredType === self::TYPE_FLOAT) {
            return ['type' => $declaredType, 'kind' => 'zval'];
        }

        return ['type' => self::TYPE_VAR, 'kind' => 'var'];
    }

    protected function getZvalValueMacroForPropType(string $type): ?string
    {
        return match ($type) {
            self::TYPE_INT => 'Z_LVAL_P',
            self::TYPE_FLOAT => 'Z_DVAL_P',
            default => null,
        };
    }

    /**
     * Generate the property reference declaration for a stable object.
     * Emits via beforeStmtLines so the reference is declared before the
     * current statement at function scope.
     *
     * Skips hoisting when inside a loop or nested block scope, since the
     * reference must be declared at function scope to be accessible later.
     */
    public function hoistStableObjectProp(string $objName, string $propName, string $id, string $cType): string
    {
        $propVar = $this->getObjectPropVarName($objName, $propName);

        if (isset($this->context->hoistedProps[$objName][$propName])) {
            return $propVar;
        }

        if ($this->context->inLoop || $this->context->scopeLevel > 1) {
            return $objName . '.attr(' . $id . ', true)';
        }

        $refGetter = $objName . '.attr(' . $id . ', true)';
        $zvalMacro = $this->getZvalValueMacroForPropType($cType);
        if ($zvalMacro !== null) {
            $this->context->beforeStmtLines[] = $cType . ' &' . $propVar . ' = ' . $zvalMacro . '(' . $refGetter . '.unwrap_ptr());';
        } else {
            $this->context->beforeStmtLines[] = self::TYPE_VAR . ' ' . $propVar . ' = ' . $refGetter . ';';
        }
        $this->context->hoistedProps[$objName][$propName] = true;

        return $propVar;
    }
}
