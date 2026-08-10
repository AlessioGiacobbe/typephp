<?php

namespace TypePhp\PythonTools\Converter;

use RuntimeException;

final class PythonToTypePhpConverter
{
    /** @var array<string, string> */
    private array $moduleAliases = [];

    /** @var array<string, array{module: string, member: string}> */
    private array $importedSymbols = [];

    /** @var array<string, true> */
    private array $definedFunctions = [];

    /** @var array<string, true> */
    private array $moduleGlobals = [];

    private string $filename = '<python>';

    private int $indent = 0;

    public function __construct(private readonly PythonAstLoader $loader = new PythonAstLoader())
    {
    }

    public function convertFile(string $file): string
    {
        $source = @file_get_contents($file);
        if ($source === false) {
            throw new RuntimeException("Unable to read Python source file: {$file}");
        }
        return $this->convertSource($source, $file);
    }

    public function convertSource(string $source, string $filename = '<python>'): string
    {
        $this->filename = $filename;
        $this->moduleAliases = [];
        $this->importedSymbols = [];
        $this->definedFunctions = [];
        $this->moduleGlobals = [];
        $this->indent = 0;
        $tree = $this->loader->parse($source, $filename);
        $functions = [];
        $main = [];

        foreach ($tree['body'] ?? [] as $node) {
            if (in_array($node['_type'] ?? '', ['Assign', 'AnnAssign', 'AugAssign'], true)) {
                $targets = ($node['_type'] ?? '') === 'Assign' ? ($node['targets'] ?? []) : [$node['target'] ?? []];
                foreach ($targets as $target) {
                    if (($target['_type'] ?? '') === 'Name') {
                        $this->moduleGlobals[(string) $target['id']] = true;
                    }
                }
            }
            $type = $node['_type'] ?? '';
            if ($type === 'Import' || $type === 'ImportFrom') {
                $this->collectImport($node);
            } elseif ($type === 'FunctionDef') {
                $name = (string) $node['name'];
                if ($name === 'main') {
                    $this->unsupported($node, 'a Python function named main conflicts with the TypePHP entry point');
                }
                $this->definedFunctions[$name] = true;
                $functions[] = $node;
            } else {
                $main[] = $node;
            }
        }

        $lines = ['<?php', '', '/** @generated from ' . $this->safeComment($filename) . ' */'];
        foreach ($this->moduleAliases as $alias => $module) {
            $namespace = 'python\\' . str_replace('.', '\\', $module);
            $defaultAlias = str_replace('.', '\\', $module);
            $lines[] = $alias === basename(str_replace('.', '/', $module))
                ? 'use ' . $namespace . ';'
                : 'use ' . $namespace . ' as ' . $alias . ';';
        }
        if ($this->moduleAliases !== []) {
            $lines[] = '';
        }
        foreach ($functions as $function) {
            array_push($lines, ...$this->statement($function));
            $lines[] = '';
        }
        $lines[] = 'function main(): void';
        $lines[] = '{';
        $this->indent = 1;
        if ($this->moduleGlobals !== []) {
            $lines[] = $this->line('global ' . implode(', ', $this->variables(array_keys($this->moduleGlobals))) . ';');
        }
        foreach ($main as $node) {
            array_push($lines, ...$this->statement($node));
        }
        $this->indent = 0;
        $lines[] = '}';
        $lines[] = '';
        return implode(PHP_EOL, $lines);
    }

    /** @param array<string, mixed> $node */
    private function collectImport(array $node): void
    {
        if ($node['_type'] === 'Import') {
            foreach ($node['names'] ?? [] as $name) {
                $module = (string) $name['name'];
                $alias = (string) ($name['asname'] ?? '');
                if ($alias === '') {
                    $alias = explode('.', $module)[0];
                    $module = $alias;
                }
                $this->moduleAliases[$alias] = $module;
            }
            return;
        }
        if (($node['level'] ?? 0) !== 0 || ($node['module'] ?? null) === null) {
            $this->unsupported($node, 'relative imports are not supported yet');
        }
        foreach ($node['names'] ?? [] as $name) {
            if (($name['name'] ?? '') === '*') {
                $this->unsupported($node, 'star imports are not supported');
            }
            $alias = (string) (($name['asname'] ?? null) ?: $name['name']);
            $this->importedSymbols[$alias] = [
                'module' => (string) $node['module'],
                'member' => (string) $name['name'],
            ];
        }
    }

