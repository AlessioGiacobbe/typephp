<?php
/**
 * This file is part of TypePHP.
 *
 * Resolves object and static method calls, including native and magic fallbacks.
 */

namespace TypePhp\Parser;

use TypePhp\Type;

use PhpParser\Modifiers;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\CallLike;
use TypePhp\Exception\DynamicCall;
use TypePhp\Exception\PlaceHolder;
use TypePhp\Generator\Symbol;

trait MethodCallTrait
{
    protected function isOverrideMethod(string $fullMethodName): bool
    {
        $fullMethodNameLower = strtolower($fullMethodName);
        return isset($this->classMethodOverride[$fullMethodNameLower]) and $this->classMethodOverride[$fullMethodNameLower];
    }

    protected function getOverrideMethodName(string $class, string $method): string
    {
        if (!$this->hasClass($class) && !$this->hasInterface($class)
            && !$this->isInternalClass($class) && !$this->isInternalInterface($class)) {
            $class = $this->getNamespacedClassName($class);
        }
        return $class . '::' . $method;
    }

    protected function hasSubClasses(string $classNameLower): bool
    {
        return !empty($this->classSubClasses[$classNameLower]);
    }

    protected function isCurrentClassFinal(): bool
    {
        return $this->classDef && ($this->classDef->flags & Modifiers::FINAL) !== 0;
    }

    protected function isFinalClass(string $class): bool
    {
        return $this->hasClass($class) && ($this->getClass($class)->flags & Modifiers::FINAL) !== 0;
    }

    protected function getMethodFlags(string $class, string $method): int
    {
        if (!$this->hasClass($class)) {
            return 0;
        }
        $classDef = $this->getClass($class);
        while (true) {
            $flags = $classDef->getMethodFlags($method);
            if ($flags !== 0) {
                return $flags;
            }
            if (!$classDef->extends || !$this->hasClass($classDef->extends)) {
                return 0;
            }
            $classDef = $this->getClass($classDef->extends);
        }
    }

    protected function guardAbstractMethod(string $class, string $method, Node $expr): void
    {
        $flags = $this->getMethodFlags($class, $method);
        if ($flags & Modifiers::ABSTRACT) {
            $this->fatalError($expr, "Cannot call abstract method `{$class}::{$method}()`");
        }
    }

    /**
     * Determine whether a method call can be devirtualized to a direct native call.
     *
     * Returns true when the exact class is known at compile time:
     *  1. $this->m() in a final class (no subclass possible)
     *  2. $this->m() where m is final (can't be overridden)
     *  3. $this->m() where m is private (not virtual)
     *  4. $obj->m() where obj's class has no known subclasses
     *  5. $obj->m() where obj is SSA-stable and its class is final
     */
    protected function canDevirtualize(string $object, string $class, string $method): bool
    {
        // Case 1: Calling on 'this_' in a final class
        if ($object === 'this_' && $this->isCurrentClassFinal()) {
            return true;
        }

        // Case 2 & 3: Method is final or private
        $flags = $this->getMethodFlags($class, $method);
        if ($flags & (Modifiers::FINAL | Modifiers::PRIVATE)) {
            return true;
        }

        // Case 4: Typed object whose class has no known subclasses
        if ($object !== 'this_' && $this->hasClass($class)) {
            $classLower = strtolower($class);
            if (!$this->hasSubClasses($classLower) && !$this->isInterface($class) && !$this->isAbstractClass($class)) {
                return true;
            }
        }

        // Case 5: SSA stability proves the variable identity, not necessarily
        // the runtime class. Only final classes are exact enough here.
        if ($object !== 'this_' && isset($this->context->stableObjects[$object])) {
            $stableClass = $this->context->stableObjects[$object];
            if ($this->isFinalClass($stableClass)) {
                return true;
            }
        }

        return false;
    }

