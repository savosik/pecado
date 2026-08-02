# outbound-proxy — выход в OpenRouter через VPS

## Зачем

OpenRouter (за Cloudflare) отвечает `403 {"success":false,"error":"Access denied by security policy."}`
на любой запрос с внешнего IP прод-сервера (`93.94.150.16`) — блокируется сам IP,
а не ключ: тот же `OPENROUTER_API_KEY` с машины разработчика отдаёт `200`.
Из-за этого не работали генерация `rich_content` (JSON-описания товаров),
нормализатор данных, AI-хелперы админки и эмбеддинги Meilisearch.

Контейнер держит SSH-туннель с SOCKS5 (`ssh -N -D`) до VPS в США и отдаёт во
внутреннюю docker-сеть обычный HTTP-прокси (`tinyproxy`) на порту `8888`.
Наружу порт не публикуется, маршрутизация хоста не меняется.

> **Почему не OpenVPN.** Туннель до того же VPS с прода поднимается (handshake
> проходит, tun0 получает адрес), но данные не идут вообще: `tun0 rx_packets = 0`,
> ping до 10.8.0.1 — 100% потерь. Сеть прода режет data-канал OpenVPN.
> SSH через тот же VPS работает без нареканий.

## Установка на сервере

1. На VPS завести пользователя без шелла и положить публичный ключ:

   ```bash
   useradd -m -s /usr/sbin/nologin pecadoproxy
   mkdir -p /home/pecadoproxy/.ssh
   echo 'no-pty,no-agent-forwarding,no-X11-forwarding ssh-ed25519 AAAA... pecado-prod-outbound-proxy' \
       > /home/pecadoproxy/.ssh/authorized_keys
   chown -R pecadoproxy:pecadoproxy /home/pecadoproxy/.ssh
   chmod 700 /home/pecadoproxy/.ssh && chmod 600 /home/pecadoproxy/.ssh/authorized_keys
   ```

2. На проде положить приватный ключ и ключ хоста VPS:

   ```
   /srv/outbound-proxy/id_ed25519   (root:root, 600)
   /srv/outbound-proxy/known_hosts  (ssh-keyscan VPS)
   ```

3. Прописать в `/srv/pecado/.env`:

   ```
   PROXY_SSH_HOST=45.77.101.56
   OPENROUTER_PROXY=http://outbound-proxy:8888
   ```

4. Применить:

   ```bash
   cd /srv/pecado
   DC="docker compose -f docker-compose.yml -f docker-compose.prod.yml"
   $DC up -d --build outbound-proxy
   $DC exec app php artisan config:cache
   $DC restart app worker meilisearch
   ```

## Проверка

```bash
docker logs pecado-outbound-proxy | tail -3      # должен быть внешний IP VPS
docker exec pecado-app curl -s -o /dev/null -w '%{http_code}\n' \
    -x http://outbound-proxy:8888 https://openrouter.ai/api/v1/models   # ожидаем 200
```

Без `PROXY_SSH_HOST` или без ключа контейнер не падает в рестарт-луп, а просто
ждёт — прокси не поднимается, приложение продолжает ходить в OpenRouter напрямую.