    /** @param array<string, mixed> $node @return list<string> */
    private function statement(array $node): array
    {
        $type = $node['_type'] ?? '';
        return match ($type) {
            'FunctionDef' => $this->functionDefinition($node),
            'Assign' => $this->assignment($node),
            'AnnAssign' => ($node['value'] ?? null) === null
                ? $this->unsupported($node, 'annotation-only assignments have no TypePHP runtime value')
                : [$this->line($this->target($node['target']) . ' = ' . $this->expression($node['value']) . ';')],
            'AugAssign' => [$this->line($this->target($node['target']) . ' ' . $this->binaryOperator($node['op'], $node)
                . '= ' . $this->expression($node['value']) . ';')],
            'Expr' => $this->expressionStatement($node),
            'Return' => [$this->line('return' . (($node['value'] ?? null) === null ? '' : ' ' . $this->expression($node['value'])) . ';')],
            'If' => $this->ifStatement($node),
            'While' => $this->whileStatement($node),
            'For' => $this->forStatement($node),
            'Break' => [$this->line('break;')],
            'Continue' => [$this->line('continue;')],
            'Pass' => [$this->line('// pass')],
            'Global' => [$this->line('global ' . implode(', ', $this->variables($node['names'] ?? [])) . ';')],
            'Delete' => $this->deleteStatement($node),
            'Import', 'ImportFrom' => [],
            default => $this->unsupported($node),
        };
    }

    /** @param array<string, mixed> $node @return list<string> */
    private function functionDefinition(array $node): array
    {
        if ($this->indent !== 0) {
            $this->unsupported($node, 'nested functions require Python closure scope analysis');
        }
        if (($node['decorator_list'] ?? []) !== []) {
            $this->unsupported($node, 'function decorators are not supported yet');
        }
        $parameters = $this->parameters($node['args'], $node);
        $lines = [$this->line('function ' . $node['name'] . '(' . $parameters . ')'), $this->line('{')];
        $this->indent++;
        $locals = $this->functionLocalNames($node);
        $globals = array_values(array_diff(array_keys($this->moduleGlobals), array_keys($locals)));
        if ($globals !== []) {
            $lines[] = $this->line('global ' . implode(', ', $this->variables($globals)) . ';');
        }
        foreach ($node['body'] ?? [] as $body) {
            array_push($lines, ...$this->statement($body));
        }
        $this->indent--;
        $lines[] = $this->line('}');
        return $lines;
    }

    /** @param array<string, mixed> $arguments @param array<string, mixed> $owner */
    private function parameters(array $arguments, array $owner): string
    {
        $positional = array_merge($arguments['posonlyargs'] ?? [], $arguments['args'] ?? []);
        $defaults = $arguments['defaults'] ?? [];
        $defaultStart = count($positional) - count($defaults);
        $result = [];
        foreach ($positional as $index => $argument) {
            $value = $this->variable((string) $argument['arg']);
            if ($index >= $defaultStart) {
                $value .= ' = ' . $this->expression($defaults[$index - $defaultStart]);
            }
            $result[] = $value;
        }
        foreach ($arguments['kwonlyargs'] ?? [] as $index => $argument) {
            $default = $arguments['kw_defaults'][$index] ?? null;
            $result[] = $this->variable((string) $argument['arg']) . ' = '
                . ($default === null ? 'null' : $this->expression($default));
        }
        $variadic = $arguments['vararg'] ?? $arguments['kwarg'] ?? null;
        if ($variadic !== null) {
            $result[] = '...' . $this->variable((string) $variadic['arg']);
        }
        if (($arguments['vararg'] ?? null) !== null && ($arguments['kwarg'] ?? null) !== null) {
            $this->unsupported($owner, 'simultaneous *args and **kwargs cannot be represented by one PHP signature');
        }
        return implode(', ', $result);
    }

