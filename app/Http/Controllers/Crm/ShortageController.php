<?php

namespace App\Http\Controllers\Crm;

use App\Models\OrderItem;
use App\Models\PersonalManager;
use App\Services\Shortage\CancellationHintResolver;
use App\Services\Shortage\ShortageLogQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * CRM-раздел «Недоборы»: журнал отменённых строк заказов.
 *
 * Раздел отвечает на три вопроса: что и на какую сумму отменилось, у кого это
 * происходит чаще всего и какие товары срываются регулярно. Замены сайт больше
 * не предлагает — природа отмены двойная (склад снял при сборке / клиент
 * попросил убрать), и подборка была уместна в лучшем случае в половине случаев.
 *
 * Причины в протоколе 1С нет, поэтому метку ставит менеджер, а сайт показывает
 * подсказку по расходному ордеру (см. {@see CancellationHintResolver}).
 */
class ShortageController extends CrmController
{
    public function __construct(
        private readonly ShortageLogQuery $log,
        private readonly CancellationHintResolver $hints,
    ) {}

    public function index(Request $request): InertiaResponse
    {
        $actor = $this->crmActor($request);
        $seesDepartment = $this->seesDepartment($request);
        $filters = $this->log->filters($request->all(), $actor);

        $query = $this->log->query($filters, $actor, $seesDepartment);

        $page = $this->log->page($query);

        /** @var Collection<int, OrderItem> $items */
        $items = collect($page->items());
        $hints = $this->hints->forItems($items);

        $rows = $page->through(fn (OrderItem $item) => $this->row($item, $hints));

        return Inertia::render('Crm/Pages/Shortages/Index', [
            'rows' => $rows,
            'totals' => $this->log->totals($query),
            'partners' => $filters['tab'] === 'partners' ? $this->log->byPartners($query) : [],
            'products' => $filters['tab'] === 'products' ? $this->log->byProducts($query) : [],
            'filters' => $filters,
            'sourceOptions' => $this->log->sourceOptions(),
            'managers' => $seesDepartment ? $this->managerOptions() : [],
            'canSeeAll' => $seesDepartment,
            'canEdit' => $actor->can('crm-shortages.edit'),
        ]);
    }

    /**
     * Разметка строки: кто отменил и комментарий менеджера.
     *
     * Метку можно снять (source = null) — ошибочная разметка не должна
     * оставаться навсегда, а «не знаю» здесь законный ответ.
     */
    public function setSource(Request $request, int $item): RedirectResponse
    {
        $actor = $this->crmActor($request);

        $data = $request->validate([
            'source' => ['nullable', 'string', 'in:warehouse,client'],
            'note' => ['nullable', 'string', 'max:500'],
        ], [
            'source.in' => 'Источник отмены должен быть «склад» или «клиент».',
            'note.max' => 'Комментарий не длиннее 500 символов.',
        ]);

        $model = $this->log
            ->visible($actor, $this->seesDepartment($request))
            ->whereKey($item)
            ->firstOrFail();

        $source = $data['source'] ?? null;

        $model->forceFill([
            'cancel_source' => $source,
            'cancel_note' => $data['note'] ?? null,
            'cancel_source_user_id' => $source === null && blank($data['note'] ?? null) ? null : $actor->id,
            'cancel_source_at' => $source === null && blank($data['note'] ?? null) ? null : now(),
        ])->save();

        return back(303);
    }

    /**
     * Строка журнала.
     *
     * @param  array<int, array<string, mixed>>  $hints
     * @return array<string, mixed>
     */
    private function row(OrderItem $item, array $hints): array
    {
        $order = $item->order;
        $client = $order?->user;

        return [
            'id' => $item->id,
            'cancelled_at' => $item->cancelled_at?->format('d.m.Y H:i'),
            'order_id' => $order?->id,
            'order_number' => $order?->erp_number ?: $order?->number,
            'order_date' => $order?->erp_created_at?->format('d.m.Y')
                ?? $order?->created_at?->format('d.m.Y'),
            'client_id' => $client?->id,
            'client' => $client !== null ? (string) $client->display_name : '—',
            'company' => $order?->company?->name,
            'manager' => $client?->personalManager !== null ? $client->personalManager->name : '—',
            'product_id' => $item->product_id,
            'product' => $item->product?->name ?: $item->name,
            'sku' => $item->product?->sku,
            'slug' => $item->product?->slug,
            'quantity' => (int) $item->quantity,
            'amount' => (float) $item->subtotal,
            'source' => $item->cancel_source?->value,
            'source_label' => $item->cancel_source?->shortLabel(),
            'source_color' => $item->cancel_source?->color(),
            'source_user' => $item->cancelSourceUser?->name,
            'source_at' => $item->cancel_source_at?->format('d.m.Y H:i'),
            'note' => $item->cancel_note,
            'hint' => $hints[$item->id] ?? null,
        ];
    }

    /**
     * Менеджеры для фильтра — только те, за кем закреплены партнёры.
     *
     * @return list<array{value: int, label: string}>
     */
    private function managerOptions(): array
    {
        return PersonalManager::query()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (PersonalManager $manager) => [
                'value' => $manager->id,
                'label' => $manager->name,
            ])
            ->all();
    }
}
