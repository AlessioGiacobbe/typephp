#!/usr/bin/env python3
"""
使用 libclang 解析 C++ 代码并添加头文件路径
"""

import clang.cindex
from clang.cindex import Index, CursorKind, TypeKind, StorageClass
import json
import sys
import os
from pathlib import Path
import subprocess
import argparse


class PHPConfigHelper:
    """PHP 配置辅助类"""

    def __init__(self, php_config_path='php-config'):
        self.php_config = php_config_path
        self._check_availability()

    def _check_availability(self):
        """检查 php-config 是否可用"""
        try:
            result = subprocess.run(
                [self.php_config, '--version'],
                capture_output=True,
                text=True,
                check=True
            )
            print(f"✓ 找到 PHP {result.stdout.strip()}")
        except (FileNotFoundError, subprocess.CalledProcessError) as e:
            print(f"警告: php-config 不可用: {e}")
            # 尝试使用常见路径
            common_paths = [
                '/usr/bin/php-config',
                '/usr/local/bin/php-config',
                '/opt/php/bin/php-config'
            ]
            for path in common_paths:
                if os.path.exists(path):
                    self.php_config = path
                    try:
                        result = subprocess.run(
                            [self.php_config, '--version'],
                            capture_output=True,
                            text=True,
                            check=True
                        )
                        print(f"✓ 找到 PHP {result.stdout.strip()} 在 {path}")
                        return
                    except (FileNotFoundError, subprocess.CalledProcessError):
                        continue
            print("警告: php-config 在任何常见路径都不可用，使用默认路径")

    def get_includes(self):
        """
        获取 include 路径列表

        Returns:
            list: 头文件路径列表（不带 -I 前缀）
        """
        try:
            result = subprocess.run(
                [self.php_config, '--includes'],
                capture_output=True,
                text=True,
                check=True
            )

            # 解析输出: "-I/path1 -I/path2" -> ['/path1', '/path2']
            includes = []
            for flag in result.stdout.strip().split():
                if flag.startswith('-I'):
                    includes.append(flag[2:])

            return includes
        except (subprocess.CalledProcessError, FileNotFoundError):
            print("警告: 无法获取 PHP includes，使用默认路径")
            return ['/usr/include/php', '/usr/include/php/20210902']  # 默认路径

    def get_include_dir(self):
        """获取主 include 目录"""
        try:
            result = subprocess.run(
                [self.php_config, '--include-dir'],
                capture_output=True,
                text=True,
                check=True
            )
            return result.stdout.strip()
        except (subprocess.CalledProcessError, FileNotFoundError):
            return '/usr/include/php'

    def get_extension_dir(self):
        """获取扩展目录"""
        try:
            result = subprocess.run(
                [self.php_config, '--extension-dir'],
                capture_output=True,
                text=True,
                check=True
            )
            return result.stdout.strip()
        except (subprocess.CalledProcessError, FileNotFoundError):
            return '/usr/lib/php'

    def get_version(self):
        """获取 PHP 版本"""
        try:
            result = subprocess.run(
                [self.php_config, '--version'],
                capture_output=True,
                text=True,
                check=True
            )
            return result.stdout.strip()
        except (subprocess.CalledProcessError, FileNotFoundError):
            return 'unknown'

    def get_php_binary(self):
        """获取 PHP 二进制路径"""
        try:
            result = subprocess.run(
                [self.php_config, '--php-binary'],
                capture_output=True,
                text=True,
                check=True
            )
            return result.stdout.strip()
        except (subprocess.CalledProcessError, FileNotFoundError):
            return 'php'

    def get_configure_options(self):
        """获取配置选项"""
        try:
            result = subprocess.run(
                [self.php_config, '--configure-options'],
                capture_output=True,
                text=True,
                check=True
            )
            return result.stdout.strip()
        except (subprocess.CalledProcessError, FileNotFoundError):
            return ''

    def get_all_info(self):
        """获取所有配置信息"""
        return {
            'version': self.get_version(),
            'includes': self.get_includes(),
            'include_dir': self.get_include_dir(),
            'extension_dir': self.get_extension_dir(),
            'php_binary': self.get_php_binary(),
            'configure_options': self.get_configure_options(),
        }


