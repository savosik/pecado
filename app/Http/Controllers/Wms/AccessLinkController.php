<?php

namespace App\Http\Controllers\Wms;

use App\Http\Controllers\User\PickupController as CabinetPickupController;
use App\Models\WmsAccessLink;
use App\Services\Wms\AccessLinkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «Ссылки для кладовщиков» (pick-17): выпустить, показать QR, перевыпустить, отключить.
 * Право `wms-access.edit` — только у начальника склада.
 */
class AccessLinkController extends WmsController
{
    public function __construct(private readonly AccessLinkService $links) {}

    public function index(): Response
    {
        return Inertia::render('Wms/Pages/AccessLinks/Index', ['links' => $this->rows()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'min:2', 'max:120']], [], ['name' => 'название']);

        [$link] = $this->links->create($data['name'], $this->wmsActor($request));

        return response()->json(['ok' => true, 'message' => 'Ссылка выпущена. Перешлите её кладовщику.', 'links' => $this->rows()]);
    }

    public function regenerate(WmsAccessLink $link): JsonResponse
    {
        $this->links->regenerate($link);

        return response()->json(['ok' => true, 'message' => 'Ссылка перевыпущена: старая больше не входит, телефоны с ней выйдут сами.', 'links' => $this->rows()]);
    }

    public function revoke(WmsAccessLink $link): JsonResponse
    {
        $this->links->revoke($link);

        return response()->json(['ok' => true, 'message' => 'Ссылка отключена.', 'links' => $this->rows()]);
    }

    /** @return array<int, array<string, mixed>> */
    private function rows(): array
    {
        return WmsAccessLink::query()->with(['user:id,name', 'creator:id,name'])->orderByDesc('id')->get()
            ->map(function (WmsAccessLink $link) {
                $url = $link->isActive() ? $this->links->url($link) : null;

                return [
                    'id' => $link->id,
                    'name' => $link->name,
                    'account' => $link->user?->name,
                    'url' => $url,
                    'qr' => $url ? CabinetPickupController::qrDataUri($url) : null,
                    'is_active' => $link->isActive(),
                    'uses_count' => $link->uses_count,
                    'last_used_at' => $link->last_used_at?->format('d.m.Y H:i'),
                    'rotated_at' => $link->rotated_at?->format('d.m.Y H:i'),
                    'created_at' => $link->created_at?->format('d.m.Y H:i'),
                    'created_by' => $link->creator?->name,
                ];
            })->values()->all();
    }
}
