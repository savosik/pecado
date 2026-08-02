# HTTP-прокси с выходом через SSH-туннель до VPS.
#
# Нужен потому, что OpenRouter (Cloudflare) отдаёт 403 на запросы с внешнего IP
# прод-сервера. Контейнер держит SSH-туннель с SOCKS5 (ssh -D) до VPS в США и
# отдаёт во внутреннюю docker-сеть обычный HTTP-прокси на порту 8888.
# Маршрутизация хоста и остальных контейнеров при этом не меняется.
#
# OpenVPN до того же VPS не подходит: туннель поднимается, но данные не идут
# (tun0 rx_packets = 0) — сеть прода режет data-канал OpenVPN. SSH проходит.
FROM alpine:3.20

RUN apk add --no-cache openssh-client tinyproxy curl

COPY tinyproxy.conf /etc/tinyproxy/tinyproxy.conf
COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

EXPOSE 8888

ENTRYPOINT ["/entrypoint.sh"]
