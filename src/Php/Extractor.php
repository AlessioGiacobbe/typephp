<?php
/**
 * This file is part of TypePHP.
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace TypePhp;

class Extractor
{
    private string $ctagsPath = 'ctags';
    private bool $isUniversalCtags = false;

    public function __construct()
    {
        $this->checkCtags();
    }

    /**
     * 提取函数定义.
     *
     * @param string $filename 文件路径
     * @param array $prefixes 函数名前缀列表
     *
     * @return array 函数列表
     */
    public function extractFunctions(string $filename, array $prefixes = ['php_']): array
    {
        if (!file_exists($filename)) {
            throw new RuntimeException("文件不存在: {$filename}");
        }

        $this->info("分析文件: {$filename}");
        $this->info('函数前缀: ' . implode(', ', $prefixes));

        // 运行 ctags
        $tags = $this->runCtags($filename);

        // 过滤和解析函数
        $functions = [];
        foreach ($tags as $tag) {
            if ($tag['kind'] !== 'function') {
                continue;
            }

            $funcName = $tag['name'] ?? '';

            // 检查前缀
            $matched = false;
            foreach ($prefixes as $prefix) {
                if (str_starts_with($funcName, $prefix)) {
                    $matched = true;
                    break;
                }
            }

            if (!$matched) {
                continue;
            }

            // 解析函数详细信息
            $funcInfo = $this->parseFunction($filename, $tag);
            if ($funcInfo) {
                $functions[] = $funcInfo;
            }
        }

        $this->info('找到 ' . count($functions) . ' 个函数');

        return $functions;
    }

    /**
     * 批量提取多个文件.
     */
    public function extractFromFiles(array $files, array $prefixes = ['php_']): array
    {
        $allFunctions = [];

        foreach ($files as $file) {
            try {
                $functions    = $this->extractFunctions($file, $prefixes);
                $allFunctions = array_merge($allFunctions, $functions);
            } catch (Exception $e) {
                $this->error("处理文件 {$file} 失败: " . $e->getMessage());
            }
        }

        return $allFunctions;
    }

    /**
     * 提取函数并添加额外信息.
     */
    public function extractWithMetadata(string $filename, array $prefixes = ['php_']): array
    {
        $functions = $this->extractFunctions($filename, $prefixes);

        // 添加额外的元数据
        foreach ($functions as &$func) {
            $func['metadata'] = $this->extractMetadata($filename, $func);
        }

        return $functions;
    }

    /**
     * 生成函数统计信息.
     */
    public function generateStatistics(array $functions): array
    {
        $stats = [
            'total'            => count($functions),
            'byReturnType'     => [],
            'byParameterCount' => [],
            'byPrefix'         => [],
            'withComments'     => 0,
            'isPHPFunction'    => 0,
        ];

        foreach ($functions as $func) {
            // 按返回类型统计
            $returnType                         = $func['returnType'];
            $stats['byReturnType'][$returnType] =
                ($stats['byReturnType'][$returnType] ?? 0) + 1;

            // 按参数数量统计
            $paramCount                             = count($func['parameters']);
            $stats['byParameterCount'][$paramCount] =
                ($stats['byParameterCount'][$paramCount] ?? 0) + 1;

            // 按前缀统计
            $name                       = $func['name'];
            $prefix                     = preg_match('/^([a-z_]+_)/i', $name, $m) ? $m[1] : 'other';
            $stats['byPrefix'][$prefix] =
                ($stats['byPrefix'][$prefix] ?? 0) + 1;

            // 有注释的函数
            if (!empty($func['metadata']['comments'])) {
                $stats['withComments']++;
            }

            // PHP 函数宏
            if ($func['metadata']['isPHPFunction'] ?? false) {
                $stats['isPHPFunction']++;
            }
        }

        return $stats;
    }

    /**
     * 导出为 Markdown 文档.
     */
    public function exportToMarkdown(array $functions, string $title = 'API 文档'): string
    {
        $md = "# {$title}\n\n";
        $md .= '生成时间: ' . date('Y-m-d H:i:s') . "\n\n";
        $md .= '总计: ' . count($functions) . " 个函数\n\n";
        $md .= "---\n\n";

        foreach ($functions as $func) {
            $md .= "## {$func['name']}\n\n";

            // 签名
            $md .= "```c\n{$func['signature']}\n```\n\n";

            // 返回类型
            $md .= "**返回类型**: `{$func['returnType']}`\n\n";

            // 参数
            if (!empty($func['parameters'])) {
                $md .= "**参数**:\n\n";
                foreach ($func['parameters'] as $param) {
                    $name = $param['name'] ?: '(unnamed)';
                    $md .= "- `{$param['type']}` **{$name}**\n";
                }
                $md .= "\n";
            } else {
                $md .= "**参数**: 无\n\n";
            }

            // 注释
            if (!empty($func['metadata']['comments'])) {
                $md .= "**说明**:\n\n";
                foreach ($func['metadata']['comments'] as $comment) {
                    $md .= $comment . "\n\n";
                }
            }

            // 位置
            $md .= "**位置**: {$func['location']['file']}:{$func['location']['line']}\n\n";

            $md .= "---\n\n";
        }

        return $md;
    }

    /**
     * 检查 ctags 是否可用.
     */
    private function checkCtags(): void
    {
        $output = shell_exec("{$this->ctagsPath} --version 2>&1");

        if ($output === null) {
            $this->error("未找到 ctags 命令\n安装: sudo apt install universal-ctags");
        }

        $this->isUniversalCtags = stripos($output, 'Universal Ctags') !== false;

        if (!$this->isUniversalCtags) {
            $this->warn('建议使用 Universal Ctags 以获得更好的支持');
        }
    }

    /**
     * 运行 ctags 命令.
     */
    private function runCtags(string $filename): array
    {
        $cmd = sprintf(
            '%s --output-format=json --fields=+nKSzZt --kinds-c++=f --extras=+q -f - %s 2>&1',
            escapeshellcmd($this->ctagsPath),
            escapeshellarg($filename)
        );

        $output = shell_exec($cmd);

        if ($output === null) {
            throw new RuntimeException('ctags 执行失败');
        }

        // 解析 JSON 输出
        $tags  = [];
        $lines = explode("\n", trim($output));

        foreach ($lines as $line) {
            if (empty($line)) {
                continue;
            }

            $tag = json_decode($line, true);
            if ($tag === null) {
                continue;
            }

            $tags[] = $tag;
        }

        return $tags;
    }

    /**
     * 解析单个函数的详细信息.
     */
    private function parseFunction(string $filename, array $tag): ?array
    {
        $funcName = $tag['name'] ?? '';
        $lineNum  = $tag['line'] ?? 0;

        if (empty($funcName) || $lineNum < 1) {
            return null;
        }

        // 提取完整的函数签名
        $signature = $this->extractSignature($filename, $lineNum, $funcName);

        if (empty($signature)) {
            return null;
        }

        // 解析返回类型
        $returnType = $this->parseReturnType($signature, $funcName);

        // 解析参数
        $parameters = $this->parseParameters($signature, $funcName);

        return [
            'name'       => $funcName,
            'returnType' => $returnType,
            'signature'  => $signature,
            'parameters' => $parameters,
            'location'   => [
                'file' => $filename,
                'line' => $lineNum,
            ],
            'scope'     => $tag['scope'] ?? null,
            'scopeKind' => $tag['scopeKind'] ?? null,
        ];
    }

    /**
     * 从源文件中提取完整的函数签名.
     */
    private function extractSignature(string $filename, int $lineNum, string $funcName): string
    {
        $lines = file($filename, FILE_IGNORE_NEW_LINES);

        if ($lines === false || $lineNum > count($lines)) {
            return '';
        }

        // 从函数声明行开始收集，直到遇到 { 或 ;
        $signatureLines = [];
        $maxLines       = min($lineNum + 20, count($lines));

        for ($i = $lineNum - 1; $i < $maxLines; $i++) {
            $line             = $lines[$i];
            $signatureLines[] = $line;

            // 检查是否到达函数体或声明结束
            if (strpos($line, '{') !== false || strpos($line, ';') !== false) {
                break;
            }
        }

        // 合并并清理
        $signature = implode(' ', $signatureLines);

        // 移除 { 或 ; 之后的内容
        $signature = preg_replace('/[{;].*$/', '', $signature);

        // 合并多个空白字符
        $signature = preg_replace('/\s+/', ' ', $signature);

        // 清理首尾空白
        return trim($signature);
    }

    /**
     * 解析返回类型.
     */
    private function parseReturnType(string $signature, string $funcName): string
    {
        // 匹配: <返回类型> <函数名>(
        $pattern = '/^(.+?)\s+' . preg_quote($funcName, '/') . '\s*\(/';

        if (preg_match($pattern, $signature, $matches)) {
            $returnType = trim($matches[1]);

            // 移除可能的修饰符
            $returnType = preg_replace('/\b(static|inline|extern|virtual|explicit)\b/', '', $returnType);
            $returnType = preg_replace('/\s+/', ' ', $returnType);
            $returnType = trim($returnType);

            return $returnType ?: 'void';
        }

        return 'unknown';
    }

    /**
     * 解析参数列表.
     */
    private function parseParameters(string $signature, string $funcName): array
    {
        // 提取括号内的参数
        $pattern = '/' . preg_quote($funcName, '/') . '\s*\((.*?)\)/s';

        if (!preg_match($pattern, $signature, $matches)) {
            return [];
        }

        $paramsStr = trim($matches[1]);

        // 空参数或 void
        if (empty($paramsStr) || $paramsStr === 'void') {
            return [];
        }

        // 分割参数（处理嵌套的模板和括号）
        $params = $this->splitParameters($paramsStr);

        $parameters = [];
        foreach ($params as $param) {
            $param = trim($param);

            if (empty($param)) {
                continue;
            }

            $paramInfo = $this->parseParameter($param);
            if ($paramInfo) {
                $parameters[] = $paramInfo;
            }
        }

        return $parameters;
    }

    /**
     * 智能分割参数（处理嵌套的模板和括号）.
     */
    private function splitParameters(string $paramsStr): array
    {
        $params  = [];
        $current = '';
        $depth   = 0;
        $length  = strlen($paramsStr);

        for ($i = 0; $i < $length; $i++) {
            $char = $paramsStr[$i];

            if ($char === '<' || $char === '(' || $char === '[') {
                $depth++;
                $current .= $char;
            } elseif ($char === '>' || $char === ')' || $char === ']') {
                $depth--;
                $current .= $char;
            } elseif ($char === ',' && $depth === 0) {
                $params[] = $current;
                $current  = '';
            } else {
                $current .= $char;
            }
        }

        if (!empty($current)) {
            $params[] = $current;
        }

        return $params;
    }

    /**
     * 解析单个参数.
     */
    private function parseParameter(string $param): ?array
    {
        $param = trim($param);

        // 移除默认值
        $param = preg_replace('/\s*=\s*.*$/', '', $param);

        // 尝试匹配: <类型> <名称>
        // 支持复杂类型如: const char*, std::string&, int**, etc.
        if (preg_match('/^(.+?)\s+(\w+)\s*$/', $param, $matches)) {
            return [
                'type' => trim($matches[1]),
                'name' => trim($matches[2]),
            ];
        }

        // 只有类型，没有名称
        return [
            'type' => $param,
            'name' => '',
        ];
    }

    /**
     * 输出信息.
     */
    private function info(string $message): void
    {
        fprintf(STDERR, "\033[0;32m%s\033[0m\n", $message);
    }

    /**
     * 输出警告.
     */
    private function warn(string $message): void
    {
        fprintf(STDERR, "\033[1;33m警告: %s\033[0m\n", $message);
    }

    /**
     * 输出错误并退出.
     */
    private function error(string $message): void
    {
        fprintf(STDERR, "\033[0;31m错误: %s\033[0m\n", $message);
        exit(1);
    }

    /**
     * 提取函数的元数据（注释、属性等）.
     */
    private function extractMetadata(string $filename, array $func): array
    {
        $lineNum = $func['location']['line'];
        $lines   = file($filename, FILE_IGNORE_NEW_LINES);

        $metadata = [
            'comments'      => [],
            'attributes'    => [],
            'isPHPFunction' => false,
            'isStatic'      => false,
            'isInline'      => false,
        ];

        // 提取函数前的注释
        $comments             = $this->extractComments($lines, $lineNum);
        $metadata['comments'] = $comments;

        // 检测 PHP 函数宏
        $metadata['isPHPFunction'] = $this->isPHPFunction($func['signature']);

        // 检测修饰符
        $signature            = $func['signature'];
        $metadata['isStatic'] = strpos($signature, 'static') !== false;
        $metadata['isInline'] = strpos($signature, 'inline') !== false;

        // 提取文档注释中的标签
        $metadata['docTags'] = $this->parseDocTags($comments);

        return $metadata;
    }

    /**
     * 提取函数前的注释.
     */
    private function extractComments(array $lines, int $lineNum): array
    {
        $comments = [];
        $i        = $lineNum - 2; // 从函数声明的前一行开始

        // 向上查找注释
        while ($i >= 0) {
            $line = trim($lines[$i]);

            // 空行
            if (empty($line)) {
                $i--;
                continue;
            }

            // C++ 风格注释
            if (str_starts_with($line, '//')) {
                array_unshift($comments, substr($line, 2));
                $i--;
                continue;
            }

            // C 风格注释结束
            if (str_ends_with($line, '*/')) {
                $commentLines = [$line];
                $i--;

                // 继续向上查找注释开始
                while ($i >= 0) {
                    $commentLine = trim($lines[$i]);
                    array_unshift($commentLines, $commentLine);

                    if (str_starts_with($commentLine, '/*')) {
                        break;
                    }
                    $i--;
                }

                // 解析多行注释
                $comment = implode("\n", $commentLines);
                $comment = preg_replace('#^/\*+\s*#', '', $comment);
                $comment = preg_replace('#\s*\*+/$#', '', $comment);
                $comment = preg_replace('#^\s*\*\s?#m', '', $comment);

                array_unshift($comments, trim($comment));
                $i--;
                continue;
            }

            // 遇到非注释行，停止
            break;
        }

        return $comments;
    }

    /**
     * 检测是否是 PHP 函数宏定义.
     */
    private function isPHPFunction(string $signature): bool
    {
        $phpMacros = [
            'PHP_FUNCTION',
            'PHP_METHOD',
            'ZEND_FUNCTION',
            'ZEND_METHOD',
        ];

        foreach ($phpMacros as $macro) {
            if (strpos($signature, $macro) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * 解析文档注释标签.
     */
    private function parseDocTags(array $comments): array
    {
        $tags = [];

        foreach ($comments as $comment) {
            // 匹配 @tag 格式
            if (preg_match_all('/@(\w+)\s+(.*)$/m', $comment, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $tagName  = $match[1];
                    $tagValue = trim($match[2]);

                    if (!isset($tags[$tagName])) {
                        $tags[$tagName] = [];
                    }

                    $tags[$tagName][] = $tagValue;
                }
            }
        }

        return $tags;
    }
}
