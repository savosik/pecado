<?php

namespace App\Http\Middleware;

use App\Models\Category;
use App\Models\MenuItem;
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
        return [
            ...parent::share($request),
            'appName' => config('app.name'),
            'auth' => [
                'user' => $request->user() ? [
                    'id' => $request->user()->id,
                    'name' => $request->user()->name,
                    'phone' => $request->user()->phone,
                    'email' => $request->user()->email,
                    'status' => $request->user()->status->value,
                    'is_admin' => $request->user()->loadMissing(['roles', 'clientStatus'])->roles->isNotEmpty(),
                    'must_change_password' => (bool) $request->user()->must_change_password,
                    'client_status_color' => $request->user()->clientStatus?->color,
                    'client_status_name' => $request->user()->clientStatus?->name,
                ] : null,
            ],
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
            ],
            'footerCategories' => Cache::remember('footer.categories', 3600, fn () => Category::active()->whereIsRoot()->select('id', 'name', 'slug')->limit(5)->get()
            ),
            'headerMenuItems' => Cache::remember('menu.header', 3600, fn () => MenuItem::published()->forHeader()->ordered()->get()
            ),
            'footerMenuItems' => Cache::remember('menu.footer', 3600, fn () => MenuItem::published()->forFooter()->ordered()->get()
            ),
        ];
    }
}
