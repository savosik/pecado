<?php

namespace App\Console\Commands;

use App\Models\GoodsIssue;
use App\Models\GoodsIssueItem;
use App\Models\GoodsIssueStatusHistory;
use App\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Имитация работы склада в 1С для тестирования самовывоза на стенде (эпик pick-00).
 *
 * Создаёт по заказу демо-ордер и ведёт его по статусам тем же способом, что и шина: статус + строка журнала
 * статусов. Срабатывают те же наблюдатели, письма и стадии, что и в бою. На проде не работает: там расходные
 * ордера принадлежат 1С.
 */
class PickupDemoStep extends Command
{
    protected $signature = 'pickup:demo-step
        {order : id, номер сайта или номер 1С заказа}
        {status=shipped : prepared | to_pick | to_check | to_ship | shipped | cancelled}
        {--packages=2 : число мест у нового демо-ордера}';

    protected $description = 'ТОЛЬКО СТЕНД: провести демо-ордер заказа по статусам склада (имитация 1С)';

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('На проде расходные ордера ведёт 1С — команда отключена.');

            return self::FAILURE;
        }

        $needle = (string) $this->argument('order');
        $order = Order::query()->where('number', $needle)->orWhere('erp_number', $needle)
            ->when(ctype_digit($needle), fn ($q) => $q->orWhere('id', (int) $needle))->first();
        if ($order === null) {
            $this->error("Заказ «{$needle}» не найден.");

            return self::FAILURE;
        }

        $status = (string) $this->argument('status');
        $allowed = [...GoodsIssue::STATUSES, GoodsIssueStatusHistory::STATUS_CANCELLED];
        if (! in_array($status, $allowed, true)) {
            $this->error('Статус должен быть одним из: '.implode(', ', $allowed));

            return self::FAILURE;
        }

        $number = 'ДЕМО-'.$order->id;
        $issue = GoodsIssue::withTrashed()->where('number', $number)->first();

        if ($issue === null) {
            $issue = GoodsIssue::create([
                'uuid' => (string) Str::uuid(),
                'number' => $number,
                'date' => now(),
                'shipment_date' => now(),
                'status' => GoodsIssue::STATUS_PREPARED,
                'status_changed_at' => now(),
                'operation' => 'Демо: имитация склада',
                'recipient_name' => $order->company?->name,
                'priority' => GoodsIssue::PRIORITY_NORMAL,
                'packages_count' => max(1, (int) $this->option('packages')),
            ]);
            GoodsIssueItem::create([
                'goods_issue_id' => $issue->id,
                'line_number' => 1,
                'product_uuid' => (string) Str::uuid(),
                'product_name' => 'Демо-строка',
                'order_uuid' => $order->uuid,
                'order_id' => $order->id,
                'order_number' => $order->erp_number ?: $order->number,
                'quantity' => 1,
            ]);
            $this->line("Создан демо-ордер {$number} (штрихкод листа: ".\App\Support\Erp\DocumentBarcode::fromGuid($issue->uuid).')');
        }

        if ($issue->trashed()) {
            $issue->restore();
        }

        $from = $issue->status;
        if ($status === GoodsIssueStatusHistory::STATUS_CANCELLED) {
            GoodsIssueStatusHistory::create(['goods_issue_id' => $issue->id, 'from_status' => $from, 'to_status' => $status, 'changed_at' => now(), 'source' => GoodsIssueStatusHistory::SOURCE_ERP]);
            $issue->delete();
            $this->info("Ордер {$number} отменён (как при удалении документа в 1С).");

            return self::SUCCESS;
        }

        if ($from === $status) {
            $this->info("Ордер {$number} уже в статусе «".GoodsIssue::STATUS_LABELS[$status].'».');

            return self::SUCCESS;
        }

        $issue->forceFill(['status' => $status, 'status_changed_at' => now()])->save();
        GoodsIssueStatusHistory::create(['goods_issue_id' => $issue->id, 'from_status' => $from, 'to_status' => $status, 'changed_at' => now(), 'source' => GoodsIssueStatusHistory::SOURCE_ERP]);

        $this->info("Ордер {$number}: «".(GoodsIssue::STATUS_LABELS[$from] ?? $from).'» → «'.GoodsIssue::STATUS_LABELS[$status].'».');

        return self::SUCCESS;
    }
}
