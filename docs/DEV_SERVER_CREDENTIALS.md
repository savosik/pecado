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

### Аккаунты с доступом к админке

| Email | Password | Роль | Описание |
|---|---|---|---|
| admin@pecado.ru | Admin2024! | super-admin | Полный доступ ко всем разделам |
| savosik@pecado.ru | Savosik2024! | super-admin | Полный доступ ко всем разделам |
| content@pecado.ru | Content2024! | content-manager | Статьи, новости, баннеры, сторис, страницы, FAQ, промоакции |
| sales@pecado.ru | Sales2024! | sales-manager | Заказы, возвраты, отгрузки, клиенты, корзины |
| catalog@pecado.ru | Catalog2024! | catalogist | Товары, категории, бренды, модели, атрибуты, размерные сетки |

> Аккаунты создаются через `DatabaseSeeder`. Для входа в админку нужна хотя бы одна роль. Управление ролями: `/admin/roles`.

| SSH user | ladmin |
| SSH password | 0zp6fx# |
| SSH key | ~/.ssh/id_ed25519 |

## Laravel Horizon (мониторинг очередей Redis)

| | |
|---|---|
| URL | http://dev.pecado.ru/horizon |

> Доступ только для роли `super-admin`. Используй `admin@pecado.ru` или `savosik@pecado.ru`.

## Laravel Pulse (мониторинг производительности)

| | |
|---|---|
| URL | http://dev.pecado.ru/pulse |

Показывает: нагрузку CPU/памяти, медленные запросы (mysql + prices), медленные jobs/requests, очереди, исключения. Данные обновляются в реальном времени через `pulse:check` (supervisor).

> Доступ только для роли `super-admin`. Используй `admin@pecado.ru` или `savosik@pecado.ru`.

## Spatie Health (проверки здоровья системы)

| | |
|---|---|
| JSON эндпоинт | http://dev.pecado.ru/health |
| CLI | `php artisan health:list` |

Проверяет: MySQL (main + prices), Redis, диск (warn 70%, fail 90%), Horizon. Запускается каждую минуту через планировщик.

> Эндпоинт `/health` публичный — используется для внешнего мониторинга (uptime-роботы).

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
| Access Key | erp1c_prices |
| Secret Key | Xe9k4Qm7RvBn3TpL2w |
| Region | us-east-1 |
| Path Style | true |

> Выделенный пользователь с политикой `prices-exchange-rw` — доступ **только** к бакету `prices-exchange`. Доступа к другим бакетам нет.

## Mailpit

| | |
|---|---|
| URL | http://93.94.150.16:8025 |
