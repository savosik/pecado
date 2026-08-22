<?php

namespace App\Enums\Crm;

/**
 * Тема письма крупными мазками: заказы, документы, оплаты, переписка.
 *
 * Метки отвечают на вопрос «за что зацепить фильтр» и их десятки; топик
 * отвечает на вопрос «что я сейчас разбираю» и их пять. Отдельной колонки
 * не заводим — топик выводится из повода, по которому письмо собрано,
 * и второго источника правды не появляется.
 */
enum MailTopic: string
{
    case ORDERS = 'orders';
    case DOCUMENTS = 'documents';
    case FINANCE = 'finance';
    case SERVICE = 'system';
    case MANUAL = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::ORDERS => 'Заказы',
            self::DOCUMENTS => 'Документы',
            self::FINANCE => 'Оплаты',
            self::SERVICE => 'Возвраты и вопросы',
            self::MANUAL => 'Переписка менеджера',
        };
    }

    /**
     * Условие отбора писем этого топика.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\CrmEmail>  $query
     */
    public function apply($query): void
    {
        if ($this === self::MANUAL) {
            $query->where('origin', \App\Models\CrmEmail::ORIGIN_MANUAL);

            return;
        }

        $query->where('origin_event', 'like', $this->value.'.%');
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $case): array => [
            'value' => $case->value,
            'label' => $case->label(),
        ], self::cases());
    }
}
