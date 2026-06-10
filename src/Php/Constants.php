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
        'get_defined_vars',
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
        'no-literal-strings' => [
            'longPrefix' => 'no-literal-strings',
            'description' => 'Disable literal strings optimization',
            'required' => false,
            'noValue' => true,
        ],
        'force' => [
            'prefix' => 'f',
            'longPrefix' => 'force',
            'description' => 'Force recompile phpx misc files (ignore cache)',
            'required' => false,
            'noValue' => true,
        ],
        'mode' => [
            'longPrefix' => 'mode',
            'prefix' => 'm',
            'description' => 'Build mode, -m bin(binary) or -m ext(extension), default: bin',
            'required' => false,
            'defaultValue' => CompilerBase::BUILD_MODE_BIN,
        ],
        'run' => [
            'prefix' => 'r',
            'longPrefix' => 'run',
            'description' => 'Run the compiled binary after build',
            'noValue' => true,
        ],
        // 内部开发选项，用于定位特定行的翻译问题，请勿写入用户文档
        'debug-line' => [
            'longPrefix' => 'debug-line',
            'description' => 'Enable debug line',
            'required' => false,
            'defaultValue' => 0,
        ],
        'debug' => [
            'longPrefix' => 'debug',
            'description' => 'Enable debug mode (auto-disable optimizations, add debug symbols)',
            'required' => false,
            'noValue' => true,
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
        'sanitize' => [
            'longPrefix' => 'sanitize',
            'description' => 'Enable sanitizers (address, undefined, etc.) for debugging',
            'required' => false,
            'defaultValue' => '',
        ],
        'cxx-std' => [
            'longPrefix' => 'cxx-std',
            'description' => 'C++ standard version (c++17, c++20, etc.)',
            'required' => false,
            'defaultValue' => 'c++17',
        ],
    ];

    /**
     * MSVC 编译器警告屏蔽列表
     * 这些警告来自 Windows SDK 和 PHP SDK 头文件，都是编译器噪音，不影响功能
     *
     * @var array<string, string> 键为警告编号，值为说明
     */
    public const array MSVC_SUPPRESSED_WARNINGS = [
        '4244' => '类型转换可能丢失数据 (int -> smaller type)',
        '4242' => '类型转换可能丢失数据 (similar to C4244)',
        '4146' => '一元负运算符应用于无符号类型',
        '4820' => '结构体成员后有填充字节（内存对齐）',
        '4464' => '相对包含路径含 ".."',
        '4365' => '有符号/无符号转换',
        '4127' => '条件表达式是常量（如 while(1)）',
        '4668' => '未定义的宏当 0 处理（#ifdef __GNUC__）',
        '4626' => '赋值运算符被隐式删除（const 成员）',
        '5027' => '移动赋值运算符被隐式删除',
        '5219' => '隐式转换警告',
        '5220' => 'volatile 成员警告',
        '4100' => '未使用的参数',
        '5039' => '使用未定义的函数',
        '4101' => '未使用的局部变量',
        '4102' => '未引用的标签',
        '4800' => '从整数到布尔类型的隐式转换警告',
        '5045' => 'Spectre 缓解警告',
        '5264' => '未使用 const 变量',
        '5246' => '子对象的初始化应当包装在大括号内',
        '4388' => '有符号/无符号不匹配',
        '4623' => '已将默认构造函数隐式定义为“已删除”',
        '4611' => '_setjmp 和 C++ 对象析构之间的交互是不可移植的',
        '4574' => '使用了 #if 预处理器指令去检查一个被定义为 0 或 1 的宏',
    ];
}
