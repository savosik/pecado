<?php

namespace App\Services\Erp\Support;

use App\Models\ErpScheduledPrice;
use App\Models\Product;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Базовая цена с будущей датой вступления в силу (v16.13.0, топик №15 Agent Hub).
 *
 * Коммерческий отдел проводит установку цен будущей датой, а 1С публикует
 * price.updated в момент проведения. Такую цену держим отложенной по паре
 * товар × документ и включаем планировщиком (erp:activate-scheduled-prices),
 * когда дата наступила. До этого витрина, корзина и заказ живут по действующей.
 *
 * Порядок блокировок везде один — сначала карточка товара, потом строки
 * расписания: так планировщик и обработчик price.updated не встают друг другу
 * в дедлок и не затирают более свежую цену.
 */
class ScheduledPrices
{
    public function __construct(private PendingPrices $pending = new PendingPrices) {}

    /**
     * Отложить цену. Повтор с той же парой товар × документ заменяет запись
     * целиком — документ перепровели другой датой или ценой.
     */
    public function schedule(
        string $productUuid,
        string $documentUuid,
        float $price,
        CarbonInterface $effectiveFrom,
        ?string $documentNumber,
        ?string $messageId,
    ): ErpScheduledPrice {
        return ErpScheduledPrice::query()->updateOrCreate(
            ['product_uuid' => $productUuid, 'document_uuid' => $documentUuid],
            [
                'price' => $price,
                // Eloquent пишет дату без пересчёта пояса: «00:00+03:00» в поясе
                // приложения, отличном от МСК, легло бы не тем моментом.
                'effective_from' => $effectiveFrom->copy()->setTimezone(config('app.timezone')),
                'document_number' => $documentNumber,
                'message_id' => $messageId,
            ],
        );
    }

    /**
     * Снять отложенную цену пары: документ перепровели датой, которая уже
     * наступила, — цена применяется сразу, запись расписания больше не нужна.
     */
    public function forget(string $productUuid, string $documentUuid): int
    {
        return ErpScheduledPrice::query()
            ->where('product_uuid', $productUuid)
            ->where('document_uuid', $documentUuid)
            ->delete();
    }

    /**
     * price.cancelled: документ распровели или удалили до наступления даты.
     *
     * @param  list<string>  $productUuids
     */
    public function cancel(string $documentUuid, array $productUuids): int
    {
        return ErpScheduledPrice::query()
            ->where('document_uuid', $documentUuid)
            ->whereIn('product_uuid', $productUuids)
            ->delete();
    }

    /**
     * Наступившие, но ещё не включённые цены товара поглощает более свежее
     * price.updated: оно пришло позже и ляжет поверх, а планировщик иначе
     * через минуту вернул бы старое значение.
     */
    public function supersedeDue(string $productUuid): int
    {
        $due = ErpScheduledPrice::query()
            ->where('product_uuid', $productUuid)
            ->where('effective_from', '<=', now())
            ->get();

        foreach ($due as $row) {
            Log::info('price.updated: наступившая отложенная цена поглощена более свежим сообщением', [
                'product_uuid' => $productUuid,
                'document_uuid' => $row->document_uuid,
                'document_number' => $row->document_number,
                'price' => $row->price,
                'effective_from' => $row->effective_from->toIso8601String(),
            ]);
        }

        return ErpScheduledPrice::query()->whereKey($due->modelKeys())->delete();
    }

    /**
     * Включить все цены с наступившей датой. У товара с несколькими такими
     * ценами действует самая поздняя по effective_from — как в регистре 1С.
     *
     * @return int число товаров, у которых включена цена
     */
    public function activateDue(): int
    {
        $productUuids = ErpScheduledPrice::query()
            ->where('effective_from', '<=', now())
            ->distinct()
            ->pluck('product_uuid');

        $activated = 0;

        foreach ($productUuids as $productUuid) {
            if ($this->activateFor($productUuid)) {
                $activated++;
            }
        }

        return $activated;
    }

    private function activateFor(string $productUuid): bool
    {
        /** @var array{0: 'none'|'applied'|'no_card', 1: ?ErpScheduledPrice} $result */
        $result = DB::transaction(function () use ($productUuid): array {
            $product = Product::withoutGlobalScopes()
                ->where('external_id', $productUuid)
                ->lockForUpdate()
                ->first();

            // Перечитываем под блокировкой карточки: пока ждали, цену могли
            // отменить (price.cancelled) или поглотить свежим price.updated.
            $due = ErpScheduledPrice::query()
                ->where('product_uuid', $productUuid)
                ->where('effective_from', '<=', now())
                ->orderBy('effective_from')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $winner = $due->last();

            if (! $winner) {
                return ['none', null];
            }

            ErpScheduledPrice::query()->whereKey($due->modelKeys())->delete();

            if (! $product) {
                return ['no_card', $winner];
            }

            $oldPrice = $product->base_price;
            $this->pending->discard($productUuid);
            $product->update(['base_price' => $winner->price]);

            Log::info('price.updated: отложенная цена вступила в силу', [
                'product_id' => $product->id,
                'product_uuid' => $productUuid,
                'document_uuid' => $winner->document_uuid,
                'document_number' => $winner->document_number,
                'effective_from' => $winner->effective_from->toIso8601String(),
                'old_price' => $oldPrice,
                'new_price' => $winner->price,
                'superseded' => $due->count() - 1,
            ]);

            return ['applied', $winner];
        });

        [$state, $parkedWithoutCard] = $result;

        if ($state !== 'no_card') {
            return $state === 'applied';
        }

        // Карточки ещё нет — цена дожидается product.created тем же путём,
        // что и обогнавший карточку price.updated (v16.12.3).
        $this->pending->park($productUuid, (float) $parkedWithoutCard->price, $parkedWithoutCard->message_id);
        $this->pending->apply($productUuid);

        Log::info('price.updated: отложенная цена вступила в силу, карточки ещё нет — ждёт product.created', [
            'product_uuid' => $productUuid,
            'document_uuid' => $parkedWithoutCard->document_uuid,
            'price' => $parkedWithoutCard->price,
        ]);

        return true;
    }
}
