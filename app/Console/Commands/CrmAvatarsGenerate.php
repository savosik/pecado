<?php

namespace App\Console\Commands;

use App\Jobs\GenerateClientAvatar;
use App\Models\CrmClientAvatar;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Пачка аватарок: раздать джобы тем партнёрам, у кого картинки ещё нет.
 *
 * Команда ничего не рисует сама — только ставит задания. Генерация платная,
 * поэтому пачка ограничена (`crm_avatars.generation.batch_limit`), а порядок
 * простой: сначала те, кем занимаются, — партнёры с менеджером и свежими
 * отгрузками. Аватарка нужна там, где список листают каждый день.
 */
class CrmAvatarsGenerate extends Command
{
    protected $signature = 'crm:avatars-generate
        {--limit= : Сколько партнёров взять за прогон (по умолчанию из конфига)}
        {--client= : Только этот партнёр (users.id)}
        {--force : Перерисовать даже тем, у кого аватарка уже есть (ручные не трогает)}
        {--dry-run : Показать, кому поставили бы задание, ничего не ставя}';

    protected $description = 'Поставить в очередь генерацию аватарок партнёров, у которых их нет';

    public function handle(): int
    {
        if (! config('crm_avatars.enabled') || ! config('crm_avatars.generation.enabled')) {
            $this->warn('Генерация аватарок выключена в config/crm_avatars.php.');

            return self::SUCCESS;
        }

        $force = (bool) $this->option('force');
        $dryRun = (bool) $this->option('dry-run');
        $limit = (int) ($this->option('limit') ?: config('crm_avatars.generation.batch_limit', 25));

        $clients = $this->targets($force, max(1, $limit));

        if ($clients->isEmpty()) {
            $this->info('Партнёров без аватарки не нашлось.');

            return self::SUCCESS;
        }

        foreach ($clients as $client) {
            $this->line("  {$client->display_name} (#{$client->id})");

            if (! $dryRun) {
                GenerateClientAvatar::dispatch((int) $client->getKey(), $force);
            }
        }

        $this->info('Заданий поставлено: '.$clients->count().($dryRun ? ' (сухой прогон)' : ''));

        return self::SUCCESS;
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, User>
     */
    private function targets(bool $force, int $limit)
    {
        $query = User::query()
            ->clients()
            ->whereNotNull('personal_manager_id')
            ->select('users.id', 'users.name', 'users.erp_name', 'users.email');

        if (! $force) {
            // Нужны те, у кого файла нет и попытки не исчерпаны. Партнёр без
            // строки в таблице тоже подходит — ему просто ещё не рисовали.
            $maxAttempts = (int) config('crm_avatars.generation.max_attempts', 3);
            $cooldown = (int) config('crm_avatars.generation.failure_cooldown_hours', 24);

            $query->whereNotExists(function ($sub) use ($maxAttempts, $cooldown) {
                $sub->selectRaw('1')
                    ->from('crm_client_avatars')
                    ->whereColumn('crm_client_avatars.user_id', 'users.id')
                    ->where(function (\Illuminate\Database\Query\Builder $q) use ($maxAttempts, $cooldown) {
                        $q->whereNotNull('path')
                            ->orWhere('attempts', '>=', $maxAttempts)
                            ->orWhere('failed_at', '>', now()->subHours($cooldown));
                    });
            });
        } else {
            // Принудительная перерисовка не трогает ручные: их выбирал человек.
            $query->whereNotExists(function ($sub) {
                $sub->selectRaw('1')
                    ->from('crm_client_avatars')
                    ->whereColumn('crm_client_avatars.user_id', 'users.id')
                    ->where('source', CrmClientAvatar::SOURCE_MANUAL);
            });
        }

        if ($client = $this->option('client')) {
            $query->where('users.id', (int) $client);
        }

        return $query
            ->orderByDesc(
                // Кем занимаются сейчас — тем аватарка нужнее: список с этими
                // партнёрами менеджер открывает каждый день.
                \App\Models\Shipment::query()
                    ->selectRaw('MAX(erp_created_at)')
                    ->whereColumn('shipments.user_id', 'users.id')
                    ->whereNull('shipments.deleted_at')
                    ->toBase()
            )
            ->limit($limit)
            ->get();
    }
}