    protected function findNativeMethod(CallLike $expr, string $object, string $method): string|false
    {
        $classDef = null;
        if ($object === 'this_') {
            $class = $this->getFullClassName();
            $classDef = $this->classDef;
        } elseif (isset($this->context->objects[$object])) {
            $class = $this->context->objects[$object];
            // SSA-stable: use exact type from stableObjects (more specific than declared type)
            if (isset($this->context->stableObjects[$object])) {
                $class = $this->context->stableObjects[$object];
            }
        } else {
            return false;
        }

        $nativeFunc = $this->getNativeMethod($expr, $class, $method);
        // 存在 Native 类，但是没有找到方法，可能是动态调用
        if (!$nativeFunc) {
            if ($this->hasClass($class) and $this->getNativeMethod($expr, $class, '__call', false)) {
                throw new DynamicCall();
            }
        }

        $fullMethodName = $this->getOverrideMethodName($class, $method);

        // 存在子类同名方法，尝试去虚化
        if ($this->isOverrideMethod($fullMethodName)) {
            if (!$this->canDevirtualize($object, $class, $method)) {
                return false;
            }
        }
        if ($nativeFunc) {
            if ($this->hasClass($class) && $this->getMethodFlags($class, $method) & Modifiers::ABSTRACT) {
                return false;
            }
            if ($object !== 'this_' && !isset($this->context->stableObjects[$object])
                && ($this->isAbstractClass($class) || $this->isInterface($class))) {
                return false;
            }
            $this->checkFunction($nativeFunc);
            if ($this->hasFunction($nativeFunc)) {
                return $nativeFunc;
            }
        }
        return false;
    }

    protected function parseNativeMethodCall(string $object, string $nativeFunc, array $args): string
    {
        if ($this->getVarType($object) != Type::OBJECT) {
            $tmpVar = $this->genTmpVarName();
            $this->context->beforeStmtLines[] = Type::OBJECT . ' ' . $tmpVar . ' = ' . $object . ';';
            $object = $tmpVar;
        }
        if (count($args) === 0) {
            return self::PREFIX . $nativeFunc . '(' . $object . ')';
        }
        return self::PREFIX . $nativeFunc . '(' . $object . ', ' . $this->parseNativeCallArgs($args, $nativeFunc) . ')';
    }

    protected function parseStdCall(Expr\StaticCall $expr): string
    {
        $func = strtolower($this->parseIdentifier($expr->name));
        $type = match ($func) {
            'int' => Type::INT,
            'float' => Type::FLOAT,
            'bool' => Type::BOOL,
            'bigint' => Type::BIGINT,
            'decimal' => Type::DECIMAL,
            'bigfloat' => Type::BIGFLOAT,
            default => '',
        };
        if ($type) {
            $expr->setAttribute('nativeType', $type);
            $valueExpr = $this->parseExpr($expr->args[0]->value);
            if (in_array($type, [Type::INT, Type::FLOAT, Type::BOOL])) {
                return $this->convertExprFromType($type, $valueExpr);
            }
            $argType = $this->detectTypeOfExpr($expr->args[0]->value);
            if ($argType === $type) {
                return $valueExpr;
            }
            if ($type === Type::BIGINT) {
                if ($argType === Type::FLOAT) {
                    $this->fatalError($expr, 'Cannot construct BigInt from float, use string or int instead');
                }
                                if ($argType === Type::INT) {
                    return 'php::toBigInt(' . $valueExpr . ')';
                }
                return 'php::BigInt::newInstance(' . $valueExpr . ')';
            }
            if ($type === Type::DECIMAL) {
                if ($argType === Type::FLOAT) {
                    $argNode = $expr->args[0]->value;
                    if ($argNode instanceof Node\Scalar\Float_) {
                        $rawValue = $argNode->getAttribute('rawValue');
                        $clean = $rawValue !== null ? $this->stripNumericUnderscores($rawValue) : (string)$argNode->value;
                                                return 'php::toDecimal(' . $this->getLiteralString($clean) . ')';
                    }
                    $this->fatalError($expr, 'Cannot construct Decimal from float variable, use string or int instead');
                }
                                if ($argType === Type::INT) {
                    return 'php::toDecimal(' . $valueExpr . ')';
                }
                return 'php::Decimal::newInstance(' . $valueExpr . ')';
            }
            if ($type === Type::BIGFLOAT) {
                                if ($argType === Type::INT) {
                    return 'php::toBigFloat(' . $valueExpr . ')';
                }
                if ($argType === Type::FLOAT) {
                    return 'php::toBigFloat(' . $valueExpr . ')';
                }
                return 'php::BigFloat::newInstance(' . $valueExpr . ')';
            }
            return $valueExpr;
        } else {
            $this->fatalError($expr, 'Unknown std method: ' . $func);
        }
    }


