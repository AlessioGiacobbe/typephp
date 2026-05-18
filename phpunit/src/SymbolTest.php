<?php

namespace PhpAot\Tests;

use PHPUnit\Framework\TestCase;
use PhpAot\Php\Symbol;
use PhpAot\Php\CompilerBase;

class SymbolTest extends TestCase
{
    public function testGetStaticProperty(): void
    {
        $this->assertEquals('php::getStaticProperty', Symbol::getStaticProperty());
    }

    public function testSetStaticProperty(): void
    {
        $this->assertEquals('php::setStaticProperty', Symbol::setStaticProperty());
    }

    public function testInstanceOf(): void
    {
        $this->assertEquals('php::instanceOf', Symbol::instanceOf());
    }

    public function testConcat(): void
    {
        $this->assertEquals('php::concat', Symbol::concat());
    }

    public function testConstant(): void
    {
        $this->assertEquals('php::constant', Symbol::constant());
    }

    public function testGetClassEntrySafe(): void
    {
        $this->assertEquals('php::getClassEntrySafe', Symbol::getClassEntrySafe());
    }

    public function testArgList(): void
    {
        $this->assertEquals('php::ArgList', Symbol::argList());
    }

    public function testGetCalledCe(): void
    {
        $result = Symbol::getCalledCe();
        $this->assertStringContainsString(CompilerBase::PREFIX, $result);
        $this->assertStringContainsString('get_called_ce', $result);
    }

    public function testGetCalledClass(): void
    {
        $result = Symbol::getCalledClass();
        $this->assertStringContainsString(CompilerBase::PREFIX, $result);
        $this->assertStringContainsString('get_called_class', $result);
    }

    public function testSafeIndex(): void
    {
        $result = Symbol::safeIndex('0', '10');
        $this->assertEquals('php::safeIndex(0, 10)', $result);
    }

    public function testSafeIndexWithVariables(): void
    {
        $result = Symbol::safeIndex('i', 'count');
        $this->assertEquals('php::safeIndex(i, count)', $result);
    }
}
