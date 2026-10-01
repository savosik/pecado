<?php

namespace App\Services\Erp\Support;

use App\Models\GoodsIssue;
use App\Models\GoodsIssuePackage;
use App\Models\GoodsIssueStatusHistory;
use App\Models\Order;
use App\Models\Product;
use App\Services\Delivery\MeasuredPlacesGate;
use App\Services\Erp\ErpHandlerOutcome;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Разбор payload расходного ордера и запись его в БД (US-20).
 *
 * Общий код для `goods_issue.created` и `goods_issue.updated`: 1С присылает документ
 * целиком в обоих случаях, и различать их на уровне записи нечем. Handler-ы остаются
 * тонкими, а вся логика живёт здесь — по образцу {@see PaymentPayloadMapper}.
 */
class GoodsIssuePayloadMapper
{
    use ResolvesContractorParty;
    use ResolvesDocumentOrganization;

    /**
     * Создать или обновить ордер по payload из 1С.
     *
     * @param  array<string, mixed>  $payload
     * @param  string  $context  Имя handler-а для логов
     */
    public function apply(array $payload, string $context): ?GoodsIssue
    {
        $uuid = $payload['uuid'] ?? null;

        if (! is_string($uuid) || trim($uuid) === '') {
            Log::warning($context.': отсутствует uuid', ['payload' => $payload]);

            return null;
        }

        $uuid = trim($uuid);

        [$companyId, $userId] = $this->resolveCompanyAndUser(
            $payload['contractor_uuid'] ?? null,
            $payload['tax_id'] ?? null,
            $payload['partner_uuid'] ?? null,
        );

        $fields = [
            'number' => $payload['number'] ?? null,
            'date' => $payload['date'] ?? null,
            'shipment_date' => $payload['shipment_date'] ?? null,
            'status' => $payload['status'] ?? GoodsIssue::STATUS_PREPARED,
            'operation' => $payload['operation'] ?? null,
            'warehouse_uuid' => $payload['warehouse_uuid'] ?? null,
            'company_id' => $companyId,
            'user_id' => $userId,
            'contractor_uuid' => $payload['contractor_uuid'] ?? null,
            'tax_id' => $payload['tax_id'] ?? null,
            'recipient_name' => $payload['recipient_name'] ?? null,
            'responsible' => $payload['responsible'] ?? null,
            'priority' => $payload['priority'] ?? null,
            'comment' => $payload['comment'] ?? null,
            'delivery_type' => $payload['delivery_type'] ?? null,
            'delivery_address' => $payload['delivery_address'] ?? null,
            'delivery_order' => $payload['delivery_order'] ?? null,
        ];

        // Аудит-метки 1С: передача null — явная установка, отсутствие ключа —
        // не трогаем существующее значение. TZ-нормализация в App\Casts\ErpDatetime.
        foreach (['erp_created_at', 'erp_updated_at'] as $auditField) {
            if (array_key_exists($auditField, $payload)) {
                $fields[$auditField] = $payload[$auditField];
            }
        }

        // v16.15.0: полный недобор — 1С отгружает ордер без строк. Признак ставится до записи
        // статуса: слушатели смены статуса (письмо «ждёт выдачи») должны видеть его сразу.
        if (isset($payload['items']) && is_array($payload['items'])) {
            $fields['shipped_empty'] = $fields['status'] === GoodsIssue::STATUS_SHIPPED && $payload['items'] === [];
        }

        // v16.14.0: способ доставки и состояние обмера. Отсутствие ключа — сообщение старого
        // формата, сохранённое не трогаем.
        $fields = array_merge($fields, $this->resolveMeasurementFields($payload, $uuid, $context));

        // Организация и склад проведения: отсутствие поля не сбрасывает сохранённое.
        $fields = array_merge($fields, $this->resolveOrganizationFields($payload, $context));

        return DB::transaction(function () use ($uuid, $fields, $payload, $context): GoodsIssue {
            $goodsIssue = GoodsIssue::withTrashed()->where('uuid', $uuid)->first();

            $previousStatus = $goodsIssue?->status;

            if ($goodsIssue) {
                if ($goodsIssue->trashed()) {
                    // 1С отменила проведение и провела заново — возвращаем документ складу.
                    $goodsIssue->restore();
                }
                $goodsIssue->update($fields);
            } else {
                $goodsIssue = GoodsIssue::create($fields + ['uuid' => $uuid]);
            }

            $this->recordStatusChange($goodsIssue, $previousStatus);

            // Пустой отгруженный ордер строки не стирает: связь с заказами живёт только в них.
            if (isset($payload['items']) && is_array($payload['items']) && ! ($fields['shipped_empty'] ?? false)) {
                $this->syncItems($goodsIssue, $payload['items'], $context);
            }

            // Отсутствие ключа и пустой массив означают разное: ключа нет — не трогаем
            // сохранённые упаковки, [] — очищаем. Та же семантика, что у payment_schedule.
            if (array_key_exists('packages', $payload) && is_array($payload['packages'])) {
                $this->syncPackages($goodsIssue, $payload['packages'], $context);
            }

            $this->reportShippingMode($goodsIssue, $payload, $context);

            return $goodsIssue;
        });
    }

