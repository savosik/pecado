<?php

namespace App\Console\Commands\Crm;

use App\Models\User;
use App\Services\Contacts\ContactSeeder;
use Illuminate\Console\Command;

/**
 * Собрать кандидатов в справочник из данных, которые в базе уже есть.
 *
 * Ничего не создаёт без явного согласия: `--dry-run` печатает сводку,
 * `--accept-all` заводит карточки по всем найденным адресам. Второе — для
 * разового прогона под присмотром, обычный путь идёт через экран в CRM.
 */
class ContactsSeedFromCorpus extends Command
{
    protected $signature = 'contacts:seed-from-corpus
        {--client= : Только по одному партнёру (users.id)}
        {--accept-all : Завести карточки по всем кандидатам, а не только показать}
        {--skip-impersonal : Пропустить общие ящики (info@, noreply@)}
        {--dry-run : Только сводка}';

    protected $description = 'Найти кандидатов в справочник контактов среди контрагентов, подписок и писем';

    public function handle(ContactSeeder $seeder): int
    {
        $clientId = $this->option('client') === null ? null : (int) $this->option('client');

        $summary = $seeder->summary($clientId);

        if ($summary === []) {
            $this->info('Кандидатов нет: всё, что есть в базе, уже в справочнике.');

            return self::SUCCESS;
        }

        $this->info('Кандидаты по источникам:');

        foreach ($summary as $source => $count) {
            $this->line(sprintf('  %-24s %d', $source, $count));
        }

        if ($this->option('dry-run') || ! $this->option('accept-all')) {
            $this->newLine();
            $this->line('Ничего не создано. Подтвердить кандидатов можно в разделе «Контакты»');
            $this->line('или прогоном с --accept-all.');

            return self::SUCCESS;
        }

        $actor = User::query()->role('sales-head')->first() ?? User::query()->first();

        if ($actor === null) {
            $this->error('Некому приписать создание карточек.');

            return self::FAILURE;
        }

        $candidates = $seeder->candidates($clientId, 100000);

        if ($this->option('skip-impersonal')) {
            $candidates = $candidates->reject(fn (array $row): bool => $row['impersonal']);
        }

        $created = $seeder->accept($candidates->pluck('email')->all(), $actor);

        $this->newLine();
        $this->info("Заведено карточек: {$created}");

        return self::SUCCESS;
    }
}