class ClangParser:
    def __init__(self, libclang_path=None):
        """
        初始化 Clang 解析器

        Args:
            libclang_path: libclang 库的路径（可选）
        """
        if libclang_path:
            try:
                clang.cindex.Config.set_library_file(libclang_path)
            except Exception as e:
                print(f"警告: 无法设置 libclang 路径 {libclang_path}: {e}")
                print("尝试使用默认路径...")

        try:
            self.index = Index.create()
        except Exception as e:
            print(f"错误: 无法创建 Clang 索引: {e}")
            print("请确保已安装 python3-clang 和 clang 库")
            raise

    def parse_file(self, filename, include_paths=None, defines=None,
                   compiler_args=None, language='c++'):
        """
        解析 C++ 文件

        Args:
            filename: 要解析的文件路径
            include_paths: 头文件搜索路径列表
            defines: 宏定义列表 ['MACRO=value', 'DEBUG']
            compiler_args: 额外的编译器参数
            language: 语言类型 ('c', 'c++', 'objective-c')

        Returns:
            TranslationUnit 对象
        """
        if not os.path.exists(filename):
            raise FileNotFoundError(f"文件不存在: {filename}")

        args = []

        # 1. 设置语言标准
        if language == 'c++':
            args.extend([
                '-x', 'c++',
                '-std=c++14',  # 更标准的 C++ 版本
            ])
        elif language == 'c':
            args.extend(['-x', 'c', '-std=c11'])

        # 2. 添加头文件搜索路径
        if include_paths:
            for path in include_paths:
                if os.path.exists(path):  # 检查路径是否存在
                    args.append(f'-I{path}')
                else:
                    print(f"警告: 包含路径不存在: {path}")

        # 3. 添加宏定义
        if defines:
            for define in defines:
                args.append(f'-D{define}')

        # 4. 添加额外的编译器参数
        if compiler_args:
            args.extend(compiler_args)

        # 5. 常用的编译选项
        args.extend([
            '-Wno-pragma-once-outside-header',  # 忽略警告
            '-ferror-limit=0',  # 不限制错误数量
            '-fno-delayed-template-parsing', # 避免某些 C++ 模板解析问题
            '-w', # 禁用所有警告以减少输出
        ])

        print(f"编译参数: {' '.join(args)}")

        # 解析文件
        try:
            tu = self.index.parse(
                filename,
                args=args,
                options=clang.cindex.TranslationUnit.PARSE_DETAILED_PROCESSING_RECORD
            )
        except Exception as e:
            print(f"解析文件时出错: {e}")
            print("尝试使用最小参数集...")
            # 尝试使用最小参数集
            minimal_args = ['-x', 'c++', '-std=c++14', '-w']
            if include_paths:
                for path in include_paths:
                    if os.path.exists(path):
                        minimal_args.append(f'-I{path}')
            try:
                tu = self.index.parse(
                    filename,
                    args=minimal_args,
                    options=clang.cindex.TranslationUnit.PARSE_DETAILED_PROCESSING_RECORD
                )
                print("使用最小参数集成功解析")
            except Exception as e2:
                print(f"使用最小参数集也失败: {e2}")
                print("尝试解析不包含头文件的简化版本...")
                # 创建一个临时文件，移除头文件包含行
                temp_filename = filename + ".tmp"
                with open(filename, 'r') as original:
                    lines = original.readlines()
                
                # 移除 #include 行
                filtered_lines = [line for line in lines if not line.strip().startswith('#include')]
                
                with open(temp_filename, 'w') as temp:
                    temp.writelines(filtered_lines)
                
                try:
                    tu = self.index.parse(
                        temp_filename,
                        args=minimal_args,
                        options=clang.cindex.TranslationUnit.PARSE_DETAILED_PROCESSING_RECORD
                    )
                    print("解析简化版本成功")
                    # 清理临时文件
                    os.remove(temp_filename)
                except Exception as e3:
                    print(f"简化版本也失败: {e3}")
                    # 清理临时文件
                    if os.path.exists(temp_filename):
                        os.remove(temp_filename)
                    raise

        # 检查诊断信息
        if tu.diagnostics:
            print(f"\n诊断信息 ({len(tu.diagnostics)} 个):")
            error_count = 0
            warning_count = 0
            
            for diag in tu.diagnostics:
                if diag.severity >= 3:  # 错误级别
                    error_count += 1
                else:  # 警告级别
                    warning_count += 1
                    
            print(f"错误: {error_count}, 警告: {warning_count}")
            
            # 只显示前几个诊断信息，避免输出过多
            for i, diag in enumerate(tu.diagnostics):
                if i >= 5:  # 只显示前5个
                    print("... 还有更多诊断信息")
                    break
                print(f"  [{diag.severity}] {diag.spelling}")
                if diag.location.file:
                    print(f"    at {diag.location.file.name}:{diag.location.line}")

        return tu

    def extract_functions(self, tu, name_prefixes=None):
        """
        提取函数定义

        Args:
            tu: TranslationUnit 对象
            name_prefixes: 函数名前缀过滤列表

        Returns:
            函数信息列表
        """
        functions = []

        def visit_node(node, depth=0):
            # 只处理函数声明/定义
            if node.kind == CursorKind.FUNCTION_DECL:
                try:
                    func_info = self.parse_function(node)

                    # 过滤函数名
                    if name_prefixes:
                        if any(func_info['name'].startswith(prefix)
                              for prefix in name_prefixes):
                            functions.append(func_info)
                    else:
                        functions.append(func_info)
                except Exception as e:
                    print(f"解析函数时出错: {e}")

            # 递归访问子节点
            for child in node.get_children():
                visit_node(child, depth + 1)

        visit_node(tu.cursor)
        return functions

    def parse_function(self, cursor):
        """
        解析函数详细信息
        """
        # 检查方法是否存在
        def safe_call(method, default_value=None):
            try:
                return method()
            except AttributeError:
                return default_value

        # 基本信息
        func_info = {
            'name': cursor.spelling,
            'displayName': cursor.displayname,
            'mangledName': cursor.mangled_name,
            'returnType': cursor.result_type.spelling,
            'isStatic': cursor.storage_class == StorageClass.STATIC,
            'isInline': safe_call(lambda: cursor.is_inline_function(), False),
            'isVirtual': safe_call(lambda: cursor.is_virtual_method(), False),
            'isConst': safe_call(lambda: cursor.is_const_method(), False),
            'location': {
                'file': str(cursor.location.file) if cursor.location.file else None,
                'line': cursor.location.line,
                'column': cursor.location.column,
            },
            'parameters': [],
            'namespaces': self.get_namespaces(cursor),
        }

        # 解析参数
        for arg in cursor.get_arguments():
            param_info = {
                'name': arg.spelling or f'arg{len(func_info["parameters"])}',
                'type': arg.type.spelling,
                'canonicalType': arg.type.get_canonical().spelling,
            }

            # 检查是否有默认值
            try:
                for token in arg.get_tokens():
                    if token.spelling == '=':
                        # 有默认值
                        param_info['hasDefault'] = True
                        break
            except:
                # 如果无法获取 tokens，跳过默认值检查
                pass

            func_info['parameters'].append(param_info)

        return func_info

    def get_namespaces(self, cursor):
        """
        获取函数所在的命名空间
        """
        namespaces = []
        parent = cursor.semantic_parent

        while parent and parent.kind != CursorKind.TRANSLATION_UNIT:
            if parent.kind == CursorKind.NAMESPACE:
                namespaces.insert(0, parent.spelling)
            parent = parent.semantic_parent

        return namespaces


