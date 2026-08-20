<?php

namespace App\Console\Commands\Notifications;

use App\Models\EntitySubscription;
use App\Models\NotificationRule;
use App\Models\NotificationRuleRecipient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Перенос подписок кабинета в правила пульта.
 *
 * `entity_subscriptions` — работающий механизм, но это вторая модель
 * маршрутизации рядом с пультом. Держать обе параллельно нельзя: это ровно
 * та «маршрутизация в двух местах», от которой уходит эпик.
 *
 * Токен отписки переносится один в один: ссылки из уже разосланных писем
 * должны продолжать работать и после удаления старой таблицы.
 */
class ImportEntitySubscriptions extends Command
{
    protected $signature = 'notifications:import-subscriptions {--dry-run : Только показать, что будет перенесено}';

    protected $description = 'Перенести подписки личного кабинета в правила пульта уведомлений';

    private const PRESET_KEY = 'imported.entity_subscription';

    /**
     * Раздел кабинета → домен событий пульта.
     */
    private const SECTION_MAP = [
        'orders' => 'orders',
        'documents' => 'documents',
    ];

    /**
     * Тип события подписки → ключ события пульта.
     */
    private const EVENT_MAP = [
        'items_updated' => 'orders.items_updated',
        'attributes_updated' => 'orders.attributes_updated',
        'api_shortfall' => 'orders.shortfall',
        'substitution_offered' => 'orders.substitution_offered',
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $created = 0;
        $skipped = 0;

        $subscriptions = EntitySubscription::query()->where('channel', 'email')->get();

        if ($subscriptions->isEmpty()) {
            $this->info('Подписок для переноса нет.');

            return self::SUCCESS;
        }

        foreach ($subscriptions as $subscription) {
            $domain = self::SECTION_MAP[$subscription->section] ?? null;

            if ($domain === null) {
                $this->warn("Раздел «{$subscription->section}» не сопоставлен домену — пропущено.");
                $skipped++;

                continue;
            }

            // Токен один на подписку, а правил из неё может выйти несколько
            // (подписка на два типа событий). Отдаём его первому правилу:
            // отписка по нему гасит всю группу, см. unsubscribeFromRule().
            $tokenTaken = false;

            foreach ($this->eventKeysFor($subscription, $domain) as $eventKey) {
                $result = $this->importOne($subscription, $eventKey, $dryRun, ! $tokenTaken);

                if ($result) {
                    $created++;
                    $tokenTaken = true;
                } else {
                    $skipped++;
                }
            }
        }

        $this->info($dryRun
            ? "Будет создано правил: {$created}, пропущено: {$skipped}."
            : "Создано правил: {$created}, пропущено (уже были): {$skipped}.");

        return self::SUCCESS;
    }

    /**
     * Какие события покрывает подписка.
     *
     * Пустой `events` означал «все типы, включая будущие» — ровно то, что
     * выражает маска домена. Одно правило вместо перечисления, и новые
     * события раздела оно подхватит само.
     *
     * @return array<int, string>
     */
    private function eventKeysFor(EntitySubscription $subscription, string $domain): array
    {
        if (blank($subscription->events)) {
            return [$domain.'.*'];
        }

        $keys = [];

        foreach ($subscription->events as $event) {
            $mapped = self::EVENT_MAP[$event] ?? null;

            if ($mapped !== null) {
                $keys[] = $mapped;
            }
        }

        return $keys === [] ? [$domain.'.*'] : $keys;
    }

    private function importOne(EntitySubscription $subscription, string $eventKey, bool $dryRun, bool $withToken): bool
    {
        $exists = NotificationRule::query()
            ->where('preset_key', self::PRESET_KEY)
            ->where('scope_user_id', $subscription->user_id)
            ->where('event_key', $eventKey)
            ->whereHas('recipients', fn ($q) => $q->where('value', $subscription->destination))
            ->exists();

        if ($exists) {
            return false;
        }

        if ($dryRun) {
            $this->line("  {$subscription->destination} ← {$eventKey} (партнёр #{$subscription->user_id})");

            return true;
        }

        DB::transaction(function () use ($subscription, $eventKey, $withToken): void {
            $rule = NotificationRule::create([
                'name' => 'Подписка кабинета: '.$subscription->destination,
                'description' => 'Перенесено из подписок личного кабинета. Клиент завёл её сам — менеджер может выключить, но не удалить.',
                'event_key' => $eventKey,
                'scope_type' => NotificationRule::SCOPE_USER,
                'scope_user_id' => $subscription->user_id,
                'priority' => 200,
                'is_active' => $subscription->is_active,
                'preset_key' => self::PRESET_KEY,
                'channel' => 'email',
                'digest' => 'none',
            ]);

            $rule->recipients()->create([
                'kind' => NotificationRuleRecipient::KIND_EMAIL,
                'value' => $subscription->destination,
                'copy_type' => 'to',
                // Токен один в один: ссылка отписки из уже отправленного письма
                // обязана работать и после удаления старой таблицы. Достаётся
                // первому правилу группы — на колонке уникальный индекс.
                'unsubscribe_token' => $withToken ? $subscription->unsubscribe_token : null,
            ]);
        });

        return true;
    }
}
