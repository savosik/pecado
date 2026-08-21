<?php

namespace App\Services\Crm\Mail;

use App\Models\CrmEmail;
use App\Models\CrmMailRule;

/**
 * Подходит ли письмо под условия правила.
 *
 * Правило фильтрует **письма**, а не события: менеджер думает «письмо про акт
 * сверки такого-то ИНН», а не «событие documents.published с полем document_type».
 * Поэтому на вход идёт готовое письмо со своими метками и текстом.
 *
 * Само сравнение переиспользует движок условий пульта — он умеет ровно то,
 * что нужно, и проверен тестами.
 */
class LetterMatcher
{
    public function __construct(private readonly ConditionEvaluator $evaluator) {}

    public function matches(CrmMailRule $rule, CrmEmail $letter): bool
    {
        return $this->evaluator->matches(
            $rule->conditions,
            $this->data($letter),
            $letter->tagList(),
        );
    }

    /**
     * Поля письма, доступные условиям.
     *
     * Данные клиента лежат отдельными полями, а не склеены в общую строку:
     * иначе условие «Ромашка» поймало бы и клиента «Ромашка», и того, у кого
     * в заметке «раньше работал в Ромашке», — и объяснить срабатывание
     * было бы нечем.
     *
     * @return array<string, mixed>
     */
    public function data(CrmEmail $letter): array
    {
        $body = trim(strip_tags((string) $letter->body_html));

        $base = [
            'subject' => (string) $letter->subject,
            'body' => $body,
            'text' => trim($letter->subject.' '.$body),
            'to' => $letter->to ?? [],
            'origin' => $letter->origin,
            'event' => $letter->origin_event,
            'tags_text' => implode(' ', $letter->tagList()),
        ];

        return array_merge((array) ($letter->origin_data ?? []), $this->clientData($letter), $base);
    }

    /**
     * Данные клиента для писем, у которых их не сохранили при создании
     * (письма менеджеров).
     *
     * @return array<string, mixed>
     */
    private function clientData(CrmEmail $letter): array
    {
        if (filled($letter->origin_data['client_name'] ?? null)) {
            return [];
        }

        $client = $letter->relationLoaded('client') ? $letter->client : $letter->client()->first();

        if ($client === null) {
            return [];
        }

        $profile = $client->relationLoaded('crmProfile') ? $client->crmProfile : $client->crmProfile()->first();

        return array_filter([
            'client_user_id' => $client->getKey(),
            'client_name' => (string) $client->display_name,
            'client_city' => $client->city,
            'client_status' => $profile?->lifecycle_status?->label(),
            'client_business' => $profile?->business_type?->label(),
            'client_notes' => $profile?->notes_md,
        ], fn ($value): bool => $value !== null);
    }
}
