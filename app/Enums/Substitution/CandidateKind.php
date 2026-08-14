<?php

namespace App\Enums\Substitution;

use App\Enums\Crm\Concerns\HasLabeledOptions;

/**
 * Слой подбора, из которого пришёл кандидат на замену.
 *
 * Порядок кейсов — это и есть приоритет слоёв: уценка того же товара сильнее
 * любой замены, подтверждённая связь сильнее эвристики, семантика — последний
 * фолбэк для пустых категорий.
 */
enum CandidateKind: string
{
    use HasLabeledOptions;

    case SAME_PRODUCT_WAIT = 'same_product_wait';
    case DEFECT_SAME = 'defect_same';
    case LINKED = 'linked';
    case VARIANT = 'variant';
    case LINE = 'line';
    case FUNCTIONAL = 'functional';
    case CATEGORY_PRICE = 'category_price';
    case SEMANTIC = 'semantic';
    case MANUAL = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::SAME_PRODUCT_WAIT => 'Тот же товар, подождать прихода',
            self::DEFECT_SAME => 'Тот же товар, уценка',
            self::LINKED => 'Подтверждённая замена',
            self::VARIANT => 'Вариант той же модели',
            self::LINE => 'Та же линейка',
            self::FUNCTIONAL => 'Тот же функциональный тип',
            self::CATEGORY_PRICE => 'Аналог по категории и цене',
            self::SEMANTIC => 'Похожий по описанию',
            self::MANUAL => 'Добавлен менеджером',
        };
    }
}
