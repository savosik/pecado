<?php

namespace App\Services\Client\Api\Operations;

use App\Enums\ReturnReason;
use App\Models\ProductReturn;
use App\Models\User;
use App\Services\Client\Api\Envelope;
use App\Services\Client\Api\Operation;
use App\Services\Client\Api\OperationProvider;
use App\Services\Returns\ClientReturnPresenter;
use App\Services\Returns\ClientReturnQuery;
use App\Services\Returns\ReturnableShipmentItems;
use App\Services\Returns\ReturnService;
use App\Support\OperationApi\OperationInput;
use App\Support\OperationApi\Param;
use Illuminate\Validation\ValidationException;

/**
 * Возвраты: свои заявки, основания (реализации и их строки), создание.
 *
 * Согласование возврата остаётся в 1С — API только создаёт заявку и читает
 * её статус, который приезжает эхом `return.updated`.
 */
class ReturnOperations implements OperationProvider
{
    use ResolvesClientEntities;

    public function __construct(
        private readonly ClientReturnQuery $query,
        private readonly ClientReturnPresenter $presenter,
        private readonly ReturnableShipmentItems $bases,
        private readonly ReturnService $returns,
    ) {}

    public static function section(): array
    {
        return ['returns', 'Возвраты'];
    }

    public static function operations(): array
    {
        return [
            new Operation(
                id: 'returns.list', section: 'returns', method: 'GET', uri: 'returns',
                summary: 'Мои возвраты с фильтрами',
                description: 'Статусы зеркалят 1С: pending_approval → for_return → in_reserve → ready_for_shipment → '
                    .'completed / rejected. Поиск — по номеру возврата или реализации (в т. ч. без дефисов), '
                    .'товару, бренду, штрихкоду, комментарию.',
                params: [
                    Param::list('status', 'Статусы', rules: []),
                    Param::list('reason', 'Причины', rules: []),
                    Param::string('search', 'Строка поиска', rules: ['max:200']),
                    Param::string('date_from', 'С даты (YYYY-MM-DD)', rules: ['date']),
                    Param::string('date_to', 'По дату (YYYY-MM-DD)', rules: ['date']),
                    Param::string('cursor', 'Курсор страницы из meta.next_cursor'),
                    Param::integer('per_page', 'Строк на странице, до 100', rules: ['min:1', 'max:100']),
                ],
                handler: [self::class, 'list'],
            ),
            new Operation(
                id: 'returns.shipments', section: 'returns', method: 'GET', uri: 'returns/shipments',
                summary: 'Реализации-основания для возврата',
                description: 'Реализации клиента (кроме внутренних организаций) по строке поиска: номер, товар, бренд, '
                    .'штрихкод. С числом ещё не закрытых возвратов по каждой. До 20 строк.',
                params: [Param::string('q', 'Строка поиска (пусто — последние реализации)', rules: ['max:200'])],
                handler: [self::class, 'shipments'],
            ),
            new Operation(
                id: 'returns.shipment-items', section: 'returns', method: 'GET', uri: 'returns/shipment-items',
                summary: 'Строки реализации с доступным к возврату количеством',
                description: 'shipment — id реализации из returns.shipments. Для каждой строки: отгружено, уже возвращено, доступно.',
                params: [Param::integer('shipment', 'id реализации', true, ['min:1'])],
                handler: [self::class, 'shipmentItems'],
            ),
            new Operation(
                id: 'returns.get', section: 'returns', method: 'GET', uri: 'returns/{return}',
                summary: 'Карточка возврата',
                description: 'Состав с основаниями, причины, статус, продавец.',
                params: [Param::integer('return', 'id возврата', true)],
                handler: [self::class, 'get'],
            ),
            new Operation(
                id: 'returns.create', section: 'returns', method: 'POST', uri: 'returns',
                summary: 'Создать заявку на возврат по строкам реализации',
                description: 'items — строки {shipment_item_id, quantity, reason, reason_comment?}; shipment_item_id из '
                    .'returns.shipment-items, quantity не больше доступного (иначе 422). Причины: '
                    .implode(', ', array_column(ReturnReason::cases(), 'value')).'. Принимает Idempotency-Key.',
                params: [
                    Param::string('comment', 'Комментарий к возврату', rules: ['max:2000'], nullable: true),
                    Param::list('items', 'Строки возврата', 'object', true, ['min:1']),
                ],
                handler: [self::class, 'create'],
                mutating: true, idempotent: true,
            ),
        ];
    }

    /** @return array<string, mixed> */
    public function list(User $actor, OperationInput $input): array
    {
        $query = $this->query->builder($actor, $input->only(['search', 'status', 'reason', 'date_from', 'date_to']));
        $query->orderByDesc('created_at')->orderByDesc('id');

        $paginator = $query->cursorPaginate(Envelope::perPage($input->get('per_page'), 50), ['*'], 'cursor', $input->string('cursor'));

        return Envelope::cursor($paginator, fn (ProductReturn $return) => $this->presenter->row($return, iso: true));
    }

    /** @return array<string, mixed> */
    public function get(User $actor, OperationInput $input): array
    {
        $return = ProductReturn::query()->where('user_id', $actor->id)->whereKey((int) $input->int('return'))->firstOrFail();

        return Envelope::data($this->presenter->card($return, iso: true));
    }

    /** @return array<string, mixed> */
    public function shipments(User $actor, OperationInput $input): array
    {
        return Envelope::data($this->bases->searchShipments($actor, (string) $input->string('q', ''))->values()->all());
    }

    /** @return array<string, mixed> */
    public function shipmentItems(User $actor, OperationInput $input): array
    {
        $shipment = $this->bases->shipmentFor($actor, (int) $input->int('shipment'));

        return Envelope::data($this->bases->forShipment($shipment));
    }

    /** @return array<string, mixed> */
    public function create(User $actor, OperationInput $input): array
    {
        $items = [];
        $reasons = array_column(ReturnReason::cases(), 'value');

        foreach ($input->array('items') as $i => $row) {
            $row = is_array($row) ? $row : [];
            $id = $row['shipment_item_id'] ?? null;
            $qty = $row['quantity'] ?? null;
            $reason = $row['reason'] ?? null;

            if (! is_numeric($id) || (int) $id < 1) {
                throw ValidationException::withMessages(["items.{$i}.shipment_item_id" => 'Выберите позицию реализации.']);
            }

            if (! is_numeric($qty) || (int) $qty < 1) {
                throw ValidationException::withMessages(["items.{$i}.quantity" => 'Количество должно быть не менее 1.']);
            }

            if (! in_array($reason, $reasons, true)) {
                throw ValidationException::withMessages(["items.{$i}.reason" => 'Недопустимая причина возврата.']);
            }

            $items[] = [
                'shipment_item_id' => (int) $id,
                'quantity' => (int) $qty,
                'reason' => $reason,
                'reason_comment' => isset($row['reason_comment']) ? (string) $row['reason_comment'] : null,
            ];
        }

        // Чужая строка реализации в сервисе даёт 403; для агента это «не найдено».
        $return = $this->returns->createForUser($actor, [
            'comment' => $input->string('comment'),
            'items' => $items,
        ]);

        return Envelope::data($this->presenter->card($return->fresh(['items']), iso: true), ['created' => true]);
    }
}
