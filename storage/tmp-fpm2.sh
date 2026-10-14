#!/usr/bin/env bash
cat > /tmp/ftest2.php <<'PHP'
<?php
chdir('/var/www/pm');
require '/var/www/pm/vendor/autoload.php';
$app = require '/var/www/pm/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\Storage;
$disk = Storage::disk('file_repository');
echo "user=" . posix_getpwuid(posix_geteuid())['name'] . "\n";
$path = 'collab/6/doc_6acf3b5c055023.43931081.pptx';
echo "exists=" . var_export($disk->exists($path), true) . "\n";
echo "abs=" . $disk->path($path) . "\n";
echo "is_file=" . var_export(is_file($disk->path($path)), true) . "\n";
echo "is_readable=" . var_export(is_readable($disk->path($path)), true) . "\n";
echo "filesize=" . filesize($disk->path($path)) . "\n";
PHP
echo "--- as pmadmin ---"
sudo -u pmadmin php /tmp/ftest2.php 2>&1
echo "--- as root ---"
php /tmp/ftest2.php 2>&1
echo "--- via PHP-FPM (use curl to trigger a real download) ---"
KEY=$(mysql -uroot -proot pm_db -N -e "SELECT doc_key FROM pm_db.collab_documents WHERE id=18")
# clear laravel.log
echo "=== MARK $(date) ===" >> /var/www/pm/storage/logs/laravel.log
# make sure opcache/log caches clear: bounce fpm? no. just hit.
curl -s -o /dev/null -w "HTTP=%{http_code}\n" "http://127.0.0.1/collab/download/18?k=$KEY"
sleep 1
echo "--- laravel log after request ---"
awk '/MARK/{flag=1; next} flag' /var/www/pm/storage/logs/laravel.log
