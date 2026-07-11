<?php

use TypePhp\Entity\ArgInfo;
use TypePhp\CompilerTest;
use TypePhp\Entity\ClassDef;
use TypePhp\Entity\FunctionDef;

class TypeCheckGeneratorTest extends \PHPUnit\Framework\TestCase
{
    private function setProtectedProperty(object $object, string $property, mixed $value): void
    {
        $ref = new ReflectionProperty($object, $property);
        $ref->setAccessible(true);
        $ref->setValue($object, $value);
    }

    private function invokeMethod(object $object, string $method, array $args = []): mixed
    {
        $ref = new ReflectionMethod($object, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs($object, $args);
    }

    public function testMethodTypeCheckErrorUsesClassQualifiedCallableName(): void
    {
        $compiler = CompilerTest::create(ROOT_PATH);
        $classDef = new ClassDef('Demo', 0, 'Foo\\Bar');
        $functionDef = new FunctionDef('run', 'php::Var', 'Foo\\Bar');
        $argInfo = new ArgInfo();
        $argInfo->name = 'value';
        $argInfo->typeStr = 'int|string';

        $functionDef->returnTypeCheck = [['kind' => 'isInt'], ['kind' => 'isString']];
        $functionDef->returnTypeStr = 'int|string';

        $this->setProtectedProperty($compiler, 'classDef', $classDef);
        $this->setProtectedProperty($compiler, 'functionDef', $functionDef);

        $callableName = $this->invokeMethod($compiler, 'getTypeCheckCallableName');
        $paramExpr = $this->invokeMethod($compiler, 'genUnionParamTypeErrorExpr', [$argInfo, 'value', '1']);
        $returnCode = $this->invokeMethod($compiler, 'genUnionReturnCheck', ['retval']);

        $this->assertSame('Foo\\Bar\\Demo::run', $callableName);
        $this->assertStringContainsString('Foo\\\\Bar\\\\Demo::run(): Argument #', $paramExpr);
        $this->assertStringContainsString('Foo\\\\Bar\\\\Demo::run', $returnCode);
    }

    public function testFunctionTypeCheckErrorUsesFunctionQualifiedCallableName(): void
    {
        $compiler = CompilerTest::create(ROOT_PATH);
        $functionDef = new FunctionDef('run', 'php::Var', 'Foo\\Bar');
        $argInfo = new ArgInfo();
        $argInfo->name = 'value';
        $argInfo->typeStr = 'int|string';

        $functionDef->returnTypeCheck = [['kind' => 'isInt'], ['kind' => 'isString']];
        $functionDef->returnTypeStr = 'int|string';

        $this->setProtectedProperty($compiler, 'classDef', null);
        $this->setProtectedProperty($compiler, 'functionDef', $functionDef);

        $callableName = $this->invokeMethod($compiler, 'getTypeCheckCallableName');
        $paramExpr = $this->invokeMethod($compiler, 'genUnionParamTypeErrorExpr', [$argInfo, 'value', '1']);
        $returnCode = $this->invokeMethod($compiler, 'genUnionReturnCheck', ['retval']);

        $this->assertSame('Foo\\Bar\\run', $callableName);
        $this->assertStringContainsString('Foo\\\\Bar\\\\run(): Argument #', $paramExpr);
        $this->assertStringContainsString('Foo\\\\Bar\\\\run', $returnCode);
    }
}
