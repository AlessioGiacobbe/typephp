<?php

namespace TypePhpTest\PythonTools;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use TypePhp\PythonTools\Converter\PythonToTypePhpConverter;

final class PythonToTypePhpConverterTest extends TestCase
{
    public function testConvertsImportsFunctionsAndTopLevelCode(): void
    {
        $source = <<<'PYTHON'
import math
import os.path as path
from json import dumps as encode

def hypotenuse(x, y=4):
    return math.sqrt(x * x + y * y)

value = hypotenuse(3)
print(encode({"value": value}))
PYTHON;

        $php = (new PythonToTypePhpConverter())->convertSource($source, 'example.py');

        self::assertStringContainsString('use python\\math;', $php);
        self::assertStringContainsString('use python\\os\\path;', $php);
        self::assertStringContainsString('function hypotenuse($x, $y = 4)', $php);
        self::assertStringContainsString('return math\\sqrt($x * $x + $y * $y);', $php);
        self::assertStringContainsString('function main(): void', $php);
        self::assertStringContainsString('python\\json\\dumps(python\\dict([\'value\' => $value]))', $php);
        self::assertStringContainsString('python\\print(', $php);
    }

    public function testPassDoesNotBecomeReturnAndPythonComparisonsStayExplicit(): void
    {
        $source = <<<'PYTHON'
def inspect_value(value, values):
    if value is None:
        pass
    return value in values
PYTHON;

        $php = (new PythonToTypePhpConverter())->convertSource($source, 'comparison.py');

        self::assertStringContainsString('if ($value === null)', $php);
        self::assertStringContainsString('// pass', $php);
        self::assertStringContainsString('python\\operator\\contains($values, $value)', $php);
        self::assertStringNotContainsString('if ($value === null) {' . "\n        return;", $php);
    }

    public function testUnsupportedSyntaxReportsSourceLocation(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('sample.py:1');
        $this->expectExceptionMessage('ClassDef');

        (new PythonToTypePhpConverter())->convertSource("class Demo:\n    pass\n", 'sample.py');
    }

    public function testModuleVariablesRemainVisibleInsideFunctions(): void
    {
        $php = (new PythonToTypePhpConverter())->convertSource(<<<'PYTHON'
factor = 4

def scale(value):
    return value * factor

print(scale(3))
PYTHON, 'globals.py');

        self::assertStringContainsString('function scale($value)' . "\n{\n" . '    global $factor;', $php);
        self::assertStringContainsString("function main(): void\n{\n" . '    global $factor;', $php);
    }

    public function testOnlyTheFirstAttributeAfterAModuleAliasIsAModuleMember(): void
    {
        $php = (new PythonToTypePhpConverter())->convertSource(<<<'PYTHON'
import sys

print(sys.version_info.major)
sys.stdout.write("hello")
print(f"{sys.version_info.minor}")
PYTHON, 'module-attribute.py');

        self::assertStringContainsString(
            "echo sys\\version_info->major, \"\\n\";",
            $php,
        );
        self::assertStringContainsString(
            "sys\\stdout->write('hello');",
            $php,
        );
        self::assertStringContainsString(
            'echo sys\\version_info->minor->toString(), "\\n";',
            $php,
        );
        self::assertStringNotContainsString('sys\\version_info\\major', $php);
        self::assertStringNotContainsString('sys\\stdout\\write', $php);
    }

    public function testLowersPrintAndSysExitOnlyWhenPhpHasTheSameBehavior(): void
    {
        $php = (new PythonToTypePhpConverter())->convertSource(<<<'PYTHON'
import sys

print()
print("hello")
print(f"version: {sys.version_info.major}")
print(True)
print("same line", end="")
sys.exit()
sys.exit(2)
sys.exit("failure")
PYTHON, 'native-statements.py');

        self::assertStringContainsString('echo "\\n";', $php);
        self::assertStringContainsString('echo \'hello\', "\\n";', $php);
        self::assertStringContainsString(
            'echo \'version: \' . sys\\version_info->major->toString(), "\\n";',
            $php,
        );
        self::assertStringContainsString('python\\print(true);', $php);
        self::assertStringContainsString("python\\print('same line', end: '');", $php);
        self::assertStringContainsString("exit;\n    exit(2);", $php);
        self::assertStringContainsString("sys\\exit('failure');", $php);
    }
}