    /** @param array<string, mixed> $node @return list<string> */
    private function assignment(array $node): array
    {
        if (count($node['targets'] ?? []) !== 1) {
            $this->unsupported($node, 'chained assignments are not supported yet');
        }
        $target = $node['targets'][0];
        if (in_array($target['_type'] ?? '', ['Tuple', 'List'], true)) {
            $this->unsupported($node, 'destructuring assignments are not supported yet');
        }
        return [$this->line($this->target($target) . ' = ' . $this->expression($node['value']) . ';')];
    }

    /** @param array<string, mixed> $node @return list<string> */
    private function expressionStatement(array $node): array
    {
        $value = $node['value'];
        if (($value['_type'] ?? '') === 'Constant' && is_string($value['value'] ?? null)) {
            return [$this->line('/** ' . $this->safeComment($value['value']) . ' */')];
        }
        if (($value['_type'] ?? '') === 'Call') {
            $native = $this->nativeCallStatement($value);
            if ($native !== null) {
                return [$this->line($native)];
            }
        }
        return [$this->line($this->expression($value) . ';')];
    }

    /** @param array<string, mixed> $node */
    private function nativeCallStatement(array $node): ?string
    {
        if ($this->isBuiltinPrintCall($node)) {
            $arguments = $node['args'] ?? [];
            if (($node['keywords'] ?? []) !== []) {
                return null;
            }
            foreach ($arguments as $argument) {
                if (!$this->isEchoCompatiblePythonValue($argument)) {
                    return null;
                }
            }
            if ($arguments === []) {
                return 'echo "\\n";';
            }
            $parts = [];
            foreach ($arguments as $index => $argument) {
                if ($index !== 0) {
                    $parts[] = "' '";
                }
                $parts[] = $this->expression($argument);
            }
            $parts[] = '"\\n"';
            return 'echo ' . implode(', ', $parts) . ';';
        }

        if ($this->isSysExitCall($node) && ($node['keywords'] ?? []) === []) {
            $arguments = $node['args'] ?? [];
            if ($arguments === []) {
                return 'exit;';
            }
            if (count($arguments) === 1 && $this->isIntegerLiteral($arguments[0])) {
                return 'exit(' . $this->expression($arguments[0]) . ');';
            }
        }
        return null;
    }

    /** @param array<string, mixed> $node */
    private function isBuiltinPrintCall(array $node): bool
    {
        $function = $node['func'] ?? [];
        return ($function['_type'] ?? '') === 'Name'
            && ($function['id'] ?? '') === 'print'
            && !isset($this->definedFunctions['print'])
            && !isset($this->importedSymbols['print'])
            && !isset($this->moduleGlobals['print']);
    }

    /** @param array<string, mixed> $node */
    private function isSysExitCall(array $node): bool
    {
        $function = $node['func'] ?? [];
        if (($function['_type'] ?? '') === 'Name') {
            $symbol = $this->importedSymbols[(string) ($function['id'] ?? '')] ?? null;
            return $symbol !== null && $symbol['module'] === 'sys' && $symbol['member'] === 'exit';
        }
        if (($function['_type'] ?? '') !== 'Attribute' || ($function['attr'] ?? '') !== 'exit') {
            return false;
        }
        $owner = $function['value'] ?? [];
        if (($owner['_type'] ?? '') !== 'Name') {
            return false;
        }
        return ($this->moduleAliases[(string) ($owner['id'] ?? '')] ?? null) === 'sys';
    }

    /** @param array<string, mixed> $node */
    private function isEchoCompatiblePythonValue(array $node): bool
    {
        $type = $node['_type'] ?? '';
        if ($type === 'Constant') {
            $value = $node['value'] ?? null;
            return is_string($value) || is_int($value);
        }
        if ($type === 'Attribute') {
            $cursor = $node;
            while (($cursor['_type'] ?? '') === 'Attribute') {
                $cursor = $cursor['value'];
            }
            return $this->attributeStartsWithModuleAlias($node)
                || (($cursor['_type'] ?? '') === 'Name'
                    && isset($this->importedSymbols[(string) ($cursor['id'] ?? '')]));
        }
        return in_array($type, ['JoinedStr', 'List', 'Tuple', 'Set', 'Dict'], true);
    }

