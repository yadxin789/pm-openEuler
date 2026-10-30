<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\IOFactory as SpreadsheetIOFactory;
use Smalot\PdfParser\Parser as PdfParser;

/**
 * 文档内容提取服务：把 PDF / DOCX / XLSX / PPTX 等常见办公文档
 * 抽取为纯文本，供 AI 大模型直接阅读。
 *
 * 设计原则：
 *  - 纯文本类（txt/md/csv/json...）直接读文件；
 *  - Office Open XML（docx/pptx/odt）用 ZipArchive 解压提取 XML 文本，不依赖外部库；
 *  - Excel（xlsx/xls）用已安装的 phpoffice/phpspreadsheet；
 *  - PDF 优先用 smalot/pdfparser（纯 PHP），失败时降级调用系统 pdftotext（poppler-utils）；
 *  - 老版 .doc（二进制）尝试 antiword 命令，不可用则返回空串。
 *
 * 所有方法都做了 try/catch 兜底，任何一种格式解析失败都不会让整个请求 500，
 * 而是返回空串，由上层把文件标记为「未能自动解析」。
 */
class DocumentTextExtractorService
{
    /** 单个文件最大提取字符数，防止超大文档撑爆上下文 */
    public const MAX_CHARS = 20000;

    /** 文件大小硬上限（20MB），超过不解析 */
    private const MAX_BYTES = 20 * 1024 * 1024;

    /**
     * 按扩展名分派提取
     */
    public function extract(string $filePath, ?string $extension = null): string
    {
        if (!is_file($filePath) || filesize($filePath) > self::MAX_BYTES) {
            return '';
        }

        $ext = strtolower(trim($extension ?? pathinfo($filePath, PATHINFO_EXTENSION), '.'));
        if ($ext === '') {
            return '';
        }

        try {
            return match ($ext) {
                // —— 纯文本 ——
                'txt', 'md', 'markdown', 'csv', 'json', 'xml', 'log',
                'yaml', 'yml', 'ini', 'sql', 'html', 'htm'
                    => $this->extractPlainText($filePath),

                // —— PDF ——
                'pdf' => $this->extractPdf($filePath),

                // —— Word ——
                'docx' => $this->extractDocx($filePath),
                'doc'  => $this->extractDocBinary($filePath),

                // —— Excel ——
                'xlsx', 'xls', 'xlsm', 'ods' => $this->extractSpreadsheet($filePath),

                // —— PowerPoint ——
                'pptx' => $this->extractPptx($filePath),

                // —— OpenDocument ——
                'odt' => $this->extractOdt($filePath),

                default => '',
            };
        } catch (\Throwable $e) {
            report($e);
            return '';
        }
    }

    /**
     * 是否为本服务能处理的扩展名
     */
    public function supports(string $extension): bool
    {
        $ext = strtolower(trim($extension, '.'));
        return in_array($ext, [
            'txt', 'md', 'markdown', 'csv', 'json', 'xml', 'log', 'yaml', 'yml', 'ini', 'sql', 'html', 'htm',
            'pdf', 'docx', 'doc', 'xlsx', 'xls', 'xlsm', 'ods', 'pptx', 'odt',
        ], true);
    }

    // ==================== 纯文本 ====================

