<?php

/**
 * Win32 Window Hello World Example
 * Demonstrates how to create Windows GUI programs with PHPX compiler
 */

// Declare external C functions (Windows API)
#[NativeFunction]
function CreateWindowEx(
    int $dwExStyle,
    string $lpClassName,
    string $lpWindowName,
    int $dwStyle,
    int $x,
    int $y,
    int $nWidth,
    int $nHeight,
    int $hWndParent,
    int $hMenu,
    int $hInstance,
    int $lpParam
): int {}

#[NativeFunction]
function ShowWindow(int $hWnd, int $nCmdShow): bool {}

#[NativeFunction]
function UpdateWindow(int $hWnd): bool {}

#[NativeFunction]
function GetMessage(array &$lpMsg, int $hWnd, int $wMsgFilterMin, int $wMsgFilterMax): int {}

#[NativeFunction]
function TranslateMessage(array $lpMsg): int {}

#[NativeFunction]
function DispatchMessage(array $lpMsg): int {}

#[NativeFunction]
function DefWindowProc(int $hWnd, int $Msg, int $wParam, int $lParam): int {}

#[NativeFunction]
function PostQuitMessage(int $nExitCode): void {}

#[NativeFunction]
function MessageBox(int $hWnd, string $lpText, string $lpCaption, int $uType): int {}

function main()
{
    // Set console to UTF-8 for proper Chinese character display
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        exec('chcp 65001 > nul 2>&1');
    }
    
    // Set timezone to China (UTC+8)
    date_default_timezone_set('Asia/Shanghai');
    
    // Show a simple message box
    $result = MessageBox(0, "Hello from PHP Compiler!\n\n这是一个使用 PHPX 编译器创建的 Windows 程序。", "Hello World", 0);
    
    echo "消息框返回值: " . $result . "\n";
    echo "程序结束。\n";
}
