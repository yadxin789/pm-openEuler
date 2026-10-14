#!/usr/bin/env bash
KEY=$(mysql -uroot -proot pm_db -N -e "SELECT doc_key FROM pm_db.collab_documents WHERE id=18")
echo "--- verbose curl ---"
curl -v "http://127.0.0.1/collab/download/18?k=$KEY" 2>&1 | head -n 25
echo "--- pm-error.log tail ---"
tail -n 20 /var/log/httpd/pm-error.log
echo "--- compare with working /login verbose ---"
curl -v "http://127.0.0.1/login" 2>&1 | head -n 12
echo "--- php-fpm pool config check ---"
grep -E "fix_pathinfo|security.limit_extensions" /etc/php-fpm.d/www.conf 2>/dev/null
echo "--- bootstrap/app.php ---"
cat /var/www/pm/bootstrap/app.php 2>/dev/null | head -n 30
