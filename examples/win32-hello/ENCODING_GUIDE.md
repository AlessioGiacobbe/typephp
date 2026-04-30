# Encoding Guide for PHPX Compiler

## Problem

When using Chinese characters in source code, you may encounter garbled text (乱码) like:
```
Win32 Hello World 绋嬪簭
鏄剧ず娑堟伅妗?..
```

This happens because:
1. Windows console uses code page 936 (GBK) by default
2. Source files are saved as UTF-8
3. The mismatch causes encoding issues

## Solution

### Option 1: Use English (Recommended) ✅

All example files now use English to avoid encoding issues:

**Before:**
```php
echo "显示消息框...\n";
```

**After:**
```php
echo "Showing message box...\n";
```

### Option 2: Set Console to UTF-8

If you must use Chinese, set the console code page to UTF-8 before running:

```powershell
chcp 65001
.\win32_hello.exe
```

Or in your PHP code, set it programmatically:

```php
function main() {
    // Set console to UTF-8
    exec('chcp 65001 > nul');
    
    echo "显示消息框...\n";
}
```

### Option 3: Use Windows API for Unicode

For message boxes and Windows GUI, use wide character functions:

```cpp
// In C++ file
Int php_messagebox(Int hWnd, String text, String caption, Int uType) {
    // Convert UTF-8 to UTF-16 for Windows API
    int wtext_len = MultiByteToWideChar(CP_UTF8, 0, text.data(), -1, NULL, 0);
    wchar_t* wtext = new wchar_t[wtext_len];
    MultiByteToWideChar(CP_UTF8, 0, text.data(), -1, wtext, wtext_len);
    
    int wcaption_len = MultiByteToWideChar(CP_UTF8, 0, caption.data(), -1, NULL, 0);
    wchar_t* wcaption = new wchar_t[wcaption_len];
    MultiByteToWideChar(CP_UTF8, 0, caption.data(), -1, wcaption, wcaption_len);
    
    int result = MessageBoxW((HWND)hWnd, wtext, wcaption, (UINT)uType);
    
    delete[] wtext;
    delete[] wcaption;
    return result;
}
```

## Best Practices

1. **Use English for code comments and strings** - Most portable solution
2. **Save all files as UTF-8 without BOM** - Standard for modern development
3. **Avoid mixing encodings** - Keep consistency across all files
4. **Test on target systems** - Different Windows versions may have different defaults

## Current Status

All files in `examples/win32-hello/` now use English:
- ✅ hello-win.php
- ✅ main.php  
- ✅ window.php
- ✅ cpp-src/winapi.cc
- ✅ cpp-src/winapi.stub.php

Rebuild to see the changes:

```powershell
php bin\compiler.php examples\win32-hello\project.yml
.\build\win32_hello.exe
```

Expected output:
```
========================================
  Win32 Hello World Program
========================================

Showing message box...
Message box return value: 1

Note: To create a full window, you need to implement window procedure and message loop.
This requires WNDCLASS registration and message pump in C++ layer.

Program ended. Press any key to exit...
```
