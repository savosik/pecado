<?php

namespace App\Services\Notifications\Pulse;

/**
 * Единственный источник правды о том, кто отвечает за событие.
 *
 * Читают обе стороны миграции: движок пульта и старые листенеры. Листенер
 * первой строкой спрашивает handles() и молчит, если событие уже переведено.
 * Поэтому двойная отправка невозможна по конструкции, а не по внимательности
 * того, кто правил код.
 */
class PulseMode
{
    public const MODE_OFF = 'off';

    /** Движок считает получателей и пишет журнал, но не отправляет. */
    public const MODE_SHADOW = 'shadow';

    public const MODE_LIVE = 'live';

    public static function mode(): string
    {
        if (! config('notification_pulse.enabled', false)) {
            return self::MODE_OFF;
        }

        $mode = (string) config('notification_pulse.mode', self::MODE_SHADOW);

        return in_array($mode, [self::MODE_OFF, self::MODE_SHADOW, self::MODE_LIVE], true)
            ? $mode
            : self::MODE_SHADOW;
    }

    /**
     * Отвечает ли пульт за отправку по этому событию.
     *
     * true означает: старый листенер обязан промолчать. false — пульт либо
     * выключен, либо в теневом режиме, либо событие ещё не переведено, и
     * письмо шлёт прежний код.
     */
    public static function handles(string $eventKey): bool
    {
        if (self::mode() !== self::MODE_LIVE) {
            return false;
        }

        if (! self::domainEnabled($eventKey)) {
            return false;
        }

        $live = (array) config('notification_pulse.live_events', []);

        // Пустой список при mode=live означает «все включённые домены»:
        // так выглядит состояние после завершения миграции.
        if ($live === []) {
            return true;
        }

        return in_array($eventKey, $live, true)
            || in_array(explode('.', $eventKey)[0].'.*', $live, true);
    }

    /**
     * Принимать ли сигнал вообще: в теневом режиме — да, но без отправки.
     */
    public static function accepts(string $eventKey): bool
    {
        return self::mode() !== self::MODE_OFF && self::domainEnabled($eventKey);
    }

    public static function isShadow(): bool
    {
        return self::mode() === self::MODE_SHADOW;
    }

    private static function domainEnabled(string $eventKey): bool
    {
        $domain = explode('.', $eventKey)[0];

        return (bool) config('notification_pulse.domains.'.$domain.'.enabled', false);
    }
}
