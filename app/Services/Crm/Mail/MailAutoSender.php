<?php

namespace App\Services\Crm\Mail;

use App\Models\CrmEmail;
use App\Models\CrmMailRule;
use App\Models\CrmMailRuleHit;
use App\Models\NotificationSuppression;
use App\Services\Crm\CrmEmailService;
use RuntimeException;

/**
 * Автоматическая отправка письма по правилу.
 *
 * Галочка «отправлять автоматически» — свойство правила, а не режим системы:
 * одни фильтры уже работают сами, другие ещё под присмотром менеджера. Так
 * запуск получается пошаговым без единого общего переключателя, который
 * страшно трогать.
 *
 * Любой отказ записывается в само письмо причиной. Молчание здесь недопустимо:
 * менеджер, уверенный, что клиенту ушло письмо, не должен узнавать обратное
 * от клиента.
 */
class MailAutoSender
{
    public function __construct(private readonly CrmEmailService $emails) {}

    public function attempt(CrmEmail $letter, CrmMailRule $rule): bool
    {
        $reason = $this->refusal($letter, $rule);

        if ($reason !== null) {
            $letter->forceFill(['skip_reason' => $reason])->save();

            return false;
        }

        try {
            $this->emails->send($letter);
        } catch (RuntimeException $exception) {
            $letter->forceFill(['skip_reason' => $exception->getMessage()])->save();

            return false;
        }

        $letter->forceFill([
            'auto_sent_rule_id' => $rule->getKey(),
            'skip_reason' => null,
        ])->save();

        CrmMailRuleHit::query()
            ->where('rule_id', $rule->getKey())
            ->where('crm_email_id', $letter->getKey())
            ->update(['auto_sent' => true]);

        return true;
    }

    /**
     * Почему письмо не уходит само — или null, если препятствий нет.
     */
    private function refusal(CrmEmail $letter, CrmMailRule $rule): ?string
    {
        if (! config('mail_stream.autosend')) {
            return 'Автоотправка выключена администратором';
        }

        if (($letter->to ?? []) === []) {
            return 'Не удалось определить ни одного получателя';
        }

        $maxAge = (int) config('mail_stream.max_age_minutes', 180);

        if ($letter->created_at !== null && $letter->created_at->lt(now()->subMinutes($maxAge))) {
            return 'Письмо старше допустимого возраста автоотправки';
        }

        $blocked = $this->blockedAddress($letter);

        if ($blocked !== null) {
            return 'Адрес в стоп-листе: '.$blocked;
        }

        $throttled = $this->throttledAddress($letter, $rule);

        if ($throttled !== null) {
            return 'Слишком часто по правилу «'.$rule->name.'»: '.$throttled;
        }

        return null;
    }

    private function blockedAddress(CrmEmail $letter): ?string
    {
        $eventKey = $letter->origin_event ?? 'crm.manual';

        foreach ((array) $letter->to as $address) {
            if (NotificationSuppression::blocks((string) $address, $eventKey)) {
                return (string) $address;
            }
        }

        return null;
    }

    /**
     * Защита от серии однотипных писем одному адресату.
     *
     * Считается по фактически отправленным письмам этого правила — если правило
     * молчало, ограничение не срабатывает.
     */
    private function throttledAddress(CrmEmail $letter, CrmMailRule $rule): ?string
    {
        $minutes = (int) ($rule->throttle_minutes ?? 0);

        if ($minutes <= 0) {
            return null;
        }

        $recent = CrmEmail::query()
            ->where('auto_sent_rule_id', $rule->getKey())
            ->where('id', '!=', $letter->getKey())
            ->where('created_at', '>=', now()->subMinutes($minutes))
            ->get(['id', 'to']);

        foreach ($recent as $sent) {
            foreach ((array) $sent->to as $address) {
                if (in_array((string) $address, (array) $letter->to, true)) {
                    return (string) $address;
                }
            }
        }

        return null;
    }
}
