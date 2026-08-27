<?php

namespace App\Enums\Crm;

/**
 * Статус подписания договора.
 *
 * Три рабочих значения — ровно столько, сколько вела таблица менеджеров
 * («Не отправлен», «Отправлен», «Подписан (получен)»). Четвёртое, «расторгнут»,
 * добавлено, чтобы закрытый договор не приходилось удалять: по нему остаются
 * сканы и задачи, а контрагент с расторгнутым договором должен подсвечиваться
 * во вкладке «Без договора» как контрагент без действующего документа.
 */
enum ContractStatus: string
{
    case DRAFT = 'draft';
    case SENT = 'sent';
    case SIGNED = 'signed';
    case TERMINATED = 'terminated';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Не отправлен',
            self::SENT => 'Отправлен',
            self::SIGNED => 'Подписан',
            self::TERMINATED => 'Расторгнут',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::DRAFT => 'gray',
            self::SENT => 'orange',
            self::SIGNED => 'green',
            self::TERMINATED => 'red',
        };
    }

    /**
     * Договор «в силе»: закрывает контрагента во вкладке «Без договора».
     */
    public function isActive(): bool
    {
        return $this !== self::TERMINATED;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * @return list<array{value: string, label: string, color: string}>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $case): array => [
                'value' => $case->value,
                'label' => $case->label(),
                'color' => $case->color(),
            ],
            self::cases(),
        );
    }
}
