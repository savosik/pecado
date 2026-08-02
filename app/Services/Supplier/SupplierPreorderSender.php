<?php

namespace App\Services\Supplier;

use App\Enums\OrderType;
use App\Models\Order;
use App\Models\SupplierPreorderRequest;
use Illuminate\Support\Facades\Log;

/**
 * Отправка предзаказа поставщику: «поставьте позиции в резерв».
 *
 * Предзаказ на сайте — это обещание клиенту привезти товар, которого нет на
 * складе. Чтобы обещание было обеспечено, тот же состав уходит поставщику
 * (Customer API sex-opt.ru, метод send_order) на склад из конфига (по умолчанию tmn).
 *
 * Каждая попытка пишется в supplier_preorder_requests целиком, вместе с ответом
 * поставщика — менеджер в админке видит и warnings (нехватка, неизвестные коды),
 * и причину отказа.
 */
class SupplierPreorderSender
{
    public function __construct(private readonly SupplierOrderApi $api) {}

    /**
     * Отправлять ли предзаказ автоматически.
     *
     * Заказы, приехавшие из 1С, сюда не попадают: их поставщику отправлять
     * нельзя — резерв по ним ставится на стороне 1С.
     */
    public function shouldSendAutomatically(Order $order): bool
    {
        if (! config('services.sex_opt.preorder.enabled')) {
            return false;
        }

        if ($this->orderType($order) !== OrderType::PREORDER->value) {
            return false;
        }

        return ! $this->alreadySent($order);
    }

    /**
     * Заказ уже создан у поставщика (успешная попытка есть в логе).
     *
     * Тестовые отправки и откаты не считаются: заказа у поставщика они не создают.
     */
    public function alreadySent(Order $order): bool
    {
        return SupplierPreorderRequest::query()
            ->where('order_id', $order->id)
            ->where('status', SupplierPreorderRequest::STATUS_SUCCESS)
            ->exists();
    }

    /**
     * Комментарий к заказу поставщика — по нему сотрудник поставщика понимает,
     * что позиции нужно зарезервировать под конкретный предзаказ.
     */
    public function buildComment(Order $order): string
    {
        // Для заказа из 1С number — это её же номер документа; erp_number
        // подстрахует случай, когда номер приехал только в него.
        $number = $order->number ?: ($order->erp_number ?: '#'.$order->id);

        return 'предзаказ '.$number.' поставьте в резерв.';
    }

    /**
     * Выполнить отправку и записать результат в лог.
     *
     * @param  int|null  $triggeredBy  пользователь, нажавший «Отправить повторно»
     */
    public function send(Order $order, ?int $triggeredBy = null): SupplierPreorderRequest
    {
        $order->loadMissing('items.product');

        $stock = (string) config('services.sex_opt.preorder.stock', 'tmn');
        $testmode = (bool) config('services.sex_opt.preorder.testmode');
        $rollbackOnWarnings = (bool) config('services.sex_opt.preorder.rollback_on_warnings');
        $comment = $this->buildComment($order);

        ['items' => $items, 'skipped' => $skipped] = $this->collectItems($order);

        $attempt = SupplierPreorderRequest::where('order_id', $order->id)->max('attempt');
        $attempt = (int) $attempt + 1;

        $base = [
            'order_id' => $order->id,
            'attempt' => $attempt,
            'stock' => $stock,
            'testmode' => $testmode,
            'comment' => $comment,
            'items_count' => count($items),
            'skipped_items' => $skipped ?: null,
            'triggered_by' => $triggeredBy,
        ];

        // Без кодов 1С поставщику отправлять нечего — фиксируем ошибку и выходим:
        // менеджер увидит в логе, у каких товаров не заполнен код.
        if ($items === []) {
            Log::warning('Предзаказ не отправлен поставщику: нет позиций с кодом 1С', [
                'order_id' => $order->id,
                'order_number' => $order->number,
            ]);

            return SupplierPreorderRequest::create($base + [
                'status' => SupplierPreorderRequest::STATUS_FAILED,
                'request_payload' => $this->requestPayload($stock, $comment, $items, $testmode, $rollbackOnWarnings),
                'error_message' => 'Ни у одной позиции заказа не заполнен код товара из 1С',
            ]);
        }

        $result = $this->api->sendOrder($items, $stock, $comment, $testmode, $rollbackOnWarnings);
        $json = $result['json'];

        $status = $this->resolveStatus($result, $testmode);
        $error = $this->resolveError($result, $status);

        $record = SupplierPreorderRequest::create($base + [
            'status' => $status,
            'request_payload' => $this->requestPayload($stock, $comment, $items, $testmode, $rollbackOnWarnings),
            'response_payload' => $json,
            'response_raw' => $result['raw'],
            'http_status' => $result['http_status'],
            'supplier_order_id' => isset($json['order']['id']) ? (string) $json['order']['id'] : null,
            'error_message' => $error,
            'duration_ms' => $result['duration_ms'],
        ]);

        $context = [
            'order_id' => $order->id,
            'order_number' => $order->number,
            'stock' => $stock,
            'testmode' => $testmode,
            'supplier_order_id' => $record->supplier_order_id,
        ];

        if ($status === SupplierPreorderRequest::STATUS_FAILED) {
            Log::error('Предзаказ не отправлен поставщику: '.$error, $context);
        } else {
            Log::info('Предзаказ отправлен поставщику ('.$record->statusLabel().')', $context);
        }

        return $record;
    }

