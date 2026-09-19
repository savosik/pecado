<?php

/*
|--------------------------------------------------------------------------
| Помощник клиента в кабинете (эпик assist-00)
|--------------------------------------------------------------------------
|
| Чат с ИИ-агентом на всех страницах сайта для авторизованного клиента.
| Агент — тот же `/mcp/client` с теми же правами: запрос уходит в Messages API
| Anthropic с MCP-коннектором и короткоживущим токеном клиента, а операции
| выполняет наш MCP-сервер. Второго пути к операциям нет намеренно.
|
| Все пороги, форматы и фразы — здесь, а не в коде и не в вёрстке.
|
*/

return [

    /*
     * Главный рубильник. Выключен — ни иконки, ни маршрутов, ни воркера.
     * Решение заказчика 19.09.2026: запуск сразу всем клиентам кабинета,
     * персональных флагов нет.
     */
    'enabled' => (bool) env('CLIENT_ASSISTANT_ENABLED', false),

    /*
     * Ключ организации Anthropic. Прод ходит к API только через контейнер
     * outbound-proxy (напрямую — 403 по региону), поэтому прокси наследуется
     * от OpenRouter, если свой не задан.
     */
    'api_key' => env('ANTHROPIC_API_KEY'),
    'proxy' => env('ANTHROPIC_PROXY', env('OPENROUTER_PROXY')),
    'base_url' => env('ANTHROPIC_BASE_URL'),
    'timeout' => (int) env('CLIENT_ASSISTANT_TIMEOUT', 300),

    /*
     * Модель и усилие. Opus 5 на низком усилии — стартовая точка: чат не
     * задача для xhigh, а цена низкого усилия в разы меньше. Перевод рутины
     * на Sonnet 5 — одна переменная окружения, без релиза.
     */
    'model' => env('CLIENT_ASSISTANT_MODEL', 'claude-opus-5'),
    'effort' => env('CLIENT_ASSISTANT_EFFORT', 'low'),
    'max_tokens' => (int) env('CLIENT_ASSISTANT_MAX_TOKENS', 16000),

    /*
     * Беты Messages API, от которых зависит конструкция. Версии зафиксированы
     * здесь, чтобы смена поведения на стороне Anthropic была осознанным шагом.
     */
    'betas' => [
        'mcp' => 'mcp-client-2025-11-20',
        'compaction' => 'compact-2026-01-12',
        'fallback' => 'server-side-fallback-2026-07-01',
    ],

    /*
     * MCP-сервер клиента глазами Anthropic: публичный адрес, до которого их
     * сервер достучится сам. Пусто — берётся APP_URL + /mcp/client.
     */
    'mcp' => [
        'name' => 'pecado',
        'url' => env('CLIENT_ASSISTANT_MCP_URL'),
    ],

    /*
     * Токен чата: отдельный вид `assistant` в api_tokens, живёт TTL минут,
     * продлевается при активности и отзывается по закрытии треда. Полный
     * вечный токен клиента наружу не отдаётся — он проходит через Anthropic.
     */
    'token_ttl_minutes' => (int) env('CLIENT_ASSISTANT_TOKEN_TTL', 60),

    /*
     * Необратимые операции, которые из чата выполняются только по кнопке
     * клиента (ворота в OperationRunner для токенов вида assistant).
     */
    'confirm_operations' => [
        'orders.create',
        'checkout.submit',
        'orders.cancel',
        'orders.repeat',
        'reserves.confirm',
        'returns.create',
        'questions.create',
        'payment-orders.send',
    ],

    /*
     * Потолки расходов (в дополнение к лимиту workspace в консоли Anthropic).
     * Персональная квота юрлица видна одному клиенту («помощник отдыхает»),
     * месячный предел организации прячет помощника у всех.
     */
    'quotas' => [
        'company_daily_turns' => (int) env('CLIENT_ASSISTANT_COMPANY_DAILY_TURNS', 120),
        'company_daily_tokens' => (int) env('CLIENT_ASSISTANT_COMPANY_DAILY_TOKENS', 600000),
        'thread_max_tokens' => (int) env('CLIENT_ASSISTANT_THREAD_MAX_TOKENS', 400000),
        'org_monthly_usd' => (float) env('CLIENT_ASSISTANT_ORG_MONTHLY_USD', 300),
    ],

    /*
     * Прайс за 1M токенов в долларах: стоимость хода считается из usage ответа
     * и хранится в chat_messages. Первичен прайс Anthropic, здесь — копия для
     * учёта; чтение кеша — десятая часть входной цены (Fable 5.1 — сороковая).
     */
    'pricing' => [
        'claude-opus-5' => ['input' => 5.0, 'output' => 25.0, 'cache_read' => 0.5, 'cache_write' => 6.25],
        'claude-opus-4-8' => ['input' => 5.0, 'output' => 25.0, 'cache_read' => 0.5, 'cache_write' => 6.25],
        'claude-sonnet-5' => ['input' => 2.0, 'output' => 10.0, 'cache_read' => 0.2, 'cache_write' => 2.5],
        'claude-haiku-4-5' => ['input' => 1.0, 'output' => 5.0, 'cache_read' => 0.1, 'cache_write' => 1.25],
        'claude-fable-5-1' => ['input' => 10.0, 'output' => 50.0, 'cache_read' => 0.25, 'cache_write' => 12.5],
    ],

    /*
     * Доступность: кончился баланс, лимит workspace, нет ключа, лёг прокси —
     * помощник исчезает у всех целиком. Пока флаг снят, планировщик раз в
     * `probe_minutes` делает пробный запрос на один токен и возвращает
     * помощника сам. Уведомление РОПу о смене состояния — не чаще раза в час.
     */
    'availability' => [
        'probe_minutes' => (int) env('CLIENT_ASSISTANT_PROBE_MINUTES', 10),
        'notify_cooldown_minutes' => 60,
    ],

    /*
     * Вложения: разрешённые форматы и размеры проверяются одинаково на фронте
     * (до загрузки, по-русски) и на бэке (по содержимому, не по расширению).
     */
    'attachments' => [
        'max_size_kb' => (int) env('CLIENT_ASSISTANT_ATTACHMENT_MAX_KB', 20480),
        'max_per_message' => 5,
        'mimes' => [
            // изображения — уходят блоком image, модель читает сама
            'image/jpeg' => 'image',
            'image/png' => 'image',
            'image/webp' => 'image',
            'image/gif' => 'image',
            'image/heic' => 'image',
            // документы — блоком document
            'application/pdf' => 'document',
            // таблицы и docx — через Files API + выполнение кода в песочнице Anthropic
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'table',
            'application/vnd.ms-excel' => 'table',
            'text/csv' => 'table',
            'text/plain' => 'text',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'table',
        ],
        'disk' => env('CLIENT_ASSISTANT_ATTACHMENT_DISK', 's3'),
        'directory' => 'assistant',
    ],

    /*
     * Тред закрывается, если в нём молчат дольше `idle_hours`: тогда модель
     * дописывает заметку о клиенте и резюме, а токен чата отзывается.
     */
    'threads' => [
        'idle_hours' => (int) env('CLIENT_ASSISTANT_THREAD_IDLE_HOURS', 24),
        'history_limit' => 200,
    ],

    /*
     * Реплики иконки-консультанта: правила частоты. Сами фразы — в
     * App\Services\Assistant\Prompts\PromptCatalog, они зависят от данных.
     */
    'bubbles' => [
        'enabled' => (bool) env('CLIENT_ASSISTANT_BUBBLES', true),
        'min_page_views_between' => 3,
        'min_seconds_between' => 90,
        'max_per_session' => 6,
        'show_seconds' => 8,
        'snooze_after_dismissals' => 3,
        'snooze_hours' => 24,
    ],

    /*
     * Голосовой ввод: распознавание браузера бесплатно и без сервера;
     * серверное (Whisper через прокси) — кандидат после пилота.
     */
    'voice' => [
        'browser' => (bool) env('CLIENT_ASSISTANT_VOICE', true),
        'server_stt' => (bool) env('CLIENT_ASSISTANT_SERVER_STT', false),
    ],

];
