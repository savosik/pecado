<?php

namespace App\Services\Substitution;

use App\Enums\Substitution\CandidateKind;
use App\Enums\Substitution\LinkKind;
use App\Enums\Substitution\LinkSource;
use App\Models\ProductSubstitution;
use App\Models\SubstitutionOffer;
use App\Models\SubstitutionOfferItem;

/**
 * Самообучение справочника замен: согласованный клиентом выбор — самый
 * сильный сигнал, что пара товаров действительно взаимозаменяема.
 *
 * learned-связь требует подтверждения менеджером до использования
 * автоподбором — кроме пары, которая уже подтверждалась раньше: там
 * согласие клиента только усиливает уверенность.
 */
class SubstitutionLearningService
{
    /**
     * Зафиксировать выбор клиента по согласованной подборке. Идемпотентно:
     * уникальный ключ пары, повторное согласование лишь наращивает score.
     */
    public function recordClientChoice(SubstitutionOffer $offer): void
    {
        $offer->loadMissing('items.sourceOrderItem');

        $chosen = $offer->items
            ->where('chosen', true)
            ->filter(fn (SubstitutionOfferItem $item) => $item->product_id !== null
                && $item->kind !== CandidateKind::SAME_PRODUCT_WAIT);

        foreach ($chosen as $item) {
            $fromProductId = $item->sourceOrderItem?->product_id;

            if ($fromProductId === null || $fromProductId === $item->product_id) {
                continue;
            }

            $link = ProductSubstitution::query()->firstOrNew([
                'from_product_id' => $fromProductId,
                'to_product_id' => $item->product_id,
            ]);

            // Отклонённая пара не воскресает: менеджер уже сказал «нет».
            if ($link->exists && $link->rejected_at !== null) {
                continue;
            }

            if (! $link->exists) {
                $link->fill([
                    'kind' => $this->mapKind($item->kind),
                    'source' => LinkSource::LEARNED,
                    'score' => 60,
                    'note' => $item->reason,
                ]);
            } else {
                $link->score = min(100, (int) $link->score + 10);
            }

            $link->save();
        }
    }

    /**
     * Слой кандидата → характер связи справочника.
     */
    private function mapKind(CandidateKind $kind): LinkKind
    {
        return match ($kind) {
            CandidateKind::VARIANT => LinkKind::VARIANT,
            CandidateKind::LINE => LinkKind::LINE,
            default => LinkKind::EQUIVALENT,
        };
    }
}
