#!/bin/bash

# 打包脚本：创建包含指定文件的 zip 压缩包（先对二进制文件进行 UPX 压缩）

OUTPUT_FILE="swoole_compiler_package.zip"
BINARY_FILE="swoole_compiler"
BACKUP_FILE="${BINARY_FILE}.backup"

# 检查必要文件是否存在
REQUIRED_FILES=(
    "$BINARY_FILE"
    "composer.json"
    "composer.lock"
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

# 检查 upx 是否安装
if ! command -v upx &> /dev/null; then
    echo "错误: 未找到 upx 命令，请先安装 upx"
    echo "Ubuntu/Debian: sudo apt-get install upx-ucl"
    echo "CentOS/RHEL: sudo yum install upx"
    exit 1
fi

echo "检测到 upx: $(upx --version | head -n 1)"

# 备份原始二进制文件
echo "备份原始二进制文件..."
cp "$BINARY_FILE" "$BACKUP_FILE"

# 使用 UPX 压缩二进制文件
echo "使用 UPX 压缩 $BINARY_FILE ..."
upx --best "$BINARY_FILE"

if [ $? -ne 0 ]; then
    echo "✗ UPX 压缩失败！"
    # 恢复原始文件
    mv "$BACKUP_FILE" "$BINARY_FILE"
    exit 1
fi

echo "✓ UPX 压缩完成"
echo "  原始大小: $(du -h "$BACKUP_FILE" | cut -f1)"
echo "  压缩后: $(du -h "$BINARY_FILE" | cut -f1)"
echo "  压缩率: $(echo "scale=2; (1 - $(stat -c%s "$BINARY_FILE") / $(stat -c%s "$BACKUP_FILE")) * 100" | bc)%"

# 删除旧的压缩包（如果存在）
if [ -f "$OUTPUT_FILE" ]; then
    rm "$OUTPUT_FILE"
    echo "已删除旧的压缩包"
fi

# 创建 zip 压缩包
echo "创建压缩包: $OUTPUT_FILE"
zip "$OUTPUT_FILE" \
    swoole_compiler \
    composer.json \
    composer.lock \
    README.md \
    LICENSE.md \
    examples/hello.php

PACK_RESULT=$?

# 恢复原始二进制文件
echo "恢复原始二进制文件..."
mv "$BACKUP_FILE" "$BINARY_FILE"

if [ $PACK_RESULT -eq 0 ]; then
    echo "✓ 打包成功！"
    echo "压缩包大小: $(du -h "$OUTPUT_FILE" | cut -f1)"
    echo "包含文件:"
    unzip -l "$OUTPUT_FILE" | grep -E "\.php$|compiler$|\.json$|\.md$"
else
    echo "✗ 打包失败！"
    exit 1
fi
