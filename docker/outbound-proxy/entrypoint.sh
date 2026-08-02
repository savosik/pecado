#!/bin/sh
#
# Поднимает SSH-туннель с SOCKS5 до VPS и запускает поверх него HTTP-прокси.
# Если падает любой из процессов — выходим с ошибкой, restart-политика docker
# поднимает контейнер заново.
set -eu

SSH_HOST="${PROXY_SSH_HOST:-}"
SSH_USER="${PROXY_SSH_USER:-pecadoproxy}"
SSH_PORT="${PROXY_SSH_PORT:-22}"
SSH_KEY="${PROXY_SSH_KEY:-/keys/id_ed25519}"
KNOWN_HOSTS="${PROXY_KNOWN_HOSTS:-/keys/known_hosts}"

# Хост или ключ не заданы — не падаем в бесконечный рестарт, а просто ждём.
# Чтобы включить прокси: положить ключ в /srv/outbound-proxy, задать
# PROXY_SSH_HOST и перезапустить контейнер.
if [ -z "$SSH_HOST" ] || [ ! -f "$SSH_KEY" ]; then
    echo "outbound-proxy: не задан PROXY_SSH_HOST или нет ключа $SSH_KEY, прокси не запущен" >&2
    exec sleep infinity
fi

# Ключ монтируется read-only снаружи, ssh требует прав 600 — копируем к себе.
install -m 600 "$SSH_KEY" /tmp/id_key

SSH_OPTS="-o ExitOnForwardFailure=yes -o ServerAliveInterval=30 -o ServerAliveCountMax=3 -o ConnectTimeout=15"
if [ -f "$KNOWN_HOSTS" ]; then
    SSH_OPTS="$SSH_OPTS -o StrictHostKeyChecking=yes -o UserKnownHostsFile=$KNOWN_HOSTS"
else
    echo "outbound-proxy: нет $KNOWN_HOSTS, ключ хоста принимается при первом подключении" >&2
    SSH_OPTS="$SSH_OPTS -o StrictHostKeyChecking=accept-new -o UserKnownHostsFile=/tmp/known_hosts"
fi

# shellcheck disable=SC2086
ssh -N -D 127.0.0.1:1080 -i /tmp/id_key -p "$SSH_PORT" $SSH_OPTS "$SSH_USER@$SSH_HOST" &
SSH_PID=$!

i=0
while [ "$i" -lt 30 ]; do
    if curl -s --max-time 5 -x socks5h://127.0.0.1:1080 -o /dev/null https://api.ipify.org; then
        break
    fi
    i=$((i + 1))
    sleep 1
done

EXTERNAL_IP="$(curl -s --max-time 10 -x socks5h://127.0.0.1:1080 https://api.ipify.org || echo '')"
if [ -z "$EXTERNAL_IP" ]; then
    echo "outbound-proxy: SOCKS-туннель до $SSH_HOST не поднялся" >&2
    kill "$SSH_PID" 2>/dev/null || true
    exit 1
fi

echo "outbound-proxy: туннель поднят, внешний IP: $EXTERNAL_IP"

tinyproxy -d -c /etc/tinyproxy/tinyproxy.conf &
PROXY_PID=$!

echo "outbound-proxy: прокси слушает 0.0.0.0:8888"

# Простейшая супервизия: как только умер любой из двух процессов — выходим.
while kill -0 "$SSH_PID" 2>/dev/null && kill -0 "$PROXY_PID" 2>/dev/null; do
    sleep 5
done

echo "outbound-proxy: один из процессов завершился, перезапускаем контейнер" >&2
kill "$SSH_PID" "$PROXY_PID" 2>/dev/null || true
exit 1
