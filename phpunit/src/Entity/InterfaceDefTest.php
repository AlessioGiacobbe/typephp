<?php

namespace PhpAot\Tests\Entity;

use PHPUnit\Framework\TestCase;
use PhpAot\Php\Entity\InterfaceDef;

class InterfaceDefTest extends TestCase
{
    public function testConstructWithoutNamespace(): void
    {
        $iface = new InterfaceDef('JsonSerializable');

        $this->assertEquals('JsonSerializable', $iface->name);
        $this->assertEquals('', $iface->namespace);
        $this->assertEquals('', $iface->extends);
    }

    public function testConstructWithNamespace(): void
    {
        $iface = new InterfaceDef('Renderable', 'App\\Contracts');

        $this->assertEquals('Renderable', $iface->name);
        $this->assertEquals('App\\Contracts', $iface->namespace);
    }

    public function testGetNamespacedName(): void
    {
        $iface = new InterfaceDef('Logger', 'App\\Contracts');

        $this->assertEquals('App_Contracts_Logger', $iface->getNamespacedName(true));
        $this->assertEquals('App\\Contracts\\Logger', $iface->getNamespacedName(false));
    }

    public function testExtendsCanBeSet(): void
    {
        $iface = new InterfaceDef('Child');
        $iface->extends = 'Parent';

        $this->assertEquals('Parent', $iface->extends);
    }
}
