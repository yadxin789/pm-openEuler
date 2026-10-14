#!/usr/bin/env bash
echo "--- SELinux ---"
getenforce 2>/dev/null
ls -lZ /var/www/pm/storage/app/file-repository/collab/6/doc_6acf3b5c055023.43931081.pptx 2>/dev/null
ls -ldZ /var/www/pm/storage/app/file-repository/collab/6/ 2>/dev/null
echo "--- php-fpm open_basedir / disable_functions ---"
grep -E "open_basedir|disable_functions|chroot" /etc/php-fpm.d/www.conf /etc/php.ini 2>/dev/null | grep -v "^;"
echo "--- phpinfo as pmadmin via fpm (hit a test page) ---"
# create a probe
cat > /var/www/pm/public/_probe.php <<'PHP'
<?php
echo "user=" . posix_getpwuid(posix_geteuid())['name'] . "\n";
echo "open_basedir=" . ini_get('open_basedir') . "\n";
echo "exists=" . var_export(is_file('/var/www/pm/storage/app/file-repository/collab/6/doc_6acf3b5c055023.43931081.pptx'), true) . "\n";
echo "readable=" . var_export(is_readable('/var/www/pm/storage/app/file-repository/collab/6/doc_6acf3b5c055023.43931081.pptx'), true) . "\n";
$e = error_get_last();
echo "err=" . ($e['message'] ?? 'none') . "\n";
echo "faccessat=";
$r = @file_get_contents('/var/www/pm/storage/app/file-repository/collab/6/doc_6acf3b5c055023.43931081.pptx', false, null, 0, 10);
echo ($r === false ? "FAIL" : "OK(" . strlen($r) . ")") . "\n";
echo "opcache.file_cache=" . ini_get('opcache.file_cache') . "\n";
PHP
chown pmadmin:pmadmin /var/www/pm/public/_probe.php
chmod 644 /var/www/pm/public/_probe.php
curl -s "http://127.0.0.1/_probe.php"
rm -f /var/www/pm/public/_probe.php
