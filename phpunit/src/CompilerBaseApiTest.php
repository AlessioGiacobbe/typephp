<?php

namespace PhpAot\Tests;

use PHPUnit\Framework\TestCase;
use PhpAot\Php\CompilerTest;
use PhpAot\Php\CompilerBase;

class CompilerBaseApiTest extends TestCase
{
    private string $testDir;
    private CompilerTest $compiler;
    private \ReflectionClass $ref;
    private array $originalArgv;
    private string|false $originalPath;

    protected function setUp(): void
    {
        parent::setUp();
        global $argv;
        $this->originalArgv = $argv ?? [];
        $this->originalPath = getenv('PATH');
        $this->testDir = sys_get_temp_dir() . '/compiler_api_test_' . uniqid();
        mkdir($this->testDir, 0777, true);
        $this->compiler = CompilerTest::create($this->testDir);
        $this->ref = new \ReflectionClass($this->compiler);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        global $argv;
        $argv = $this->originalArgv;
        if ($this->originalPath === false) {
            putenv('PATH');
        } else {
            putenv('PATH=' . $this->originalPath);
        }
        // Recursively remove the test directory (compiler creates build/ subdir)
        $this->removeDirectory($this->testDir);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function getPropertyValue(string $name): mixed
    {
        $prop = $this->ref->getProperty($name);
        $prop->setAccessible(true);
        return $prop->getValue($this->compiler);
    }

    private function setPropertyValue(string $name, mixed $value): void
    {
        $prop = $this->ref->getProperty($name);
        $prop->setAccessible(true);
        $prop->setValue($this->compiler, $value);
    }

    private function invokeMethod(string $method, ...$args): mixed
    {
        $m = $this->ref->getMethod($method);
        $m->setAccessible(true);
        return $m->invoke($this->compiler, ...$args);
    }

    private function fixturePath(string $file): string
    {
        return __DIR__ . '/../code/compiler_api/' . $file;
    }

    private function createProjectFile(string $yaml, string $filename = 'project.yml', string $baseDir = ''): string
    {
        $projectDir = $baseDir === '' ? $this->testDir : $this->testDir . '/' . trim($baseDir, '/');
        if (!is_dir($projectDir)) {
            mkdir($projectDir, 0777, true);
        }

        $sourceFile = $projectDir . '/main.php';
        copy($this->fixturePath('main.php'), $sourceFile);

        $projectFile = $projectDir . '/' . $filename;
        file_put_contents($projectFile, $yaml);

        return $projectFile;
    }

    private function createFakeClangFormat(string $binDir, string $logFile): void
    {
        mkdir($binDir, 0777, true);
        file_put_contents($binDir . '/clang-format', "#!/bin/sh\nif [ \"$1\" = \"--version\" ]; then\n  echo 'clang-format version test'\n  exit 0\nfi\npwd > " . escapeshellarg($logFile) . "\nprintf '%s\\n' \"$@\" >> " . escapeshellarg($logFile) . "\n");
        chmod($binDir . '/clang-format', 0755);
    }

    // ========================================================================
    // getTypeFromZendType
    // ========================================================================

    public function testGetTypeFromZendTypeKnown(): void
    {
        $this->assertEquals(CompilerBase::TYPE_INT, $this->compiler->getTypeFromZendType('int'));
        $this->assertEquals(CompilerBase::TYPE_FLOAT, $this->compiler->getTypeFromZendType('float'));
        $this->assertEquals(CompilerBase::TYPE_BOOL, $this->compiler->getTypeFromZendType('bool'));
        $this->assertEquals(CompilerBase::TYPE_BOOL, $this->compiler->getTypeFromZendType('true'));
        $this->assertEquals(CompilerBase::TYPE_BOOL, $this->compiler->getTypeFromZendType('false'));
        $this->assertEquals(CompilerBase::TYPE_VOID, $this->compiler->getTypeFromZendType('void'));
        $this->assertEquals(CompilerBase::TYPE_VOID, $this->compiler->getTypeFromZendType('never'));
        $this->assertEquals(CompilerBase::TYPE_STR, $this->compiler->getTypeFromZendType('string'));
        $this->assertEquals(CompilerBase::TYPE_ARRAY, $this->compiler->getTypeFromZendType('array'));
        $this->assertEquals(CompilerBase::TYPE_OBJECT, $this->compiler->getTypeFromZendType('object'));
        $this->assertEquals(CompilerBase::TYPE_VAR, $this->compiler->getTypeFromZendType('mixed'));
        $this->assertEquals(CompilerBase::TYPE_VAR, $this->compiler->getTypeFromZendType('null'));
        $this->assertEquals(CompilerBase::TYPE_VAR, $this->compiler->getTypeFromZendType('callable'));
        $this->assertEquals(CompilerBase::TYPE_VAR, $this->compiler->getTypeFromZendType('iterable'));
    }

    public function testGetTypeFromZendTypeUnknown(): void
    {
        $this->assertEquals(CompilerBase::TYPE_VAR, $this->compiler->getTypeFromZendType('unknown_type'));
        $this->assertEquals(CompilerBase::TYPE_VAR, $this->compiler->getTypeFromZendType('SomeClass'));
    }

    // ========================================================================
    // genTmpVarName
    // ========================================================================

    public function testGenTmpVarName(): void
    {
        // context must be initialized before genTmpVarName can be used
        $this->invokeMethod('resetFunction');

        $name1 = $this->compiler->genTmpVarName();
        $name2 = $this->compiler->genTmpVarName();
        $name3 = $this->compiler->genTmpVarName();

        $this->assertStringStartsWith('tmp_var_', $name1);
        $this->assertStringStartsWith('tmp_var_', $name2);
        $this->assertStringStartsWith('tmp_var_', $name3);

        // Must be sequential and unique
        $this->assertNotEquals($name1, $name2);
        $this->assertNotEquals($name2, $name3);
        $this->assertNotEquals($name1, $name3);
    }

    // ========================================================================
    // genAnonClassName
    // ========================================================================

    public function testGenAnonClassName(): void
    {
        $name1 = $this->compiler->genAnonClassName();
        $name2 = $this->compiler->genAnonClassName();

        $this->assertStringStartsWith(CompilerBase::ANON_CLASS, $name1);
        $this->assertStringStartsWith(CompilerBase::ANON_CLASS, $name2);
        $this->assertNotEquals($name1, $name2);
    }

    // ========================================================================
    // getIncludeDir / getBuildDir
    // ========================================================================

    public function testGetBuildDir(): void
    {
        $buildDir = $this->compiler->getBuildDir();
        $this->assertStringEndsWith('/build', $buildDir);
        $this->assertStringStartsWith($this->testDir, $buildDir);
    }

    public function testFormatCodeDisabledByDefault(): void
    {
        $this->assertFalse($this->getPropertyValue('formatCode'));
    }

    public function testGetIncludeDir(): void
    {
        $includeDir = $this->compiler->getIncludeDir();
        $buildDir = $this->compiler->getBuildDir();
        $this->assertEquals($buildDir . '/include', $includeDir);
    }

    public function testParseProjectYamlLoadsDocumentedCompilerOptions(): void
    {
        $binDir = $this->testDir . '/bin';
        $formatLog = $this->testDir . '/format.log';
        $this->createFakeClangFormat($binDir, $formatLog);
        putenv('PATH=' . $binDir . ':' . ($this->originalPath ?: ''));

        $projectFile = $this->createProjectFile(<<<'YAML'
sources:
  - main.php
optimize: 2
job: 8
debug: true
profile: true
no-progress: true
no-console: true
no-literal-strings: true
sanitize: address
target-platform: aarch64-linux-gnu
build-dir: /tmp/project-build
include-paths:
  - /opt/mylib/include
  - ../shared/headers
defines:
  - ENABLE_LOGGING=1
  - DEBUG_LEVEL=3
lto: true
format: true
link-libs:
  - curl
  - ssl
link-paths:
  - /usr/local/lib
  - /opt/custom/lib
YAML);

        $this->invokeMethod('parseProjectYaml', $projectFile);

        $this->assertSame(2, $this->getPropertyValue('optimizeLevel'));
        $this->assertSame(8, $this->getPropertyValue('maxJob'));
        $this->assertTrue($this->getPropertyValue('debug'));
        $this->assertTrue($this->getPropertyValue('enableProfiler'));
        $this->assertTrue($this->getPropertyValue('noProgress'));
        $this->assertTrue($this->getPropertyValue('noConsole'));
        $this->assertTrue($this->getPropertyValue('noLiteralStrings'));
        $this->assertSame('address', $this->getPropertyValue('sanitize'));
        $this->assertSame('aarch64-linux-gnu', $this->getPropertyValue('targetPlatform'));
        $this->assertSame(['/opt/mylib/include', dirname($projectFile) . '/../shared/headers'], $this->compiler->getUserIncludePaths());
        $this->assertSame(['ENABLE_LOGGING=1', 'DEBUG_LEVEL=3'], $this->compiler->getUserDefines());
        $this->assertTrue($this->compiler->isLtoEnabled());
        $this->assertSame(['curl', 'ssl'], $this->compiler->getLinkLibs());
        $this->assertSame(['/usr/local/lib', '/opt/custom/lib'], $this->compiler->getLinkPaths());
        $this->assertSame('/tmp/project-build', $this->compiler->getBuildDir());
        $this->assertTrue($this->getPropertyValue('formatCode'));
    }

    public function testParseProjectYamlSupportsCustomFilenameAndRelativeBuildDir(): void
    {
        $projectFile = $this->createProjectFile(<<<'YAML'
sources:
  - main.php
build-dir: build/output
YAML, 'myproject.yml', 'nested/config');

        $this->invokeMethod('parseProjectYaml', $projectFile);

        $this->assertSame(
            realpath($this->testDir . '/nested/config/build/output'),
            $this->compiler->getBuildDir()
        );
    }

    public function testParseProjectYamlSupportsCliStyleModeAndOutputAliases(): void
    {
        $projectFile = $this->createProjectFile(<<<'YAML'
sources:
  - main.php
mode: ext
output: out/custom-ext
dry: true
YAML, 'custom-name.yml', 'yaml-alias');

        $this->invokeMethod('parseProjectYaml', $projectFile);

        $this->assertSame(CompilerBase::BUILD_MODE_EXT, $this->getPropertyValue('buildMode'));
        $this->assertTrue($this->getPropertyValue('dryRun'));
        $this->assertSame('custom_ext', $this->getPropertyValue('targetName'));
        $this->assertSame(dirname($projectFile) . '/out', $this->getPropertyValue('outputDir'));
    }

    public function testParseProjectYamlResolvesRelativePathOptionsAgainstYamlDirectory(): void
    {
        $projectFile = $this->createProjectFile(<<<'YAML'
sources:
  - main.php
include-paths:
  - includes
link-paths:
  - libs
output: bin/my-app
YAML, 'myproject.yml', 'nested/config');

        $this->invokeMethod('parseProjectYaml', $projectFile);

        $projectDir = dirname($projectFile);
        $this->assertSame([$projectDir . '/includes'], $this->compiler->getUserIncludePaths());
        $this->assertSame([$projectDir . '/libs'], $this->compiler->getLinkPaths());
        $this->assertSame($projectDir . '/bin', $this->getPropertyValue('outputDir'));
        $this->assertSame('my_app', $this->getPropertyValue('targetName'));
    }

    public function testCliOutputOverridesYamlOutputOnlyWhenCommandLineArgumentsAreApplied(): void
    {
        global $argv;
        $argv = ['compiler.php', '--output', 'cli/out-file'];
        $compiler = CompilerTest::create($this->testDir);
        $ref = new \ReflectionClass($compiler);
        $parseMethod = $ref->getMethod('parseProjectYaml');
        $parseMethod->setAccessible(true);
        $applyMethod = $ref->getMethod('applyCommandLineArguments');
        $applyMethod->setAccessible(true);
        $targetProp = $ref->getProperty('targetName');
        $targetProp->setAccessible(true);
        $outputProp = $ref->getProperty('outputDir');
        $outputProp->setAccessible(true);

        $projectFile = $this->createProjectFile(<<<'YAML'
sources:
  - main.php
output: yaml/out-file
YAML, 'myproject.yml', 'cli-output');

        $parseMethod->invoke($compiler, $projectFile);
        $this->assertSame(dirname($projectFile) . '/yaml', $outputProp->getValue($compiler));
        $this->assertSame('out_file', $targetProp->getValue($compiler));

        $applyMethod->invoke($compiler);
        $this->assertSame('cli', $outputProp->getValue($compiler));
        $this->assertSame('out_file', $targetProp->getValue($compiler));
    }

    public function testApplyCommandLineArgumentsDoesNotClearYamlRepeatableOptionsWhenCliAbsent(): void
    {
        $projectFile = $this->createProjectFile(<<<'YAML'
sources:
  - main.php
include-paths:
  - /yaml/include
defines:
  - YAML_DEFINE=1
lto: true
link-libs:
  - yamlssl
link-paths:
  - /yaml/lib
YAML);

        $this->invokeMethod('parseProjectYaml', $projectFile);
        $this->invokeMethod('applyCommandLineArguments');

        $this->assertSame(['/yaml/include'], $this->compiler->getUserIncludePaths());
        $this->assertSame(['YAML_DEFINE=1'], $this->compiler->getUserDefines());
        $this->assertTrue($this->compiler->isLtoEnabled());
        $this->assertSame(['yamlssl'], $this->compiler->getLinkLibs());
        $this->assertSame(['/yaml/lib'], $this->compiler->getLinkPaths());
    }

    public function testParseProjectYamlFiltersIgnoredFilesFromReturnedSources(): void
    {
        $projectFile = $this->createProjectFile(<<<'YAML'
sources:
  - .
ignore:
  - ignored.php
  - skipped
YAML);
        $projectDir = dirname($projectFile);
        mkdir($projectDir . '/skipped', 0777, true);
        copy($this->fixturePath('ignored.php'), $projectDir . '/ignored.php');
        copy($this->fixturePath('skipped_nested.php'), $projectDir . '/skipped/nested.php');
        copy($this->fixturePath('kept.php'), $projectDir . '/kept.php');

        $files = $this->invokeMethod('parseProjectYaml', $projectFile);

        $this->assertContains(realpath($projectDir . '/main.php'), $files);
        $this->assertContains(realpath($projectDir . '/kept.php'), $files);
        $this->assertNotContains(realpath($projectDir . '/ignored.php'), $files);
        $this->assertNotContains(realpath($projectDir . '/skipped/nested.php'), $files);
    }

    public function testCCompileCommandOptionsKeepCommonUserConfiguration(): void
    {
        $this->setPropertyValue('userIncludePaths', ['/user/include']);
        $this->setPropertyValue('userDefines', ['FEATURE_X=1']);
        $this->setPropertyValue('buildMode', CompilerBase::BUILD_MODE_EXT);
        $this->setPropertyValue('enableProfiler', true);
        $this->setPropertyValue('enableLto', true);
        $this->setPropertyValue('sanitize', 'address');
        $this->setPropertyValue('targetPlatform', 'aarch64-linux-gnu');
        $this->setPropertyValue('march', 'native');

        $options = $this->invokeMethod('getCCompileCommandOptions');

        $this->assertContains('/user/include', $options['include_paths']);
        $this->assertSame(['FEATURE_X=1'], $options['user_defines']);
        $this->assertSame(CompilerBase::BUILD_MODE_EXT, $options['build_mode']);
        $this->assertTrue($options['enable_profiler']);
        $this->assertTrue($options['lto']);
        $this->assertSame('address', $options['sanitize']);
        $this->assertSame('aarch64-linux-gnu', $options['target_platform']);
        $this->assertSame('native', $options['march']);
    }

    public function testNativeCompileCommandOptionsKeepCommonUserConfiguration(): void
    {
        $this->setPropertyValue('userIncludePaths', ['/native/include']);
        $this->setPropertyValue('userDefines', ['NATIVE_FEATURE=1']);
        $this->setPropertyValue('buildMode', CompilerBase::BUILD_MODE_EXT);
        $this->setPropertyValue('enableProfiler', true);
        $this->setPropertyValue('enableLto', true);

        $options = $this->invokeMethod('getNativeCompileCommandOptions', 'objective-c');

        $this->assertContains('/native/include', $options['include_paths']);
        $this->assertSame(['NATIVE_FEATURE=1'], $options['user_defines']);
        $this->assertSame(CompilerBase::BUILD_MODE_EXT, $options['build_mode']);
        $this->assertTrue($options['enable_profiler']);
        $this->assertTrue($options['lto']);
        $this->assertArrayNotHasKey('cpp_std', $options);
        $this->assertArrayNotHasKey('cxxflags', $options);
    }

    public function testObjectiveCppCompileCommandOptionsKeepCppOptions(): void
    {
        $this->setPropertyValue('cxxStd', 'c++20');
        $this->setPropertyValue('cxxFlags', '-fobjc-arc');

        $options = $this->invokeMethod('getNativeCompileCommandOptions', 'objective-c++');

        $this->assertSame('c++20', $options['cpp_std']);
        $this->assertSame('-fobjc-arc', $options['cxxflags']);
    }

    public function testLinkCommandOptionsPassUserLibrariesThroughBackendFields(): void
    {
        $this->setPropertyValue('linkLibs', ['curl', 'ssl']);
        $this->setPropertyValue('linkPaths', ['/user/lib']);
        $this->setPropertyValue('enableProfiler', true);
        $this->setPropertyValue('ldflags', '-Wl,--as-needed');

        $options = $this->invokeMethod('getLinkCommandOptions');

        $this->assertContains('/user/lib', $options['library_paths']);
        $this->assertContains('profiler', $options['libraries']);
        $this->assertContains('curl', $options['libraries']);
        $this->assertContains('ssl', $options['libraries']);
        $this->assertSame('-Wl,--as-needed', $options['ldflags']);
    }

    public function testFormatCppCodeEscapesPathsWithSpaces(): void
    {
        $spaceDir = sys_get_temp_dir() . '/compiler api format ' . uniqid();
        mkdir($spaceDir, 0777, true);
        $binDir = $spaceDir . '/bin';
        $logFile = $spaceDir . '/format.log';
        $sourceFile = $spaceDir . '/hello world.cc';

        copy($this->fixturePath('hello_world.cc'), $sourceFile);
        $this->createFakeClangFormat($binDir, $logFile);
        putenv('PATH=' . $binDir . ':' . ($this->originalPath ?: ''));

        $compiler = CompilerTest::create($spaceDir);
        $ref = new \ReflectionClass($compiler);
        $formatProp = $ref->getProperty('formatCode');
        $formatProp->setAccessible(true);
        $formatProp->setValue($compiler, true);

        $method = $ref->getMethod('formatCppCode');
        $method->setAccessible(true);
        $method->invoke($compiler, $sourceFile);

        $this->assertFileExists($logFile);
        $lines = file($logFile, FILE_IGNORE_NEW_LINES);
        $this->assertSame($spaceDir, $lines[0]);
        $this->assertSame('-i', $lines[1]);
        $this->assertSame($sourceFile, $lines[2]);

        $this->removeDirectory($spaceDir);
    }

    // ========================================================================
    // isWindows / isLinux / isMacos
    // ========================================================================

    public function testPlatformDetectionMethods(): void
    {
        $isWin = $this->compiler->isWindows();
        $isLin = $this->compiler->isLinux();
        $isMac = $this->compiler->isMacos();

        // Exactly one platform must be true
        $sum = ($isWin ? 1 : 0) + ($isLin ? 1 : 0) + ($isMac ? 1 : 0);
        $this->assertEquals(1, $sum, 'Exactly one platform must be detected');

        // All return bool
        $this->assertIsBool($isWin);
        $this->assertIsBool($isLin);
        $this->assertIsBool($isMac);
    }

    // ========================================================================
    // isScalarInt - public method
    // ========================================================================

    public function testIsScalarIntTrue(): void
    {
        $this->assertTrue($this->compiler->isScalarInt(new \PhpParser\Node\Scalar\LNumber(42)));
    }

    public function testIsScalarIntFalse(): void
    {
        $this->assertFalse($this->compiler->isScalarInt(new \PhpParser\Node\Expr\Variable('a')));
    }

    // ========================================================================
    // getNamespacedClassName - fully qualified
    // ========================================================================

    public function testGetNamespacedClassNameFullyQualified(): void
    {
        $this->assertEquals(
            'App\\Entity\\User',
            $this->compiler->getNamespacedClassName('\\App\\Entity\\User')
        );
    }

    // ========================================================================
    // getNamespacedClassName - with use alias
    // ========================================================================

    public function testGetNamespacedClassNameWithUseAlias(): void
    {
        $this->setPropertyValue('useAliases', ['User' => 'App\\Entity\\User']);
        $this->assertEquals(
            'App\\Entity\\User',
            $this->compiler->getNamespacedClassName('User')
        );
    }

    public function testGetNamespacedClassNameWithUseAliasSubNamespace(): void
    {
        $this->setPropertyValue('useAliases', ['Entity' => 'App\\Entity']);
        $this->assertEquals(
            'App\\Entity\\User',
            $this->compiler->getNamespacedClassName('Entity\\User')
        );
    }

    // ========================================================================
    // getNamespacedClassName - with use namespace (partial match)
    // ========================================================================

    public function testGetNamespacedClassNameWithUseNamespace(): void
    {
        $this->setPropertyValue('useNamespaces', ['App\\Entity']);
        // The last segment of 'App\Entity' is 'Entity', matching input 'Entity'
        $this->assertEquals(
            'App\\Entity',
            $this->compiler->getNamespacedClassName('Entity')
        );
    }

    public function testGetNamespacedClassNameWithUseNamespaceSub(): void
    {
        $this->setPropertyValue('useNamespaces', ['App\\Entity']);
        // 'Entity\User' - first part 'Entity' matches the last part of 'App\Entity'
        $this->assertEquals(
            'App\\Entity\\User',
            $this->compiler->getNamespacedClassName('Entity\\User')
        );
    }

    // ========================================================================
    // getNamespacedClassName - with current namespace
    // ========================================================================

    public function testGetNamespacedClassNameWithCurrentNamespace(): void
    {
        $this->setPropertyValue('namespace', 'App\\Service');
        // No matching alias or use namespace
        $this->setPropertyValue('useAliases', []);
        $this->setPropertyValue('useNamespaces', []);
        $this->assertEquals(
            'App\\Service\\MyClass',
            $this->compiler->getNamespacedClassName('MyClass')
        );
    }

    public function testGetNamespacedClassNameNoNamespace(): void
    {
        $this->setPropertyValue('namespace', '');
        $this->setPropertyValue('useAliases', []);
        $this->setPropertyValue('useNamespaces', []);
        $this->assertEquals(
            'MyClass',
            $this->compiler->getNamespacedClassName('MyClass')
        );
    }

    // ========================================================================
    // getNamespacedClassName - alias takes priority over use namespace
    // ========================================================================

    public function testGetNamespacedClassNameAliasPriority(): void
    {
        $this->setPropertyValue('useAliases', ['User' => 'App\\Models\\User']);
        $this->setPropertyValue('useNamespaces', ['App\\Controllers']);
        // Alias should be checked first
        $this->assertEquals(
            'App\\Models\\User',
            $this->compiler->getNamespacedClassName('User')
        );
    }

    // ========================================================================
    // getNamespacedFuncName
    // ========================================================================

    public function testGetNamespacedFuncNameFullyQualified(): void
    {
        $this->assertEquals(
            'App\\Lib\\helper_func',
            $this->compiler->getNamespacedFuncName('\\App\\Lib\\helper_func')
        );
    }

    public function testGetNamespacedFuncNameWithUseFunction(): void
    {
        $this->setPropertyValue('useFunctions', [
            'helper_func' => 'App\\Lib',
        ]);
        $this->assertEquals(
            'App\\Lib\\helper_func',
            $this->compiler->getNamespacedFuncName('helper_func')
        );
    }

    public function testGetNamespacedFuncNameNoNamespace(): void
    {
        $this->setPropertyValue('useFunctions', []);
        $this->assertEquals(
            'helper_func',
            $this->compiler->getNamespacedFuncName('helper_func')
        );
    }

    // ========================================================================
    // getNamespacedFuncName - not in useFunctions returns bare name
    // ========================================================================

    public function testGetNamespacedFuncNameNotInUseFunctions(): void
    {
        $this->setPropertyValue('useFunctions', ['other' => 'Some\\Ns']);
        $this->assertEquals(
            'my_func',
            $this->compiler->getNamespacedFuncName('my_func')
        );
    }

    // ========================================================================
    // getPhpDir
    // ========================================================================

    public function testGetPhpDir(): void
    {
        $phpDir = $this->compiler->getPhpDir();
        $this->assertIsString($phpDir);
        $this->assertNotEmpty($phpDir);
    }
}