    /** @param array<string, mixed> $node */
    private function isIntegerLiteral(array $node): bool
    {
        if (($node['_type'] ?? '') === 'Constant') {
            return is_int($node['value'] ?? null);
        }
        return ($node['_type'] ?? '') === 'UnaryOp'
            && in_array($node['op']['_type'] ?? '', ['USub', 'UAdd'], true)
            && ($node['operand']['_type'] ?? '') === 'Constant'
            && is_int($node['operand']['value'] ?? null);
    }

    /** @param array<string, mixed> $node @return list<string> */
    private function ifStatement(array $node, bool $elseif = false): array
    {
        $lines = [$this->line(($elseif ? 'elseif' : 'if') . ' (' . $this->expression($node['test']) . ')'), $this->line('{')];
        $this->indent++;
        foreach ($node['body'] ?? [] as $body) {
            array_push($lines, ...$this->statement($body));
        }
        $this->indent--;
        $lines[] = $this->line('}');
        $otherwise = $node['orelse'] ?? [];
        if (count($otherwise) === 1 && ($otherwise[0]['_type'] ?? '') === 'If') {
            $nested = $this->ifStatement($otherwise[0], true);
            $nested[0] = $this->line('elseif (' . $this->expression($otherwise[0]['test']) . ')');
            array_push($lines, ...$nested);
        } elseif ($otherwise !== []) {
            $lines[] = $this->line('else');
            $lines[] = $this->line('{');
            $this->indent++;
            foreach ($otherwise as $body) {
                array_push($lines, ...$this->statement($body));
            }
            $this->indent--;
            $lines[] = $this->line('}');
        }
        return $lines;
    }

    /** @param array<string, mixed> $node @return list<string> */
    private function whileStatement(array $node): array
    {
        if (($node['orelse'] ?? []) !== []) {
            $this->unsupported($node, 'while/else is not supported yet');
        }
        $lines = [$this->line('while (' . $this->expression($node['test']) . ')'), $this->line('{')];
        $this->indent++;
        foreach ($node['body'] ?? [] as $body) {
            array_push($lines, ...$this->statement($body));
        }
        $this->indent--;
        $lines[] = $this->line('}');
        return $lines;
    }

    /** @param array<string, mixed> $node @return list<string> */
    private function forStatement(array $node): array
    {
        if (($node['orelse'] ?? []) !== []) {
            $this->unsupported($node, 'for/else is not supported yet');
        }
        if (($node['target']['_type'] ?? '') !== 'Name') {
            $this->unsupported($node, 'only a simple for-loop target is supported');
        }
        $lines = [$this->line('foreach (' . $this->expression($node['iter']) . ' as '
            . $this->variable($node['target']['id']) . ')'), $this->line('{')];
        $this->indent++;
        foreach ($node['body'] ?? [] as $body) {
            array_push($lines, ...$this->statement($body));
        }
        $this->indent--;
        $lines[] = $this->line('}');
        return $lines;
    }

    /** @param array<string, mixed> $node @return list<string> */
    private function deleteStatement(array $node): array
    {
        $lines = [];
        foreach ($node['targets'] ?? [] as $target) {
            if (!in_array($target['_type'] ?? '', ['Name', 'Attribute', 'Subscript'], true)) {
                $this->unsupported($node, 'unsupported del target');
            }
            $lines[] = $this->line('unset(' . $this->target($target) . ');');
        }
        return $lines;
    }

    /** @param array<string, mixed> $node */
    private function expression(array $node): string
    {
        return match ($node['_type'] ?? '') {
            'Constant' => $this->constant($node['value'] ?? null),
            'Name' => $this->nameExpression((string) $node['id']),
            'Attribute' => $this->attribute($node),
            'Call' => $this->call($node),
            'List' => 'python\\list([' . $this->expressionList($node['elts'] ?? []) . '])',
            'Tuple' => 'python\\tuple([' . $this->expressionList($node['elts'] ?? []) . '])',
            'Set' => 'python\\set([' . $this->expressionList($node['elts'] ?? []) . '])',
            'Dict' => 'python\\dict([' . $this->dictionaryItems($node) . '])',
            'BinOp' => $this->binaryExpression($node),
            'UnaryOp' => $this->unaryExpression($node),
            'Compare' => $this->comparison($node),
            'IfExp' => '(' . $this->expression($node['test']) . ' ? ' . $this->expression($node['body'])
                . ' : ' . $this->expression($node['orelse']) . ')',
            'Subscript' => $this->expression($node['value']) . '[' . $this->slice($node['slice']) . ']',
            'Lambda' => 'fn (' . $this->parameters($node['args'], $node) . ') => ' . $this->expression($node['body']),
            'JoinedStr' => $this->joinedString($node),
            'Starred' => '...' . $this->expression($node['value']),
            default => $this->unsupported($node),
        };
    }

