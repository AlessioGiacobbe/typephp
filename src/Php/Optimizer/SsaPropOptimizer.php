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
 *  5. No unset($o->prop) on the property
 *  6. No &$o->prop (reference capture of the property)
 *  7. No func(&$o->prop) (property passed by reference)
 *  8. First access is not inside a loop or nested block scope
 */

namespace PhpAot\Php\Optimizer;

use PhpAot\Php\Analysis\SsaBuilder;
use PhpAot\Php\Analysis\SsaFlags;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\NodeAbstract;

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
        if (!$ssa || empty($ssa->ssaVars) || !$this->nativeTypes) {
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

            if ($this->hasDangerousPropOps($objName, $ssa->getStmts())) {
                continue;
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
                if ($className === 'self' || $className === 'static') {
                    if ($this->classDef) {
                        $className = $this->classDef->getFullName();
                    } else {
                        return null;
                    }
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
     *  - unset($o->prop) — destroys property slot
     *  - $ref = &$o->prop — property becomes reference, zval type changes
     *  - func(&$o->prop) or $obj->method(&$o->prop) — property passed by ref
     */
    protected function hasDangerousPropOps(string $objName, array $stmts): bool
    {
        foreach ($stmts as $stmt) {
            if ($this->scanDangerousPropOp($stmt, $objName)) {
                return true;
            }
        }
        return false;
    }

    protected function scanDangerousPropOp($stmt, string $objName): bool
    {
        if (!$stmt instanceof Node) {
            return false;
        }

        // unset($o->prop) — typed property can't be unset in PHP 8,
        // but untyped dynamic properties could be. Check anyway.
        if ($stmt instanceof Node\Stmt\Unset_) {
            foreach ($stmt->vars as $var) {
                if ($this->isPropOfObj($var, $objName)) {
                    return true;
                }
            }
        }

        // $ref = &$o->prop — reference capture of property
        if ($stmt instanceof Node\Stmt\Expression && $stmt->expr instanceof Expr\AssignRef) {
            if ($this->isPropOfObj($stmt->expr->expr, $objName)) {
                return true;
            }
        }

        // Check function/method call arguments for &$o->prop patterns
        if ($stmt instanceof Node\Stmt\Expression) {
            $expr = $stmt->expr;
            $args = null;
            if ($expr instanceof Expr\FuncCall) {
                $args = $expr->args;
            } elseif ($expr instanceof Expr\MethodCall || $expr instanceof Expr\StaticCall
                || $expr instanceof Expr\NullsafeMethodCall) {
                $args = $expr->args;
            }
            if ($args) {
                foreach ($args as $arg) {
                    // Explicit &$o->prop
                    if ($arg->byRef && $this->isPropOfObj($arg->value, $objName)) {
                        return true;
                    }
                    // refval($o->prop) pseudo-function
                    if ($arg->value instanceof Expr\FuncCall
                        && $arg->value->name instanceof Node\Name
                        && $arg->value->name->toLowerString() === 'refval'
                        && !empty($arg->value->args)) {
                        $inner = $arg->value->args[0]->value;
                        if ($this->isPropOfObj($inner, $objName)) {
                            return true;
                        }
                    }
                }
            }
        }

        // Recurse into compound statements
        return $this->recurseDangerousPropOp($stmt, $objName);
    }

    /**
     * Check if an expression is a property fetch on a specific object.
     */
    protected function isPropOfObj($node, string $objName): bool
    {
        return $node instanceof Expr\PropertyFetch
            && $node->var instanceof Expr\Variable
            && is_string($node->var->name)
            && $node->var->name === $objName;
    }

    protected function recurseDangerousPropOp($stmt, string $objName): bool
    {
        if ($stmt instanceof Node\Stmt\If_) {
            if ($this->hasDangerousPropOps($objName, $stmt->stmts)) return true;
            foreach ($stmt->elseifs as $elseif) {
                if ($this->hasDangerousPropOps($objName, $elseif->stmts)) return true;
            }
            if ($stmt->else && $this->hasDangerousPropOps($objName, $stmt->else->stmts)) return true;
        }

        if ($stmt instanceof Node\Stmt\While_ || $stmt instanceof Node\Stmt\Do_) {
            if ($this->hasDangerousPropOps($objName, $stmt->stmts)) return true;
        }

        if ($stmt instanceof Node\Stmt\For_ || $stmt instanceof Node\Stmt\Foreach_) {
            if ($this->hasDangerousPropOps($objName, $stmt->stmts)) return true;
        }

        if ($stmt instanceof Node\Stmt\TryCatch) {
            if ($this->hasDangerousPropOps($objName, $stmt->stmts)) return true;
            foreach ($stmt->catches as $catch) {
                if ($this->hasDangerousPropOps($objName, $catch->stmts)) return true;
            }
            if ($stmt->finally && $this->hasDangerousPropOps($objName, $stmt->finally->stmts)) return true;
        }

        if ($stmt instanceof Node\Stmt\Switch_) {
            foreach ($stmt->cases as $case) {
                if ($this->hasDangerousPropOps($objName, $case->stmts)) return true;
            }
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
        $zvalMacro = ($cType === 'php::Float') ? 'Z_DVAL_P' : 'Z_LVAL_P';
        $this->context->beforeStmtLines[] = $cType . ' &' . $propVar . ' = ' . $zvalMacro . '(' . $refGetter . '.unwrap_ptr());';
        $this->context->hoistedProps[$objName][$propName] = true;

        return $propVar;
    }
}
