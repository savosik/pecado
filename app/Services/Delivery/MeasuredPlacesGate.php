<?php

namespace App\Services\Delivery;

use App\Enums\DeliveryMethod;
use App\Models\GoodsIssue;
use App\Models\GoodsIssuePackage;
use App\Models\Order;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Можно ли считать доставку по обмеру грузовых мест расходного ордера (v16.14.0, топик №13).
 *
 * Единственное место, где живут правила «done / pending / mixed / null»: их читают расчёт
 * тарифов, форма отправки склада, карточка ордера и приём сообщения 1С (запись в журнал).
 *
 * Расчёт идёт ПО ОРДЕРУ, а не по заказу: коробки у ордера общие, разложить их по заказам
 * нельзя. Поэтому `mixed` расчёт не блокирует — к перевозчику уходит весь ордер целиком,
 * включая заказы на самовывоз (решение заказчика 28.09.2026), и одно место дважды не считается.
 *
 * Вердикты:
 *  - `legacy`        — ордер старого формата, блока `measurement` не было: обмера нет, места
 *                      кладовщик вводит руками, как до v16.14.0;
 *  - `not_required`  — самовывоз или ордер отгружен без товара: обмер не нужен;
 *  - `ready`         — `delivery`/`mixed`, обмер завершён, каждое место обмерено: считаем;
 *  - `pending`       — обмер нужен и не завершён (или сброшен): ждём упаковщика;
 *  - `mode_unknown`  — 1С не определила способ доставки (`shipping_mode = null`): расчёта нет;
 *  - `mode_mismatch` — 1С говорит «самовывоз», а заказы ордера на сайте — на доставку,
 *                      или наоборот: расчёта нет до выяснения.
 */
class MeasuredPlacesGate
{
    public const LEGACY = 'legacy';

    public const NOT_REQUIRED = 'not_required';

    public const READY = 'ready';

    public const PENDING = 'pending';

    public const MODE_UNKNOWN = 'mode_unknown';

    public const MODE_MISMATCH = 'mode_mismatch';

    /** Вердикты, при которых расчёт доставки по ордеру запрещён. */
    public const BLOCKING = [self::PENDING, self::MODE_UNKNOWN, self::MODE_MISMATCH];

    /**
     * Вердикт по одному ордеру.
     *
     * @return array{verdict: string, blocks: bool, message: string|null, site_mode: string|null, places: list<array<string, mixed>>}
     */
    public function evaluate(GoodsIssue $goodsIssue): array
    {
        return $this->evaluateMany([$goodsIssue])[$goodsIssue->getKey()];
    }

    /**
     * Вердикты по набору ордеров — пакетно: заказы всех ордеров читаются двумя запросами.
     *
     * @param  iterable<GoodsIssue>  $goodsIssues
     * @return array<int, array{verdict: string, blocks: bool, message: string|null, site_mode: string|null, places: list<array<string, mixed>>}>
     */
    public function evaluateMany(iterable $goodsIssues): array
    {
        $issues = collect($goodsIssues)->keyBy(static fn (GoodsIssue $issue): int => (int) $issue->getKey());

        // Способ сайта нужен только ордерам нового формата: у старых сверять не с чем.
        $siteModes = $this->siteModes(
            $issues->filter(static fn (GoodsIssue $issue): bool => $issue->measurement_state !== null),
        );

        $result = [];

        foreach ($issues as $id => $issue) {
            $siteMode = $siteModes[$id] ?? null;
            $verdict = $this->verdict($issue, $siteMode);

            $result[$id] = [
                'verdict' => $verdict,
                'blocks' => in_array($verdict, self::BLOCKING, true),
                'message' => $this->message($verdict, $issue),
                'site_mode' => $siteMode,
                'places' => $verdict === self::READY ? $this->places($issue) : [],
            ];
        }

        return $result;
    }

    /**
     * Расходные ордера за набором заказов: по каждому заказу — последний ордер.
     *
     * Прямой связи «реализация → ордер» в 1С нет, оба документа ссылаются на заказ.
     * Ордер, собранный по нескольким заказам, попадает в результат один раз — поэтому
     * его места в расчёт входят единожды, сколько бы заказов ордера ни выбрали.
     *
     * @param  iterable<string>  $orderUuids
     * @return Collection<int, GoodsIssue> по id ордера
     */
    public function issuesForOrders(iterable $orderUuids): Collection
    {
        $uuids = collect($orderUuids)->filter()->unique()->values();

        if ($uuids->isEmpty()) {
            return collect();
        }

        $links = DB::table('goods_issue_items')
            ->whereIn('order_uuid', $uuids->all())
            ->select('order_uuid', 'goods_issue_id')
            ->distinct()
            ->get();

        if ($links->isEmpty()) {
            return collect();
        }

        $issues = GoodsIssue::query()
            ->with('packages')
            ->whereIn('id', $links->pluck('goods_issue_id')->unique()->all())
            ->get()
            ->keyBy('id');

        $latest = [];

        foreach ($links as $link) {
            $issue = $issues->get($link->goods_issue_id);

            if ($issue === null) {
                continue;
            }

            $current = $latest[$link->order_uuid] ?? null;

            if ($current === null || ($issue->date?->gt($current->date) ?? false)) {
                $latest[$link->order_uuid] = $issue;
            }
        }

        return collect($latest)->keyBy(static fn (GoodsIssue $issue): int => (int) $issue->getKey());
    }

