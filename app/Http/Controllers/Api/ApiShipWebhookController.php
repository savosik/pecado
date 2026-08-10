<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Delivery\DeliveryStatusSynchronizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Приём вебхука ORDER_STATUS от ApiShip.
 *
 * ApiShip считает доставку неудачной при HTTP ≥ 500 или ответе дольше 30 секунд
 * и повторяет её до трёх раз. Поэтому отвечаем 200 на всё, что не является
 * проблемой аутентификации: неизвестный номер отправки или мусорный payload —
 * это наша забота, а не повод заставлять ApiShip ретраить.
 *
 * @see https://docs.apiship.ru/docs/api/webhooks/types/order-status/
 */
class ApiShipWebhookController extends Controller
{
    public function __construct(private readonly DeliveryStatusSynchronizer $synchronizer) {}

    public function handle(Request $request): JsonResponse
    {
        /** @var array<string, mixed> $orderInfo */
        $orderInfo = (array) $request->input('orderInfo', []);
        /** @var array<string, mixed> $status */
        $status = (array) $request->input('status', []);

        if ($orderInfo === [] || $status === []) {
            Log::warning('ApiShip: вебхук без orderInfo или status', ['payload' => $request->all()]);

            return response()->json(['status' => 'ignored']);
        }

        $delivery = $this->synchronizer->resolve($orderInfo);

        if ($delivery === null) {
            // Чужой или уже удалённый номер. Ретраить нечего — отвечаем успехом,
            // иначе ApiShip будет долбить эндпоинт тремя попытками на каждое событие.
            Log::info('ApiShip: вебхук по неизвестной отправке', [
                'client_number' => $orderInfo['clientNumber'] ?? null,
                'order_id' => $orderInfo['orderId'] ?? null,
            ]);

            return response()->json(['status' => 'unknown']);
        }

        $changed = $this->synchronizer->apply(
            $delivery,
            $orderInfo,
            $status,
            $this->synchronizer->sourceWebhook(),
        );

        return response()->json(['status' => $changed ? 'applied' : 'duplicate']);
    }
}
