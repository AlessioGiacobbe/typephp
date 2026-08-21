<?php

use TypePhp\CompilerTest;

final class NewObjectCodegenTest extends \BaseTest
{
    public function testStableClassEntryLookupIsHoistedOutOfObjectCreationLoop(): void
    {
        $code = $this->compileFixture();

        self::assertMatchesRegularExpression(
            '/php_createknownobjects\([^)]*\) \{[\s\S]*?zend_class_entry \*(tmp_var_\d+) = php_get_persistent_class\([^;]+;[\s\S]*?php::newObject\(\1\)/',
            $code,
        );
        self::assertStringNotContainsString(
            'php::newObject(php_get_persistent_class(',
            $code,
        );
    }

    public function testRuntimeProvidedClassStillResolvesAtNewExpression(): void
    {
        $code = $this->compileFixture();

        self::assertMatchesRegularExpression(
            '/php_createruntimeobject\([^)]*\)[\s\S]*?php::newObject\(php_get_class\(/',
            $code,
        );
    }

    public function testStubRepresentablePropertyDefaultsUseZendDefaultTable(): void
    {
        [, $extension] = $this->compileFixtureAndExtension();

        self::assertStringNotContainsString(
            'create_object_KnownNewObjectCodegen = php_get_create_object_fn',
            $extension,
        );
        self::assertStringNotContainsString(
            'create_object_EmptyArrayDefaultCodegen = php_get_create_object_fn',
            $extension,
        );
        self::assertStringNotContainsString(
            'create_object_ScalarExpressionDefaultCodegen = php_get_create_object_fn',
            $extension,
        );
        self::assertStringNotContainsString(
            'create_object_ScalarConstantDefaultCodegen = php_get_create_object_fn',
            $extension,
        );
        self::assertStringContainsString(
            'create_object_RuntimeArrayDefaultCodegen = php_get_create_object_fn',
            $extension,
        );
        self::assertStringNotContainsString(
            'zend_update_property(php_class_entry_RuntimeArrayDefaultCodegen, obj, ZEND_STRL("scalar")',
            $extension,
        );
        self::assertStringContainsString(
            'create_object_EnumPropertyDefaultCodegen = php_get_create_object_fn',
            $extension,
        );
        self::assertStringNotContainsString(
            'create_object_HookOnlyDefaultCodegen = php_get_create_object_fn',
            $extension,
        );
        self::assertStringNotContainsString(
            'create_object_AsymmetricOnlyDefaultCodegen = php_get_create_object_fn',
            $extension,
        );
    }

    public function testRuntimeArrayDefaultsUseLazyRequestTemplates(): void
    {
        [, $extension] = $this->compileFixtureAndExtension();

        self::assertStringContainsString(
            'THREAD_LOCAL bool php_request_array_defaults_initialized_RuntimeArrayDefaultCodegen = false;',
            $extension,
        );
        self::assertStringContainsString(
            'THREAD_LOCAL php::Var php_request_array_default_runtimearraydefaultcodegen__values;',
            $extension,
        );
        self::assertStringContainsString(
            'THREAD_LOCAL php::Var php_request_array_default_runtimearraydefaultcodegen__labels;',
            $extension,
        );
        self::assertMatchesRegularExpression(
            '/if \(UNEXPECTED\(!php_request_array_defaults_initialized_RuntimeArrayDefaultCodegen\)\) \{[\s\S]*prepared_default_0[\s\S]*prepared_default_1[\s\S]*php_request_array_defaults_initialized_RuntimeArrayDefaultCodegen = true;/',
            $extension,
        );
        self::assertMatchesRegularExpression(
            '/create_object_RuntimeArrayDefaultCodegen[^=]*= \[\][\s\S]*php_ensure_request_array_defaults_RuntimeArrayDefaultCodegen\(\);[\s\S]*typephp_create_object_with_defaults/',
            $extension,
        );
        self::assertStringContainsString(
            '= php_request_array_default_runtimearraydefaultcodegen__values;',
            $extension,
        );
        self::assertStringContainsString(
            'php_request_array_default_runtimearraydefaultcodegen__values.unset();',
            $extension,
        );
        self::assertStringContainsString(
            'php_request_array_default_runtimearraydefaultcodegen__labels.unset();',
            $extension,
        );
    }

    private function compileFixture(): string
    {
        return $this->compileFixtureAndExtension()[0];
    }

    /** @return array{string, string} */
    private function compileFixtureAndExtension(): array
    {
        global $translator;

        $compiler = CompilerTest::create(ROOT_PATH);
        $translator = $compiler;
        $source = ROOT_PATH . '/phpunit/code/new-object-codegen.php';
        $compiler->addFiles([$source]);
        $compiler->prepareFile($source);
        $generated = $compiler->convertFile($source);
        $code = file_get_contents($generated);
        $extension = file_get_contents($compiler->genExtension());

        self::assertIsString($code);
        self::assertIsString($extension);
        return [$code, $extension];
    }
}