    protected function parseParentMethodCall(Expr\StaticCall $expr): string
    {
        if (!$this->classDef->extends) {
            $this->fatalError($expr, 'Cannot call parent method because class `' . $this->classDef->name . '` does not extend any class');
        }
        $parentClass = $this->classDef->extends;
        if ($this->isIdExpr($expr->name)) {
            $method = $this->parseIdentifier($expr->name);
            $this->guardAbstractMethod($parentClass, $method, $expr);
            $methodPtr = $this->getMethodPtr($parentClass, $method);
        } else {
            $method = '';
            // parent:: is bound to the lexical parent class, not the runtime
            // object's parent. Look the method up on that class, then invoke it
            // through this_ so Zend receives the current call scope.
            $methodPtr = 'php::getMethod(' . $this->getClassEntryPtr($parentClass) . ', '
                . $this->identifierToStr($expr->name) . ')';
        }
        if (empty($expr->args)) {
            return 'this_.call(' . $methodPtr . ')';
        }
        // 传入方法名与父类名，以便在按引用参数检测时解析方法签名
        return 'this_.call(' . $methodPtr . ', ' . $this->parseCallArgs($expr->args, $method, $parentClass) . ')';
    }


    protected function genToObjectCall(Expr\MethodCall $expr, string $receiver): string
    {
        if (empty($expr->args)) {
            return 'php::toObject(' . $receiver . ')';
        }
        $className = $this->resolveClassNameArg($expr->args[0]->value);
        return 'php::toObject(' . $receiver . ', ' . $this->getClassEntryPtr($className) . ')';
    }

    protected function genToRefCall(Expr\MethodCall $expr): string
    {
        if (!empty($expr->args)) {
            $this->fatalError($expr, 'The toRef method does not accept parameters');
        }
        return $this->parseChainedExpr($expr->var, self::OP_REFVAL);
    }

