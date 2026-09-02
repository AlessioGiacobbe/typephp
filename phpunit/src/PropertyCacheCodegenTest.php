<?php

use TypePhp\CompilerTest;

final class PropertyCacheCodegenTest extends BaseTest
{
    public function testOnlyStaticallyNamedPropertySitesReceiveZendCacheSlots(): void
    {
        global $translator;

        $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        $source = TYPEPHP_ROOT_PATH . '/phpunit/code/property-cache-sites.php';
        $compiler->addFiles([$source]);
        $compiler->prepareFile($source);
        $generated = $compiler->convertFile($source);
        $code = file_get_contents($generated);

        self::assertIsString($code);
        self::assertSame(1, substr_count($code, 'typephp_read_property_cached('));
        self::assertSame(2, substr_count($code, 'typephp_write_property_cached('));
        self::assertStringContainsString('.attr(name, php::AttrMode::Get)', $code);
        self::assertStringContainsString('typephp_write_property_scoped(object, name, value', $code);
        self::assertSame(1, substr_count($code, 'typephp_read_magic_property_direct('));
        self::assertSame(1, substr_count($code, 'typephp_write_magic_property_direct('));
    }
}
