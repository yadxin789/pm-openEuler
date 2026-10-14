#!/usr/bin/env bash
echo "--- php-fpm pools ---"
ls /etc/php-fpm.d/
echo "--- whoami per pool ---"
for f in /etc/php-fpm.d/*.conf; do
  echo "== $f =="
  grep -E "^\[|user|group|listen" "$f"
done
echo "--- storage dir perms ---"
ls -ld /var/www/pm/storage /var/www/pm/storage/app /var/www/pm/storage/app/file-repository /var/www/pm/storage/app/file-repository/collab /var/www/pm/storage/app/file-repository/collab/6
echo "--- as apache user test read ---"
sudo -u apache ls -la /var/www/pm/storage/app/file-repository/collab/6/doc_6acf3b5c055023.43931081.pptx 2>&1
echo "--- as apache user test via Storage ---"
cat > /tmp/ftest.php <<'PHP'
<?php
chdir('/var/www/pm');
require '/var/www/pm/vendor/autoload.php';
$app = require '/var/www/pm/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\Storage;
$disk = Storage::disk('file_repository');
echo "user=" . posix_getpwuid(posix_geteuid())['name'] . "\n";
echo "exists=" . var_export($disk->exists('collab/6/doc_6acf3b5c055023.43931081.pptx'), true) . "\n";
echo "is_file=" . var_export(is_file($disk->path('collab/6/doc_6acf3b5c055023.43931081.pptx')), true) . "\n";
$err = error_get_last();
echo "err=" . ($err['message'] ?? 'none') . "\n";
PHP
sudo -u apache php /tmp/ftest.php 2>&1
