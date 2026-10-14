#!/usr/bin/env bash
cd /var/www/pm
echo "--- bootstrap/cache ---"
ls -la bootstrap/cache/
echo "--- routes for collab ---"
php artisan route:list 2>&1 | grep -i collab | head -20
echo "--- tail laravel log ---"
tail -n 30 storage/logs/laravel.log 2>/dev/null
echo "--- curl with -v ---"
curl -s -o /dev/null -w "HTTP=%{http_code}\n" "http://192.168.2.20/collab/download/18?k=a02d0aed05542e523bcfb6fa36bd2955"
