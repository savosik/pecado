<?php

namespace App\Services\Crm\Mail;

use App\Enums\Crm\EmailStatus;
use App\Models\CrmEmail;
use App\Models\CrmMailRule;
use App\Models\CrmMailRuleHit;
use App\Models\PersonalManager;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Разбор письма по правилам-фильтрам.
 *
 * Все активные правила проверяются независимо: письмо может подойти сразу
 * под несколько, а один и тот же адрес получит его один раз. Приоритетов
 * и «остановки разбора» здесь нет намеренно — именно они оказались главным
 * источником непонимания в предыдущем подходе. Исключение выражается
 * условием «не содержит», а не порядком.
 */
class MailRuleEngine
{
    public function __construct(
        private readonly LetterMatcher $matcher,
        private readonly MailAutoSender $autoSender,
    ) {}

    /**
     * Разобрать письмо: проставить получателей и решить, в какую папку оно ляжет.
     *
     * @return array<int, CrmMailRule> сработавшие правила
     */
    public function apply(CrmEmail $letter): array
    {
        if ($letter->status === EmailStatus::SENT || $letter->status === EmailStatus::QUEUED) {
            return [];
        }

        $matched = $this->match($letter);

        if ($matched === []) {
            // Ручное письмо остаётся черновиком: менеджер написал его сам,
            // и отсутствие правила ничего не значит. Мимо фильтров уходит
            // только то, что собрала система.
            if ($letter->isSystem() && $letter->status !== EmailStatus::UNMATCHED) {
                $letter->status = EmailStatus::UNMATCHED;
                $letter->save();
            }

            return [];
        }

        $recipients = $this->recipients($matched, $letter);

        if ($recipients['to'] !== []) {
            $letter->to = $recipients['to'];
        }

        if ($recipients['cc'] !== []) {
            $letter->cc = $recipients['cc'];
        }

        $letter->status = EmailStatus::DRAFT;
        $letter->save();

        $this->recordHits($matched, $letter);

        $auto = $this->firstAutoSending($matched);

        if ($auto !== null) {
            $this->autoSender->attempt($letter, $auto);
        }

        return $matched;
    }

    /**
     * Правила, под которые письмо подошло.
     *
     * @return array<int, CrmMailRule>
     */
    public function match(CrmEmail $letter): array
    {
        return $this->activeRules()
            ->filter(fn (CrmMailRule $rule): bool => $this->matcher->matches($rule, $letter))
            ->values()
            ->all();
    }

    /**
     * Пересобрать папку «Мимо фильтров» после появления или правки правила.
     *
     * Правило, заведённое сегодня, обязано подобрать вчерашние письма: иначе
     * менеджер настроит фильтр по сводке непойманного и не увидит ни одного
     * письма, ради которого настраивал.
     *
     * @return int сколько писем перешло в «Черновики»
     */
    public function reapplyToUnmatched(?CrmMailRule $rule = null): int
    {
        $retention = (int) config('mail_stream.unmatched_retention_days', 14);

        $letters = CrmEmail::query()
            ->where('status', EmailStatus::UNMATCHED->value)
            ->where('created_at', '>=', now()->subDays($retention))
            ->with(['client.crmProfile'])
            ->latest('id')
            ->limit(1000)
            ->get();

        $moved = 0;

        foreach ($letters as $letter) {
            if ($rule !== null && ! $this->matcher->matches($rule, $letter)) {
                continue;
            }

            if ($this->apply($letter) !== []) {
                $moved++;
            }
        }

        return $moved;
    }

    /**
     * Раскрыть получателей сработавших правил.
     *
     * Адрес, встретившийся в нескольких правилах, остаётся один: получатель
     * не должен получать два одинаковых письма из-за того, что менеджер завёл
     * два похожих фильтра.
     *
     * @param  array<int, CrmMailRule>  $rules
     * @return array{to: array<int, string>, cc: array<int, string>}
     */
    public function recipients(array $rules, CrmEmail $letter): array
    {
        $to = [];
        $cc = [];

        foreach ($rules as $rule) {
            foreach ((array) $rule->recipients as $address) {
                foreach ($this->resolve((string) $address, $letter) as $resolved) {
                    $to[mb_strtolower($resolved)] = $resolved;
                }
            }

            foreach ((array) ($rule->cc ?? []) as $address) {
                foreach ($this->resolve((string) $address, $letter) as $resolved) {
                    $cc[mb_strtolower($resolved)] = $resolved;
                }
            }
        }

        // Уже стоящие в письме адреса сохраняются: менеджер мог дописать
        // получателя руками, и правило не должно его вычёркивать.
        foreach ((array) ($letter->to ?? []) as $address) {
            $to[mb_strtolower((string) $address)] = (string) $address;
        }

        $cc = array_diff_key($cc, $to);

        return ['to' => array_values($to), 'cc' => array_values($cc)];
    }

    /**
     * Спецзначения «клиент» и «менеджер» раскрываются по самому письму.
     *
     * Без них правило «смена статуса → на почту клиента» пришлось бы заводить
     * отдельно на каждого клиента — то есть восемьсот раз.
     *
     * @return array<int, string>
     */
    private function resolve(string $address, CrmEmail $letter): array
    {
        $address = trim($address);

        if ($address === '') {
            return [];
        }

        if (mb_strtolower($address) === CrmMailRule::RECIPIENT_CLIENT) {
            $email = $letter->client_user_id === null
                ? null
                : User::query()->whereKey($letter->client_user_id)->value('email');

            return filled($email) ? [(string) $email] : [];
        }

        if (mb_strtolower($address) === CrmMailRule::RECIPIENT_MANAGER) {
            return $this->managerEmail($letter);
        }

        return [$address];
    }

    /**
     * @return array<int, string>
     */
    private function managerEmail(CrmEmail $letter): array
    {
        $managerId = $letter->client_user_id === null
            ? null
            : User::query()->whereKey($letter->client_user_id)->value('personal_manager_id');

        if ($managerId === null) {
            return [];
        }

        $email = PersonalManager::query()->with('user')->find($managerId)?->user?->email;

        return filled($email) ? [(string) $email] : [];
    }

    /**
     * @param  array<int, CrmMailRule>  $rules
     */
    private function firstAutoSending(array $rules): ?CrmMailRule
    {
        foreach ($rules as $rule) {
            if ($rule->auto_send) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * @param  array<int, CrmMailRule>  $rules
     */
    private function recordHits(array $rules, CrmEmail $letter): void
    {
        foreach ($rules as $rule) {
            $created = CrmMailRuleHit::query()->firstOrCreate([
                'rule_id' => $rule->getKey(),
                'crm_email_id' => $letter->getKey(),
            ], ['auto_sent' => false]);

            if (! $created->wasRecentlyCreated) {
                continue;
            }

            $rule->forceFill([
                'matched_count' => $rule->matched_count + 1,
                'last_matched_at' => now(),
            ])->saveQuietly();
        }
    }

    /**
     * @return Collection<int, CrmMailRule>
     */
    private function activeRules(): Collection
    {
        return CrmMailRule::query()->active()->orderBy('id')->get();
    }
}