    protected function parseMethodCall(Expr\MethodCall $expr): string
    {
        if ($this->containsNullsafeChain($expr->var)) {
            return $this->parseNullsafeExpr($expr);
        }

        $class = '';
        $object = $this->parseIdentifier($expr->var);
        if ($this->isVarExpr($expr->var)) {
            if (!$this->hasVar($object)) {
                $this->errorUndefinedVariable($expr->var);
            }
            if ($this->isTypedObject($object)) {
                $class = $this->getObjectType($object);
            } elseif ($object === 'this_') {
                // $this 在构造函数/方法中静态类型为当前类，便于解析抽象方法等按引用参数签名
                $class = $this->classDef !== null ? $this->classDef->getNamespacedName(false) : $this->class;
            } else {
                // 接口和抽象类类型的变量没有具体对象类型，仍可从声明签名解析按引用参数。
                $class = $this->getDeclaredObjectType($object);
            }
        }

        $magicMethod = false;
        $method = $this->identifierToStr($expr->name, literal: true);

        // keyword methods (to* builtins + __ extensions) — dispatched before type-specific logic
        if ($this->isNamedMethod($expr->name)) {
            $methodName = $expr->name->toString();
            $receiverType = $this->isVarExpr($expr->var) ? $this->getVarType($object) : $this->detectTypeOfExpr($expr->var);
            if ($receiverType === Type::VOID) {
                $receiverType = Type::VAR;
            }
            // to* builtins
            if (isset(self::KEYWORD_METHOD_MAP[$methodName])) {
                if ($methodName === 'toObject') {
                    return $this->genToObjectCall($expr, $object);
                }
                if ($methodName === 'toRef') {
                    return $this->genToRefCall($expr);
                }
                if ($methodName === 'toAny' && !empty($expr->args)) {
                    $this->fatalError($expr, 'The toAny method does not accept parameters');
                }
                return $this->genToConvertCall($object, $methodName, $receiverType);
            }
            // A provider targeting Type::Any only applies when
            // the receiver's static type is actually mixed/any.
            if ($receiverType === Type::VAR) {
                $anyExtension = $this->findExtensionMethod(Type::VAR, $methodName);
                if ($anyExtension) {
                    return $this->parseUniversalMethodCall($expr, $object, $methodName, $anyExtension, $this->isVarExpr($expr->var));
                }
            }
            // ExtensionProvider::Keyword extensions apply to every receiver type.
            $kwExt = $this->findKeywordExtensionMethod($methodName);
            if ($kwExt) {
                return $this->parseUniversalMethodCall($expr, $object, $methodName, $kwExt, $this->isVarExpr($expr->var));
            }
        }

        // 可转为原生调用的 MethodCall
        if ($this->isVarExpr($expr->var) and $this->isNamedMethod($expr->name)) {
            $type = $this->getVarType($object);
            // 引用参数允许方法调用：有class信息走原生调用，无class信息走动态调用
            if (!$this->checkArgType($type, Type::OBJECT) and $type !== Type::REF) {
                $methodName = $expr->name->toString();
                // 非对象类型可使用内置方法
                $fn = $this->findUniversalMethodAnyType($type, $methodName);
                if ($fn) {
                    if ($type === Type::STREAM) {
                        return $this->genStreamNullGuard($expr, $object, $methodName, $fn);
                    }
                    return $this->parseUniversalMethodCall($expr, $object, $methodName, $fn);
                }
                $this->fatalError($expr, "Cannot call method `{$methodName}()` on variable of type {$type}");
            }
            $this->context->beforeStmtLines[] = $this->formatCppLineComment(
                'Method Call: ',
                $object . '->' . $this->parseIdentifier($expr->name) . '()'
            );
            try {
                $nativeFunc = $this->findNativeMethod($expr, $object, $this->parseIdentifier($expr->name));
                if ($nativeFunc) {
                    $expr->setAttribute('nativeCall', $nativeFunc);
                    try {
                        if ($this->shouldUseDynamicCallForNativeArgs($nativeFunc, $expr->args)) {
                            return $this->genRuntimeObjectMethodCall($object, $this->getMethodPtr($class, $methodName), $expr->args, $methodName, $class);
                        }
                        return $this->parseNativeMethodCall($object, $nativeFunc, $expr->args);
                    } catch (PlaceHolder) {
                        return $this->genPlaceHolder($this->genArray([$object, $method]));
                    }
                }
            } catch (DynamicCall) {
                $extension = $this->findObjectExtensionMethod($class, $methodName);
                if ($extension !== null) {
                    return $this->parseUniversalMethodCall($expr, $object, $methodName, $extension);
                }
                $magicMethod = true;
            }
            if (!$nativeFunc) {
                $extension = $this->findObjectExtensionMethod($class, $methodName);
                if ($extension !== null) {
                    return $this->parseUniversalMethodCall($expr, $object, $methodName, $extension);
                }
            }
        }

        // 表达式返回值也可使用内置方法：fn()->method(), $obj->fn()->method(), Foo::fn()->method(), $obj->prop->method()
        if (!$this->isVarExpr($expr->var) and $this->isNamedMethod($expr->name)) {
            $type = $this->detectTypeOfExpr($expr->var);
            if ($type === Type::VOID) {
                $type = Type::VAR;
            }
            if ($type !== Type::VAR && !$this->checkArgType($type, Type::OBJECT)) {
                $methodName = $expr->name->toString();
                $fn = $this->findUniversalMethodAnyType($type, $methodName);
                if ($fn) {
                    // Wrap receiver in type conversion for direct_method handlers
                    // since the raw expression (often from php::call()) is php::Variant
                    $receiver = $object;
                    if ($fn['handler'] === 'direct_method') {
                        $receiver = $this->wrapUniversalReceiver($type, $object);
                    }
                    if ($type === Type::STREAM) {
                        return $this->genStreamNullGuard($expr, $receiver, $methodName, $fn);
                    }
                    return $this->parseUniversalMethodCall($expr, $receiver, $methodName, $fn, false);
                }
            }

            $extensionClass = $this->detectClassOfExpr($expr->var);
            $extension = $this->findObjectExtensionMethod($extensionClass, $methodName);
            if ($extension !== null) {
                return $this->parseUniversalMethodCall($expr, $object, $methodName, $extension, false);
            }
        }

        if ($this->isNamedMethod($expr->name)) {
            $funcName = $this->parseIdentifier($expr->name);
        } else {
            $funcName = '';
        }

        if ($class && $funcName && !$magicMethod && $this->isInternalClass($class)) {
            $methodPtr = $this->getMethodPtr($class, $funcName);
        } else {
            $methodPtr = $method;
        }

        if ($object === 'this_' or $object === 'self' or $object === 'static') {
            $this->methodDef->hasDynamicCall = true;
        }

        if (empty($expr->args)) {
            return $object . '.call(' . $methodPtr . ')';
        }
        try {
            $class = empty($class) ? self::DYNAMIC_CALLED_CLASS : $class;
            return $this->genRuntimeObjectMethodCall($object, $methodPtr, $expr->args, $funcName, $class);
        } catch (PlaceHolder) {
            return $this->genPlaceHolder($this->genArray([$object, $method]));
        }
    }


