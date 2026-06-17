#!/bin/bash

# 打包脚本：创建包含指定文件的 zip 压缩包
# Linux 系统下使用 UPX 压缩二进制文件，macOS 系统使用 strip 删除调试符号
# 支持版本管理，每次打包版本号自动递增
# 输出文件名格式：swoole_compiler_v{版本}_{操作系统}_{架构}.zip

BINARY_FILE="swoole_compiler"
VERSION_FILE="version.txt"
BACKUP_FILE="${BINARY_FILE}.backup"

# 读取或初始化版本号
if [ -f "$VERSION_FILE" ]; then
    VERSION_ID=$(cat "$VERSION_FILE")
else
    VERSION_ID=1000
fi

# 版本号递增
VERSION_ID=$((VERSION_ID + 1))
echo "$VERSION_ID" > "$VERSION_FILE"

echo "当前版本: $VERSION_ID"

# 生成带版本号的二进制文件名
VERSIONED_BINARY="${BINARY_FILE}_v${VERSION_ID}"

# 检查必要文件是否存在
REQUIRED_FILES=(
    "$BINARY_FILE"
    "composer.json"
    "README.md"
    "LICENSE.md"
    "examples/hello.php"
)

echo "检查文件..."
for file in "${REQUIRED_FILES[@]}"; do
    if [ ! -e "$file" ]; then
        echo "错误: 文件不存在 - $file"
        exit 1
    fi
done

echo "所有文件检查通过"

# 复制二进制文件为带版本号的名称
echo "创建版本化二进制文件: $VERSIONED_BINARY"
cp "$BINARY_FILE" "$VERSIONED_BINARY"

# 检测操作系统
detect_os() {
    case "$(uname -s)" in
        Darwin*)
            echo "macos"
            ;;
        Linux*)
            echo "linux"
            ;;
        *)
            echo "unknown"
            ;;
    esac
}

# 检测硬件架构
detect_arch() {
    case "$(uname -m)" in
        x86_64|amd64)
            echo "x86_64"
            ;;
        aarch64|arm64)
            echo "arm64"
            ;;
        armv7l|armv7)
            echo "armv7"
            ;;
        i386|i686)
            echo "i386"
            ;;
        *)
            echo "$(uname -m)"
            ;;
    esac
}

OS_TYPE=$(detect_os)
ARCH_TYPE=$(detect_arch)
echo "检测到操作系统: $OS_TYPE"
echo "检测到硬件架构: $ARCH_TYPE"

# 生成带版本号、操作系统和架构的输出文件名
OUTPUT_FILE="swoole_compiler_v${VERSION_ID}_${OS_TYPE}_${ARCH_TYPE}.zip"

echo "输出文件: $OUTPUT_FILE"

# 检查 upx 是否安装（仅 Linux 需要）
if [ "$OS_TYPE" = "linux" ]; then
    if ! command -v upx &> /dev/null; then
        echo "错误: 未找到 upx 命令，请先安装 upx"
        echo "Ubuntu/Debian: sudo apt-get install upx-ucl"
        echo "CentOS/RHEL: sudo yum install upx"
        # 清理临时文件
        rm -f "$VERSIONED_BINARY"
        exit 1
    fi
    
    echo "检测到 upx: $(upx --version | head -n 1)"
fi

