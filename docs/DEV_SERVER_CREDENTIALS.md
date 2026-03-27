# Dev Server Credentials

> ⚠️ Этот файл добавлен в .gitignore и не попадает в репозиторий

## Сервер

| | |
|---|---|
| IP (внешний) | 93.94.150.16 |
| IP (локальная сеть) | 10.2.2.100 |
| URL | http://dev.pecado.ru |

## Admin сайта

| | |
|---|---|
| URL | http://dev.pecado.ru/admin |
| Email | admin@pecado.ru |
| Password | Admin2024! |

| SSH user | ladmin |
| SSH password | 0zp6fx# |
| SSH key | ~/.ssh/id_ed25519 |

## RabbitMQ — AMQP для 1С (из локальной сети)

| | |
|---|---|
| Host | 10.2.2.100 |
| Port | 5672 |
| Login | erp_1c |
| Password | ERP_Pecado_2024! |
| Virtual host | / |

Права: публикация в `erp.*` exchanges, чтение из `erp_out.*` очередей.

## RabbitMQ — AMQP для Laravel приложения

| | |
|---|---|
| Host | rabbitmq (внутри Docker) |
| Port | 5672 |
| Login | pecado_app |
| Password | PecadoApp2024! |
| Virtual host | / |

(прописано в `.env` на сервере)

## RabbitMQ Management UI

| | |
|---|---|
| URL | http://93.94.150.16:15672 |
| Login | pecado_admin |
| Password | SecurePass2024! |

## MySQL (внутри Docker)

| | |
|---|---|
| Host | mysql (внутри Docker) / 93.94.150.16:3308 (снаружи) |
| Database | pecado |
| User | pecado |
| Password | secret |

## MinIO — Медиа (бакет pecado)

| | |
|---|---|
| Console URL | http://93.94.150.16:9001 |
| User | sail |
| Password | password |

## MinIO — S3 для обмена ценами с 1С (бакет prices-exchange)

| | |
|---|---|
| Endpoint (из локальной сети) | http://10.2.2.100:9000 |
| Endpoint (извне) | http://93.94.150.16:9000 |
| Bucket | prices-exchange |
| Access Key | sail |
| Secret Key | password |
| Region | us-east-1 |
| Path Style | true |

## Mailpit

| | |
|---|---|
| URL | http://93.94.150.16:8025 |