    /** @param array<string, mixed> $node */
    private function call(array $node): string
    {
        $function = $node['func'];
        if (($function['_type'] ?? '') === 'Name') {
            $name = (string) $function['id'];
            if (isset($this->importedSymbols[$name])) {
                $symbol = $this->importedSymbols[$name];
                $callable = 'python\\' . str_replace('.', '\\', $symbol['module']) . '\\' . $symbol['member'];
            } elseif (isset($this->definedFunctions[$name])) {
                $callable = $name;
            } elseif ($this->isPythonBuiltin($name)) {
                $callable = 'python\\' . $name;
            } else {
                $callable = $this->variable($name);
            }
        } elseif (($function['_type'] ?? '') === 'Attribute') {
            $callable = $this->attribute($function);
        } else {
            $callable = '(' . $this->expression($function) . ')';
        }
        $arguments = [];
        foreach ($node['args'] ?? [] as $argument) {
            $arguments[] = $this->expression($argument);
        }
        foreach ($node['keywords'] ?? [] as $keyword) {
            $arguments[] = ($keyword['arg'] === null ? '...' : $keyword['arg'] . ': ')
                . $this->expression($keyword['value']);
        }
        return $callable . '(' . implode(', ', $arguments) . ')';
    }

    /** @param array<string, mixed> $node */
    private function attribute(array $node): string
    {
        $parts = [];
        $cursor = $node;
        while (($cursor['_type'] ?? '') === 'Attribute') {
            array_unshift($parts, (string) $cursor['attr']);
            $cursor = $cursor['value'];
        }
        if (($cursor['_type'] ?? '') === 'Name' && isset($this->moduleAliases[$cursor['id']])) {
            // A Python module is represented by a TypePHP namespace, but only
            // the first attribute is a member of that module. Any remaining
            // attributes belong to the PyObject returned by that member.
            // For example, sys.version_info.major becomes
            // sys\version_info->major, not sys\version_info\major.
            $result = $cursor['id'] . '\\' . array_shift($parts);
            foreach ($parts as $part) {
                $result .= '->' . $part;
            }
            return $result;
        }
        $result = $this->expression($cursor);
        foreach ($parts as $part) {
            $result .= '->' . $part;
        }
        return $result;
    }

    /** @param array<string, mixed> $node */
    private function binaryExpression(array $node): string
    {
        $operator = $node['op']['_type'] ?? '';
        if ($operator === 'FloorDiv') {
            return 'python\\operator\\floordiv(' . $this->expression($node['left']) . ', '
                . $this->expression($node['right']) . ')';
        }
        if ($operator === 'MatMult') {
            return 'python\\operator\\matmul(' . $this->expression($node['left']) . ', '
                . $this->expression($node['right']) . ')';
        }
        return $this->expression($node['left']) . ' ' . $this->binaryOperator($node['op'], $node)
            . ' ' . $this->expression($node['right']);
    }

    /** @param array<string, mixed> $operator @param array<string, mixed> $owner */
    private function binaryOperator(array $operator, array $owner): string
    {
        return match ($operator['_type'] ?? '') {
            'Add' => '+', 'Sub' => '-', 'Mult' => '*', 'Div' => '/', 'Mod' => '%',
            'Pow' => '**', 'LShift' => '<<', 'RShift' => '>>', 'BitOr' => '|',
            'BitXor' => '^', 'BitAnd' => '&',
            default => $this->unsupported($owner, 'unsupported binary operator ' . ($operator['_type'] ?? 'unknown')),
        };
    }

    /** @param array<string, mixed> $node */
    private function unaryExpression(array $node): string
    {
        $operator = match ($node['op']['_type'] ?? '') {
            'USub' => '-', 'UAdd' => '+', 'Not' => '!', 'Invert' => '~',
            default => $this->unsupported($node, 'unsupported unary operator'),
        };
        return $operator . $this->expression($node['operand']);
    }

