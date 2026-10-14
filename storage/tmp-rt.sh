#!/usr/bin/env bash
cd /var/www/pm
echo "--- git head on server ---"
git log -1 --oneline
echo "--- route:list -v for collab.download ---"
php artisan route:list -v 2>&1 | grep -A3 "collab.download"
echo "--- controller file head on server ---"
head -n 20 app/Http/Controllers/CollabDocumentController.php
echo "--- raw byte check routes/web.php line 306 ---"
sed -n '306p' routes/web.php | cat -A
echo "--- add probe: hit download with a query that would prove routing ---"
# if route matches, even wrong doc id would route-bind and 404 (ModelNotFound) vs framework 404.
# Both same in prod. So instead, check via a known route that IS public: collab.callback is POST only.
# Test GET on a definitely-defined public route: there's none obvious. Use the /up health.
curl -s -o /dev/null -w "/up=%{http_code}\n" "http://127.0.0.1/up"