# 根据操作系统决定使用不同的优化方式
if [ "$OS_TYPE" = "linux" ]; then
    # 备份原始二进制文件
    echo "备份原始二进制文件..."
    cp "$BINARY_FILE" "$BACKUP_FILE"

    # 使用 UPX 压缩版本化二进制文件
    echo "使用 UPX 压缩 $VERSIONED_BINARY ..."
    upx --best "$VERSIONED_BINARY"

    if [ $? -ne 0 ]; then
        echo "✗ UPX 压缩失败！"
        # 恢复原始文件并清理临时文件
        mv "$BACKUP_FILE" "$BINARY_FILE"
        rm -f "$VERSIONED_BINARY"
        exit 1
    fi

    echo "✓ UPX 压缩完成"
    echo "  原始大小: $(du -h "$BACKUP_FILE" | cut -f1)"
    echo "  压缩后: $(du -h "$VERSIONED_BINARY" | cut -f1)"
    
    # 根据操作系统使用不同的 stat 命令
    if [ "$OS_TYPE" = "linux" ]; then
        ORIGINAL_SIZE=$(stat -c%s "$BACKUP_FILE")
        COMPRESSED_SIZE=$(stat -c%s "$VERSIONED_BINARY")
    else
        ORIGINAL_SIZE=$(stat -f%z "$BACKUP_FILE")
        COMPRESSED_SIZE=$(stat -f%z "$VERSIONED_BINARY")
    fi
    
    COMPRESSION_RATIO=$(echo "scale=2; (1 - $COMPRESSED_SIZE / $ORIGINAL_SIZE) * 100" | bc)
    echo "  压缩率: ${COMPRESSION_RATIO}%"
else
    echo "macOS 系统，使用 strip 删除调试符号..."
    
    # 备份原始二进制文件
    echo "备份原始二进制文件..."
    cp "$BINARY_FILE" "$BACKUP_FILE"
    
    # 检查 strip 是否可用
    if ! command -v strip &> /dev/null; then
        echo "警告: 未找到 strip 命令，跳过符号剥离"
    else
        # 使用 strip 删除调试符号
        echo "使用 strip 处理 $VERSIONED_BINARY ..."
        strip -x "$VERSIONED_BINARY"
        
        if [ $? -ne 0 ]; then
            echo "✗ strip 处理失败！"
            # 恢复原始文件并清理临时文件
            mv "$BACKUP_FILE" "$BINARY_FILE"
            rm -f "$VERSIONED_BINARY"
            exit 1
        fi
        
        echo "✓ strip 处理完成"
        echo "  原始大小: $(du -h "$BACKUP_FILE" | awk '{print $1}')"
        echo "  处理后: $(du -h "$VERSIONED_BINARY" | awk '{print $1}')"
        
        # 计算大小变化
        ORIGINAL_SIZE=$(stat -f%z "$BACKUP_FILE")
        STRIPPED_SIZE=$(stat -f%z "$VERSIONED_BINARY")
        
        if [ $ORIGINAL_SIZE -gt 0 ]; then
            REDUCTION_RATIO=$(echo "scale=2; (1 - $STRIPPED_SIZE / $ORIGINAL_SIZE) * 100" | bc)
            echo "  减小率: ${REDUCTION_RATIO}%"
        fi
    fi
fi

# 删除旧的压缩包（如果存在）
if [ -f "$OUTPUT_FILE" ]; then
    rm "$OUTPUT_FILE"
    echo "已删除旧的压缩包"
fi

# 创建 zip 压缩包（使用带版本号的二进制文件）
echo "创建压缩包: $OUTPUT_FILE"
zip "$OUTPUT_FILE" \
    "$VERSIONED_BINARY" \
    composer.json \
    README.md \
    LICENSE.md \
    examples/hello.php

PACK_RESULT=$?

# 恢复原始二进制文件并清理临时文件
echo "恢复原始二进制文件..."
mv "$BACKUP_FILE" "$BINARY_FILE"
rm -f "$VERSIONED_BINARY"

if [ $PACK_RESULT -eq 0 ]; then
    echo "✓ 打包成功！"
    echo "版本号: $VERSION_ID"
    
    # 根据操作系统使用不同的 du 命令
    if [ "$OS_TYPE" = "linux" ]; then
        PACKAGE_SIZE=$(du -h "$OUTPUT_FILE" | cut -f1)
    else
        PACKAGE_SIZE=$(du -h "$OUTPUT_FILE" | awk '{print $1}')
    fi
    
    echo "压缩包大小: $PACKAGE_SIZE"
    echo "包含文件:"
    unzip -l "$OUTPUT_FILE" | grep -E "\.php$|compiler|\.json$|\.md$"
else
    echo "✗ 打包失败！"
    exit 1
fi
