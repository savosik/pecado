<?php

namespace Database\Seeders;

use App\Enums\ClientContactRole;
use App\Models\ClientContact;
use App\Models\Company;
use App\Models\NotificationRule;
use App\Models\NotificationRuleRecipient;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Демонстрационные правила пульта — кейс заказчика на живых данных dev.
 *
 * Берёт первого попавшегося партнёра с юрлицом, заводит ему три контакта
 * и три правила из постановки: недобор — закупщикам, закрытие заказа —
 * директору вместо клиента, прочие статусы — клиенту.
 *
 * Только для dev: на прод такие правила не поедут — там их заводит менеджер
 * под конкретного клиента. Запуск идемпотентен, повторный ничего не дублирует.
 */
class NotificationPulseDemoSeeder extends Seeder
{
    private const PRESET_KEY = 'demo.customer_case';

    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->warn('Демо-правила на проде не заводятся.');

            return;
        }

        $company = $this->pickCompany();

        if ($company === null) {
            $this->command?->warn('Не нашлось партнёра с юрлицом — демо-правила не заведены.');

            return;
        }

        $partner = $company->user;

        DB::transaction(function () use ($company, $partner): void {
            $contacts = $this->ensureContacts($partner, $company);

            $this->ensureRule(
                name: 'Недобор по заказу — закупщикам',
                description: 'Кейс заказчика: если в заказе недосталось позиций, письмо уходит закупщикам контрагента, а не одному менеджеру в мессенджер.',
                eventKey: 'orders.shortfall',
                company: $company,
                priority: 100,
                stop: false,
                conditions: null,
                recipients: [
                    ['kind' => NotificationRuleRecipient::KIND_CONTACT_ROLE, 'value' => ClientContactRole::BUYER->value],
                ],
            );

            $this->ensureRule(
                name: 'Заказ закрыт — директору',
                description: 'Кейс заказчика: закрытие заказа уходит директору ВМЕСТО клиента. Отметка «не обрабатывать дальше» отрезает правило ниже.',
                eventKey: 'orders.status_changed',
                company: $company,
                priority: 50,
                stop: true,
                conditions: ['field' => 'status', 'op' => 'in', 'value' => ['closed']],
                recipients: [
                    ['kind' => NotificationRuleRecipient::KIND_CONTACT, 'contact_id' => $contacts['director']->id],
                ],
            );

            $this->ensureRule(
                name: 'Акты сверки — бухгалтеру',
                description: 'Второй кейс заказчика: акты сверки уходят бухгалтеру контрагента, а не на общий адрес.',
                eventKey: 'documents.published',
                company: $company,
                priority: 100,
                stop: false,
                conditions: ['field' => 'document_type', 'op' => '=', 'value' => 'reconciliation_act'],
                recipients: [
                    ['kind' => NotificationRuleRecipient::KIND_CONTACT_ROLE, 'value' => ClientContactRole::ACCOUNTANT->value],
                ],
            );

            $this->ensureRule(
                name: 'Реализации и накладные — логисту',
                description: 'Тот же кейс, вторая половина: УПД и товарные накладные идут на другой адрес.',
                eventKey: 'documents.published',
                company: $company,
                priority: 110,
                stop: false,
                conditions: ['field' => 'document_type', 'op' => 'in', 'value' => ['upd', 'waybill']],
                recipients: [
                    ['kind' => NotificationRuleRecipient::KIND_CONTACT_ROLE, 'value' => ClientContactRole::LOGIST->value],
                ],
            );

            $this->ensureRule(
                name: 'Просрочка от 30 дней — бухгалтеру и директору',
                description: 'Третий кейс: порог задан условием правила, а не кодом. У другого клиента он может быть иным.',
                eventKey: 'finance.*',
                company: $company,
                priority: 100,
                stop: false,
                conditions: ['field' => 'days_overdue', 'op' => '>=', 'value' => 30],
                recipients: [
                    ['kind' => NotificationRuleRecipient::KIND_CONTACT_ROLE, 'value' => ClientContactRole::ACCOUNTANT->value],
                    ['kind' => NotificationRuleRecipient::KIND_CONTACT, 'contact_id' => $contacts['director']->id],
                ],
            );

            $this->ensureRule(
                name: 'Смена статуса — клиенту',
                description: 'Кейс заказчика: обычная смена статуса уходит на почту самого клиента. Закрытие сюда не доходит — его перехватывает правило с приоритетом 50.',
                eventKey: 'orders.status_changed',
                company: $company,
                priority: 100,
                stop: false,
                conditions: null,
                recipients: [
                    ['kind' => NotificationRuleRecipient::KIND_CLIENT_USER],
                ],
            );
        });

        $this->command?->info("Демо-правила заведены для контрагента «{$company->name}» (партнёр #{$partner->id}).");
        $this->command?->line('Смотреть: /crm/notifications/rules — вкладка «Исключения».');
    }

    private function pickCompany(): ?Company
    {
        return Company::query()
            ->withoutGlobalScopes()
            ->whereNotNull('user_id')
            ->whereHas('user')
            ->with('user')
            ->orderBy('id')
            ->first();
    }

    /**
     * @return array<string, ClientContact>
     */
    private function ensureContacts(User $partner, Company $company): array
    {
        $definitions = [
            'director' => ['Залупкин Виктор Петрович', ClientContactRole::DIRECTOR, 'demo-director@example.test', 'Генеральный директор'],
            'buyer_one' => ['Жопкин Анатолий Сергеевич', ClientContactRole::BUYER, 'demo-buyer1@example.test', 'Менеджер по закупкам'],
            'buyer_two' => ['Петров Иван Иванович', ClientContactRole::BUYER, 'demo-buyer2@example.test', 'Старший закупщик'],
            'accountant' => ['Сидорова Анна Львовна', ClientContactRole::ACCOUNTANT, 'demo-buh@example.test', 'Главный бухгалтер'],
            'logist' => ['Кузнецов Олег Дмитриевич', ClientContactRole::LOGIST, 'demo-logist@example.test', 'Начальник склада'],
        ];

        $contacts = [];

        foreach ($definitions as $key => [$name, $role, $email, $position]) {
            $contacts[$key] = ClientContact::firstOrCreate(
                ['user_id' => $partner->id, 'email' => $email],
                [
                    'company_id' => $company->id,
                    'full_name' => $name,
                    'role' => $role,
                    'position' => $position,
                    'is_active' => true,
                    'source' => ClientContact::SOURCE_MANUAL,
                    'notes' => 'Демонстрационный контакт пульта уведомлений',
                ],
            );
        }

        return $contacts;
    }

    /**
     * @param  array<int, array<string, mixed>>  $recipients
     */
    private function ensureRule(
        string $name,
        string $description,
        string $eventKey,
        Company $company,
        int $priority,
        bool $stop,
        ?array $conditions,
        array $recipients,
    ): void {
        $existing = NotificationRule::query()
            ->where('preset_key', self::PRESET_KEY)
            ->where('scope_company_id', $company->id)
            ->where('event_key', $eventKey)
            ->where('priority', $priority)
            ->first();

        if ($existing !== null) {
            return;
        }

        $rule = NotificationRule::create([
            'name' => $name,
            'description' => $description,
            'event_key' => $eventKey,
            'scope_type' => NotificationRule::SCOPE_COMPANY,
            'scope_company_id' => $company->id,
            'conditions' => $conditions,
            'priority' => $priority,
            'stop_processing' => $stop,
            'is_active' => true,
            'preset_key' => self::PRESET_KEY,
            'channel' => 'email',
            'digest' => 'none',
        ]);

        foreach ($recipients as $recipient) {
            $rule->recipients()->create($recipient + ['copy_type' => 'to']);
        }
    }
}
