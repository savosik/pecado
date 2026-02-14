<?php

namespace App\Http\Middleware;

use App\Models\Currency;
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
            'auth' => [
                'user' => $request->user() ? [
                    'id' => $request->user()->id,
                    'name' => $request->user()->name,
                    'surname' => $request->user()->surname,
                    'email' => $request->user()->email,
                    'is_admin' => $request->user()->is_admin,
                ] : null,
            ],
            'currency' => $request->user() ? fn () => [
                'code'   => $request->user()->currency?->code ?? 'RUB',
                'name'   => $request->user()->currency?->name ?? 'Российский рубль',
                'symbol' => $request->user()->currency?->symbol ?? '₽',
                'available' => Cache::remember('currencies.list', 3600,
                    fn () => Currency::select('id', 'code', 'name', 'symbol')->get()
                ),
            ] : null,
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'warning' => fn () => $request->session()->get('warning'),
                'info' => fn () => $request->session()->get('info'),
            ],
        ];
    }
}
