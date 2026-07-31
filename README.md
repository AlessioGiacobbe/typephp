# 依赖
- 编译器需要 PHP 8.4 以上版本；生成的扩展仍可面向 PHP 8.2～8.5
- 需要 GCC-9 以上版本，支持 C++17 标准
- 需要 CMake-3.24 以上版本
- 需要高精度数学库：`GMP`、`MPFR`、`libmpdec`

# Composer 安装

在项目中安装 TypePHP：

```bash
composer require --dev swoole/typephp
```

安装后可直接编译项目：

```bash
vendor/bin/tpc.php project.yml
```

在 TypePHP 源码仓库中则使用：

```bash
bin/tpc.php project.yml
```

Linux 环境缺少 `libphp.so` 时，`tpc.php` 可以交互式下载 PHP 源码并自动构建，详见 [自动构建 libphp.so](docs/LIBPHP_INSTALLER.md)。

```shell
# Ubuntu/Debian
sudo apt install libgmp-dev libmpfr-dev libmpdec-dev

# RHEL/CentOS/Fedora
sudo dnf install gmp-devel mpfr-devel libmpdec-devel

# Arch Linux
sudo pacman -S gmp mpfr mpdecimal
```

> GMP 用于 `BigInt` 任意精度整数，MPFR 用于 `BigFloat` 高精度浮点数，libmpdec 用于 `Decimal` 十进制高精度小数。

> 预览版目前仅支持 `Linux` 系统，建议使用 `Ubuntu 22.04`

## PHP
必须包含 embed 模块

## PHPX
可使用 `composer install` 安装依赖。
进入 `vendor/swoole/phpx` 目录，编译 `phpx`

```shell
cd vendor/swoole/phpx
cmake .
make -j32
```

## 动态链接库
```shell
sudo ldconfig -p | grep php
```
必须包含 `libphp.so` 和 `libphpx.so`

若编译完成，但找不到动态链接库，需要修改
```shell
vim /etc/ld.so.conf.d/swoole.conf 
```

添加路径
```
/home/swoole/workspace/projects/phpx/lib
/opt/php-8.4/lib/
```

## Release packaging

Use the same PHP entry point on Windows, Linux, and macOS:

```shell
php package.php
```

Windows packaging requires `PHP_HOME` and `PHPX_HOME`; Linux packaging requires
UPX; macOS uses `strip` when available. TypePHP rejects 32-bit targets and
supports common 64-bit CPU architectures, including x86-64 and ARM64.
