<?php

namespace App\Providers;

use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Horizon\Horizon;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(
            \App\Contracts\Pricing\PriceServiceInterface::class,
            \App\Services\Pricing\PriceService::class
        );

        $this->app->bind(
            \App\Contracts\Currency\CurrencyConversionServiceInterface::class,
            \App\Services\Currency\CurrencyConversionService::class
        );

        $this->app->bind(
            \App\Contracts\Currency\UserCurrencyResolverInterface::class,
            \App\Services\Currency\UserCurrencyResolver::class
        );

        $this->app->bind(
            \App\Contracts\Stock\StockServiceInterface::class,
            \App\Services\Stock\StockService::class
        );

        $this->app->bind(
            \App\Contracts\Defect\DefectStockServiceInterface::class,
            \App\Services\Defect\DefectStockService::class
        );

        $this->app->bind(
            \App\Contracts\Promotion\PromoStockServiceInterface::class,
            \App\Services\Promotion\PromoStockService::class
        );

        $this->app->bind(
            \App\Contracts\Cart\CartServiceInterface::class,
            \App\Services\Cart\CartService::class
        );

        $this->app->bind(
            \App\Contracts\Order\CheckoutServiceInterface::class,
            \App\Services\Order\CheckoutService::class
        );

        $this->app->bind(
            \App\Contracts\Order\OrderRepositoryInterface::class,
            \App\Repositories\OrderRepository::class
        );

        // Проверка остатка и фонд промо — одна реализация: движок спрашивает
        // «сколько можно выдать», корзина и чекаут — «сколько свободно».
        // Scoped, потому что внутри живёт снимок доступности на одно вычисление
        // (см. PromoStockService::availableFor).
        $this->app->scoped(
            \App\Services\Promotion\PromoStockService::class,
            \App\Services\Promotion\PromoStockService::class
        );

        $this->app->bind(
            \App\Contracts\Promotion\PromoStockCheckerInterface::class,
            \App\Services\Promotion\PromoStockService::class
        );

        $this->app->bind(
            \App\Contracts\Promotion\PromoUsageCounterInterface::class,
            \App\Services\Promotion\NoPromoUsageHistory::class
        );

        $this->app->singleton(\App\Services\Promotion\ActivePromotionRuleCache::class);

        // v15.4: исход обработки входящего ERP-сообщения. Singleton — handler
        // помечает исход, ErpIncomingJob читает его после handle() и сбрасывает
        // перед следующим сообщением.
        $this->app->singleton(\App\Services\Erp\ErpHandlerOutcome::class);

        $this->app->singleton(\App\Services\Product\RichContent\BlockSchemaProvider::class, function () {
            return new \App\Services\Product\RichContent\BlockSchemaProvider(
                (string) config('rich_content.schema_path', base_path('docs/content-blocks-schema.json'))
            );
        });

        Scramble::ignoreDefaultRoutes();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Super-admin bypass — role «super-admin» получает все права автоматически
        \Illuminate\Support\Facades\Gate::before(function ($user, $ability) {
            return $user->hasRole('super-admin') ? true : null;
        });

        Horizon::auth(function ($request) {
            return app()->environment('local') ||
                ($request->user() && $request->user()->hasRole('super-admin'));
        });

        \Illuminate\Support\Facades\Gate::define('viewPulse', function ($user) {
            return app()->environment('local') || $user->hasRole('super-admin');
        });

        \Illuminate\Support\Facades\Event::listen(
            \App\Events\UserCreated::class,
            \App\Listeners\PublishUserToErp::class,
        );

        \Illuminate\Support\Facades\Event::listen(
            \App\Events\UserUpdated::class,
            \App\Listeners\PublishUserToErp::class,
        );

        // Message-ID письма менеджера известен только после отправки —
        // без него письмо не найти в логах почтового сервера.
        \Illuminate\Support\Facades\Event::listen(
            \Illuminate\Mail\Events\MessageSent::class,
            \App\Listeners\RecordCrmEmailMessageId::class,
        );

        \Illuminate\Support\Facades\Event::listen(
            \App\Events\CompanyCreated::class,
            \App\Listeners\PublishContractorToErp::class,
        );

        \Illuminate\Support\Facades\Event::listen(
            \App\Events\CompanyUpdated::class,
            \App\Listeners\PublishContractorToErp::class,
        );

        \App\Models\CompanyBankAccount::observe(\App\Observers\CompanyBankAccountObserver::class);

        // Инвалидация кешей публички (главная, шапка, подвал)
        \App\Models\Banner::observe(\App\Observers\BannerObserver::class);
        \App\Models\Story::observe(\App\Observers\StoryObserver::class);
        \App\Models\StorySlide::observe(\App\Observers\StorySlideObserver::class);
        \App\Models\ProductSelection::observe(\App\Observers\ProductSelectionObserver::class);
        \App\Models\MenuItem::observe(\App\Observers\MenuItemObserver::class);
        \App\Models\Category::observe(\App\Observers\CategoryObserver::class);
        \App\Models\Product::observe(\App\Observers\ProductHomeCacheObserver::class);

        // Материализация участников правил акций (promotion_rule_product)
        \App\Models\PromotionRule::observe(\App\Observers\PromotionRuleObserver::class);

        // v16.0.0: доклейка движений регистра взаиморасчётов к документу, который
        // приехал позже них. Очереди движений и документов независимы, порядок
        // не гарантирован ни в какую сторону.
        \App\Models\Shipment::observe(\App\Observers\SettlementLinkObserver::class);
        \App\Models\Order::observe(\App\Observers\SettlementLinkObserver::class);
        \App\Models\Payment::observe(\App\Observers\SettlementLinkObserver::class);

        // Инвалидация кеша версий выгрузок: после save/delete товара любая
        // выгрузка, сгенерированная ранее, считается устаревшей.
        // См. App\Services\ProductExport\ProductExportDataVersion.
        $bumpExportVersion = fn () => app(\App\Services\ProductExport\ProductExportDataVersion::class)->bump();
        \App\Models\Product::saved($bumpExportVersion);
        \App\Models\Product::deleted($bumpExportVersion);

        \Illuminate\Support\Facades\Event::listen(
            \App\Events\OrderCreated::class,
            \App\Listeners\PublishOrderToErp::class,
        );

        // Предзаказ клиента → заказ поставщику «поставьте в резерв» (через очередь)
        \Illuminate\Support\Facades\Event::listen(
            \App\Events\OrderCreated::class,
            \App\Listeners\SendPreorderToSupplier::class,
        );

        \Illuminate\Support\Facades\Event::listen(
            \App\Events\OrderUpdated::class,
            \App\Listeners\PublishOrderToErp::class,
        );

        \Illuminate\Support\Facades\Event::listen(
            \App\Events\OrderDeleted::class,
            \App\Listeners\PublishOrderToErp::class,
        );

        \Illuminate\Support\Facades\Event::listen(
            \App\Events\ReturnCreated::class,
            \App\Listeners\PublishReturnToErp::class,
        );

        \Illuminate\Support\Facades\Event::listen(
            \App\Events\ReturnCreated::class,
            \App\Listeners\SendReturnCreatedEmail::class,
        );

        \Illuminate\Support\Facades\Event::listen(
            \App\Events\ReturnStatusChanged::class,
            \App\Listeners\SendReturnStatusChangedEmail::class,
        );

        \Illuminate\Support\Facades\Event::listen(
            \App\Events\UserRegisteredOnSite::class,
            \App\Listeners\SendWelcomeEmail::class,
        );

        \Illuminate\Support\Facades\Event::listen(
            \App\Events\UserPasswordChanged::class,
            \App\Listeners\SendPasswordChangedEmail::class,
        );

        // Письмо клиенту — на покупку, а не на документ (см. SendOrdersPlacedEmail)
        \Illuminate\Support\Facades\Event::listen(
            \App\Events\OrdersPlaced::class,
            \App\Listeners\SendOrdersPlacedEmail::class,
        );

        \Illuminate\Support\Facades\Event::listen(
            \App\Events\OrdersPlaced::class,
            \App\Listeners\NotifyManagersAboutNewOrder::class,
        );

        \Illuminate\Support\Facades\Event::listen(
            \App\Events\OrderUpdated::class,
            \App\Listeners\SendOrderStatusChangedEmail::class,
        );

        // Универсальные подписки на изменения сущностей разделов кабинета
        \Illuminate\Support\Facades\Event::listen(
            \App\Events\EntityChanged::class,
            \App\Listeners\SendEntitySubscriptionNotifications::class,
        );

        // Rate limiter для Content API (ИИ-агент)
        RateLimiter::for('content-api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // Rate limiter для CRM API. Считаем по владельцу токена, а не по IP:
        // агенты нескольких менеджеров могут сидеть за одним офисным адресом,
        // и лимит по IP превратился бы в общий на весь отдел.
        RateLimiter::for('crm-api', function (Request $request) {
            return Limit::perMinute(120)->by('crm-api:'.($request->user()?->id ?: $request->ip()));
        });

        // Scramble: спека API (/docs/api, /docs/api.json) публично доступна.
        // По умолчанию RestrictedDocsAccess пускает только в local; открываем всем,
        // чтобы ИИ-агент мог скачать OpenAPI-контракт по URL на dev/prod.
        Gate::define('viewApiDocs', fn ($user = null) => true);

        // Scramble: документировать только /api/content/* роуты
        Scramble::registerApi('content', [
            'api_path' => 'api/content',
            'info' => [
                'title' => 'Pecado Content API',
                'version' => '1.0',
                'description' => 'API для ИИ-агента контент-менеджера. Авторизация через Bearer Token (Sanctum).',
            ],
        ])->afterOpenApiGenerated(function (OpenApi $openApi) {
            $openApi->secure(
                SecurityScheme::http('bearer', 'bearer')
            );
        });

        Scramble::registerUiRoute(path: 'docs/api', api: 'content');
        Scramble::registerJsonSpecificationRoute(path: 'docs/api.json', api: 'content');

        // Scramble: Kanban API — для LLM-агентов
        Scramble::registerApi('kanban', [
            'api_path' => 'api/kanban',
            'middleware' => ['web'],
            'info' => [
                'title' => 'Pecado Kanban API',
                'version' => '1.0',
                'description' => implode("\n\n", [
                    'Публичный API управления задачами канбан-доски.',
                    '**Авторизация не требуется.** API активируется переменной окружения `KANBAN_API_ENABLED=true`.',
                    '',
                    '## Основные сценарии для LLM-агентов',
                    '',
                    '1. **Получить задачи** — `GET /api/kanban/tasks?status=backlog`',
                    '2. **Взять задачу в работу** — `PATCH /api/kanban/tasks/{id}` с `{"status": "in_progress"}`',
                    '3. **Оставить комментарий** — `POST /api/kanban/tasks/{id}/comments`',
                    '4. **Закрыть задачу** — `PATCH /api/kanban/tasks/{id}` с `{"status": "done"}`',
                    '',
                    '## Доступные статусы',
                    '| Статус | Описание |',
                    '|--------|----------|',
                    '| `backlog` | Новые задачи, не распределённые в спринт |',
                    '| `todo` | К выполнению в текущем спринте |',
                    '| `in_progress` | В работе |',
                    '| `testing` | На тестировании |',
                    '| `done` | Выполнено |',
                    '| `reopen` | Открыто повторно |',
                    '',
                    '## Доступные типы',
                    '| Тип | Описание |',
                    '|-----|----------|',
                    '| `bug` | Ошибка в работе системы |',
                    '| `unexpected` | Странное поведение |',
                    '| `ux` | Неудобство в использовании |',
                    '| `cosmetic` | Визуальная проблема |',
                    '| `feature` | Новая функциональность |',
                    '| `improvement` | Улучшение существующего |',
                ]),
            ],
        ]);

        Scramble::registerUiRoute(path: 'docs/kanban-api', api: 'kanban');
        Scramble::registerJsonSpecificationRoute(path: 'docs/kanban-api.json', api: 'kanban');

        $this->registerCrmApiDocs();
    }

    /**
     * Документация CRM API.
     *
     * Спецификация не сканируется из контроллеров, а строится из
     * OperationRegistry: контроллер там один на все операции, и вывести из его
     * сигнатуры аргументы тридцати разных вызовов невозможно — зато в реестре
     * они уже описаны вместе с правилами проверки. Интерфейс при этом остаётся
     * тот же, что у /docs/api и /docs/kanban-api: чужой вёрстки не заводим.
     */
    private function registerCrmApiDocs(): void
    {
        Scramble::registerApi('crm', [
            'api_path' => 'api/crm',
            'info' => [
                'title' => 'Pecado CRM API',
                'version' => '1.0',
            ],
        ]);

        // Конфиг берётся внутри замыкания, а не захватывается через use: при
        // route:cache замыкание маршрута сериализуется вместе с захваченным
        // окружением, а GeneratorConfig хранит Closure-свойства и переживает
        // сериализацию объектом-сериализатором вместо замыкания — прод падает
        // на прогреве кешей. Сам Scramble в registerUiRoute делает так же.
        \Illuminate\Support\Facades\Route::get('docs/crm-api', function (\App\Services\Crm\Api\CrmApiDocument $document) {
            return view('scramble::docs', [
                'spec' => $document->build(),
                'config' => Scramble::getGeneratorConfig('crm'),
            ]);
        })->middleware(\Dedoc\Scramble\Http\Middleware\RestrictedDocsAccess::class);

        \Illuminate\Support\Facades\Route::get('docs/crm-api.json', function (\App\Services\Crm\Api\CrmApiDocument $document) {
            return response()->json($document->build(), options: JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        })->middleware(\Dedoc\Scramble\Http\Middleware\RestrictedDocsAccess::class);
    }
}