    protected function parseStaticCall(Expr\StaticCall $expr): string
    {
        $self = false;
        $callScope = [];
        $rtFunc = '';
        $rtClass = '';
        $class = $this->parseIdentifier($expr->class);

        // parent::$method() still has a lexical parent class even when the
        // method name itself is dynamic. Handle it before the generic dynamic
        // static-call branch below.
        if ($this->isNameExpr($expr->class) && $class === 'parent') {
            return $this->parseParentMethodCall($expr);
        }

        if ($this->isVarExpr($expr->class) or $this->isVarExpr($expr->name)) {
            $var = $class;
            if ($this->isTypedObject($var)) {
                $class = $this->getObjectType($var);
                goto _do_call;
            }
            if ($this->getVarType($var) == Type::OBJECT) {
                $fn = 'php::concat({' . $var . '.getClassName(), "::", ' . $this->identifierToStr($expr->name) . '})';
            } else {
                $fn = 'php::concat({' . $this->identifierToStr($expr->class) . ', "::", ' . $this->identifierToStr($expr->name) . '})';
            }
            $placeHolder = $fn;
        } elseif ($this->isNameExpr($expr->class) and $class === 'static') {
            $method = $this->parseIdentifier($expr->name);
            $methodPtr = $this->identifierToStr($expr->name, literal: true);
            $fn = Symbol::getCalledCe() . ', php::getMethod(' . Symbol::getCalledCe() . ', ' . $methodPtr . ')';
            $this->context->beforeStmtLines[] = $this->formatCppLineComment(
                'Static Method Call: ',
                'static::' . $method . '()'
            );
            $placeHolder = $this->genArray([Symbol::getCalledClass(), $methodPtr]);
            // 用于在按引用参数检测时解析方法签名（late static binding 在当前类层级中解析）
            $rtFunc = $method;
            $rtClass = $this->getNamespacedClassName($this->class);
        } elseif ($this->isNameExpr($expr->class)) {
            if ($class === 'self') {
                $class = $this->class;
                $self = true;
            } elseif ($class === 'std') {
                return $this->parseStdCall($expr);
            }
            $class = $this->getNamespacedClassName($class);

            _do_call:
            $method = $this->parseIdentifier($expr->name);
            $rtFunc = $method;
            $rtClass = $class;
            $dynamicCall = false;
            $this->context->beforeStmtLines[] = $this->formatCppLineComment(
                'Static Method Call: ',
                $class . '::' . $method . '()'
            );

            if ($this->isNameExpr($expr->class) and $this->isIdExpr($expr->name)) {
                $callScope = [$this->genCharPtr($class, true), $this->genCharPtr($method)];
            }

            if ($callScope) {
                $nativeFunc = $this->getNativeMethod($expr, $class, $method);
                // 存在 Native 类，但是没有找到方法，可能是动态调用
                if (!$nativeFunc and $this->hasClass($class) and $this->getNativeMethod($expr, $class, '__callStatic', false)) {
                    $dynamicCall = true;
                }

                if ($nativeFunc) {
                    try {
                        if ($this->shouldUseDynamicCallForNativeArgs($nativeFunc, $expr->args)) {
                            return $this->genRuntimeFunctionCall(
                                $this->getClassEntryPtr($class) . ', ' . $this->getFuncPtr($class . '::' . $method),
                                $expr->args,
                                $method,
                                $class
                            );
                        }
                        $args = $this->parseNativeCallArgs($expr->args, $nativeFunc);
                        $expr->setAttribute('nativeCall', $nativeFunc);
                    } catch (PlaceHolder) {
                        return $this->genPlaceHolder($this->genArray($callScope));
                    }
                    // 在方法定义中使用了当前类的方法 self::method()，依然应该传递 this_ 指针
                    if ($this->methodDef and $self) {
                        $object = 'this_';
                    } else {
                        $object = $this->getCeWrapper($class);
                    }
                    if ($args) {
                        return self::PREFIX . $nativeFunc . '(' . $object . ', ' . $args . ')';
                    } else {
                        return self::PREFIX . $nativeFunc . '(' . $object . ')';
                    }
                }
            }

            if ($dynamicCall) {
                $fn = $this->getLiteralString($class . '::' . $method);
            } else {
                $ce = $this->getClassEntryPtr($class);
                $fn = $ce . ', ' . $this->getFuncPtr($class . '::' . $method);
            }
            $placeHolder = $this->genArray($callScope);
        } else {
            $fn = 'php::concat({' . $this->identifierToStr($expr->class) . ', "::", ' . $this->identifierToStr($expr->name) . '})';
            $placeHolder = $fn;
        }

        $call = 'php::call';
        if (empty($expr->args)) {
            return $call . '(' . $fn . ')';
        }
        try {
            return $this->genRuntimeFunctionCall($fn, $expr->args, $rtFunc, $rtClass);
        } catch (PlaceHolder) {
            return $this->genPlaceHolder($placeHolder);
        }
    }

}
