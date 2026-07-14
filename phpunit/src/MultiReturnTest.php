<?php

use PHPUnit\Framework\TestCase;
use TypePhp\CompilerTest;

final class MultiReturnTest extends TestCase
{
    public function testGeneratesTupleFastPathAndArrayCompatibilityAdapter(): void
    {
        global $translator;
        $compiler = CompilerTest::create(ROOT_PATH);
        $translator = $compiler;
        $file = __DIR__ . '/../code/multi-return-tuple.php';
        $compiler->addFiles([$file]);
        $compiler->prepareFile($file);
        $cppFile = $compiler->convertFile($file);
        $code = file_get_contents($cppFile);

        $this->assertStringContainsString(
            'std::tuple<php::Var, php::Var> typephp::detail::php_phpunit_multi_values()',
            $code,
        );
        $this->assertStringContainsString(
            'std::tie(first, second) = typephp::detail::php_phpunit_multi_values()',
            $code,
        );
        $this->assertStringContainsString(
            'php::Array php_phpunit_multi_values()',
            $code,
        );
        $this->assertStringContainsString(
            'array = php_phpunit_multi_values()',
            $code,
        );
        $this->assertStringContainsString(
            'std::tie(partialFirst, partialSecond, std::ignore) = typephp::detail::php_phpunit_multi_three_values()',
            $code,
        );
        $this->assertStringNotContainsString(
            'std::tie(overflowFirst, overflowSecond, overflowThird)',
            $code,
        );
        $this->assertStringNotContainsString(
            'typephp::detail::php_phpunit_multi_side_effect',
            $code,
        );
    }
}
