#!/bin/bash
# Debug 504 script - run on dev server
echo "=== PHP-FPM Processes ==="
docker exec pecado-app ps aux | grep -E 'php|PID'

echo ""
echo "=== MySQL Processlist ==="
docker exec pecado-mysql mysql -uroot -proot -e "SHOW FULL PROCESSLIST;" 2>/dev/null

echo ""
echo "=== PHP-FPM Pool Status ==="
docker exec pecado-app cat /usr/local/etc/php-fpm.d/www.conf 2>/dev/null | grep -E 'pm\.' | head -20

echo ""
echo "=== Disk Space ==="
df -h / /var

echo ""
echo "=== Memory ==="
free -h

echo ""
echo "=== Last Laravel errors ==="
docker exec pecado-app tail -100 /var/www/storage/logs/laravel.log 2>/dev/null | grep -A 3 "ERROR"

echo ""
echo "=== Nginx error log ==="
docker exec pecado-nginx cat /var/log/nginx/error.log 2>/dev/null | tail -20
