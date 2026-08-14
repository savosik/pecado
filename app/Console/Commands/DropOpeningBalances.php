<?php

namespace App\Console\Commands;

use App\Models\SettlementEntry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Разовая чистка: удалить строки `opening_balance` из регистра взаиморасчётов.
 *
 * Начальное сальдо дублирует ленту — 1С отдаёт историю целиком, с 2025 года
 * (v16.3.0, 14.08.2026). Пока строки лежат в регистре, баланс задвоен: на проде
 * это было 13,7 млн ₽ и 100 разошедшихся контрольных точек из 148.
 *
 * Команда идемпотентна: второй запуск не найдёт что удалять и завершится успехом.
 * Обработчик события с той же версии сальдо не создаёт, поэтому строки не вернутся
 * даже при повторной досылке от 1С.
 */
class DropOpeningBalances extends Command
{
    protected $signature = 'settlements:drop-opening-balances
        {--dry-run : Показать, что будет удалено, и выйти}
        {--force : Не спрашивать подтверждения (для CI и деплоя)}';

    protected $description = 'Удалить дублирующие строки начального сальдо из регистра взаиморасчётов';

    public function handle(): int
    {
        $query = SettlementEntry::query()->where('type', SettlementEntry::TYPE_OPENING_BALANCE);

        $count = (clone $query)->count();
        $total = (float) (clone $query)->sum('amount');

        if ($count === 0) {
            $this->info('Строк начального сальдо нет — чистить нечего.');

            return self::SUCCESS;
        }

        $this->warn(sprintf(
            'Найдено строк: %d, суммарно %s ₽.',
            $count,
            number_format($total, 2, ',', ' '),
        ));

        if ($this->option('dry-run')) {
            $this->line('Пробный запуск: ничего не удалено.');

            return self::SUCCESS;
        }

        // Бэкап до удаления, а не после: если что-то пойдёт не так, восстановить
        // строки будет неоткуда — событие 1С больше не создаёт их заново.
        $path = sprintf('opening-balance-backup-%s.json', now()->format('Y-m-d-His'));

        Storage::disk('local')->put(
            $path,
            (string) json_encode((clone $query)->get()->toArray(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        );

        $this->info('Бэкап: '.Storage::disk('local')->path($path));

        if (! $this->option('force') && ! $this->confirm('Удалить эти строки?', false)) {
            $this->line('Отменено.');

            return self::SUCCESS;
        }

        $deleted = $query->delete();

        $this->info(sprintf('Удалено строк: %d.', $deleted));
        $this->line('Проверьте результат: php artisan settlements:verify');

        return self::SUCCESS;
    }
}
