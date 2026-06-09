<?php

use PhpAot\Php\CompilerTest;

class ClassMethodOverrideTest extends \BaseTest
{
    public function testChildBeforeParentOverride(): void
    {
        $compiler = CompilerTest::create(ROOT_PATH);
        $testFile = __DIR__ . '/../code/class-method-override-order.php';
        $compiler->addFiles([$testFile]);
        $compiler->prepareFile($testFile);

        $ref = new ReflectionClass($compiler);
        $prop = $ref->getProperty('classMethodOverride');
        $classMethodOverride = $prop->getValue($compiler);

        $parentMethodLower = strtolower('ParentBase::bar');
        $childMethodLower = strtolower('ChildOverride::bar');

        $this->assertArrayHasKey($parentMethodLower, $classMethodOverride, 'ParentBase::bar should be registered');
        $this->assertArrayHasKey($childMethodLower, $classMethodOverride, 'ChildOverride::bar should be registered');
        // 父类方法应被标记为已被子类覆盖
        $this->assertTrue($classMethodOverride[$parentMethodLower], 'ParentBase::bar should be marked as overridden by child');
        // 子类方法未被覆盖
        $this->assertFalse($classMethodOverride[$childMethodLower], 'ChildOverride::bar should not be marked as overridden');
    }
}
