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

> Креды в `/srv/pecado/.env` хранятся под именами `RABBITMQ_ERP_USER` / `RABBITMQ_ERP_PASSWORD`. Деплой-workflow идемпотентно пересоздаёт пользователя при каждом запуске (см. блок `[5.2/7] Провижининг пользователей RabbitMQ`).

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

## RabbitMQ Shovel — остатки с московского ESB

Shovel настраивается автоматически при деплое командой `php artisan rabbitmq:setup` (вызывается из `.github/workflows/deploy-dev.yml`). Он вытягивает сообщения из очереди `remains_for_moscow` на ESB и публикует их в локальный fanout-обменник `external.remains`, откуда они расходятся в две очереди:

- `external.remains_for_website` — потребитель: сайт Pecado
- `external.remains_for_erp` — потребитель: 1С

### Параметры shovel-а

| | |
|---|---|
| Name | `moscow-remains` |
| `src-uri` | `amqp://moscow:Msk7x!9wQp2vLmKe4tRn@93.125.18.73:5672` |
| `src-queue` | `remains_for_moscow` |
| `dest-exchange` | `external.remains` (fanout) |
| `ack-mode` | `on-confirm` |
| `add-forward-headers` | `false` |
| `delete-after` | `never` |
| TTL сообщений в источнике | 3 дня (старые удаляются автоматически) |

### Переменные окружения

Добавь в `/srv/pecado/.env` на dev-сервере (одноразовая настройка):

```dotenv
MOSCOW_ESB_AMQP_URI="amqp://moscow:Msk7x!9wQp2vLmKe4tRn@93.125.18.73:5672"
MOSCOW_ESB_SRC_QUEUE=remains_for_moscow
MOSCOW_ESB_SHOVEL_PREFETCH=1000
MOSCOW_ESB_SHOVEL_RECONNECT_DELAY=5
```

Если `MOSCOW_ESB_AMQP_URI` пустой, `rabbitmq:setup` только создаст fanout и очереди, но shovel пропустит (локальный dev без доступа к ESB).

> Плагины `rabbitmq_shovel` и `rabbitmq_shovel_management` включены через файл `docker/rabbitmq/enabled_plugins`, который монтируется в контейнер. Персистентность RabbitMQ обеспечена volume `rabbitmq-data`.

> Сообщения в `remains_for_moscow` копятся сразу, как только ESB пришлёт очередной апдейт остатков. TTL 3 дня — если shovel задержится с подключением, непрочитанные сообщения уйдут сами.

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

## DaData (подсказки реквизитов компаний)

Бесплатный «Лёгкий» тариф, лимит 10 000 запросов/день. Используется для автозаполнения формы компании по ИНН (см. `app/Services/DaData/DaDataClient.php` и прокси-роуты `/api/dadata/*` в `routes/web.php`).

| | |
|---|---|
| Личный кабинет | https://dadata.ru/profile/#info |
| API-ключ (env `DADATA_API_KEY`) | _записать сюда после регистрации_ |
| Секретный ключ (env `DADATA_SECRET_KEY`) | _записать сюда после регистрации_ |
| Suggestions URL | https://suggestions.dadata.ru/suggestions/api/4_1/rs |
| Cache TTL (ИНН → реквизиты) | 86400 сек (24 часа) |

> Ключи **не должны** попадать в Git. Для dev-сервера записать в `/srv/pecado/.env` через SSH (см. секцию «Сервер» выше). Для локальной разработки — в `.env` в корне проекта.
