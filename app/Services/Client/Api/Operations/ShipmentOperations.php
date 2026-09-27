<?php

namespace App\Services\Client\Api\Operations;

use App\Models\Shipment;
use App\Models\User;
use App\Services\Client\Api\CompanyContext;
use App\Services\Client\Api\Envelope;
use App\Services\Client\Api\FeatureGate;
use App\Services\Client\Api\Operation;
use App\Services\Client\Api\OperationProvider;
use App\Services\Shipment\ClientShipmentPresenter;
use App\Services\Shipment\ClientShipmentQuery;
use App\Support\OperationApi\OperationInput;
use App\Support\OperationApi\Param;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Реализации (отгрузочные документы 1С) клиента.
 *
 * Форма строки — та же, что у legacy `/api/client-api/{token}/shipments`, плюс
 * продавец: интеграции переезжают без переписывания разбора. Блок оплаты и фильтр
 * `payment_status` действуют только при открытых финансах — иначе фильтр стал бы
 * обходным путём к скрытым цифрам долга. Реализации внутренних юрлиц скрыты.
 */
class ShipmentOperations implements OperationProvider
{
    use ResolvesClientEntities;

    public function __construct(
        private readonly ClientShipmentQuery $shipments,
        private readonly ClientShipmentPresenter $presenter,
        private readonly CompanyContext $companies,
    ) {}

    public static function section(): array
    {
        return ['shipments', 'Реализации'];
    }

    public static function operations(): array
    {
        return [
            new Operation(
                id: 'shipments.list',
                section: 'shipments',
                method: 'GET',
                uri: 'shipments',
                summary: 'Список реализаций с фильтрами и курсорной пагинацией',
                description: 'Документы, проведённые в 1С по учётной записи: номер, дата, контрагент, сумма в валюте '
                    .'документа и — при `with_items=true` — товарный состав (страница тогда короче). Свежие первыми. '
                    .'`updated_since` — для инкрементальной синхронизации; `order` — реализации по конкретному заказу '
                    .'(id, номер или uuid). Блок оплаты появляется только при открытом разделе «Оплаты».',
                params: [
                    Param::list('status', 'Статусы реализации: new, in_progress, completed, cancelled', 'string'),
                    Param::list('payment_status', 'Статусы оплаты: unpaid, partial, paid, overpaid (только при открытых финансах)', 'string'),
                    Param::integer('company_id', 'Юрлицо клиента (companies[].id из /me)'),
                    Param::string('inn', 'ИНН контрагента по документу', rules: ['max:12']),
                    Param::string('order', 'Заказ, по которому собраны реализации: id, номер или uuid', rules: ['max:64']),
                    Param::string('number', 'Часть номера документа; дефисы и пробелы не важны', rules: ['max:100']),
                    Param::string('date_from', 'Дата отгрузки не раньше, ГГГГ-ММ-ДД', rules: ['date_format:Y-m-d']),
                    Param::string('date_to', 'Дата отгрузки не позже, ГГГГ-ММ-ДД', rules: ['date_format:Y-m-d']),
                    Param::string('updated_since', 'Изменены после момента (дата или дата-время)', rules: ['date']),
                    Param::boolean('with_items', 'Добавить товарный состав в каждую строку'),
                    Param::string('cursor', 'Курсор страницы из meta.next_cursor'),
                    Param::integer('per_page', 'Размер страницы: до '.Envelope::PER_PAGE_MAX_FEED.' без состава, до '.Envelope::PER_PAGE_MAX.' с составом', rules: ['min:1']),
                ],
                handler: [self::class, 'list'],
            ),
            new Operation(
                id: 'shipments.get',
                section: 'shipments',
                method: 'GET',
                uri: 'shipments/{shipment}',
                summary: 'Карточка реализации: состав, заказы, график оплаты',
                description: 'Реализация по id, uuid из 1С или номеру (дефисы в номере не важны). Всегда с составом '
                    .'и списком заказов, по которым собран документ; при открытых финансах — `payment_schedule` '
                    .'из «Правил оплаты» 1С.',
                params: [
                    Param::string('shipment', 'Реализация: id, uuid или номер', required: true),
                ],
                handler: [self::class, 'get'],
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function list(User $actor, OperationInput $input): array
    {
        $finance = FeatureGate::FINANCE->allows($actor);
        $withItems = $input->bool('with_items');

        $filters = [
            'status' => $input->array('status'),
            'payment_status' => $input->array('payment_status'),
            'company_id' => $input->has('company_id')
                ? $this->companies->filterIds($actor, $input->int('company_id'))[0]
                : null,
            'inn' => $input->string('inn'),
            'order_uuid' => $input->has('order')
                ? $this->orderOf($actor, (string) $input->get('order'), withTrashed: true)->uuid
                : null,
            'number' => $input->string('number'),
            'date_from' => $input->string('date_from'),
            'date_to' => $input->string('date_to'),
            'updated_since' => $input->string('updated_since'),
            // Дата хранится без времени — id держит порядок внутри дня.
            'sort_by' => 'date',
            'sort_order' => 'desc',
        ];

        // Состав раздувает ответ на порядок, поэтому с ним страница короче.
        $perPage = Envelope::perPage(
            $input->get('per_page'),
            max: $withItems ? Envelope::PER_PAGE_MAX : Envelope::PER_PAGE_MAX_FEED,
        );

        $paginator = $this->shipments
            ->builder($actor, $filters, $finance)
            ->with($this->shipments->eagerLoads($withItems))
            ->withCount('items')
            ->cursorPaginate($perPage, ['*'], 'cursor', $input->string('cursor'));

        return Envelope::cursor(
            $paginator,
            fn (Shipment $shipment) => $this->presenter->row($shipment, $finance, $withItems),
            ['finance' => $finance],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function get(User $actor, OperationInput $input): array
    {
        $shipment = $this->shipmentOf($actor, (string) $input->get('shipment'));

        // Реализация «Рекламы» для клиента не существует — как и в кабинете.
        if ($shipment->isFromInternalOrganization()) {
            throw (new ModelNotFoundException)->setModel(Shipment::class);
        }

        $finance = FeatureGate::FINANCE->allows($actor);

        $shipment->loadCount('items');
        $shipment->load($this->shipments->eagerLoads(withItems: true));

        return Envelope::data($this->presenter->card($shipment, $finance), ['finance' => $finance]);
    }
}
