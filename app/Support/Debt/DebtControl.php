<?php

namespace App\Support\Debt;

/**
 * Рубильники лестницы долга — одно место, где читается config/debt.php
 * про режимы. Ступени считаются всегда, когда домен включён; действия
 * (письма, гейт, задачи, кабинет) включаются поштучно в боевом режиме.
 */
final class DebtControl
{
    public const ACTION_MAIL = 'mail';

    public const ACTION_GATE = 'gate';

    public const ACTION_TASKS = 'tasks';

    public const ACTION_CABINET = 'cabinet';

    public static function enabled(): bool
    {
        return (bool) config('debt.enabled', false);
    }

    /** Теневой расчёт: ступени пишутся с dry_run = 1, действий нет. */
    public static function shadow(): bool
    {
        return ! self::enabled() || (string) config('debt.mode', 'shadow') !== 'live';
    }

    /**
     * Включено ли конкретное действие в бою.
     */
    public static function live(string $action): bool
    {
        if (self::shadow()) {
            return false;
        }

        return in_array($action, self::liveActions(), true);
    }

    /**
     * @return list<string>
     */
    public static function liveActions(): array
    {
        $raw = (string) config('debt.live_actions', '');

        return array_values(array_filter(array_map(
            static fn (string $item): string => trim($item),
            explode(',', $raw),
        )));
    }
}
