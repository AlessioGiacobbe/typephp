# PHP AOT 编译器快速入门指南

## 🚀 5 分钟快速开始

本指南将帮助您在 5 分钟内开始使用 PHP AOT 编译器。

---

## 📦 前置要求

### 系统要求
- **操作系统**: Linux (推荐 Ubuntu 20.04+)
- **PHP 版本**: PHP 8.0+
- **编译器**: GCC 9.0+ 或 Clang 10.0+
- **内存**: 至少 2GB RAM
- **磁盘空间**: 至少 500MB

### 依赖安装

```bash
# Ubuntu/Debian
sudo apt-get update
sudo apt-get install -y build-essential php-cli php-dev clang-format

# CentOS/RHEL
sudo yum install -y gcc gcc-c++ php php-devel clang-tools-extra
```

---

## 🔧 安装步骤

### 1. 克隆项目

```bash
git clone https://github.com/your-org/php-aot-compiler.git
cd php-aot-compiler
```

### 2. 安装 Composer 依赖

```bash
composer install
```

### 3. 验证安装

```bash
php bin/compiler.php --help
```

如果看到帮助信息，说明安装成功！

---

## 🎯 基本使用

### 示例项目结构

假设我们有一个简单的 PHP 项目：

```
my-project/
├── src/
│   ├── Calculator.php
│   └── main.php
```

**Calculator.php**:
```php
<?php
class Calculator {
    public function add($a, $b) {
        return $a + $b;
    }
    
    public function multiply($a, $b) {
        return $a * $b;
    }
}
```

**main.php**:
```php
<?php
require_once 'Calculator.php';

function main() {
    $calc = new Calculator();
    
    echo "5 + 3 = " . $calc->add(5, 3) . "\n";
    echo "4 * 7 = " . $calc->multiply(4, 7) . "\n";
}
```

---

## 📝 编译模式

### 模式一：二进制可执行文件（推荐新手）

#### 编译命令

```bash
php bin/compiler.php my-project/src/ -o my-app
```

#### 运行程序

```bash
./my-app
```

#### 输出

```
5 + 3 = 8
4 * 7 = 28
```

✅ **优点**:
- 独立运行，无需 PHP 环境
- 部署简单
- 性能更好

⚠️ **注意**: 必须包含 `main()` 函数

---

### 模式二：PHP 扩展

#### 编译命令

```bash
php bin/compiler.php my-project/src/ --mode=ext -o calculator
```

#### 安装扩展

```bash
# 复制 .so 文件到 PHP 扩展目录
sudo cp calculator.so $(php-config --extension-dir)/

# 在 php.ini 中添加
echo "extension=calculator" | sudo tee /etc/php/8.1/cli/conf.d/30-calculator.ini
```

#### 使用扩展

```bash
php -m | grep calculator  # 验证扩展已加载
```

✅ **优点**:
- 与现有 PHP 项目集成
- 可以在 php-fpm 中使用
- 适合 Web 应用

⚠️ **注意**: 不需要 `main()` 函数

---

## 🎨 代码规范

### ✅ 正确的代码结构

```php
<?php
// 类和函数定义（允许在全局）
class MyClass {
    public function doSomething() {
        return "Something";
    }
}

function helperFunction() {
    return "Helper";
}

const MY_CONSTANT = 'value';

// 可执行代码必须在 main() 函数中
function main() {
    $obj = new MyClass();
    echo $obj->doSomething();
    echo helperFunction();
    echo MY_CONSTANT;
}
```

### ❌ 错误的代码结构

```php
<?php
// ❌ 游离的可执行代码（不允许）
echo "Hello World";  // 错误！

some_function_call();  // 错误！

for ($i = 0; $i < 10; $i++) {  // 错误！
    echo $i;
}
```

---

## 🧪 运行测试

### 运行单个测试

```bash
php run-tests.php --no-aot tests/aot/arrow-functions.phpt
```

### 运行所有测试

```bash
php run-tests.php --no-aot tests/aot/
```

### 查看测试结果

```
PASS Arrow Functions - PHP 8.1+ short closure syntax
FAIL Some test
=====================================================================
Number of tests :   100                100
Tests passed    :    95 ( 95.0%)
Tests failed    :     5 (  5.0%)
```

---

## 💡 最佳实践

### 1. 项目组织

```
project/
├── src/              # 源代码
│   ├── Classes/      # 类文件
│   ├── Functions/    # 函数库
│   └── main.php      # 入口文件
├── tests/            # 测试文件
└── build/            # 编译输出
```

### 2. 命名约定

- 文件名使用小写，单词间用下划线分隔：`my_class.php`
- 类名使用帕斯卡命名法：`MyClass`
- 函数名使用驼峰命名法：`myFunction`

### 3. 性能提示

- 避免不必要的对象创建
- 使用标量类型声明
- 减少全局变量使用
- 优先使用数组而非对象集合

---

## 🔍 常见问题

### Q: 编译失败，提示 "Not implemented"
**A**: 该语法特性暂不支持。查看 [语法支持规范](UNSUPPORTED_SYNTAX.md) 了解支持的语法。

### Q: 如何调试编译后的程序？
**A**: 使用 `--keep-all` 选项保留中间文件，查看生成的 C++ 代码。

```bash
php bin/compiler.php src/ -o app --keep-all
```

### Q: 编译速度慢怎么办？
**A**: 使用并行编译选项 `-j`：

```bash
php bin/compiler.php src/ -o app -j4  # 使用 4 个进程
```

---

## 📚 下一步

完成快速入门后，建议阅读：

1. **[语法支持规范](UNSUPPORTED_SYNTAX.md)** - 详细了解支持的语法
2. **[编译模式详解](COMPILATION_MODES.md)** - 深入了解两种编译模式
3. **[性能优化指南](PERFORMANCE.md)** - 提升程序性能

---

## 🆘 获取帮助

- 📖 查看完整文档：[docs/](.)
- 🐛 报告问题：[GitHub Issues]
- 💬 社区讨论：[论坛/聊天室链接]

---

**祝您使用愉快！** 🎉
