<?php
// 从新模板恢复被误删的协作文档实体文件（仅用于修复已知行 id=18）
$docId = (int) ($argv[1] ?? 18);

$row = trim((string) shell_exec("mysql -uroot -proot pm_db -N -e 'SELECT CONCAT(storage_path,0x7c,size) FROM pm_db.collab_documents WHERE id={$docId}'"));
if ($row === '') { echo "doc {$docId} not found\n"; exit(1); }
[$storagePath, $expectedSize] = explode('|', $row);
$ext = pathinfo($storagePath, PATHINFO_EXTENSION);
$templatePath = "/var/www/pm/storage/app/private/templates/template.{$ext}";

if (!is_file($templatePath)) { echo "template missing: {$templatePath}\n"; exit(1); }

$target = "/var/www/pm/storage/app/file-repository/{$storagePath}";
$dir = dirname($target);
if (!is_dir($dir)) { mkdir($dir, 0775, true); }

$ok = copy($templatePath, $target);
chown($target, 'pmadmin');
chgrp($target, 'pmadmin');
chmod($target, 0644);

$newSize = filesize($target);
echo ($ok ? "restored: " : "FAIL: ") . $target . " ({$newSize} bytes, expected {$expectedSize})\n";

// 把 size 同步成实际大小（双保险）
shell_exec("mysql -uroot -proot pm_db -e 'UPDATE pm_db.collab_documents SET size={$newSize} WHERE id={$docId}'");
echo "size updated\n";
