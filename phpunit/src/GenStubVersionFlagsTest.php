<?php

final class GenStubVersionFlagsTest extends BaseTest
{
    public function testFlagIntroducedBeforeMinimumSupportedVersionRemainsEnabled(): void
    {
        $flags = new VersionFlags(['ZEND_ACC_PRIVATE']);
        $flags->addForVersionsAbove('ZEND_ACC_STATIC', PHP_70_VERSION_ID);

        self::assertSame(
            'ZEND_ACC_PRIVATE|ZEND_ACC_STATIC',
            $flags->generateVersionDependentFlagCode('%s', null),
        );
    }
}
