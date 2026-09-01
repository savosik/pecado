<?php

namespace App\Http\Controllers\Crm;

use App\Models\OrderItem;
use App\Models\PersonalManager;
use App\Models\ShortageReason;
use App\Services\Shortage\CancellationHintResolver;
use App\Services\Shortage\FulfillmentRateQuery;
use App\Services\Shortage\ShortageLogQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * CRM-раздел «Недоборы»: журнал отменённых строк заказов.
 *
 * Раздел отвечает на четыре вопроса: что и на какую сумму отменилось, по какой
 * причине, у кого это происходит чаще всего и какую долю заказанного мы в итоге
 * довозим. Замены сайт не предлагает — природа отмены разная (склад снял при
 * сборке / клиент отказался / товар не был обеспечен), и подборка была уместна
 * в лучшем случае в половине случаев.
 *
 * Причины в протоколе 1С нет, поэтому её ставит человек, выбирая строку
 * справочника; сайт показывает подсказку по расходному ордеру
 * (см. {@see CancellationHintResolver}).
 */
class ShortageController extends CrmController
{
    public function __construct(
        private readonly ShortageLogQuery $log,
        private readonly CancellationHintResolver $hints,
        private readonly FulfillmentRateQuery $fulfillment,
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
            'chips' => $this->log->chips($filters, $actor, $seesDepartment),
            'fulfillment' => $this->fulfillment->forFilters($filters, $actor, $seesDepartment),
            'partners' => $filters['tab'] === 'partners' ? $this->log->byPartners($query) : [],
            'products' => $filters['tab'] === 'products' ? $this->log->byProducts($query) : [],
            'reasons' => $this->log->reasonOptions(),
            'reasonUsage' => $filters['tab'] === 'reasons' ? $this->reasonUsage() : [],
            'categories' => $this->log->categoryOptions(),
            'filters' => $filters,
            'managers' => $seesDepartment ? $this->managerOptions() : [],
            'canSeeAll' => $seesDepartment,
            'canEdit' => $actor->can('crm-shortages.edit'),
            'canSeeReasons' => $actor->can('crm-shortage-reasons.view'),
            'canManageReasons' => $actor->can('crm-shortage-reasons.edit'),
            'canCreateReasons' => $actor->can('crm-shortage-reasons.create'),
            'canDeleteReasons' => $actor->can('crm-shortage-reasons.delete'),
        ]);
    }

    /**
     * Разметка строки: причина недобора и комментарий менеджера.
     *
     * Причину можно снять (reason_id = null) — ошибочная разметка не должна
     * оставаться навсегда, а «ещё не разобрались» здесь законный ответ.
     */
    public function setReason(Request $request, int $item): RedirectResponse
    {
        $actor = $this->crmActor($request);

        $data = $request->validate([
            'reason_id' => ['nullable', 'integer', Rule::exists('shortage_reasons', 'id')],
            'note' => ['nullable', 'string', 'max:500'],
        ], [
            'reason_id.exists' => 'Такой причины нет в справочнике.',
            'note.max' => 'Комментарий не длиннее 500 символов.',
        ]);

        $model = $this->log
            ->visible($actor, $this->seesDepartment($request))
            ->whereKey($item)
            ->firstOrFail();

        $reasonId = $data['reason_id'] ?? null;
        $note = $data['note'] ?? null;

        // Отключённую причину выбрать нельзя, но уже проставленную она сохраняет:
        // РОП убирает причину из оборота, а не переписывает историю разметки.
        if ($reasonId !== null && $reasonId !== $model->cancel_reason_id) {
            $reason = ShortageReason::query()->findOrFail($reasonId);

            if (! $reason->is_active) {
                throw ValidationException::withMessages([
                    'reason_id' => "Причина «{$reason->name}» отключена — выберите действующую.",
                ]);
            }
        }

        $marked = $reasonId !== null || filled($note);

        $model->forceFill([
            'cancel_reason_id' => $reasonId,
            'cancel_note' => $note,
            'cancel_source_user_id' => $marked ? $actor->id : null,
            'cancel_source_at' => $marked ? now() : null,
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
        $reason = $item->cancelReason;

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
            'archived_at' => $item->cancel_archived_at?->format('d.m.Y'),
            'reason_id' => $reason?->getKey(),
            'reason' => $reason?->name,
            'reason_color' => $reason?->color(),
            'reason_category' => $reason?->category->value,
            'reason_category_label' => $reason?->category->label(),
            'reason_active' => $reason !== null ? (bool) $reason->is_active : null,
            'source_user' => $item->cancelSourceUser?->name,
            'source_at' => $item->cancel_source_at?->format('d.m.Y H:i'),
            'note' => $item->cancel_note,
            'hint' => $hints[$item->id] ?? null,
        ];
    }

    /**
     * Справочник причин со счётчиком разметки — вкладка «Причины».
     *
     * Счётчик считается по всем строкам за всё время, а не по текущему фильтру:
     * он отвечает на вопрос «можно ли эту причину удалить», а не «сколько её
     * было в августе».
     *
     * @return list<array<string, mixed>>
     */
    private function reasonUsage(): array
    {
        return ShortageReason::query()
            ->withCount('orderItems')
            ->ordered()
            ->get()
            ->map(fn (ShortageReason $reason) => [
                ...$reason->toOption(),
                'id' => $reason->getKey(),
                'name' => $reason->name,
                'lines_count' => (int) $reason->order_items_count,
            ])
            ->all();
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