    /**
     * Причина, по которой расчёт по этим заказам сейчас запрещён, либо null.
     *
     * @param  iterable<string>  $orderUuids
     */
    public function blockingMessage(iterable $orderUuids): ?string
    {
        if (! config('services.apiship.measurement_gate', true)) {
            return null;
        }

        $messages = collect($this->evaluateMany($this->issuesForOrders($orderUuids)))
            ->filter(static fn (array $row): bool => $row['blocks'])
            ->pluck('message')
            ->filter()
            ->unique();

        return $messages->isEmpty() ? null : $messages->implode(' ');
    }

    /**
     * Способ доставки ордера по заказам сайта (связь — только через `items[].order_uuid`).
     *
     * null — заказов за ордером на сайте нет, сверять не с чем.
     */
    public function siteMode(GoodsIssue $goodsIssue): ?string
    {
        return $this->siteModes(collect([(int) $goodsIssue->getKey() => $goodsIssue]))[$goodsIssue->getKey()] ?? null;
    }

    /**
     * Расходится ли способ 1С со способом сайта: «самовывоз» против «доставка».
     *
     * `mixed` с любой стороны расхождением не считается: ордер едет целиком.
     */
    public function isMismatch(?string $erpMode, ?string $siteMode): bool
    {
        return ($erpMode === GoodsIssue::SHIPPING_PICKUP && $siteMode === GoodsIssue::SHIPPING_DELIVERY)
            || ($erpMode === GoodsIssue::SHIPPING_DELIVERY && $siteMode === GoodsIssue::SHIPPING_PICKUP);
    }

    private function verdict(GoodsIssue $issue, ?string $siteMode): string
    {
        if ($issue->measurement_state === null) {
            return self::LEGACY;
        }

        // Отгружен без товара (v16.15.0): мест нет, везти нечего — способ уже не важен.
        if ($issue->isShippedEmpty()) {
            return self::NOT_REQUIRED;
        }

        if ($issue->shipping_mode === null) {
            return self::MODE_UNKNOWN;
        }

        if ($this->isMismatch($issue->shipping_mode, $siteMode)) {
            return self::MODE_MISMATCH;
        }

        if ($issue->measurement_state === GoodsIssue::MEASUREMENT_NOT_REQUIRED
            || $issue->shipping_mode === GoodsIssue::SHIPPING_PICKUP) {
            return self::NOT_REQUIRED;
        }

        if ($issue->measurement_state !== GoodsIssue::MEASUREMENT_DONE) {
            return self::PENDING;
        }

        // Схема не пропустит `done` с необмеренным местом, но расчёт не должен
        // зависеть от валидатора: частичные данные в тариф не идут.
        $packages = $issue->packages;

        if ($packages->isEmpty() || $packages->contains(static fn (GoodsIssuePackage $p): bool => ! $p->isMeasured())) {
            return self::PENDING;
        }

        return self::READY;
    }

    private function message(string $verdict, GoodsIssue $issue): ?string
    {
        $number = $issue->number;

        return match ($verdict) {
            self::PENDING => "Обмер мест по ордеру {$number} не завершён — расчёт доставки появится, когда упаковщик закончит обмер.",
            self::MODE_UNKNOWN => "По ордеру {$number} 1С не определила способ доставки — расчёт недоступен, обратитесь к менеджеру.",
            self::MODE_MISMATCH => "По ордеру {$number} способ доставки в 1С и в заказах на сайте расходится — расчёт недоступен, обратитесь к менеджеру.",
            self::READY => $issue->shipping_mode === GoodsIssue::SHIPPING_MIXED
                ? "Ордер {$number} собран по заказам с доставкой и самовывозом — в отправление входит весь ордер, все его места."
                : null,
            default => null,
        };
    }

    /**
     * Места ордера в единицах перевозчика: вес — граммы, габариты — сантиметры.
     *
     * @return list<array<string, mixed>>
     */
    private function places(GoodsIssue $issue): array
    {
        return $issue->packages
            ->map(static fn (GoodsIssuePackage $package): array => [
                'number' => (int) $package->number,
                'uuid' => $package->uuid,
                'barcode' => $package->barcode,
                'package_type' => $package->package_type ?? GoodsIssuePackage::TYPE_OTHER,
                'package_type_label' => $package->package_type_label,
                'weight' => $package->weightGrams(),
                'length' => (int) $package->length,
                'width' => (int) $package->width,
                'height' => (int) $package->height,
                'volume_m3' => $package->volumeM3(),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, GoodsIssue>  $issues
     * @return array<int, string|null>
     */
    private function siteModes(Collection $issues): array
    {
        if ($issues->isEmpty()) {
            return [];
        }

        $links = DB::table('goods_issue_items')
            ->whereIn('goods_issue_id', $issues->keys()->all())
            ->whereNotNull('order_uuid')
            ->select('goods_issue_id', 'order_uuid')
            ->distinct()
            ->get();

        if ($links->isEmpty()) {
            return [];
        }

        $methods = Order::withoutGlobalScopes()
            ->whereIn('uuid', $links->pluck('order_uuid')->unique()->all())
            ->get(['uuid', 'delivery_method'])
            ->mapWithKeys(static fn (Order $order): array => [$order->uuid => $order->delivery_method?->value]);

        $modes = [];

        foreach ($links->groupBy('goods_issue_id') as $issueId => $rows) {
            $kinds = $rows
                ->map(static fn ($row): ?string => $methods[$row->order_uuid] ?? null)
                ->filter()
                ->map(static fn (string $method): string => $method === DeliveryMethod::PICKUP->value
                    ? GoodsIssue::SHIPPING_PICKUP
                    : GoodsIssue::SHIPPING_DELIVERY)
                ->unique()
                ->values();

            $modes[(int) $issueId] = match (true) {
                $kinds->isEmpty() => null,
                $kinds->count() > 1 => GoodsIssue::SHIPPING_MIXED,
                default => $kinds->first(),
            };
        }

        return $modes;
    }
}
