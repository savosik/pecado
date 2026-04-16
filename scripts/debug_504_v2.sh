#!/bin/bash
echo "=== Container pecado-app status ==="
docker inspect pecado-app --format='{{.State.Status}} | Running: {{.State.Running}} | Pid: {{.State.Pid}} | StartedAt: {{.State.StartedAt}}'

echo ""
echo "=== ALL processes inside pecado-app ==="
docker exec pecado-app ps aux 2>&1 || echo "ps failed, trying top"
docker top pecado-app 2>&1

echo ""
echo "=== PHP-FPM config test ==="
docker exec pecado-app php-fpm -t 2>&1

echo ""
echo "=== Check if port 9000 is listening ==="
docker exec pecado-app sh -c 'ss -tlnp 2>/dev/null || netstat -tlnp 2>/dev/null || echo "no ss/netstat"'

echo ""
echo "=== MySQL connectivity from app ==="
docker exec pecado-app php -r "try { new PDO('mysql:host=mysql;dbname=pecado', 'root', 'root'); echo 'MySQL OK'; } catch(Exception \$e) { echo 'MySQL FAIL: '.\$e->getMessage(); }" 2>&1

echo ""
echo "=== Nginx error log ==="
docker logs pecado-nginx 2>&1 | grep -i 'error\|upstream\|timeout' | tail -20

echo ""
echo "=== App container logs (last 30 lines) ==="
docker logs pecado-app --tail 30 2>&1
