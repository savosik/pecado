<?php

namespace App\Notifications\Pulse\Events;

use App\Notifications\Pulse\Contracts\NotificationEventContract;
use App\Notifications\Pulse\Support\FieldSpec;

/**
 * Общая часть описания события: домен из ключа, метки, шаблон по умолчанию.
 *
 * Событию остаётся объявить key(), label() и fields() — остальное берётся
 * отсюда. Чем меньше обязательных методов, тем меньше поводов при добавлении
 * события лезть в движок.
 */
abstract class AbstractNotificationEvent implements NotificationEventContract
{
    public function domain(): string
    {
        return explode('.', $this->key())[0];
    }

    public function group(): string
    {
        return (string) config('notification_pulse.domains.'.$this->domain().'.label', $this->domain());
    }

    public function description(): string
    {
        return '';
    }

    public function defaultTemplate(): string
    {
        return 'mail.pulse.default';
    }

    public function defaultSubject(): string
    {
        return $this->label().' — Pecado.ru';
    }

    /**
     * Метки события: общие для всех плюс собственные.
     *
     * Общие отвечают на «про кого», собственные — на «про что». Правило
     * «содержит инн:7701234567» без второго условия ловит вообще всё
     * по этому контрагенту, включая события, добавленные позже.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, string>
     */
    public function tags(array $data): array
    {
        return array_values(array_unique(array_merge(
            $this->commonTags($data),
            $this->ownTags($data),
        )));
    }

    /**
     * Метки конкретного события — переопределяется потомком.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, string>
     */
    protected function ownTags(array $data): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, string>
     */
    protected function commonTags(array $data): array
    {
        $tags = [
            'раздел:'.$this->domain(),
            'событие:'.$this->key(),
        ];

        foreach ([
            'партнёр' => 'client_user_id',
            'контрагент' => 'company_id',
            'менеджер' => 'manager_id',
            'инн' => 'company_tax_id',
        ] as $prefix => $field) {
            if (filled($data[$field] ?? null)) {
                $tags[] = $prefix.':'.$data[$field];
            }
        }

        return $tags;
    }

    /**
     * Поля, доступные условиям у любого события.
     *
     * Их же видит правило с маской 'orders.*' или '*', где полей конкретного
     * события ещё не известно.
     *
     * @return array<string, FieldSpec>
     */
    public static function commonFields(): array
    {
        return [
            'client_user_id' => new FieldSpec('client_user_id', 'Партнёр', FieldSpec::TYPE_NUMBER),
            'company_id' => new FieldSpec('company_id', 'Контрагент', FieldSpec::TYPE_NUMBER),
            'company_tax_id' => new FieldSpec('company_tax_id', 'ИНН контрагента', FieldSpec::TYPE_STRING),
            'manager_id' => new FieldSpec('manager_id', 'Персональный менеджер', FieldSpec::TYPE_NUMBER),
            'client_status' => new FieldSpec('client_status', 'Стадия работы с клиентом', FieldSpec::TYPE_STRING),
            'weekday' => new FieldSpec('weekday', 'День недели', FieldSpec::TYPE_NUMBER, hint: '1 — понедельник, 7 — воскресенье'),
            'hour' => new FieldSpec('hour', 'Час события', FieldSpec::TYPE_NUMBER, hint: '0–23 по времени сервера'),
        ];
    }
}
