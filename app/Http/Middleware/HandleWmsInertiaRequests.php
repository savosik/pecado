<?php

namespace App\Http\Middleware;

use App\Http\Middleware\Concerns\SharesPanelAuth;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleWmsInertiaRequests extends Middleware
{
    use SharesPanelAuth;

    protected $rootView = 'wms';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => $this->panelAuthProps($request->user()),
            'flash' => $this->panelFlashProps($request),
            // Флаги для меню панели: пункт с `feature` виден только при включённой функции.
            'config' => ['pickup' => (bool) config('pickup.wms_enabled')],
        ];
    }
}
