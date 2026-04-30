<?php

/**
 * Win32 Hello World - Using C++ helper functions
 * This is the simplest Windows GUI program example
 * 
 * Note: C++ functions are declared in cpp-src/winapi.stub.php and implemented in cpp-src/winapi.cc
 */

function main()
{
    // Set console to UTF-8 for proper Chinese character display
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        exec('chcp 65001 > nul 2>&1');
    }
    
    // Set timezone to China (UTC+8)
    date_default_timezone_set('Asia/Shanghai');
    
    echo "========================================\n";
    echo "  Win32 Hello World 程序\n";
    echo "========================================\n\n";
    
    // Method 1: Use message box (simplest)
    echo "显示消息框...\n";
    $result = messagebox(0, "Hello from PHP Compiler!\n\n这是一个使用 PHPX 编译器创建的 Windows 程序。\n\n当前时间: " . date('Y-m-d H:i:s'), "Hello World", 0);
    echo "消息框返回值: " . $result . "\n\n";
    
    // Method 2: Create window (requires more code)
    echo "提示：要创建完整窗口，需要实现窗口过程函数和消息循环。\n";
    echo "这需要在 C++ 层实现 WNDCLASS 注册和消息泵。\n\n";
    
    echo "程序结束。按任意键退出...\n";
}
