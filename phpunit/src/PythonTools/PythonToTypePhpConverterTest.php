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
}
