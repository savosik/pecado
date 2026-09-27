<?php

namespace App\Services\Pickup;

use App\Models\GoodsIssue;
use App\Models\GoodsIssueItem;
use App\Models\Order;
use App\Models\Pickup\PickupPass;
use App\Models\Pickup\PickupScanMiss;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\User;
use App\Support\Erp\DocumentBarcode;
use App\Support\Search\DocumentNumber;
use Illuminate\Support\Collection;

/**
 * Что отсканировал кладовщик (pick-08, pick-11): пропуск, код или штрихкод расходного листа.
 *
 * Формат штрихкода 1С на момент разработки не подтверждён владельцем базы, поэтому пробуем
 * типовой алгоритм по трём видам документов и номер документа, а промахи пишем в журнал —
 * по первым реальным листам формат подтвердится без догадок.
 */
class ScanResolver
{
    public function __construct(private readonly PickupPassService $passes) {}

    /** @return array{kind: string, pass?: PickupPass, goods_issues?: Collection<int, GoodsIssue>, via?: string, message?: string} */
    public function resolve(string $raw, User $by, string $context = 'pickups'): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return ['kind' => 'miss', 'message' => 'Пустой скан'];
        }

        if (($token = $this->extractToken($raw)) !== null) {
            $pass = $this->passes->findByToken($token);

            return $pass ? ['kind' => 'pass', 'pass' => $pass, 'via' => 'qr'] : $this->miss($raw, $by, $context, PickupScanMiss::KIND_SCAN, 'Пропуск не найден');
        }

        $digits = preg_replace('/[\s-]+/', '', $raw) ?? '';
        if (ctype_digit($digits) && strlen($digits) === 6) {
            if ($this->throttled($by)) {
                return ['kind' => 'throttled', 'message' => 'Слишком много неверных кодов. Подождите несколько минут или отсканируйте QR'];
            }

            $pass = $this->passes->findUsableByCode($digits);

            return $pass ? ['kind' => 'pass', 'pass' => $pass, 'via' => 'code'] : $this->miss($raw, $by, $context, PickupScanMiss::KIND_CODE, 'Действующего пропуска с таким кодом нет');
        }

        $issues = $this->byDocumentBarcode($digits);
        if ($issues->isEmpty()) {
            $issues = $this->byDocumentNumber($raw);
        }

        return $issues->isNotEmpty()
            ? ['kind' => 'goods_issue', 'goods_issues' => $issues, 'via' => 'barcode']
            : $this->miss($raw, $by, $context, PickupScanMiss::KIND_SCAN, 'Документ по штрихкоду не найден');
    }

    private function extractToken(string $raw): ?string
    {
        if (preg_match('~/p/([A-Za-z0-9_-]{20,128})~', $raw, $m)) {
            return $m[1];
        }

        return preg_match('/^[A-Za-z0-9_-]{40,128}$/', $raw) && ! ctype_digit($raw) ? $raw : null;
    }

    /** @return Collection<int, GoodsIssue> */
    private function byDocumentBarcode(string $digits): Collection
    {
        if (! ctype_digit($digits) || strlen($digits) < 7) {
            return collect();
        }

        $guid = DocumentBarcode::toGuid($digits);
        if ($guid === null) {
            return collect();
        }

        $issue = GoodsIssue::query()->where('uuid', $guid)->first();
        if ($issue !== null) {
            return collect([$issue]);
        }

        // Лист мог быть напечатан из реализации или из заказа клиента — идём к ордерам через заказы.
        $orderUuids = collect();
        if (($shipment = Shipment::query()->where('uuid', $guid)->first()) !== null) {
            $orderUuids = ShipmentItem::query()->where('shipment_id', $shipment->id)->whereNotNull('order_uuid')->pluck('order_uuid');
        } elseif (Order::withoutGlobalScopes()->where('uuid', $guid)->exists()) {
            $orderUuids = collect([$guid]);
        }

        return $this->issuesOfOrders($orderUuids);
    }

    /** @return Collection<int, GoodsIssue> */
    private function byDocumentNumber(string $raw): Collection
    {
        if (mb_strlen($raw) < 5 || mb_strlen($raw) > 32) {
            return collect();
        }

        $issues = GoodsIssue::query()->where('number', $raw)->where('created_at', '>=', now()->subDays(60))->get();
        if ($issues->isNotEmpty()) {
            return $issues;
        }

        // Сканер и ручной ввод дают номер без дефиса и в любом регистре («29ут014170»). Отбираем
        // кандидатов по цифровому хвосту в базе, точное совпадение нормализованных номеров — в PHP:
        // LOWER() для кириллицы в SQLite не работает, а в MySQL такой запрос не взял бы индекс.
        $normalized = DocumentNumber::normalize($raw);
        if (! preg_match('/(\d{4,})$/u', $normalized, $m)) {
            return collect();
        }

        $orderUuids = Order::withoutGlobalScopes()
            ->where('created_at', '>=', now()->subDays(60))
            ->where(fn ($q) => $q->where('erp_number', 'like', '%'.$m[1])->orWhere('number', 'like', '%'.$m[1]))
            ->limit(100)
            ->get(['uuid', 'number', 'erp_number'])
            ->filter(fn (Order $o) => in_array($normalized, [DocumentNumber::normalize((string) $o->erp_number), DocumentNumber::normalize((string) $o->number)], true))
            ->pluck('uuid');

        return $this->issuesOfOrders($orderUuids);
    }

    /**
     * @param  Collection<int, string>  $orderUuids
     * @return Collection<int, GoodsIssue>
     */
    private function issuesOfOrders(Collection $orderUuids): Collection
    {
        if ($orderUuids->isEmpty()) {
            return collect();
        }

        $ids = GoodsIssueItem::query()->whereIn('order_uuid', $orderUuids->unique())->distinct()->pluck('goods_issue_id');

        return GoodsIssue::query()->whereIn('id', $ids)->get();
    }

    private function throttled(User $by): bool
    {
        return PickupScanMiss::query()
            ->where('user_id', $by->id)
            ->where('kind', PickupScanMiss::KIND_CODE)
            ->where('created_at', '>=', now()->subMinutes((int) config('pickup.code_attempts_window_minutes', 10)))
            ->count() >= (int) config('pickup.code_attempts', 10);
    }

    /** @return array{kind: string, message: string} */
    private function miss(string $raw, User $by, string $context, string $kind, string $message): array
    {
        PickupScanMiss::create(['user_id' => $by->id, 'raw' => mb_substr($raw, 0, 512), 'kind' => $kind, 'context' => $context]);

        return ['kind' => 'miss', 'message' => $message];
    }
}
