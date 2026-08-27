<?php

namespace App\Enums;

/**
 * Откуда в справочнике взялась карточка человека.
 *
 * Нужен не ради статистики, а ради доверия к данным: менеджер должен видеть,
 * что телефон поправил сам партнёр на прошлой неделе, а не машина год назад
 * распознала его из свободного текста анкеты. Источник рисуется бейджем
 * в списке и определяет права партнёра в кабинете — свою карточку он удаляет,
 * нашу только гасит.
 */
enum ContactSource: string
{
    case MANUAL = 'manual';
    case SELF = 'self';
    case PROFILE_IMPORT = 'profile_import';
    case DIRECTORY_IMPORT = 'directory_import';
    case VCF = 'vcf';
    case ERP = 'erp';
    case MANAGER_SHEET = 'manager_sheet';

    public function label(): string
    {
        return match ($this) {
            self::MANUAL => 'Завёл менеджер',
            self::SELF => 'Указал партнёр',
            self::PROFILE_IMPORT => 'Перенесено из анкеты',
            self::DIRECTORY_IMPORT => 'Собрано из данных сайта',
            self::VCF => 'Импорт из телефона',
            self::ERP => 'Из 1С',
            self::MANAGER_SHEET => 'Из таблицы менеджера',
        };
    }

    /**
     * Короткая подпись для бейджа: в строке списка длинной не место.
     */
    public function badge(): string
    {
        return match ($this) {
            self::MANUAL => 'Менеджер',
            self::SELF => 'Партнёр',
            self::PROFILE_IMPORT => 'Из анкеты',
            self::DIRECTORY_IMPORT => 'Собрано',
            self::VCF => 'Из телефона',
            self::ERP => '1С',
            self::MANAGER_SHEET => 'Таблица',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::MANUAL => 'gray',
            self::SELF => 'green',
            self::PROFILE_IMPORT, self::DIRECTORY_IMPORT => 'orange',
            self::VCF => 'cyan',
            self::ERP => 'purple',
            self::MANAGER_SHEET => 'blue',
        };
    }

    /**
     * Завёл ли карточку сам партнёр.
     *
     * По этому вопросу расходятся его права в кабинете: свою карточку он удаляет,
     * нашу — только помечает «больше не работает».
     */
    public function belongsToPartner(): bool
    {
        return $this === self::SELF;
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

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
