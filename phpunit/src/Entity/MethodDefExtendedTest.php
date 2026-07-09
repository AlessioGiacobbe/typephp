<?php

namespace TypePhp\Tests\Entity;

use PHPUnit\Framework\TestCase;
use TypePhp\Entity\MethodDef;
use TypePhp\Entity\FunctionDef;
use TypePhp\ArgInfo;
use PhpParser\Modifiers;

class MethodDefExtendedTest extends TestCase
{
    public function testHasDynamicCallDefaultsToFalse(): void
    {
        $method = new MethodDef(Modifiers::PUBLIC, 'test');
        $this->assertFalse($method->hasDynamicCall);
    }

    public function testHasDynamicCallCanBeSet(): void
    {
        $method = new MethodDef(Modifiers::PUBLIC, 'test');
        $method->hasDynamicCall = true;
        $this->assertTrue($method->hasDynamicCall);
    }

    public function testStaticMethod(): void
    {
        $method = new MethodDef(Modifiers::PUBLIC | Modifiers::STATIC, 'factory');
        $this->assertTrue((bool) ($method->flags & Modifiers::STATIC));
    }

    public function testAbstractMethod(): void
    {
        $method = new MethodDef(Modifiers::PUBLIC | Modifiers::ABSTRACT, 'handle');
        $this->assertTrue((bool) ($method->flags & Modifiers::ABSTRACT));
    }

    public function testFinalMethod(): void
    {
        $method = new MethodDef(Modifiers::PUBLIC | Modifiers::FINAL, 'lock');
        $this->assertTrue((bool) ($method->flags & Modifiers::FINAL));
    }

    public function testCombinedFlags(): void
    {
        $method = new MethodDef(
            Modifiers::PUBLIC | Modifiers::STATIC | Modifiers::FINAL,
            'combined'
        );
        $this->assertTrue((bool) ($method->flags & Modifiers::PUBLIC));
        $this->assertTrue((bool) ($method->flags & Modifiers::STATIC));
        $this->assertTrue((bool) ($method->flags & Modifiers::FINAL));
    }

    public function testFunctionDefLinkRoundTrip(): void
    {
        $method = new MethodDef(Modifiers::PUBLIC, 'getValue');
        $fn = new FunctionDef('getValue', 'php::Int', 'App\\Service');
        $fn->argInfoList = [
            new ArgInfo('arg1', 'php::Str'),
        ];
        $fn->params = 'php::Str arg1';
        $method->functionDef = $fn;

        $this->assertSame($fn, $method->functionDef);
        $this->assertEquals('php::Int', $method->getReturnType());
        $this->assertEquals('App\\Service\\getValue', $fn->getNamespacedName());
    }
}