    protected function extractPlainText(string $filePath): string
    {
        $content = @file_get_contents($filePath);
        if ($content === false || $content === '') {
            return '';
        }
        $content = mb_convert_encoding($content, 'UTF-8', ['UTF-8', 'GBK', 'GB18030', 'BIG-5', 'ISO-8859-1']);
        // 去掉控制字符但保留换行
        return trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $content) ?? '');
    }

    // ==================== PDF ====================

    protected function extractPdf(string $filePath): string
    {
        // 方案一：smalot/pdfparser（纯 PHP）
        if (class_exists(PdfParser::class)) {
            try {
                $parser = new PdfParser();
                $pdf = $parser->parseFile($filePath);
                $text = $pdf->getText();
                if (trim((string) $text) !== '') {
                    return $this->truncate($text);
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }

        // 方案二：系统命令 pdftotext（poppler-utils，openEuler: dnf install poppler-utils）
        $pdftotext = trim((string) @shell_exec('which pdftotext 2>/dev/null') ?? '');
        if ($pdftotext !== '') {
            $outFile = sys_get_temp_dir() . '/ai_pdf_' . uniqid() . '.txt';
            $cmd = escapeshellarg($pdftotext) . ' -enc UTF-8 ' . escapeshellarg($filePath) . ' ' . escapeshellarg($outFile) . ' 2>/dev/null';
            @exec($cmd, $output, $retCode);
            if ($retCode === 0 && is_file($outFile)) {
                $text = (string) file_get_contents($outFile);
                @unlink($outFile);
                return $this->truncate($text);
            }
            @unlink($outFile);
        }

        return '';
    }

    // ==================== Word DOCX（OOXML） ====================

    protected function extractDocx(string $filePath): string
    {
        $xml = $this->readZipEntry($filePath, 'word/document.xml');
        if ($xml === '') {
            return '';
        }
        return $this->extractXmlText($xml);
    }

    // ==================== Word 老版 .doc（二进制） ====================

    protected function extractDocBinary(string $filePath): string
    {
        $antiword = trim((string) @shell_exec('which antiword 2>/dev/null') ?? '');
        if ($antiword !== '') {
            $cmd = escapeshellarg($antiword) . ' ' . escapeshellarg($filePath) . ' 2>/dev/null';
            $text = (string) @shell_exec($cmd);
            if (trim($text) !== '') {
                return $this->truncate($text);
            }
        }
        return '';
    }

    // ==================== Excel ====================

    protected function extractSpreadsheet(string $filePath): string
    {
        if (!class_exists(SpreadsheetIOFactory::class)) {
            return '';
        }

        $spreadsheet = SpreadsheetIOFactory::load($filePath);
        $lines = [];

        foreach ($spreadsheet->getAllSheets() as $sheet) {
            $lines[] = "【工作表：{$sheet->getTitle()}】";
            $highestRow = $sheet->getHighestDataRow();
            $highestCol = $sheet->getHighestDataColumn();

            // 限制最大行列，避免超大表卡死
            $maxRow = min($highestRow, 200);
            $maxCol = min(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestCol), 30);

            for ($row = 1; $row <= $maxRow; $row++) {
                $rowCells = [];
                for ($col = 1; $col <= $maxCol; $col++) {
                    $cell = $sheet->getCell([$col, $row]);
                    $value = trim((string) $cell->getCalculatedValue());
                    if ($value !== '') {
                        $rowCells[] = $value;
                    }
                }
                if (!empty($rowCells)) {
                    $lines[] = implode(' | ', $rowCells);
                }
            }
            $lines[] = '';
        }

        $spreadsheet->disconnectWorksheets();
        return $this->truncate(implode("\n", $lines));
    }

    // ==================== PowerPoint PPTX ====================

    protected function extractPptx(string $filePath): string
    {
        $zip = new \ZipArchive();
        if ($zip->open($filePath) !== true) {
            return '';
        }

        $slides = [];
        for ($i = 1; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (preg_match('#^ppt/slides/slide\d+\.xml$#', $name)) {
                $slides[] = $name;
            }
        }
        sort($slides, SORT_NATURAL);

        $lines = [];
        foreach ($slides as $idx => $slide) {
            $xml = (string) $zip->getFromName($slide);
            $lines[] = "【幻灯片 " . ($idx + 1) . "】";
            $lines[] = $this->extractXmlText($xml);
            $lines[] = '';
        }
        $zip->close();

        return $this->truncate(implode("\n", $lines));
    }

    // ==================== OpenDocument Text ====================

    protected function extractOdt(string $filePath): string
    {
        $xml = $this->readZipEntry($filePath, 'content.xml');
        if ($xml === '') {
            return '';
        }
        return $this->extractXmlText($xml);
    }

    // ==================== 工具方法 ====================

    /**
     * 读取 ZIP 内某个文件的内容
     */
    protected function readZipEntry(string $zipPath, string $entry): string
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return '';
        }
        $content = (string) $zip->getFromName($entry);
        $zip->close();
        return $content;
    }

    /**
     * 从 Office XML 中提取纯文本：
     *  - </w:p> 等段落标签替换为换行；
     *  - 去掉所有 XML 标签；
     *  - 解码 HTML 实体；
     *  - 压缩多余空行。
     */
    protected function extractXmlText(string $xml): string
    {
        // 段落/换行标记 → 换行
        $xml = preg_replace('#</w:p>#i', "\n", $xml);
        $xml = preg_replace('#</a:p>#i', "\n", $xml);
        $xml = preg_replace('#</text:p>#i', "\n", $xml);

        // 去掉所有标签
        $text = strip_tags($xml);

        // 解码 XML/HTML 实体（&amp; &lt; &#123; 等）
        $text = html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');

        // 压缩 3 个以上换行为 2 个
        $text = preg_replace("/\n{3,}/", "\n\n", $text);

        return trim($text ?? '');
    }

    /**
     * 截断到最大字符数
     */
    protected function truncate(string $text): string
    {
        $text = trim($text);
        if (mb_strlen($text) > self::MAX_CHARS) {
            $text = mb_substr($text, 0, self::MAX_CHARS) . "\n…（内容过长，已截断）";
        }
        return $text;
    }
}
