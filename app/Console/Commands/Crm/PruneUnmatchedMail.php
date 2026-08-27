<?php

namespace App\Console\Commands\Crm;

use App\Enums\Crm\EmailStatus;
use App\Models\CrmEmail;
use Illuminate\Console\Command;

/**
 * Уборка папки «Без получателя».
 *
 * Система собирает письма по всем поводам, а правила ловят не всё. Без уборки
 * непойманное копилось бы вечно — ровно та история, из-за которой боевая база
 * однажды на 88% состояла из журналов мониторинга.
 */
class PruneUnmatchedMail extends Command
{
    protected $signature = 'mail:prune-unmatched {--days= : Сколько дней хранить} {--dry-run : Только показать, сколько удалилось бы}';

    protected $description = 'Удалить письма, оставшиеся без получателя и не пригодившиеся';

    public function handle(): int
    {
        // Не `?:` — ноль в PHP пустой, и «--days=0» молча превращался
        // в умолчание: команда отвечала «удалено 0» и выглядела отработавшей.
        $option = $this->option('days');
        $days = $option === null || $option === ''
            ? (int) config('mail_stream.unmatched_retention_days', 14)
            : max(0, (int) $option);
        $query = CrmEmail::query()
            ->where('status', EmailStatus::UNMATCHED->value);

        // Ноль дней — «убрать всё», включая созданное только что. Через
        // `created_at < now` это не работает: даты хранятся с точностью
        // до секунды, и письмо из текущей секунды пережило бы уборку.
        if ($days > 0) {
            $query->where('created_at', '<', now()->subDays($days));
        }

        $count = (clone $query)->count();

        if ($this->option('dry-run')) {
            $this->info($days > 0
                ? "Удалилось бы писем: {$count} (старше {$days} дн.)"
                : "Удалилось бы писем: {$count} (все)");

            return self::SUCCESS;
        }

        $query->delete();

        $this->info($days > 0
            ? "Удалено писем без получателя: {$count} (старше {$days} дн.)"
            : "Удалено писем без получателя: {$count} (все)");

        return self::SUCCESS;
    }
}