    /**
     * Зафиксировать переход статуса.
     *
     * Строка пишется только при фактической смене: 1С досылает документ целиком при
     * любом изменении, и без этой проверки журнал забился бы повторами одного статуса.
     */
    private function recordStatusChange(GoodsIssue $goodsIssue, ?string $previousStatus): void
    {
        if ($previousStatus === $goodsIssue->status) {
            return;
        }

        $changedAt = now();

        GoodsIssueStatusHistory::create([
            'goods_issue_id' => $goodsIssue->id,
            'from_status' => $previousStatus,
            'to_status' => $goodsIssue->status,
            'changed_at' => $changedAt,
            'source' => GoodsIssueStatusHistory::SOURCE_ERP,
        ]);

        $goodsIssue->forceFill(['status_changed_at' => $changedAt])->save();
    }

    /**
     * Полная замена строк ордера.
     *
     * Именно замена, а не merge: 1С присылает табличную часть целиком, и «дописать
     * недостающие» означало бы дублировать строки при каждом перепроведении.
     *
     * @param  array<int, mixed>  $items
     */
    private function syncItems(GoodsIssue $goodsIssue, array $items, string $context): void
    {
        $goodsIssue->items()->delete();

        $itemsCount = 0;
        $totalQuantity = 0.0;
        $unresolvedCount = 0;

        foreach (array_values($items) as $index => $item) {
            if (! is_array($item)) {
                continue;
            }

            $productUuid = $item['product_uuid'] ?? null;

            // Скрытые товары читаем тоже: ордера уезжают и по снятой с публикации
            // номенклатуре, и кладовщику она нужна не меньше остальной.
            $product = is_string($productUuid) && $productUuid !== ''
                ? Product::withoutGlobalScopes()->where('external_id', $productUuid)->first()
                : null;

            if (! $product) {
                $unresolvedCount++;
            }

            $orderUuid = $item['order_uuid'] ?? null;
            $order = is_string($orderUuid) && $orderUuid !== ''
                ? Order::withoutGlobalScopes()->where('uuid', $orderUuid)->first()
                : null;

            $quantity = (float) ($item['quantity'] ?? 0);

            $goodsIssue->items()->create([
                'line_number' => $item['line_number'] ?? $index + 1,
                'product_id' => $product?->id,
                'product_uuid' => $productUuid,
                'product_name' => $item['product_name'] ?? $product?->name,
                'order_id' => $order?->id,
                'order_uuid' => $orderUuid,
                'order_number' => $item['order_number'] ?? $order?->erp_number,
                'order_date' => $item['order_date'] ?? null,
                'quantity' => $quantity,
                'unit' => $item['unit'] ?? null,
                'cell' => $this->normalizeCell($item['cell'] ?? null),
                'package_number' => $item['package_number'] ?? null,
            ]);

            $itemsCount++;
            $totalQuantity += $quantity;
        }

        $goodsIssue->forceFill([
            'items_count' => $itemsCount,
            'total_quantity' => $totalQuantity,
            'unresolved_items_count' => $unresolvedCount,
        ])->save();

        if ($unresolvedCount > 0) {
            Log::info($context.': часть позиций не привязана к каталогу', [
                'uuid' => $goodsIssue->uuid,
                'unresolved' => $unresolvedCount,
                'total' => $itemsCount,
            ]);
        }
    }

    /**
     * Ячейка хранения строки: строка как есть, пустая — то же, что отсутствие поля.
     *
     * Справочника ячеек на сайте нет и не планируется — адресное хранение ведётся
     * в 1С, сайт значение только показывает кладовщику.
     */
    private function normalizeCell(mixed $cell): ?string
    {
        if (! is_string($cell)) {
            return null;
        }

        $cell = trim($cell);

        return $cell === '' ? null : $cell;
    }

