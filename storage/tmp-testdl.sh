#!/usr/bin/env bash
set -e
cd /var/www/pm
KEY=$(mysql -uroot -proot pm_db -N -e "SELECT doc_key FROM pm_db.collab_documents WHERE id=18")
echo "key=$KEY"
echo "--- direct curl ---"
curl -s -o /tmp/dl_body -w "HTTP=%{http_code} SIZE=%{size_download}\n" "http://192.168.2.20/collab/download/18?k=$KEY"
head -c 300 /tmp/dl_body; echo
echo "--- route ---"
grep -n "collab/download\|collab/callback" routes/web.php
