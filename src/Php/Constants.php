<?php
/**
 * This file is part of Swoole-Compiler(AOT).
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace PhpAot\Php;

class Constants
{
    public const array CPP_RESERVED_NAMES = [
        'auto',
        'break',
        'case',
        'catch',
        'class',
        'struct',
        'const',
        'continue',
        'default',
        'do',
        'else',
        'elseif',
        'enum',
        'extends',
        'final',
        'finally',
        'for',
        'function',
        'global',
        'if',
        'bool',
        'int',
        'short',
        'long',
        'unsigned',
        'void',
        'signed',
        'double',
        'float',
        'false',
        'for',
        'if',
        'int',
        'new',
        'null',
        'var',
        'char',
        'or',
        'and',
        'private',
        'protected',
        'public',
        'return',
        'static',
        'pipe',
        'template',
        'namespace',
        'errno', // Linux error code
        'this_', // phpx keywords
    ];

    public const array UNSUPPORTED_FUNCTIONS = [
        'extract',
    ];

    public const array COMPILER_OPTIONS = [
        'optimize' => [
            'prefix' => 'O',
            'longPrefix' => 'optimize',
            'description' => 'Set the optimization level of the gcc compiler to 0 by default',
            'required' => false,
            'castTo' => 'int',
            'defaultValue' => 0,
        ],
        'output' => [
            'prefix' => 'o',
            'longPrefix' => 'output',
            'description' => 'Output file',
        ],
        'help' => [
            'prefix' => 'h',
            'longPrefix' => 'help',
            'description' => 'Show help',
            'noValue' => true,
        ],
        'version' => [
            'prefix' => 'v',
            'longPrefix' => 'version',
            'description' => 'Show Version',
            'noValue' => true,
        ],
        'profile' => [
            'longPrefix' => 'profile',
            'description' => 'Enable performance profiling',
            'required' => false,
            'noValue' => true,
        ],
        'noLiteralStrings' => [
            'longPrefix' => 'no-literal-strings',
            'description' => 'Disable literal strings optimization',
            'required' => false,
            'noValue' => true,
        ],
        'force' => [
            'prefix' => 'f',
            'longPrefix' => 'force',
            'description' => 'Force compile even if cache exists',
            'required' => false,
            'noValue' => true,
        ],
        'mode' => [
            'longPrefix' => 'mode',
            'prefix' => 'm',
            'description' => 'Build mode, -m bin(binary) or -m ext(extension), default: bin',
            'required' => false,
            'defaultValue' => 'bin',
        ],
        'debug-line' => [
            'longPrefix' => 'debug-line',
            'description' => 'Enable debug line',
            'required' => false,
            'defaultValue' => 0,
        ],
        'debug-info' => [
            'longPrefix' => 'debug-info',
            'description' => 'Enable debug info',
            'required' => false,
            'defaultValue' => 0,
        ],
        'job' => [
            'prefix' => 'j',
            'longPrefix' => 'job',
            'description' => 'Number of jobs to run in parallel',
            'required' => false,
            'defaultValue' => 4,
        ],
        'no-console' => [
            'longPrefix' => 'no-console',
            'description' => 'Hide console window (Windows only, use /SUBSYSTEM:WINDOWS)',
            'required' => false,
            'noValue' => true,
        ],
    ];
}
