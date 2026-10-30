# AI 智能助手附件解析与工作区 — 部署说明

## 问题

AI 聊天页上传 PDF / DOCX / Excel / PPT / ZIP 等文件后，AI 回复"无法读取文件内容，请粘贴文本"。
根因：`AiController::extractText()` 只处理 txt/md/csv/json 等纯文本后缀，PDF/DOCX/XLSX/ZIP 全部直接返回空串。

## 改动文件

| 文件 | 改动 |
|------|------|
| `app/Services/DocumentTextExtractorService.php` | **新增** — 统一文档提取服务（PDF/DOCX/XLSX/PPTX/ODT/纯文本） |
| `app/Services/AiWorkspaceService.php` | **新增** — 会话工作区服务（ZIP 自动解压、文件浏览、读取、清理） |
| `app/Ai/Tools/ListWorkspaceFiles.php` | **新增** — AI 工具：列出工作区文件 |
| `app/Ai/Tools/ReadWorkspaceFile.php` | **新增** — AI 工具：读取工作区文件内容 |
| `app/Http/Controllers/AiController.php` | extractText 委托新服务；ZIP 自动解压；注入工作区工具；删除会话时清理工作区 |
| `app/Services/AiService.php` | 系统提示词增加工作区与上传文件说明 |
| `composer.json` | 新增依赖 `smalot/pdfparser: ^2.11` |

## 支持的格式

| 格式 | 提取方式 | 依赖 |
|------|----------|------|
| .txt .md .csv .json .xml .log .yaml .sql .html | 直接读文件 | 无 |
| .pdf | smalot/pdfparser（纯PHP）→ 降级 pdftotext | composer 包 + 可选 poppler-utils |
| .docx | ZipArchive 解压 word/document.xml | PHP ZipArchive 扩展（默认开启） |
| .doc（老版二进制） | antiword 命令 | 可选：dnf install antiword |
| .xlsx .xls .xlsm .ods | phpoffice/phpspreadsheet | 已随 maatwebsite/excel 安装 |
| .pptx | ZipArchive 解压 ppt/slides/slide*.xml | PHP ZipArchive 扩展 |
| .odt | ZipArchive 解压 content.xml | PHP ZipArchive 扩展 |
| .zip | **自动解压到会话工作区**，AI 可浏览/读取其中文件 | PHP ZipArchive 扩展 |

## 会话工作区机制

```
storage/app/ai-workspace/{conversation_id}/
  └── extracted/           ← ZIP 解压后的内容
      └── design_lib/       ← 以 ZIP 文件名命名的子目录
          ├── schematic.pdf
          ├── bom.xlsx
          └── notes.docx
```

- 用户上传 ZIP 时自动解压，AI 收到提示后可用 `ListWorkspaceFiles` 浏览文件列表、`ReadWorkspaceFile` 读取具体文件内容。
- 删除会话或清空会话时，整个工作区目录递归删除。
- 工作区文件路径做了安全防护（禁止 `..` 路径穿越）。

## 部署步骤（在 openEuler 服务器 192.168.2.20 上执行）

```bash
cd /path/to/pm-server

# 1. 拉取最新代码
git pull

# 2. 安装新依赖
composer require smalot/pdfparser --no-dev --optimize-autoloader

# 3. （可选但推荐）安装系统级 PDF 文本提取工具
dnf install -y poppler-utils

# 4. （可选）支持老版 .doc 文件
dnf install -y antiword

# 5. 确保 PHP 有 ZipArchive 扩展（openEuler 通常默认有）
php -m | grep zip

# 6. 清除缓存
php artisan cache:clear
php artisan config:clear
php artisan route:clear

# 7. 重启 PHP-FPM
systemctl restart php-fpm
```

## 验证

部署后在 AI 聊天页：
1. 上传 PDF，问"总结一下"——AI 应引用文档内容。
2. 上传 .docx——AI 应提取正文。
3. 上传 .xlsx——AI 应读出行列数据。
4. 上传一个 ZIP（如设计资料库），问"里面有什么文件"——AI 应调用 ListWorkspaceFiles 列出文件。
5. 上传 ZIP 后问"读取某个 PDF 的内容"——AI 应调用 ReadWorkspaceFile 读取并总结。
6. 删除会话后检查 `storage/app/ai-workspace/{id}/` 目录应已被清理。

## 关于图片（看图）

当前 DeepSeek-V3 是纯文本模型，不支持图片输入。如需 AI 识别图片内容，需在「AI 设置」中启用一个支持视觉的模型（如 qwen-vl-max、doubao-vision-pro），勾选"is_vision"。

## 注意事项

- 每个文件最多提取 20000 字符，超出自动截断。
- 文件大小上限 20MB（上传校验）。
- PDF 加密或纯扫描件（图片型 PDF）无法提取文字。
- 所有解析异常都被 try/catch 兜底，不会导致请求 500。
- 工作区文件是临时文件，删除会话即清除，不会持久化。