    /**
     * Поля шапки из `shipping_mode` и `measurement` (v16.14.0).
     *
     * `done` сайт принимает только из сообщения с `revision` и только когда каждое место
     * обмерено. Схема эти условия проверяет сама, но приём от валидатора зависеть не должен:
     * нарушение превращает `done` в `pending`, а факт уходит в журнал шины.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function resolveMeasurementFields(array $payload, string $uuid, string $context): array
    {
        $fields = [];

        if (array_key_exists('shipping_mode', $payload)) {
            $mode = $payload['shipping_mode'];
            $fields['shipping_mode'] = is_string($mode) && isset(GoodsIssue::SHIPPING_MODE_LABELS[$mode]) ? $mode : null;
        }

        $measurement = $payload['measurement'] ?? null;

        if (! is_array($measurement)) {
            return $fields;
        }

        $state = $measurement['state'] ?? null;

        if (! is_string($state) || ! isset(GoodsIssue::MEASUREMENT_LABELS[$state])) {
            return $fields;
        }

        $required = $state !== GoodsIssue::MEASUREMENT_NOT_REQUIRED;

        if ($state === GoodsIssue::MEASUREMENT_DONE) {
            $refusal = $this->doneRefusal($payload);

            if ($refusal !== null) {
                $state = GoodsIssue::MEASUREMENT_PENDING;

                $this->note($context, $uuid, 'Обмер мест принят как «не завершён» (pending), а не done: '.$refusal);
            }
        }

        $done = $state === GoodsIssue::MEASUREMENT_DONE;
        $measuredBy = $measurement['measured_by'] ?? null;

        return $fields + [
            'measurement_required' => $required,
            'measurement_state' => $state,
            // Заполнены только при done — история обмеров остаётся в 1С.
            'measured_at' => $done ? ($measurement['measured_at'] ?? null) : null,
            'measured_by' => $done && is_string($measuredBy) && trim($measuredBy) !== '' ? trim($measuredBy) : null,
        ];
    }

    /**
     * Почему `done` из этого сообщения принять нельзя, либо null.
     *
     * @param  array<string, mixed>  $payload
     */
    private function doneRefusal(array $payload): ?string
    {
        if (! is_int($payload['revision'] ?? null)) {
            return 'done принимается только из сообщения с revision.';
        }

        $packages = $payload['packages'] ?? null;

        if (! is_array($packages) || $packages === []) {
            return 'в сообщении нет ни одного места.';
        }

        foreach ($packages as $package) {
            $dimensions = is_array($package) ? ($package['dimensions'] ?? null) : null;
            $weight = is_array($package) ? ($package['weight'] ?? null) : null;

            if (! is_numeric($weight) || (float) $weight <= 0) {
                return 'у места нет веса.';
            }

            foreach (['length', 'width', 'height'] as $side) {
                if (! is_array($dimensions) || ! is_numeric($dimensions[$side] ?? null) || (int) $dimensions[$side] < 1) {
                    return 'у места нет габаритов (каждая сторона — не меньше 1 см).';
                }
            }
        }

        return null;
    }

    /**
     * Журнал по способу доставки: неизвестный способ и расхождение с заказами сайта.
     *
     * Сам запрет расчёта считает {@see MeasuredPlacesGate} в момент расчёта — заказ могут
     * исправить позже. Здесь только запись факта, чтобы 1С увидела его рядом с сообщением.
     *
     * @param  array<string, mixed>  $payload
     */
    private function reportShippingMode(GoodsIssue $goodsIssue, array $payload, string $context): void
    {
        // Только сообщения нового формата: у старых способа нет вовсе, и это не событие.
        if (! array_key_exists('shipping_mode', $payload) || $goodsIssue->measurement_state === null) {
            return;
        }

        if ($goodsIssue->isShippedEmpty()) {
            return;
        }

        if ($goodsIssue->shipping_mode === null) {
            $this->note($context, $goodsIssue->uuid, 'Способ доставки ордера не определён (shipping_mode = null): расчёт доставки по ордеру недоступен.');

            return;
        }

        $gate = app(MeasuredPlacesGate::class);
        $siteMode = $gate->siteMode($goodsIssue);

        if ($gate->isMismatch($goodsIssue->shipping_mode, $siteMode)) {
            $this->note($context, $goodsIssue->uuid, sprintf(
                'Способ доставки расходится: 1С — %s, заказы ордера на сайте — %s. Расчёт доставки по ордеру заблокирован.',
                $goodsIssue->shipping_mode,
                $siteMode,
            ));
        }
    }

