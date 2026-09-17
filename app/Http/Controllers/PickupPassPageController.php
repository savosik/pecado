<?php

namespace App\Http\Controllers;

use App\Http\Controllers\User\PickupController;
use App\Services\Pickup\PickupPassService;
use App\Services\Warehouse\WarehouseSchedule;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Публичная страница пропуска для курьера (pick-10): без входа, по секретной ссылке.
 *
 * Ссылка может уйти куда угодно, поэтому на странице нет названия клиента, сумм и состава.
 * На несуществующий и недействующий токен ответ одинаковый — по нему нельзя перебирать пропуска.
 */
class PickupPassPageController extends Controller
{
    public function __invoke(string $token, PickupPassService $passes, WarehouseSchedule $schedule): Response
    {
        abort_unless((bool) config('pickup.enabled'), 404);

        $pass = $passes->findByToken($token);
        $usable = $pass !== null && $pass->isUsable();

        $props = ['schedule' => $this->publicSchedule($schedule), 'pass' => null];

        if ($usable) {
            $items = $passes->contents($pass);
            $ready = $items->where('state', 'ready');
            $picking = $items->where('state', 'picking');

            $props['pass'] = [
                'code' => $pass->code_display,
                'qr' => PickupController::qrDataUri(route('pickup.pass', ['token' => $token])),
                'expires_text' => 'до '.$pass->expires_at->timezone($schedule->timezone())->format('d.m H:i'),
                'state' => match (true) {
                    $ready->isNotEmpty() => 'ready',
                    $picking->isNotEmpty() => 'picking',
                    default => 'empty',
                },
                'ready_sets' => $ready->count(),
                'ready_packages' => (int) $ready->sum('packages_count'),
                'picking_sets' => $picking->count(),
                'handed_sets' => $items->where('state', 'handed')->count(),
                'orders' => $items->whereIn('state', ['ready', 'picking'])
                    ->flatMap(fn (array $row) => collect($row['orders'])->map(fn (array $o) => [
                        'number' => $o['number'],
                        'state' => $row['state'],
                    ]))->values()->all(),
            ];
        }

        return Inertia::render('Pickup/Pass', $props)
            ->toResponse(request())
            ->setStatusCode($usable ? 200 : 404)
            ->withHeaders([
                'X-Robots-Tag' => 'noindex, nofollow, noarchive',
                'Referrer-Policy' => 'no-referrer',
                'Cache-Control' => 'no-store, private',
            ]);
    }

    /** @return array<string, mixed> */
    private function publicSchedule(WarehouseSchedule $schedule): array
    {
        $today = $schedule->today(now());

        return [
            'is_open' => $today['is_open'],
            'closes_at' => $today['closes_at'],
            'week_text' => $today['week_text'],
            'address' => $today['address'],
            'how_to_find' => $today['how_to_find'],
            'phone' => $today['phone'],
        ];
    }
}
