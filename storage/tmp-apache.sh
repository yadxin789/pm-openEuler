#!/usr/bin/env bash
KEY=$(mysql -uroot -proot pm_db -N -e "SELECT doc_key FROM pm_db.collab_documents WHERE id=18")
echo "KEY=$KEY"
echo "--- with key ---"
curl -s -o /dev/null -w "%{http_code}\n" "http://127.0.0.1/collab/download/18?k=$KEY"
echo "--- without key ---"
curl -s -o /dev/null -w "%{http_code}\n" "http://127.0.0.1/collab/download/18"
echo "--- non-numeric id ---"
curl -s -o /dev/null -w "%{http_code}\n" "http://127.0.0.1/collab/download/abc"
echo "--- valid existing doc 16 ---"
KEY16=$(mysql -uroot -proot pm_db -N -e "SELECT doc_key FROM pm_db.collab_documents WHERE id=16")
echo "KEY16=$KEY16"
curl -s -o /dev/null -w "%{http_code}\n" "http://127.0.0.1/collab/download/16?k=$KEY16"
echo "--- apache access log tail ---"
tail -n 5 /var/log/httpd/pm-access.log
echo "--- apache error log tail ---"
tail -n 20 /var/log/httpd/pm-error.log