    /**
     * Оговорка к принятому сообщению: в лог приложения и в журнал шины.
     */
    private function note(string $context, string $uuid, string $message): void
    {
        Log::warning($context.': '.$message, ['uuid' => $uuid]);

        app(ErpHandlerOutcome::class)->addNote($message);
    }

    /**
     * Сверка грузовых мест по `uuid` (v16.14.0).
     *
     * Место из снимка обновляется, отсутствующее удаляется, новое создаётся — строка места
     * в БД переживает перепроведение, и к ней можно будет привязать трек-номер перевозчика.
     * Места без `uuid` (старый формат) идентичности не имеют: они заменяются целиком,
     * как раньше, — в том числе при первом снимке нового формата.
     *
     * @param  array<int, mixed>  $packages
     */
    private function syncPackages(GoodsIssue $goodsIssue, array $packages, string $context): void
    {
        /** @var \Illuminate\Support\Collection<string, GoodsIssuePackage> $existing */
        $existing = $goodsIssue->packages()
            ->whereNotNull('uuid')
            ->get()
            ->keyBy(static fn (GoodsIssuePackage $package): string => strtolower((string) $package->uuid));

        $goodsIssue->packages()->whereNull('uuid')->delete();

        $count = 0;
        $seenNumbers = [];
        $seenUuids = [];

        foreach ($packages as $package) {
            if (! is_array($package) || ! isset($package['number'])) {
                continue;
            }

            $number = (int) $package['number'];
            $uuid = is_string($package['uuid'] ?? null) && trim($package['uuid']) !== ''
                ? strtolower(trim($package['uuid']))
                : null;

            if ($uuid === null) {
                // Старый формат: номер места уникален в пределах ордера. Повтор в payload —
                // ошибка 1С, но ронять весь документ из-за неё нельзя: ордер нужен складу.
                if (isset($seenNumbers[$number])) {
                    continue;
                }
                $seenNumbers[$number] = true;
            } elseif (isset($seenUuids[$uuid])) {
                Log::warning($context.': место с повторяющимся uuid пропущено', [
                    'uuid' => $goodsIssue->uuid,
                    'package_uuid' => $uuid,
                ]);

                continue;
            } else {
                $seenUuids[$uuid] = true;
            }

            $attributes = $this->packageAttributes($package, $number);

            if ($uuid !== null && $existing->has($uuid)) {
                $existing->get($uuid)->update($attributes);
            } else {
                $goodsIssue->packages()->create($attributes + ['uuid' => $uuid]);
            }

            $count++;
        }

        // Места, которых в снимке больше нет: лист удалён или пересоздан с новым GUID.
        $gone = $existing->reject(static fn (GoodsIssuePackage $package, string $uuid): bool => isset($seenUuids[$uuid]));

        if ($gone->isNotEmpty()) {
            $goodsIssue->packages()->whereIn('id', $gone->map->getKey()->all())->delete();
        }

        $goodsIssue->unsetRelation('packages');
        $goodsIssue->forceFill(['packages_count' => $count])->save();
    }

    /**
     * Поля места из payload. Единицы — как в контракте: вес в кг брутто, габариты в целых см.
     *
     * `barcode` хранится строкой как пришёл: длина переменная, ведущих нулей нет, к числу
     * не приводится и нулями не дополняется — иначе скан наклейки не совпадёт с сообщением.
     *
     * @param  array<string, mixed>  $package
     * @return array<string, mixed>
     */
    private function packageAttributes(array $package, int $number): array
    {
        $dimensions = is_array($package['dimensions'] ?? null) ? $package['dimensions'] : [];
        $barcode = $package['barcode'] ?? null;
        $type = $package['package_type'] ?? null;

        $side = static fn (string $key): ?int => is_numeric($dimensions[$key] ?? null) && (int) $dimensions[$key] >= 1
            ? (int) $dimensions[$key]
            : null;

        return [
            'number' => $number,
            'barcode' => is_string($barcode) && trim($barcode) !== '' ? trim($barcode) : null,
            'package_type' => is_string($type) && isset(GoodsIssuePackage::TYPE_LABELS[$type]) ? $type : null,
            'positions_count' => $package['positions_count'] ?? null,
            'weight' => $package['weight'] ?? null,
            'length' => $side('length'),
            'width' => $side('width'),
            'height' => $side('height'),
            'volume' => $package['volume'] ?? null,
        ];
    }
}