    /** @param array<string, mixed> $node */
    private function comparison(array $node): string
    {
        if (count($node['ops'] ?? []) !== 1 || count($node['comparators'] ?? []) !== 1) {
            $this->unsupported($node, 'chained comparisons require explicit temporary variables');
        }
        $left = $this->expression($node['left']);
        $right = $this->expression($node['comparators'][0]);
        return match ($node['ops'][0]['_type'] ?? '') {
            'Eq' => $left . ' == ' . $right,
            'NotEq' => $left . ' != ' . $right,
            'Is' => $left . ' === ' . $right,
            'IsNot' => $left . ' !== ' . $right,
            'Lt' => $left . ' < ' . $right,
            'LtE' => $left . ' <= ' . $right,
            'Gt' => $left . ' > ' . $right,
            'GtE' => $left . ' >= ' . $right,
            'In' => 'python\\operator\\contains(' . $right . ', ' . $left . ')',
            'NotIn' => '!python\\operator\\contains(' . $right . ', ' . $left . ')',
            default => $this->unsupported($node, 'unsupported comparison operator'),
        };
    }

    /** @param array<string, mixed> $node */
    private function target(array $node): string
    {
        if (($node['_type'] ?? '') === 'Attribute' && $this->attributeStartsWithModuleAlias($node)) {
            $this->unsupported($node, 'Python module attributes cannot be assigned or deleted');
        }
        return match ($node['_type'] ?? '') {
            'Name' => $this->variable((string) $node['id']),
            'Attribute' => $this->attribute($node),
            'Subscript' => $this->expression($node['value']) . '[' . $this->slice($node['slice']) . ']',
            default => $this->unsupported($node, 'unsupported assignment target'),
        };
    }

    private function nameExpression(string $name): string
    {
        if (isset($this->moduleAliases[$name])) {
            throw new RuntimeException(
                "{$this->filename}: a Python module cannot be used as a first-class value in TypePHP namespace syntax",
            );
        }
        if (isset($this->importedSymbols[$name])) {
            $symbol = $this->importedSymbols[$name];
            return 'python\\' . str_replace('.', '\\', $symbol['module']) . '\\' . $symbol['member'];
        }
        return $this->variable($name);
    }

    private function variable(string $name): string
    {
        return '$' . ($name === 'this' ? 'this_' : $name);
    }

    private function constant(mixed $value): string
    {
        if (is_array($value) && isset($value['_python_constant'])) {
            throw new RuntimeException("{$this->filename}: Python {$value['_python_constant']} literals are not supported yet");
        }
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        return var_export($value, true);
    }

    /** @param list<array<string, mixed>> $nodes */
    private function expressionList(array $nodes): string
    {
        $expressions = [];
        foreach ($nodes as $node) {
            $expressions[] = $this->expression($node);
        }
        return implode(', ', $expressions);
    }

    /** @param array<string, mixed> $node */
    private function dictionaryItems(array $node): string
    {
        $items = [];
        foreach ($node['keys'] ?? [] as $index => $key) {
            if ($key === null) {
                $items[] = '...' . $this->expression($node['values'][$index]);
            } else {
                $items[] = $this->expression($key) . ' => ' . $this->expression($node['values'][$index]);
            }
        }
        return implode(', ', $items);
    }

    /** @param array<string, mixed> $node */
    private function slice(array $node): string
    {
        if (($node['_type'] ?? '') !== 'Slice') {
            return $this->expression($node);
        }
        return 'python\\slice('
            . (($node['lower'] ?? null) === null ? 'null' : $this->expression($node['lower'])) . ', '
            . (($node['upper'] ?? null) === null ? 'null' : $this->expression($node['upper'])) . ', '
            . (($node['step'] ?? null) === null ? 'null' : $this->expression($node['step'])) . ')';
    }

