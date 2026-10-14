#!/usr/bin/env bash
KEY=$(mysql -uroot -proot pm_db -N -e "SELECT doc_key FROM pm_db.collab_documents WHERE id=18")
echo "--- direct via index.php (bypass rewrite) ---"
curl -s -o /dev/null -w "%{http_code}\n" "http://127.0.0.1/index.php/collab/download/18?k=$KEY"
echo "--- check if there is a tail of web.php beyond 307 ---"
tail -n 20 /var/www/pm/routes/web.php
echo "--- check RouteServiceProvider ---"
cat /var/www/pm/app/Providers/RouteServiceProvider.php
echo "--- mod_rewrite loaded? ---"
httpd -M 2>/dev/null | grep -i rewrite
echo "--- any deny in conf.d? ---"
grep -rn "Require all denied\|Require all" /etc/httpd/conf.d/ 2>/dev/null
