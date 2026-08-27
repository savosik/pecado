<?php

namespace App\Http\Middleware;

use App\Models\Category;
use App\Models\MenuItem;
use App\Models\User;
use App\Support\Impersonation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user()?->loadMissing(['roles', 'clientStatus']);

        // Менеджер смотрит сайт от имени клиента: нужна плашка на всех страницах.
        $impersonating = Impersonation::active();
        $impersonator = $impersonating ? User::find(Impersonation::managerId()) : null;

        return [
            ...parent::share($request),
            'appName' => config('app.name'),
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'phone' => $user->phone,
                    'email' => $user->email,
                    'status' => $user->status?->value,
                    // is_staff — витрина показывает цены сотруднику независимо от status.
                    // is_admin/is_crm — только ссылки на панели, у них разные условия.
                    'is_staff' => $user->isStaff(),
                    'is_admin' => $user->hasAdminAccess(),
                    'is_crm' => $user->hasCrmAccess(),
                    'is_wms' => $user->hasWmsAccess(),
                    // В режиме просмотра флаг гасим: у клиентов из 1С он взведён,
                    // и ChangePasswordDialog закрыл бы менеджеру экран диалогом,
                    // который нельзя ни закрыть, ни отправить (пароль он не знает).
                    'must_change_password' => ! $impersonating && (bool) $user->must_change_password,
                    'client_status_color' => $user->clientStatus?->color,
                    'client_status_name' => $user->clientStatus?->name,
                ] : null,
            ],
            // Плашка «просмотр от имени клиента». null — обычная сессия.
            'impersonation' => $impersonating && $user ? [
                'client_name' => $user->display_name,
                'manager_name' => $impersonator?->name,
                'stop_url' => route('impersonation.stop'),
            ] : null,
            'currency' => $request->user() ? fn () => [
                'code' => $request->user()->region?->currency?->code ?? 'RUB',
                'name' => $request->user()->region?->currency?->name ?? 'Российский рубль',
                'symbol' => $request->user()->region?->currency?->symbol ?? '₽',
            ] : null,
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'warning' => fn () => $request->session()->get('warning'),
                'info' => fn () => $request->session()->get('info'),
                'onboarding_completed' => fn () => $request->session()->get('onboarding_completed'),
                'stock_conflicts' => fn () => $request->session()->get('stock_conflicts'),
            ],
            'footerCategories' => Cache::remember('footer.categories', 3600, fn () => Category::active()->whereIsRoot()->select('id', 'name', 'slug')->limit(5)->get()
            ),
            'headerMenuItems' => Cache::remember('menu.header', 3600, fn () => MenuItem::published()->forHeader()->ordered()->get()
            ),
            'footerMenuItems' => Cache::remember('menu.footer', 3600, fn () => MenuItem::published()->forFooter()->ordered()->get()
            ),
            'bugReportMode' => (bool) config('app.bug_report_mode'),
            'config' => [
                'yandex_maps_api_key' => (string) config('services.yandex_maps.api_key', ''),
                // Показывать ли клиенту его долги. Флаг нужен и на фронте: пункт меню
                // и денежные блоки прячутся здесь, а данные — в контроллерах.
                'cabinet_finance_enabled' => \App\Support\Cabinet\CabinetFinance::enabledFor($request->user()),
                // Раздел «Документы» (v16.1.0). Два флага, а не один: менеджеры
                // сверяют печатные формы раньше, чем их увидит клиент.
                'documents_enabled' => (bool) config('documents.enabled'),
                'documents_crm_enabled' => (bool) config('documents.crm_enabled'),
                // Раздел «Договоры» в кабинете партнёра: выключатель на случай,
                // если реестр ещё не выверен и показывать его клиентам рано.
                'contracts_cabinet_enabled' => (bool) config('contracts.cabinet_enabled'),
            ],
        ];
    }
}
