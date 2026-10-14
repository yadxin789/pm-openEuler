#!/usr/bin/env bash
KEY=$(mysql -uroot -proot pm_db -N -e "SELECT doc_key FROM pm_db.collab_documents WHERE id=18")
echo "--- before restart ---"
curl -s -o /dev/null -w "%{http_code}\n" "http://127.0.0.1/collab/download/18?k=$KEY"
echo "--- restart php-fpm ---"
systemctl restart php-fpm
sleep 1
echo "--- after restart ---"
curl -s -o /dev/null -w "%{http_code}\n" "http://127.0.0.1/collab/download/18?k=$KEY"
echo "--- compare 404 bodies: collab vs random ---"
curl -s "http://127.0.0.1/collab/download/18?k=$KEY" -o /tmp/a.html
curl -s "http://127.0.0.1/totally-not-exist" -o /tmp/b.html
echo "a size=$(wc -c < /tmp/a.html) b size=$(wc -c < /tmp/b.html)"
diff -q /tmp/a.html /tmp/b.html
echo "--- server routes/web.php lines 300-310 ---"
sed -n '300,310p' /var/www/pm/routes/web.php
