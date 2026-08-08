<?php

use PHPUnit\Framework\TestCase;
use TypePhp\Transform\CompileTimeAttributeRegistry;

final class CompileTimeAttributeRegistryTest extends TestCase
{
    public function testEveryBuiltInCompileTimeAttributeHasCompleteMetadata(): void
    {
        $expected = [
            'MethodsFor', 'NoExport', 'WasmExport', 'Getter', 'Setter', 'With', 'Printer', 'Arrayable',
            'NotNull', 'NotEmpty', 'Validate', 'Override', 'MustUse', 'Hot', 'Cold', 'Constructor',
        ];
        $this->assertSame($expected, CompileTimeAttributeRegistry::names());

        foreach (CompileTimeAttributeRegistry::all() as $definition) {
            $this->assertNotEmpty($definition['targets']);
            $this->assertNotSame('', $definition['argument_parser']);
            $this->assertNotSame('', $definition['phase']);
            $this->assertIsBool($definition['preserve_in_library_stub']);
            $this->assertFalse($definition['repeatable']);
        }
        $this->assertNotContains('NoExport', CompileTimeAttributeRegistry::names(true));
        $this->assertNotContains('WasmExport', CompileTimeAttributeRegistry::names(true));
        $this->assertContains('Getter', CompileTimeAttributeRegistry::names(true));
        $this->assertContains('Override', CompileTimeAttributeRegistry::names(true));
        $this->assertSame(
            ['Override', 'MustUse', 'Hot', 'Cold'],
            CompileTimeAttributeRegistry::namesForPhase(CompileTimeAttributeRegistry::PHASE_ENTER),
        );
    }

    public function testRegistryMatchesPublicCompileTimeAttributeDeclarations(): void
    {
        $source = file_get_contents(ROOT_PATH . '/src/polyfills.php');
        $this->assertNotFalse($source);
        preg_match_all(
            '/#\[Attribute\([^\]]+\)\]\s+final readonly class ([A-Za-z_][A-Za-z0-9_]*)/',
            $source,
            $matches,
        );
        $declaredByTypePhp = array_values(array_filter(
            CompileTimeAttributeRegistry::names(),
            static fn (string $name): bool => $name !== 'Override',
        ));
        $this->assertSame($declaredByTypePhp, $matches[1]);
    }
}
