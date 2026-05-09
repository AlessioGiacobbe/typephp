/**
 * SVG → ICO 转换脚本 (Node.js)
 * 
 * 使用 sharp 库将 SVG 渲染为多尺寸 PNG，再合并为 ICO
 * 
 * 用法: node svg-to-ico.js <input.svg> [output.ico]
 */

const sharp = require('sharp');
const fs = require('fs');
const path = require('path');

const sizes = [16, 32, 48, 64, 128, 256];

async function main() {
    const args = process.argv.slice(2);
    if (args.length < 1) {
        console.log('用法: node svg-to-ico.js <input.svg> [output.ico]');
        process.exit(1);
    }

    const inputSvg = path.resolve(args[0]);
    const outputIco = args.length >= 2 
        ? path.resolve(args[1]) 
        : path.join(path.dirname(inputSvg), path.basename(inputSvg, '.svg') + '.ico');

    if (!fs.existsSync(inputSvg)) {
        console.log(`错误: SVG 文件不存在: ${inputSvg}`);
        process.exit(1);
    }

    console.log('=== SVG → ICO 转换工具 ===\n');
    console.log(`输入: ${inputSvg}`);
    console.log(`输出: ${outputIco}\n`);

    const svgBuffer = fs.readFileSync(inputSvg);

    // 步骤1: 渲染 SVG 为多个尺寸的 PNG
    const pngBuffers = {};
    for (const size of sizes) {
        console.log(`[1/2] 渲染 SVG → PNG (${size}x${size})...`);
        try {
            const pngBuffer = await sharp(svgBuffer, { density: 300 })
                .resize(size, size, { fit: 'contain', background: { r: 255, g: 255, b: 255, alpha: 1 } })
                .png()
                .toBuffer();
            pngBuffers[size] = pngBuffer;
            console.log(`  完成: ${size}x${size} (${pngBuffer.length} bytes)`);
        } catch (err) {
            console.log(`  警告: ${size}x${size} PNG 生成失败: ${err.message}，跳过`);
        }
    }

    const validSizes = Object.keys(pngBuffers).map(Number).sort((a, b) => a - b);
    if (validSizes.length === 0) {
        console.log('\n错误: 所有尺寸的 PNG 都生成失败');
        process.exit(1);
    }

    // 步骤2: 合并为 ICO
    console.log('\n[2/2] 合并 PNG → ICO...');
    const icoBuffer = createIcoFromPngs(pngBuffers, validSizes);
    fs.writeFileSync(outputIco, icoBuffer);

    console.log(`\n成功! ICO 文件已生成: ${outputIco}`);
    console.log(`包含尺寸: ${validSizes.join(', ')}`);
    console.log(`文件大小: ${icoBuffer.length} bytes`);
}

/**
 * 将多个 PNG buffer 合并为 ICO 格式的 Buffer
 */
function createIcoFromPngs(pngBuffers, sizes) {
    const imageCount = sizes.length;
    
    // 计算各部分大小
    const headerSize = 6;           // ICO 头部
    const dirEntrySize = 16;        // 每个目录条目
    const dirSize = dirEntrySize * imageCount;
    
    let dataOffset = headerSize + dirSize;
    
    // 构建目录条目
    const dirEntries = [];
    for (const size of sizes) {
        const pngData = pngBuffers[size];
        const dataSize = pngData.length;
        
        // Width/Height: 0 表示 256
        const w = size >= 256 ? 0 : size;
        const h = size >= 256 ? 0 : size;
        
        dirEntries.push({
            width: w,
            height: h,
            colorCount: 0,
            reserved: 0,
            planes: 1,
            bitCount: 32,
            dataSize: dataSize,
            offset: dataOffset
        });
        
        dataOffset += dataSize;
    }
    
    // 计算总大小
    let totalSize = headerSize + dirSize;
    for (const size of sizes) {
        totalSize += pngBuffers[size].length;
    }
    
    // 构建 ICO 二进制数据
    const buffer = Buffer.alloc(totalSize);
    let pos = 0;
    
    // ICO 头部 (6 bytes)
    buffer.writeUInt16LE(0, pos); pos += 2;      // Reserved
    buffer.writeUInt16LE(1, pos); pos += 2;      // Type: 1 = ICO
    buffer.writeUInt16LE(imageCount, pos); pos += 2;  // Image count
    
    // 目录条目
    for (const entry of dirEntries) {
        buffer.writeUInt8(entry.width, pos); pos += 1;
        buffer.writeUInt8(entry.height, pos); pos += 1;
        buffer.writeUInt8(entry.colorCount, pos); pos += 1;
        buffer.writeUInt8(entry.reserved, pos); pos += 1;
        buffer.writeUInt16LE(entry.planes, pos); pos += 2;
        buffer.writeUInt16LE(entry.bitCount, pos); pos += 2;
        buffer.writeUInt32LE(entry.dataSize, pos); pos += 4;
        buffer.writeUInt32LE(entry.offset, pos); pos += 4;
    }
    
    // 图像数据
    for (const size of sizes) {
        const pngData = pngBuffers[size];
        pngData.copy(buffer, pos);
        pos += pngData.length;
    }
    
    return buffer;
}

main().catch(err => {
    console.error('错误:', err);
    process.exit(1);
});
