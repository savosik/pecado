<?php

namespace App\Enums\Crm;

/**
 * Папка в разделе «Письма».
 *
 * Папка — это не новая сущность, а другой показ того же списка: статус письма
 * и есть папка. Отдельный enum нужен затем, что «Черновики» объединяют два
 * статуса (черновик и поставленное в очередь), а менеджеру эта разница не важна.
 */
enum MailFolder: string
{
    case DRAFTS = 'drafts';
    case SENT = 'sent';
    case FAILED = 'failed';
    case UNMATCHED = 'unmatched';

    public function label(): string
    {
        return match ($this) {
            self::DRAFTS => 'Черновики',
            self::SENT => 'Отправленные',
            self::FAILED => 'Не ушли',
            self::UNMATCHED => 'Мимо фильтров',
        };
    }

    public function hint(): string
    {
        return match ($this) {
            self::DRAFTS => 'Ждут отправки — здесь самолётик',
            self::SENT => 'Ушли адресатам',
            self::FAILED => 'Ошибка адреса или отказ почтового сервера',
            self::UNMATCHED => 'Собрано системой, но ни одно правило не поймало',
        };
    }

    /**
     * Статусы писем, попадающих в папку.
     *
     * @return array<int, string>
     */
    public function statuses(): array
    {
        return match ($this) {
            self::DRAFTS => [EmailStatus::DRAFT->value, EmailStatus::QUEUED->value],
            self::SENT => [EmailStatus::SENT->value],
            self::FAILED => [EmailStatus::FAILED->value],
            self::UNMATCHED => [EmailStatus::UNMATCHED->value],
        };
    }

    /**
     * @return list<array{value: string, label: string, hint: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $case): array => [
            'value' => $case->value,
            'label' => $case->label(),
            'hint' => $case->hint(),
        ], self::cases());
    }
}
