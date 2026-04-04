<?php

namespace App\Console\Commands;

use App\Jobs\NormalizeCompanyDataJob;
use App\Jobs\NormalizeUserDataJob;
use App\Models\Company;
use App\Models\User;
use Illuminate\Console\Command;

class NormalizePartnerData extends Command
{
    protected $signature = 'normalize:partners 
                            {--dry-run : Показать изменения без применения}
                            {--limit=0 : Ограничить количество записей (0 = все)}
                            {--users : Нормализовать только пользователей}
                            {--companies : Нормализовать только компании}
                            {--sync : Выполнить синхронно (не через очередь)}';

    protected $description = 'Нормализация данных партнёров через AI (OpenRouter)';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $limit = (int) $this->option('limit');
        $onlyUsers = $this->option('users');
        $onlyCompanies = $this->option('companies');
        $sync = $this->option('sync');

        if ($dryRun) {
            $this->info('🔍 Режим DRY-RUN — изменения НЕ будут применены');
            $this->info('   Результаты будут записаны в storage/logs/laravel.log');
        }

        $processUsers = ! $onlyCompanies;
        $processCompanies = ! $onlyUsers;

        if ($processUsers) {
            $this->normalizeUsers($dryRun, $limit, $sync);
        }

        if ($processCompanies) {
            $this->normalizeCompanies($dryRun, $limit, $sync);
        }

        $this->info('✅ Готово!');

        return Command::SUCCESS;
    }

    private function normalizeUsers(bool $dryRun, int $limit, bool $sync): void
    {
        $query = User::doesntHave('roles');

        if ($limit > 0) {
            $query->limit($limit);
        }

        $count = $limit > 0 ? min($limit, $query->count()) : $query->count();
        $this->info("👤 Нормализация пользователей: {$count} записей");

        $bar = $this->output->createProgressBar($count);
        $bar->start();

        $query->chunkById(50, function ($users) use ($dryRun, $sync, $bar) {
            foreach ($users as $user) {
                $job = new NormalizeUserDataJob($user->id, $dryRun);

                if ($sync) {
                    $job->handle(app(\App\Services\DataNormalizerService::class));
                } else {
                    dispatch($job);
                }

                $bar->advance();

                // Задержка при синхронном режиме для rate limiting
                if ($sync) {
                    usleep(300_000); // 300ms
                }
            }
        });

        $bar->finish();
        $this->newLine();
    }

    private function normalizeCompanies(bool $dryRun, int $limit, bool $sync): void
    {
        $query = Company::withoutGlobalScopes();

        if ($limit > 0) {
            $query->limit($limit);
        }

        $count = $limit > 0 ? min($limit, $query->count()) : $query->count();
        $this->info("🏢 Нормализация компаний: {$count} записей");

        $bar = $this->output->createProgressBar($count);
        $bar->start();

        $query->chunkById(50, function ($companies) use ($dryRun, $sync, $bar) {
            foreach ($companies as $company) {
                $job = new NormalizeCompanyDataJob($company->id, $dryRun);

                if ($sync) {
                    $job->handle(app(\App\Services\DataNormalizerService::class));
                } else {
                    dispatch($job);
                }

                $bar->advance();

                if ($sync) {
                    usleep(300_000);
                }
            }
        });

        $bar->finish();
        $this->newLine();
    }
}
