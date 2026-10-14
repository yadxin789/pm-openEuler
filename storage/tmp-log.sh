#!/usr/bin/env bash
KEY=$(mysql -uroot -proot pm_db -N -e "SELECT doc_key FROM pm_db.collab_documents WHERE id=18")
# Clear log marker
echo "=== MARKER $(date) ===" >> /var/www/pm/storage/logs/laravel.log
curl -s -o /dev/null "http://127.0.0.1/collab/download/18?k=$KEY"
sleep 1
echo "--- new log lines ---"
awk '/MARKER/{flag=1; next} flag' /var/www/pm/storage/logs/laravel.log | tail -n 40
