# 依赖
- 需要 PHP-8.2 以上版本
- 需要 GCC-9 以上版本，支持 C++17 标准
- 需要 CMake-3.24 以上版本

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