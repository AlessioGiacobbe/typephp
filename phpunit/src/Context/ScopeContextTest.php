<?php

namespace PhpAot\Tests\Context;

use PHPUnit\Framework\TestCase;
use PhpAot\Php\Context\ScopeContext;

class ScopeContextTest extends TestCase
{
    public function testCanInstantiate(): void
    {
        $scope = new ScopeContext();
        $this->assertInstanceOf(ScopeContext::class, $scope);
    }
}