    /**
     * Состав заказа в формате API поставщика: код товара из 1С => количество.
     *
     * Одинаковые коды суммируются: у поставщика ключ уникален, а в заказе один
     * и тот же товар может встретиться несколькими строками (например, промо).
     *
     * @return array{items: array<string, int>, skipped: list<array<string, mixed>>}
     */
    private function collectItems(Order $order): array
    {
        $items = [];
        $skipped = [];

        foreach ($order->items as $item) {
            $code = $item->product?->code ?: $item->product?->sku;

            if (! $code) {
                $skipped[] = [
                    'order_item_id' => $item->id,
                    'product_id' => $item->product_id,
                    'name' => $item->name,
                    'quantity' => (int) $item->quantity,
                    'reason' => 'Не заполнен код товара из 1С',
                ];

                continue;
            }

            $code = (string) $code;
            $items[$code] = ($items[$code] ?? 0) + (int) $item->quantity;
        }

        return ['items' => $items, 'skipped' => $skipped];
    }

    /**
     * Тело запроса для лога — без api_key.
     *
     * @param  array<string, int>  $items
     * @return array<string, mixed>
     */
    private function requestPayload(string $stock, string $comment, array $items, bool $testmode, bool $rollbackOnWarnings): array
    {
        return [
            'method' => 'send_order',
            'data' => [
                'stock' => $stock,
                'comment' => $comment,
                'items' => $items,
                'testmode' => $testmode,
                'rollback_on_warnings' => $rollbackOnWarnings,
            ],
        ];
    }

    /**
     * Классификация ответа поставщика.
     *
     * result=success говорит лишь о том, что запрос составлен верно; создан ли
     * заказ, показывает transaction=commit. В testmode поставщик всегда
     * откатывает транзакцию — это ожидаемый исход, а не ошибка.
     *
     * @param  array{ok: bool, json: array<string, mixed>|null, error: string|null}  $result
     */
    private function resolveStatus(array $result, bool $testmode): string
    {
        $json = $result['json'];

        if (! $result['ok'] || ! is_array($json)) {
            return SupplierPreorderRequest::STATUS_FAILED;
        }

        if (($json['result'] ?? null) !== 'success') {
            return SupplierPreorderRequest::STATUS_FAILED;
        }

        if ($testmode) {
            return SupplierPreorderRequest::STATUS_TESTMODE;
        }

        return ($json['transaction'] ?? null) === 'commit'
            ? SupplierPreorderRequest::STATUS_SUCCESS
            : SupplierPreorderRequest::STATUS_ROLLBACK;
    }

    /**
     * @param  array{json: array<string, mixed>|null, error: string|null}  $result
     */
    private function resolveError(array $result, string $status): ?string
    {
        if ($status !== SupplierPreorderRequest::STATUS_FAILED) {
            return null;
        }

        $json = $result['json'];

        if (is_array($json)) {
            $error = $json['error'] ?? null;

            if (is_array($error)) {
                $message = $error['message'] ?? json_encode($error, JSON_UNESCAPED_UNICODE);

                return trim(($error['code'] ?? '').' '.$message) ?: 'Поставщик вернул ошибку';
            }

            if (is_string($error) && $error !== '') {
                return $error;
            }

            if (($json['result'] ?? null) === 'error') {
                return json_encode($json, JSON_UNESCAPED_UNICODE) ?: 'Поставщик вернул ошибку';
            }
        }

        return $result['error'] ?? 'Неизвестная ошибка при обращении к API поставщика';
    }

    private function orderType(Order $order): string
    {
        return $order->type->value;
    }
}
