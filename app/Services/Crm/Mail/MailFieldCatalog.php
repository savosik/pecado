<?php

namespace App\Services\Crm\Mail;

/**
 * Что можно проверить в письме и какими сравнениями.
 *
 * Справочник для конструктора правила: имена полей русские, технических
 * ключей менеджер не видит. Набор закрытый — свободный ввод имени поля
 * дал бы правило, которое молча никогда не срабатывает.
 */
class MailFieldCatalog
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function groups(): array
    {
        return [
            [
                'label' => 'Письмо',
                'fields' => [
                    self::field('tag', 'Метка', 'tag', 'Метки письма — за них цепляется фильтр'),
                    self::field('text', 'Тема или текст', 'text'),
                    self::field('subject', 'Тема', 'text'),
                    self::field('body', 'Текст письма', 'text'),
                    self::field('to', 'Получатель', 'text'),
                ],
            ],
            [
                'label' => 'Клиент',
                'fields' => [
                    self::field('company_tax_id', 'ИНН контрагента', 'text'),
                    self::field('company_name', 'Контрагент', 'text'),
                    self::field('client_name', 'Партнёр', 'text'),
                    self::field('client_city', 'Город клиента', 'text'),
                    self::field('client_status', 'Стадия работы', 'text'),
                    self::field('client_business', 'Тип бизнеса', 'text'),
                    self::field('client_notes', 'Заметки о клиенте', 'text'),
                ],
            ],
            [
                'label' => 'Числа',
                'fields' => [
                    self::field('days_overdue', 'Дней просрочки', 'number'),
                    self::field('overdue_amount', 'Сумма просрочки', 'number'),
                    self::field('days_left', 'Дней до срока оплаты', 'number'),
                    self::field('amount', 'Сумма к оплате', 'number'),
                    self::field('total', 'Сумма заказа', 'number'),
                    self::field('total_delta', 'Изменение суммы заказа', 'number'),
                    self::field('removed_count', 'Позиций выбыло', 'number'),
                    self::field('shortfall_items_count', 'Позиций в недоборе', 'number'),
                ],
            ],
        ];
    }

    /**
     * Сравнения, доступные полю каждого типа.
     *
     * @return array<string, array<int, array{value: string, label: string}>>
     */
    public static function operators(): array
    {
        return [
            'tag' => [
                ['value' => 'has_tag', 'label' => 'есть метка'],
                ['value' => 'not_has_tag', 'label' => 'нет метки'],
            ],
            'text' => [
                ['value' => 'contains', 'label' => 'содержит'],
                ['value' => 'not_contains', 'label' => 'не содержит'],
                ['value' => '=', 'label' => 'равно'],
                ['value' => '!=', 'label' => 'не равно'],
                ['value' => 'not_empty', 'label' => 'заполнено'],
                ['value' => 'is_empty', 'label' => 'не заполнено'],
                ['value' => 'regex', 'label' => 'подходит под выражение'],
            ],
            'number' => [
                ['value' => '>', 'label' => 'больше'],
                ['value' => '>=', 'label' => 'больше или равно'],
                ['value' => '<', 'label' => 'меньше'],
                ['value' => '<=', 'label' => 'меньше или равно'],
                ['value' => '=', 'label' => 'равно'],
            ],
        ];
    }

    /**
     * Операторы, которым не нужно значение.
     *
     * @return array<int, string>
     */
    public static function unaryOperators(): array
    {
        return ['is_empty', 'not_empty'];
    }

    /**
     * @return array<int, string>
     */
    public static function allFields(): array
    {
        $fields = [];

        foreach (self::groups() as $group) {
            foreach ($group['fields'] as $field) {
                $fields[] = $field['value'];
            }
        }

        return $fields;
    }

    /**
     * @return array<int, string>
     */
    public static function allOperators(): array
    {
        $operators = [];

        foreach (self::operators() as $list) {
            foreach ($list as $item) {
                $operators[] = $item['value'];
            }
        }

        return array_values(array_unique($operators));
    }

    /**
     * @return array<string, mixed>
     */
    private static function field(string $value, string $label, string $type, string $hint = ''): array
    {
        return ['value' => $value, 'label' => $label, 'type' => $type, 'hint' => $hint];
    }
}
