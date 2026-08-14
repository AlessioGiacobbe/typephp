<?php
/**
 * This file is part of TypePHP.
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace TypePhp\NativeClass;

use TypePhp\Entity\ArgInfo;
use TypePhp\Entity\ClassDef;
use TypePhp\Entity\FunctionDef;
use TypePhp\Entity\MethodDef;
use TypePhp\Entity\PropertyDef;
use TypePhp\Resolver\Reflection;
use PhpParser\Modifiers;
use TypePhp\Type;
use PhpParser\NodeAbstract;
use PhpParser\Node;

trait NativeClassSupportTrait
{
    /**
     * Magic methods whose semantics require Zend object handlers, runtime
     * method resolution, dynamic properties, or Zend serialization state.
     * Native classes deliberately have none of those runtime facilities.
     */
    private const UNSUPPORTED_NATIVE_MAGIC_METHODS = [
        '__call' => true,
        '__callstatic' => true,
        '__get' => true,
        '__set' => true,
        '__isset' => true,
        '__unset' => true,
        '__sleep' => true,
        '__wakeup' => true,
        '__serialize' => true,
        '__unserialize' => true,
        '__set_state' => true,
        '__debuginfo' => true,
    ];

    protected function assertNativeMagicMethodSupported(NodeAbstract $node, string $method): void
    {
        if (!$this->classDef?->nativeObject
            || !isset(self::UNSUPPORTED_NATIVE_MAGIC_METHODS[strtolower($method)])
        ) {
            return;
        }
        $this->fatalError(
            $node,
            "Native classes do not support dynamic magic method `{$method}()`",
        );
    }

    /**
     * Native classes have no zend_class_entry, so Zend cannot perform the
     * normal MINIT-time interface verification for them. Convert an internal
     * reflection signature into the compiler's existing MethodDef model and
     * run the same compatibility checker used for project interfaces.
     */
    protected function checkInternalInterfaceImplementation(
        NodeAbstract $node,
        ClassDef $classDef,
        string $interfaceName,
    ): void {
        $interface = Reflection::getClass($interfaceName);
        if ($interface === null) {
            $this->fatalError($node, "Internal interface `{$interfaceName}` is not available");
        }

        foreach ($interface->getMethods() as $method) {
            $childMethodDef = $this->findClassMethodDef($classDef, $method->getName(), $classDef->isAbstract());
            if ($childMethodDef === null) {
                if ($classDef->isAbstract()) {
                    continue;
                }
                $this->fatalError(
                    $node,
                    "Class `{$classDef->getNamespacedName(false)}` must implement method " .
                    "`{$interfaceName}::{$method->getName()}()`",
                );
            }
            $this->validateMethodOverrideSignature(
                $childMethodDef->node ?? $node,
                $method->getName(),
                $childMethodDef,
                $this->createInternalInterfaceMethodDef($method),
                $interfaceName,
            );
        }
    }

    private function createInternalInterfaceMethodDef(\ReflectionMethod $method): MethodDef
    {
        $flags = Modifiers::PUBLIC | Modifiers::ABSTRACT;
        if ($method->isStatic()) {
            $flags |= Modifiers::STATIC;
        }

        $methodDef = new MethodDef($flags, $method->getName());
        $functionDef = new FunctionDef($method->getName(), Type::VAR, '');
        $functionDef->method = true;
        $functionDef->declaringClass = $method->getDeclaringClass()->getName();
        $functionDef->returnsByRef = $method->returnsReference();
        $functionDef->returnTypeUndeclared = $method->getReturnType() === null;
        $this->applyReflectedReturnType($functionDef, $method->getReturnType(), $method->getDeclaringClass());

        foreach ($method->getParameters() as $parameter) {
            $argument = new ArgInfo();
            $argument->name = $parameter->getName();
            $argument->phpName = $parameter->getName();
            $argument->byRef = $parameter->isPassedByReference();
            $argument->variadic = $parameter->isVariadic();
            $argument->undeclared = $parameter->getType() === null;
            $argument->nullable = $parameter->allowsNull();
            $this->applyReflectedParameterType($argument, $parameter->getType(), $method->getDeclaringClass());
            if ($parameter->isOptional() || $parameter->isVariadic()) {
                // Compatibility only needs to distinguish required from
                // optional parameters; the concrete default is irrelevant.
                $argument->defaultValue = new Node\Expr\ConstFetch(new Node\Name('null'));
            }
            $functionDef->argInfoList[] = $argument;
        }
        $functionDef->argCountRequired = $method->getNumberOfRequiredParameters();
        $methodDef->functionDef = $functionDef;
        return $methodDef;
    }

    private function applyReflectedReturnType(
        FunctionDef $function,
        ?\ReflectionType $type,
        \ReflectionClass $declaringClass,
    ): void {
        if ($type === null) {
            $function->returnTypeStr = '';
            return;
        }
        $node = $this->reflectionTypeToNode($type, $declaringClass);
        $function->returnTypeStr = $this->typeCheckNodeToString($node);
        if ($node instanceof Node\NullableType
            || $node instanceof Node\UnionType
            || $node instanceof Node\IntersectionType
        ) {
            $typeInfo = $this->buildTypeCheckFromNode($node);
            $function->returnType = Type::VAR;
            $function->returnTypeCheck = $typeInfo['check'];
            $function->returnTypeNode = $node;
            return;
        }
        [$function->returnType, $function->returnClass] = $this->resolveReflectedNamedType($node);
    }

    private function applyReflectedParameterType(
        ArgInfo $argument,
        ?\ReflectionType $type,
        \ReflectionClass $declaringClass,
    ): void {
        if ($type === null) {
            $argument->type = Type::VAR;
            return;
        }
        $node = $this->reflectionTypeToNode($type, $declaringClass);
        $argument->typeStr = $this->typeCheckNodeToString($node);
        if ($node instanceof Node\NullableType
            || $node instanceof Node\UnionType
            || $node instanceof Node\IntersectionType
        ) {
            $typeInfo = $this->buildTypeCheckFromNode($node);
            $argument->type = Type::VAR;
            $argument->typeCheck = $typeInfo['check'];
            $argument->typeNode = $node;
            return;
        }
        [$argument->type, $argument->declaredClass] = $this->resolveReflectedNamedType($node);
        if ($argument->declaredClass !== '' && !$this->isInterface($argument->declaredClass)) {
            $argument->class = $argument->declaredClass;
        }
        $argument->explicitMixed = strtolower($argument->typeStr) === 'mixed';
    }

    /** @return array{string, string} */
    private function resolveReflectedNamedType(NodeAbstract $node): array
    {
        $name = $this->parseIdentifier($node);
        $lower = strtolower(ltrim($name, '\\'));
        if (isset($this->zendTypeMap[$lower])) {
            return [$this->getTypeFromZendType($lower), ''];
        }
        return [Type::OBJECT, ltrim($name, '\\')];
    }

    private function reflectionTypeToNode(
        \ReflectionType $type,
        \ReflectionClass $declaringClass,
        bool $allowNullableWrapper = true,
    ): NodeAbstract {
        if ($type instanceof \ReflectionUnionType) {
            return new Node\UnionType(array_map(
                fn (\ReflectionType $member): NodeAbstract =>
                    $this->reflectionTypeToNode($member, $declaringClass, false),
                $type->getTypes(),
            ));
        }
        if ($type instanceof \ReflectionIntersectionType) {
            return new Node\IntersectionType(array_map(
                fn (\ReflectionType $member): NodeAbstract =>
                    $this->reflectionTypeToNode($member, $declaringClass, false),
                $type->getTypes(),
            ));
        }

        /** @var \ReflectionNamedType $type */
        $name = $type->getName();
        $lower = strtolower($name);
        if ($lower === 'self') {
            $node = new Node\Name\FullyQualified($declaringClass->getName());
        } elseif ($lower === 'parent') {
            $parent = $declaringClass->getParentClass();
            $node = $parent === false
                ? new Node\Name('parent')
                : new Node\Name\FullyQualified($parent->getName());
        } elseif ($lower === 'static') {
            $node = new Node\Name('static');
        } elseif ($type->isBuiltin()) {
            $node = new Node\Identifier($name);
        } else {
            $node = new Node\Name\FullyQualified($name);
        }

        if ($allowNullableWrapper
            && $type->allowsNull()
            && !in_array($lower, ['mixed', 'null'], true)
        ) {
            return new Node\NullableType($node);
        }
        return $node;
    }

    protected function isNativeObjectClass(string $class): bool
    {
        $class = ltrim($class, '\\');
        return $class !== '' && $this->hasClass($class) && $this->getClass($class)->nativeObject;
    }

    protected function getNativeObjectCppName(string|ClassDef $class): string
    {
        $classDef = $class instanceof ClassDef ? $class : $this->getClass(ltrim($class, '\\'));
        return self::PREFIX . $this->getNativeName('', $classDef->namespace, $classDef->name);
    }

    protected function getNativeObjectDescriptorName(string|ClassDef $class): string
    {
        return $this->getNativeObjectCppName($class) . '__type';
    }

    protected function getNativeObjectPointerType(string|ClassDef $class): string
    {
        return $this->getNativeObjectCppName($class) . ' *';
    }

    protected function getNativeObjectArgumentType(ArgInfo $argument): ?string
    {
        $class = $argument->declaredClass ?: $argument->class;
        if ((!$argument->byRef && $argument->type !== Type::OBJECT)
            || !$this->isNativeObjectClass($class)
        ) {
            return null;
        }
        return $this->getNativeObjectPointerType($class) . ($argument->byRef ? '&' : '');
    }

    protected function resolveNullableNativeObjectType(?NodeAbstract $type, int $declarationKind): ?array
    {
        $inner = null;
        if ($type instanceof Node\NullableType) {
            $inner = $type->type;
        } elseif ($type instanceof Node\UnionType && count($type->types) === 2) {
            foreach ($type->types as $member) {
                if ($member instanceof Node\Identifier && strtolower($member->toString()) === 'null') {
                    continue;
                }
                if ($inner !== null) {
                    return null;
                }
                $inner = $member;
            }
        }
        if (!$inner instanceof Node\Name) {
            return null;
        }
        [$innerType, $class] = $this->resolveTypeDecl($inner, $declarationKind);
        if ($innerType !== Type::OBJECT || !$this->isNativeObjectClass($class)) {
            return null;
        }
        return [Type::OBJECT, $class];
    }

    /** @return list<string> */
    protected function getNativeObjectClassesFromTypeNode(?NodeAbstract $type, int $declarationKind): array
    {
        if ($type === null || $type instanceof Node\Identifier) {
            return [];
        }
        if ($type instanceof Node\NullableType) {
            return $this->getNativeObjectClassesFromTypeNode($type->type, $declarationKind);
        }
        if ($type instanceof Node\UnionType || $type instanceof Node\IntersectionType) {
            $classes = [];
            foreach ($type->types as $member) {
                foreach ($this->getNativeObjectClassesFromTypeNode($member, $declarationKind) as $class) {
                    $classes[strtolower($class)] = $class;
                }
            }
            return array_values($classes);
        }
        if (!$type instanceof Node\Name) {
            return [];
        }
        [$resolvedType, $class] = $this->resolveTypeDecl($type, $declarationKind);
        return $resolvedType === Type::OBJECT && $this->isNativeObjectClass($class) ? [$class] : [];
    }

    /**
     * Resolve a common Native pointer type for value-selection branches.
     * Null is accepted as the empty state; any Zend/non-object branch makes
     * the expression unsuitable for the Native object model.
     *
     * @param list<NodeAbstract> $expressions
     */
    protected function getCommonNativeObjectExpressionClass(array $expressions): string
    {
        $common = '';
        foreach ($expressions as $expression) {
            if ($this->isNull($expression)) {
                continue;
            }
            $class = $this->detectClassOfExpr($expression);
            if (!$this->isNativeObjectClass($class)) {
                return '';
            }
            if ($common === '') {
                $common = $class;
                continue;
            }
            if ($this->isObjectClassStaticallyAssignableTo($class, $common)) {
                continue;
            }
            if ($this->isObjectClassStaticallyAssignableTo($common, $class)) {
                $common = $class;
                continue;
            }
            return '';
        }
        return $common;
    }

    protected function assertSupportedNativeObjectTypeNode(
        ?NodeAbstract $type,
        int $declarationKind,
        NodeAbstract $errorNode,
    ): void {
        if (!$type instanceof Node\UnionType && !$type instanceof Node\IntersectionType) {
            return;
        }
        $nativeClasses = $this->getNativeObjectClassesFromTypeNode($type, $declarationKind);
        if ($nativeClasses !== [] && $this->resolveNullableNativeObjectType($type, $declarationKind) === null) {
            $this->fatalError(
                $errorNode,
                'Native object types cannot be combined with other union or intersection members',
            );
        }
    }

    protected function getNativeObjectReturnType(FunctionDef $function): ?string
    {
        if ($function->returnType !== Type::OBJECT || !$this->isNativeObjectClass($function->returnClass)) {
            return null;
        }
        return $this->getNativeObjectPointerType($function->returnClass);
    }

    protected function getNativeObjectMethodThisType(FunctionDef $function): ?string
    {
        if (!$function->method || !$this->isNativeObjectClass($function->declaringClass)) {
            return null;
        }
        return $this->getNativeObjectCppName($function->declaringClass) . ' &';
    }

    protected function functionUsesNativeObject(FunctionDef $function): bool
    {
        if ($this->getNativeObjectReturnType($function) !== null
            || $this->getNativeObjectMethodThisType($function) !== null
        ) {
            return true;
        }
        foreach ($function->argInfoList as $argument) {
            if ($this->getNativeObjectArgumentType($argument) !== null) {
                return true;
            }
        }
        return false;
    }

    protected function genNativeObjectParameterChecks(FunctionDef $function): string
    {
        $code = '';
        foreach ($function->argInfoList as $argument) {
            $class = $argument->declaredClass ?: $argument->class;
            if (!$argument->nullable && $this->isNativeObjectClass($class)) {
                $code .= $this->getIndent() . 'php::nativeGcRequireObject('
                    . $argument->name . ', "' . addslashes($class) . '");' . PHP_EOL;
            }
        }
        return $code;
    }

    protected function addNativeObject(string $name, string $class): void
    {
        $this->context->nativeObjects[$name] = ltrim($class, '\\');
        $this->context->objects[$name] = ltrim($class, '\\');
    }

    protected function isNativeObjectVar(string $name): bool
    {
        return isset($this->context->nativeObjects[$name]);
    }

    protected function getNativeObjectVarClass(string $name): string
    {
        return $this->context->nativeObjects[$name] ?? '';
    }

    protected function getNativeObjectReceiver(string $name): string
    {
        if ($name === 'this_') {
            return 'this_';
        }
        $class = $this->getNativeObjectVarClass($name);
        return 'php::nativeDeref(' . $name . ', "' . addslashes($class) . '")';
    }

    protected function getNativeObjectMemberReceiver(string $name): string
    {
        return $this->getNativeObjectReceiver($name) . '.';
    }

    /**
     * Materialize a Native-producing expression as a precisely rooted local.
     * This is shared by chained method and property access so neither path can
     * accidentally pass a Native pointer through php::Variant.
     */
    protected function materializeNativeObjectReceiver(NodeAbstract $expr, string $class): string
    {
        [$receiver, $beforeStmts, $afterStmts] = $this->parseExprWithCapturedStmts($expr);
        $this->appendCapturedStmtLinesToContext($beforeStmts);
        $object = $this->genTmpVarName();
        $this->addLocalVar($object, $this->getNativeObjectPointerType($class));
        $this->addNativeObject($object, $class);
        $this->context->beforeStmtLines[] = $object . ' = ' . $receiver . ';';
        $this->appendCapturedStmtLinesToContext($afterStmts);
        // Keep the temporary in the precise root frame while the complete PHP
        // statement executes, then release that root at statement end.
        $this->context->afterStmtLines[] = $object . ' = nullptr;';
        return $object;
    }

    protected function findNativeObjectProperty(string $class, string $property): ?PropertyDef
    {
        while ($class !== '' && $this->hasClass($class)) {
            $classDef = $this->getClass($class);
            if ($classDef->hasProperty($property)) {
                return $classDef->getProperty($property);
            }
            $class = $classDef->extends;
        }
        return null;
    }

    protected function findNativeObjectMethod(string $class, string $method): ?MethodDef
    {
        while ($class !== '' && $this->isNativeObjectClass($class)) {
            $classDef = $this->getClass($class);
            if ($classDef->hasMethod($method)) {
                return $classDef->getMethod($method);
            }
            $class = $classDef->extends;
        }
        return null;
    }

    /**
     * Native objects cannot fall back to PHPX/Zend conversion helpers. A
     * keyword conversion is therefore a statically checked ordinary Native
     * method call. __toString() is accepted as the PHP-compatible spelling of
     * toString().
     */
    protected function resolveNativeObjectKeywordMethod(
        NodeAbstract $node,
        string $class,
        string $method,
    ): string {
        $expectedType = self::KEYWORD_METHOD_MAP[$method] ?? null;
        if ($expectedType === null) {
            return $method;
        }
        $resolvedMethod = $method;
        $methodDef = $this->findNativeObjectMethod($class, $resolvedMethod);
        if ($method === 'toString' && $methodDef === null) {
            $resolvedMethod = '__toString';
            $methodDef = $this->findNativeObjectMethod($class, $resolvedMethod);
        }
        if ($methodDef === null) {
            $this->fatalError($node, "Native class `{$class}` must define `{$method}()` for this conversion");
        }
        $function = $methodDef->functionDef;
        if ($function->argInfoList !== []) {
            $this->fatalError($node, "Native conversion method `{$class}::{$resolvedMethod}()` must not accept arguments");
        }
        if ($function->returnsByRef || $function->returnNullable || $function->returnType !== $expectedType) {
            $expectedTypeName = match ($expectedType) {
                Type::INT => 'int',
                Type::FLOAT => 'float',
                Type::STR => 'string',
                Type::BOOL => 'bool',
                Type::ARRAY => 'array',
                Type::STREAM => 'Stream',
                Type::BIGINT => 'BigInt',
                Type::BIGFLOAT => 'BigFloat',
                Type::DECIMAL => 'Decimal',
                Type::OBJECT => 'object',
                Type::VAR => 'mixed',
                default => $expectedType,
            };
            $this->fatalError(
                $node,
                "Native conversion method `{$class}::{$resolvedMethod}()` must return exactly `{$expectedTypeName}`",
            );
        }
        return $resolvedMethod;
    }

    protected function getNativeVirtualMethodName(string $method): string
    {
        return '__typephp_virtual_' . strtolower($method);
    }

    protected function isNativeVirtualMethod(ClassDef $class, MethodDef $method): bool
    {
        if ($method->flags & (Modifiers::STATIC | Modifiers::PRIVATE | Modifiers::FINAL | Modifiers::ABSTRACT)) {
            return false;
        }
        if (in_array(strtolower($method->name), ['__construct', '__destruct', '__clone'], true)) {
            return false;
        }
        if ($this->isOverrideMethod($class->getNamespacedName(false) . '::' . $method->name)) {
            return true;
        }
        $parent = $class->extends;
        while ($parent !== '' && $this->hasClass($parent)) {
            $parentDef = $this->getClass($parent);
            if ($parentDef->hasMethod($method->name)) {
                return true;
            }
            $parent = $parentDef->extends;
        }
        return false;
    }

    protected function getNativeMethodReturnCppType(FunctionDef $function): string
    {
        return $function->returnsByRef
            ? Type::REF
            : ($this->getNativeObjectReturnType($function) ?? $function->returnType);
    }

    protected function getNativeMethodParameterDeclarations(FunctionDef $function): string
    {
        $args = [];
        foreach ($function->argInfoList as $argument) {
            if ($argument->variadic) {
                $args[] = Type::ARRAY . ' ' . $argument->name;
            } else {
                $args[] = $this->genArgumentDeclaration($argument);
            }
        }
        return implode(', ', $args);
    }

    protected function getNativeObjectPropertyType(PropertyDef $property): string
    {
        if ($property->type === Type::OBJECT && $this->isNativeObjectClass($property->class)) {
            return $this->getNativeObjectPointerType($property->class);
        }
        return match ($property->type) {
            Type::STREAM, Type::BOX => Type::VAR,
            default => $property->type,
        };
    }

    protected function getNativeObjectPropertyCppName(
        string|PropertyDef $property,
        string|ClassDef|null $declaringClass = null,
    ): string
    {
        $name = $property instanceof PropertyDef ? $property->name : $property;
        if (!$property instanceof PropertyDef || !$property->isPrivate()) {
            return $this->escapeVarName($name);
        }
        if ($declaringClass === null) {
            throw new \LogicException('Native private property field requires its declaring class');
        }
        return '__private_' . $this->getNativeObjectCppName($declaringClass)
            . '__' . $this->escapeVarName($name);
    }

    protected function isNativeObjectForbiddenPropertyType(PropertyDef $property): bool
    {
        if (in_array($property->type, [
            Type::BOX,
            Type::STD_ARRAY,
            Type::STD_VECTOR,
            Type::STD_MAP,
            Type::STD_ORDERED_MAP,
        ], true)) {
            return true;
        }
        return in_array(strtolower(ltrim($property->class, '\\')), [
            'std\\array',
            'std\\vector',
            'std\\map',
            'std\\ordered_map',
        ], true);
    }

    protected function isNativeObjectInheritedPropertyRedeclaration(
        ClassDef $class,
        PropertyDef $property,
    ): bool {
        $parent = $class->extends;
        while ($parent !== '' && $this->isNativeObjectClass($parent)) {
            $parentDef = $this->getClass($parent);
            if ($parentDef->hasProperty($property->name)) {
                $parentProperty = $parentDef->getProperty($property->name);
                if ($property->isPrivate() || $parentProperty->isPrivate()) {
                    return false;
                }
                // Property compatibility is validated separately. TypePHP
                // treats a compatible public/protected redeclaration as the
                // same inherited slot, so a Native child must not emit a
                // second C++ field with the same PHP property name.
                return true;
            }
            $parent = $parentDef->extends;
        }
        return false;
    }

    /**
     * C++ requires a base struct to be complete before defining a derived
     * struct. PHP source order has no such restriction, so emit Native class
     * definitions in inheritance order while retaining source order between
     * unrelated classes.
     *
     * @return list<ClassDef>
     */
    protected function getNativeObjectClassesInDeclarationOrder(): array
    {
        $classes = array_values(array_filter(
            $this->symbols->classes(),
            static fn (ClassDef $class): bool => $class->nativeObject,
        ));
        $byName = [];
        foreach ($classes as $class) {
            $byName[strtolower(ltrim($class->getNamespacedName(false), '\\'))] = $class;
        }

        $ordered = [];
        $visited = [];
        $visit = function (ClassDef $class) use (&$visit, &$ordered, &$visited, $byName): void {
            $key = strtolower(ltrim($class->getNamespacedName(false), '\\'));
            if (isset($visited[$key])) {
                return;
            }
            $visited[$key] = true;
            $parent = strtolower(ltrim($class->extends, '\\'));
            if ($parent !== '' && isset($byName[$parent])) {
                $visit($byName[$parent]);
            }
            $ordered[] = $class;
        };
        foreach ($classes as $class) {
            $visit($class);
        }
        return $ordered;
    }

    protected function genNativeObjectDeclarations(): string
    {
        $classes = $this->getNativeObjectClassesInDeclarationOrder();
        if ($classes === []) {
            return '';
        }

        $code = '// TypePHP Native Object declarations' . PHP_EOL;
        foreach ($classes as $class) {
            $code .= 'struct ' . $this->getNativeObjectCppName($class) . ';' . PHP_EOL;
        }
        $code .= PHP_EOL;

        foreach ($classes as $class) {
            $name = $this->getNativeObjectCppName($class);
            $parent = $class->extends !== '' && $this->isNativeObjectClass($class->extends)
                ? ' : public ' . $this->getNativeObjectCppName($class->extends)
                : '';
            $code .= 'struct ' . $name . $parent . ' {' . PHP_EOL;
            foreach ($class->properties as $property) {
                if ($property->flags & Modifiers::STATIC
                    || $this->isNativeObjectInheritedPropertyRedeclaration($class, $property)
                ) {
                    continue;
                }
                $type = $this->getNativeObjectPropertyType($property);
                // PHPX value types own their storage and must be initialized by
                // their C++ default constructor. PHP-level defaults are applied
                // by the generated allocation/constructor path, not in this
                // shared declaration header (which cannot reference file-local
                // literal tables).
                $default = null;
                if ($property->type === Type::OBJECT && $this->isNativeObjectClass($property->class)) {
                    $default = 'nullptr';
                } elseif (in_array($property->type, [Type::INT, Type::FLOAT, Type::BOOL], true)) {
                    $default = '0';
                }
                $code .= '    ' . $type . ' ' . $this->getNativeObjectPropertyCppName($property, $class);
                if ($default !== null) {
                    $code .= ' = ' . $default;
                }
                $code .= ';' . PHP_EOL;
            }
            foreach ($class->methods as $method) {
                if (!$this->isNativeVirtualMethod($class, $method)) {
                    continue;
                }
                $parentHasMethod = $class->extends !== ''
                    && $this->hasClass($class->extends)
                    && $this->getClass($class->extends)->hasMethod($method->name);
                $code .= '    virtual ' . $this->getNativeMethodReturnCppType($method->functionDef)
                    . ' ' . $this->getNativeVirtualMethodName($method->name) . '('
                    . $this->getNativeMethodParameterDeclarations($method->functionDef) . ')'
                    . ($parentHasMethod ? ' override' : '') . ';' . PHP_EOL;
            }
            $code .= '};' . PHP_EOL;
            $code .= 'void ' . $name . '__initialize(' . $name . ' &object);' . PHP_EOL;
            $code .= 'void ' . $name . '__gc_trace(void *object, php::NativeMarker &marker);' . PHP_EOL;
            $code .= 'extern const php::NativeTypeDescriptor '
                . $this->getNativeObjectDescriptorName($class) . ';' . PHP_EOL . PHP_EOL;
        }
        return $code;
    }

    protected function genNativeObjectRuntimeDefinition(ClassDef $class): string
    {
        $cpp = $this->getNativeObjectCppName($class);
        $prefix = $cpp . '__gc';
        $code = '';
        $code .= 'void ' . $cpp . '__initialize(' . $cpp . ' &this_) {' . PHP_EOL;
        if ($class->extends !== '' && $this->isNativeObjectClass($class->extends)) {
            $code .= '    ' . $this->getNativeObjectCppName($class->extends) . '__initialize(this_);' . PHP_EOL;
        }
        foreach ($class->properties as $property) {
            if ($property->isStatic() || $property->default === null) {
                continue;
            }
            if ($property->type === Type::OBJECT && $this->isNativeObjectClass($property->class)) {
                $value = 'nullptr';
            } else {
                $value = $property->default;
            }
            $code .= '    this_.' . $this->getNativeObjectPropertyCppName($property, $class) . ' = ' . $value . ';' . PHP_EOL;
        }
        $code .= '}' . PHP_EOL . PHP_EOL;
        foreach ($class->methods as $method) {
            if (!$this->isNativeVirtualMethod($class, $method)) {
                continue;
            }
            $function = $method->functionDef;
            $returnType = $this->getNativeMethodReturnCppType($function);
            $args = array_map(static fn (ArgInfo $arg): string => $arg->name, $function->argInfoList);
            $nativeFunction = self::PREFIX . $this->getNativeName(
                $method->name,
                $class->namespace,
                $class->name,
            );
            $code .= $returnType . ' ' . $cpp . '::' . $this->getNativeVirtualMethodName($method->name)
                . '(' . $this->getNativeMethodParameterDeclarations($function) . ') {' . PHP_EOL;
            $call = $nativeFunction . '(*this' . ($args === [] ? '' : ', ' . implode(', ', $args)) . ')';
            $code .= '    ' . ($returnType === Type::VOID ? '' : 'return ') . $call . ';' . PHP_EOL;
            $code .= '}' . PHP_EOL . PHP_EOL;
        }
        $code .= 'void ' . $prefix . '_trace(void *object, php::NativeMarker &marker) {' . PHP_EOL;
        $hasParentTrace = $class->extends !== '' && $this->isNativeObjectClass($class->extends);
        $nativeProperties = array_filter(
            $class->properties,
            fn (PropertyDef $property): bool => !$property->isStatic()
                && !$this->isNativeObjectInheritedPropertyRedeclaration($class, $property)
                && $property->type === Type::OBJECT
                && $this->isNativeObjectClass($property->class),
        );
        if ($hasParentTrace || $nativeProperties !== []) {
            $code .= '    auto &this_ = *static_cast<' . $cpp . ' *>(object);' . PHP_EOL;
        } else {
            $code .= '    (void) object;' . PHP_EOL;
            $code .= '    (void) marker;' . PHP_EOL;
        }
        if ($hasParentTrace) {
            $code .= '    ' . $this->getNativeObjectCppName($class->extends)
                . '__gc_trace(static_cast<' . $this->getNativeObjectCppName($class->extends) . ' *>(&this_), marker);'
                . PHP_EOL;
        }
        foreach ($nativeProperties as $property) {
            $code .= '    marker.mark(this_.' . $this->getNativeObjectPropertyCppName($property, $class) . ');' . PHP_EOL;
        }
        $code .= '}' . PHP_EOL;

        $destructors = [];
        $destructorClass = $class;
        while (true) {
            if ($destructorClass->hasMethod('__destruct')) {
                $destructors[] = [
                    self::PREFIX . $this->getNativeName(
                        '__destruct',
                        $destructorClass->namespace,
                        $destructorClass->name,
                    ),
                    $this->getNativeObjectCppName($destructorClass),
                ];
            }
            if ($destructorClass->extends === '' || !$this->isNativeObjectClass($destructorClass->extends)) {
                break;
            }
            $destructorClass = $this->getClass($destructorClass->extends);
        }
        if ($destructors !== []) {
            $code .= 'static void ' . $prefix . '_finalize(void *object) {' . PHP_EOL;
            foreach ($destructors as [$destructor, $destructorCpp]) {
                $code .= '    ' . $destructor . '(*static_cast<' . $destructorCpp . ' *>(object));' . PHP_EOL;
            }
            $code .= '}' . PHP_EOL;
        }
        $code .= 'static void ' . $prefix . '_destroy(void *object) noexcept {' . PHP_EOL;
        $code .= '    static_cast<' . $cpp . ' *>(object)->~' . $cpp . '();' . PHP_EOL;
        $code .= '}' . PHP_EOL;
        $code .= 'const php::NativeTypeDescriptor ' . $this->getNativeObjectDescriptorName($class) . ' = {' . PHP_EOL;
        $code .= '    "' . addslashes($class->getNamespacedName(false)) . '",' . PHP_EOL;
        $code .= '    sizeof(' . $cpp . '),' . PHP_EOL;
        $code .= '    alignof(' . $cpp . '),' . PHP_EOL;
        $code .= '    ' . $prefix . '_trace,' . PHP_EOL;
        $code .= '    ' . ($destructors !== [] ? $prefix . '_finalize' : 'nullptr') . ',' . PHP_EOL;
        $code .= '    ' . $prefix . '_destroy,' . PHP_EOL;
        $code .= '};' . PHP_EOL . PHP_EOL;
        return $code;
    }
}
