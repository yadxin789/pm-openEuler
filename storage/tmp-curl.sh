#!/usr/bin/env bash
KEY=$(mysql -uroot -proot pm_db -N -e "SELECT doc_key FROM pm_db.collab_documents WHERE id=18")
echo "KEY=$KEY"
curl -s -o /tmp/dl2 -w "HTTP=%{http_code} SIZE=%{size_download}\n" "http://127.0.0.1/collab/download/18?k=$KEY"
echo "--- body head ---"
head -c 200 /tmp/dl2; echo
echo "--- as www user (apache/php-fpm) ---"
sudo -u pmadmin curl -s -o /tmp/dl3 -w "HTTP=%{http_code} SIZE=%{size_download}\n" "http://127.0.0.1/collab/download/18?k=$KEY"
head -c 200 /tmp/dl3; echo
echo "--- storage check as pmadmin ---"
sudo -u pmadmin ls -la /var/www/pm/storage/app/file-repository/collab/6/doc_6acf3b5c055023.43931081.pptx