    /** @param array<string, mixed> $node */
    private function joinedString(array $node): string
    {
        $parts = [];
        foreach ($node['values'] ?? [] as $value) {
            if (($value['_type'] ?? '') === 'FormattedValue') {
                if (($value['format_spec'] ?? null) !== null || ($value['conversion'] ?? -1) !== -1) {
                    $this->unsupported($value, 'formatted f-string conversions are not supported yet');
                }
                $expression = $this->expression($value['value']);
                // PHP permits direct dereferencing of these expressions. Keep
                // parentheses for operators and other precedence-sensitive
                // expressions, whose result must be converted as a whole.
                if (!in_array($value['value']['_type'] ?? '', [
                    'Name', 'Attribute', 'Call', 'Subscript', 'List', 'Tuple', 'Set', 'Dict',
                ], true)) {
                    $expression = '(' . $expression . ')';
                }
                $parts[] = $expression . '->toString()';
            } else {
                $parts[] = $this->expression($value);
            }
        }
        return $parts === [] ? "''" : implode(' . ', $parts);
    }

    private function isPythonBuiltin(string $name): bool
    {
        static $builtins = [
            'abs', 'all', 'any', 'bool', 'bytes', 'callable', 'dict', 'dir', 'enumerate',
            'filter', 'float', 'getattr', 'hasattr', 'int', 'isinstance', 'issubclass', 'iter',
            'len', 'list', 'map', 'max', 'min', 'next', 'object', 'open', 'ord', 'pow', 'print',
            'range', 'repr', 'reversed', 'round', 'set', 'setattr', 'slice', 'sorted', 'str',
            'sum', 'tuple', 'type', 'vars', 'zip',
        ];
        return in_array($name, $builtins, true);
    }

    /** @param array<string, mixed> $function @return array<string, true> */
    private function functionLocalNames(array $function): array
    {
        $locals = [];
        $globals = [];
        $arguments = $function['args'] ?? [];
        foreach (array_merge($arguments['posonlyargs'] ?? [], $arguments['args'] ?? [], $arguments['kwonlyargs'] ?? []) as $argument) {
            $locals[(string) $argument['arg']] = true;
        }
        foreach (['vararg', 'kwarg'] as $kind) {
            if (($arguments[$kind] ?? null) !== null) {
                $locals[(string) $arguments[$kind]['arg']] = true;
            }
        }
        $stack = array_reverse($function['body'] ?? []);
        while ($stack !== []) {
            $value = array_pop($stack);
            if (!is_array($value)) {
                continue;
            }
            if (($value['_type'] ?? '') === 'FunctionDef') {
                continue;
            }
            if (($value['_type'] ?? '') === 'Global') {
                foreach ($value['names'] ?? [] as $name) {
                    $globals[(string) $name] = true;
                }
                continue;
            }
            if (($value['_type'] ?? '') === 'Name' && ($value['ctx']['_type'] ?? '') === 'Store') {
                $locals[(string) $value['id']] = true;
            }
            foreach ($value as $item) {
                if (is_array($item)) {
                    $stack[] = $item;
                }
            }
        }
        foreach ($globals as $name => $_) {
            unset($locals[$name]);
        }
        return $locals;
    }

    /** @param list<string> $names @return list<string> */
    private function variables(array $names): array
    {
        $variables = [];
        foreach ($names as $name) {
            $variables[] = $this->variable((string) $name);
        }
        return $variables;
    }

    /** @param array<string, mixed> $node */
    private function attributeStartsWithModuleAlias(array $node): bool
    {
        $cursor = $node;
        while (($cursor['_type'] ?? '') === 'Attribute') {
            $cursor = $cursor['value'];
        }
        return ($cursor['_type'] ?? '') === 'Name' && isset($this->moduleAliases[$cursor['id']]);
    }

    private function line(string $code): string
    {
        return str_repeat('    ', $this->indent) . $code;
    }

    private function safeComment(string $value): string
    {
        return str_replace(['*/', "\r", "\n"], ['* /', ' ', ' '], $value);
    }

    /** @param array<string, mixed> $node */
    private function unsupported(array $node, ?string $detail = null): never
    {
        $line = (int) ($node['lineno'] ?? 0);
        $type = (string) ($node['_type'] ?? 'unknown');
        $message = "{$this->filename}:{$line}: unsupported Python syntax {$type}";
        if ($detail !== null) {
            $message .= ": {$detail}";
        }
        throw new RuntimeException($message);
    }
}
