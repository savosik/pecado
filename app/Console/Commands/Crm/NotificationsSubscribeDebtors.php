<?php

namespace App\Console\Commands\Crm;

use App\Models\Contact;
use App\Models\NotificationPreference;
use App\Models\SettlementEntry;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Подписать должников на понедельничную сводку актов сверки.
 *
 * Массовая операция: включать поштучно 58 партнёров руками — работа на день,
 * а список должников меняется, и команду захочется повторить.
 *
 * Адресат выбирается по справочнику контактов: если у партнёра есть бухгалтер,
 * акты идут ему, иначе — на почту аккаунта. Ровно ради этого справочник
 * и заводился.
 */
class NotificationsSubscribeDebtors extends Command
{
    protected $signature = 'notifications:subscribe-debtors
        {--occasion=documents.reconciliation_weekly : Ключ уведомления}
        {--dry-run : Только показать, кого затронет}';

    protected $description = 'Подписать партнёров с непогашенным долгом на уведомление';

    public function handle(): int
    {
        $key = (string) $this->option('occasion');
        $dry = (bool) $this->option('dry-run');

        if (! array_key_exists($key, (array) config('mail_occasions', []))) {
            $this->error("Неизвестное уведомление: {$key}");

            return self::FAILURE;
        }

        $ids = $this->debtorIds();
        $stats = ['created' => 0, 'accountant' => 0, 'login' => 0, 'skipped' => 0, 'no_email' => 0];

        foreach ($ids as $id) {
            $partner = User::query()->find($id);

            if ($partner === null) {
                continue;
            }

            // Настроенное руками не трогаем: чьё-то решение важнее массовой правки.
            $configured = NotificationPreference::query()
                ->where('user_id', $id)
                ->where('occasion_key', $key)
                ->exists();

            if ($configured) {
                $stats['skipped']++;

                continue;
            }

            $destinations = $this->destinationsFor($partner, $stats);

            if ($destinations === []) {
                $stats['no_email']++;

                continue;
            }

            if (! $dry) {
                NotificationPreference::query()->create([
                    'user_id' => $id,
                    'occasion_key' => $key,
                    'is_enabled' => true,
                    'destinations' => $destinations,
                ]);
            }

            $stats['created']++;
        }

        $this->info($dry ? 'Сухой прогон — ничего не записано:' : 'Подписка выполнена:');
        $this->line('  должников найдено:     '.$ids->count());
        $this->line('  подписано:             '.$stats['created']);
        $this->line('    бухгалтеру:          '.$stats['accountant']);
        $this->line('    на почту аккаунта:   '.$stats['login']);
        $this->line('  пропущено (настроено): '.$stats['skipped']);
        $this->line('  без почты вовсе:       '.$stats['no_email']);

        return self::SUCCESS;
    }

    /**
     * Партнёры с непогашенным долгом.
     *
     * Планы по заказам исключены: заказ — это план, а не долг, долг создаёт
     * отгрузка. Иначе просроченный план заказа числился бы долгом навсегда.
     *
     * @return \Illuminate\Support\Collection<int, int>
     */
    private function debtorIds(): \Illuminate\Support\Collection
    {
        return SettlementEntry::query()
            ->outstanding()
            ->where(static function (Builder $query): void {
                $query->whereNull('document_kind')->orWhere('document_kind', '<>', 'order');
            })
            ->whereNotNull('user_id')
            ->distinct()
            ->pluck('user_id')
            ->map(fn ($id): int => (int) $id);
    }

    /**
     * @param  array<string, int>  $stats
     * @return list<array<string, mixed>>
     */
    private function destinationsFor(User $partner, array &$stats): array
    {
        $accountants = Contact::query()
            ->deliverable()
            ->where('client_user_id', $partner->getKey())
            ->whereHas('links', fn ($links) => $links->where('role', 'accountant'))
            ->pluck('id');

        if ($accountants->isNotEmpty()) {
            $stats['accountant']++;

            return $accountants
                ->map(fn ($id): array => ['type' => 'contact', 'contact_id' => (int) $id])
                ->all();
        }

        if (blank($partner->email)) {
            return [];
        }

        $stats['login']++;

        return [['type' => 'login']];
    }
}
