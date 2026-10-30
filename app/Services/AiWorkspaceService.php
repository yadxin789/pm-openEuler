<?php

namespace App\Services;

/**
 * AI 会话工作区服务：为每个 AI 会话提供独立的临时文件目录。
 *
 * 目录结构：
 *   storage/app/ai-workspace/{conversation_id}/
 *     ├── extracted/       ← ZIP / 压缩包解压后的内容
 *     └── (其他临时文件)
 *
 * 生命周期：
 *   - 用户上传 ZIP 时自动解压到 extracted/{zip名}/
 *   - AI 通过 ListWorkspaceFiles / ReadWorkspaceFile 工具浏览和读取
 *   - 用户删除会话或清空会话时，整个目录递归删除
 */
class AiWorkspaceService
{
    /** 工作区根目录（storage/app/ai-workspace） */
    public function rootPath(): string
    {
        return storage_path('app/ai-workspace');
    }

    /** 某个会话的工作区根目录 */
    public function path(int $conversationId): string
    {
        return $this->rootPath() . DIRECTORY_SEPARATOR . $conversationId;
    }

    /** 解压目录 */
    public function extractedPath(int $conversationId): string
    {
        return $this->path($conversationId) . DIRECTORY_SEPARATOR . 'extracted';
    }

    /** 确保工作区目录存在 */
    public function ensureDir(int $conversationId): string
    {
        $path = $this->path($conversationId);
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }
        $extracted = $this->extractedPath($conversationId);
        if (!is_dir($extracted)) {
            mkdir($extracted, 0755, true);
        }
        return $path;
    }

    /**
     * 解压 ZIP 到工作区。
     *
     * @param string $zipFile     ZIP 文件绝对路径
     * @param string $zipName     原始文件名（用于命名解压目录）
     * @return array{0:bool,1:string} [是否成功, 解压目录或错误信息]
     */
    public function extractZip(int $conversationId, string $zipFile, string $zipName): array
    {
        $this->ensureDir($conversationId);

        // 用 ZIP 文件名（去扩展名）作为子目录名
        $safeName = preg_replace('/[^a-zA-Z0-9_\-\x{4e00}-\x{9fa5}]/u', '_', pathinfo($zipName, PATHINFO_FILENAME));
        $safeName = mb_substr($safeName, 0, 80) ?: 'zip_' . substr(md5($zipName), 0, 8);

        $targetDir = $this->extractedPath($conversationId) . DIRECTORY_SEPARATOR . $safeName;
        if (is_dir($targetDir)) {
            $this->removeDir($targetDir); // 重复上传同名文件时先清理
        }
        mkdir($targetDir, 0755, true);

        $zip = new \ZipArchive();
        if ($zip->open($zipFile) !== true) {
            return [false, '无法打开 ZIP 文件（可能已损坏或加密）'];
        }

        $totalFiles = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entryName = $zip->getNameIndex($i);

            // 安全防护：禁止路径穿越
            if (str_contains($entryName, '..') || str_starts_with($entryName, '/')) {
                continue;
            }

            // 跳过目录条目和系统隐藏文件
            if (str_ends_with($entryName, '/') || str_starts_with(basename($entryName), '.')) {
                continue;
            }

            $destFile = $targetDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $entryName);
            $destDir = dirname($destFile);
            if (!is_dir($destDir)) {
                mkdir($destDir, 0755, true);
            }

            $stream = $zip->getStream($entryName);
            if ($stream) {
                file_put_contents($destFile, $stream);
                fclose($stream);
                $totalFiles++;
            }
        }
        $zip->close();

        if ($totalFiles === 0) {
            $this->removeDir($targetDir);
            return [false, 'ZIP 中没有可解压的文件'];
        }

        return [true, $targetDir . "（共 {$totalFiles} 个文件）"];
    }

    /**
     * 列出工作区中的文件（递归）。
     *
     * @param string $subDir 相对 extracted/ 的子目录，默认列出全部
     * @return array<int,array{path:string,size:int,type:string}>
     */
    public function listFiles(int $conversationId, string $subDir = '', int $limit = 500): array
    {
        $baseDir = $this->extractedPath($conversationId);
        if ($subDir !== '') {
            // 安全：禁止路径穿越
            $subDir = ltrim(str_replace('..', '', $subDir), '/\\');
            $baseDir .= DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $subDir);
        }

        if (!is_dir($baseDir)) {
            return [];
        }

        $results = [];
        $this->scanDir($baseDir, $baseDir, $results, $limit);
        return $results;
    }

    /**
     * 递归扫描目录
     */
    protected function scanDir(string $baseDir, string $currentDir, array &$results, int $limit): void
    {
        if (count($results) >= $limit) {
            return;
        }
        $entries = @scandir($currentDir);
        if ($entries === false) {
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
                continue;
            }
            $fullPath = $currentDir . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($fullPath)) {
                $this->scanDir($baseDir, $fullPath, $results, $limit);
            } elseif (is_file($fullPath)) {
                $relativePath = ltrim(str_replace($baseDir, '', $fullPath), DIRECTORY_SEPARATOR);
                $ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
                $results[] = [
                    'path' => $relativePath,
                    'size' => (int) filesize($fullPath),
                    'type' => $ext ?: 'unknown',
                ];
                if (count($results) >= $limit) {
                    return;
                }
            }
        }
    }

    /**
     * 读取工作区中某个文件的文本内容。
     * 自动调用 DocumentTextExtractorService 处理 PDF/DOCX/XLSX 等。
     *
     * @return array{0:bool,1:string} [是否成功, 内容或错误信息]
     */
    public function readFile(int $conversationId, string $relativePath, DocumentTextExtractorService $extractor): array
    {
        // 安全：禁止路径穿越
        $relativePath = ltrim(str_replace('..', '', $relativePath), '/\\');
        $fullPath = $this->extractedPath($conversationId) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);

        if (!is_file($fullPath)) {
            return [false, "文件不存在：{$relativePath}"];
        }
        if (filesize($fullPath) > 20 * 1024 * 1024) {
            return [false, "文件超过 20MB，无法读取：{$relativePath}"];
        }

        $ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));

        // 用文档提取服务处理（支持 pdf/docx/xlsx/pptx/txt 等）
        $text = $extractor->extract($fullPath, $ext);

        if ($text === '') {
            // 二进制文件（图片、CAD、设计文件等）
            $mime = mime_content_type($fullPath) ?: 'unknown';
            if (str_starts_with($mime, 'image/')) {
                return [false, "这是图片文件（{$mime}），当前模型未开启视觉识别，无法直接读取。请用文字描述图片内容。"];
            }
            return [false, "无法提取该文件的文本内容（类型：{$ext}，MIME：{$mime}）。该文件可能是二进制格式，请尝试其他方式查看。"];
        }

        return [true, $text];
    }

    /**
     * 清理整个会话工作区（递归删除）。
     */
    public function cleanup(int $conversationId): void
    {
        $this->removeDir($this->path($conversationId));
    }

    /**
     * 递归删除目录
     */
    protected function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $entries = array_diff(scandir($dir), ['.', '..']);
        foreach ($entries as $entry) {
            $fullPath = $dir . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($fullPath)) {
                $this->removeDir($fullPath);
            } else {
                @unlink($fullPath);
            }
        }
        @rmdir($dir);
    }
}
