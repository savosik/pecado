<?php

namespace App\Console\Commands\Crm;

use App\Enums\Crm\EmailStatus;
use App\Models\CrmEmail;
use Illuminate\Console\Command;

/**
 * Уборка папки «Мимо фильтров».
 *
 * Система собирает письма по всем поводам, а правила ловят не всё. Без уборки
 * непойманное копилось бы вечно — ровно та история, из-за которой боевая база
 * однажды на 88% состояла из журналов мониторинга.
 */
class PruneUnmatchedMail extends Command
{
    protected $signature = 'mail:prune-unmatched {--days= : Сколько дней хранить} {--dry-run : Только показать, сколько удалилось бы}';

    protected $description = 'Удалить письма, прошедшие мимо фильтров и не пригодившиеся';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?: config('mail_stream.unmatched_retention_days', 14));
        $cutoff = now()->subDays($days);

        $query = CrmEmail::query()
            ->where('status', EmailStatus::UNMATCHED->value)
            ->where('created_at', '<', $cutoff);

        $count = (clone $query)->count();

        if ($this->option('dry-run')) {
            $this->info("Удалилось бы писем: {$count} (старше {$days} дн.)");

            return self::SUCCESS;
        }

        $query->delete();

        $this->info("Удалено писем мимо фильтров: {$count} (старше {$days} дн.)");

        return self::SUCCESS;
    }
}