def main():
    parser = argparse.ArgumentParser(description='使用 libclang 解析 C++ 代码并提取函数信息')
    parser.add_argument('filename', help='要解析的 C++ 文件路径')
    parser.add_argument('--libclang-path', help='libclang 库路径')
    parser.add_argument('--include-paths', nargs='*', help='额外的包含路径')
    parser.add_argument('--function-prefixes', nargs='*', help='函数名前缀过滤器')
    
    args = parser.parse_args()

    if not os.path.exists(args.filename):
        print(f"错误: 文件不存在: {args.filename}")
        sys.exit(1)

    try:
        # 创建解析器
        parser_obj = ClangParser(libclang_path=args.libclang_path)

        # 配置头文件路径
        include_paths = args.include_paths or [
            "/usr/include/linux",
            "/home/swoole/workspace/projects/phpx/include"
        ]
        
        # 尝试获取 PHP 配置的头文件路径
        try:
            php_config = PHPConfigHelper()
            php_includes = php_config.get_includes()
            include_paths.extend(php_includes)
        except Exception as e:
            print(f"警告: 无法获取 PHP 配置: {e}")
            print("继续使用默认路径...")

        # 配置宏定义
        defines = [
            'HAVE_CONFIG_H',
            'ZEND_ENABLE_STATIC_TSRMLS_CACHE=1',
        ]

        # 额外的编译器参数
        compiler_args = [
            '-fparse-all-comments',  # 解析所有注释
            '-Wno-unknown-pragmas',
        ]

        # 解析文件
        tu = parser_obj.parse_file(
            args.filename,
            include_paths=include_paths,
            defines=defines,
            compiler_args=compiler_args,
            language='c++'
        )

        # 提取函数
        name_prefixes = args.function_prefixes or None
        functions = parser_obj.extract_functions(tu, name_prefixes=name_prefixes)

        # 输出结果
        output = {
            'file': args.filename,
            'functions': functions,
            'total': len(functions),
        }

        print(json.dumps(output, indent=2, ensure_ascii=False))

    except clang.cindex.TranslationUnitLoadError as e:
        print(f"翻译单元加载错误: {e}")
        print("这通常意味着 C++ 代码包含语法错误或缺少必要的头文件")
        sys.exit(1)
    except Exception as e:
        print(f"错误: {e}")
        sys.exit(1)

if __name__ == '__main__':
    main()