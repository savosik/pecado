<?php

namespace App\Console\Commands;

use App\Contracts\Stock\StockServiceInterface;
use App\Models\Product;
use App\Notifications\Substitutions\PurchasingShortageReportNotification;
use App\Services\Substitution\ShortageAnalyticsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

/**
 * Еженедельный отчёт закупкам: топ повторных недоборов с остатками.
 *
 * Получатели — MAIL_PURCHASING_RECIPIENTS (по данным, не по ролям: пусто —
 * письмо не отправляется, и это видно в выводе команды, а не молча).
 */
class SubstitutionsPurchasingReport extends Command
{
    protected $signature = 'substitutions:purchasing-report
        {--days=90 : Окно повторных недоборов, дней}
        {--min-shortages=2 : Порог отмен для попадания в отчёт}
        {--dry-run : Показать отчёт в консоли, ничего не отправляя}';

    protected $description = 'Отправить закупкам отчёт о повторных недоборах за окно';

    public function handle(ShortageAnalyticsService $analytics, StockServiceInterface $stock): int
    {
        if (! config('substitutions.enabled') && ! $this->option('dry-run')) {
            $this->warn('Контур замен выключен (SHORTAGE_OFFERS_ENABLED=false) — отчёт не отправляется.');

            return self::SUCCESS;
        }

        $windowDays = (int) $this->option('days');
        $repeated = $analytics->repeatedShortages($windowDays, (int) $this->option('min-shortages'));

        if ($repeated === []) {
            $this->info('Повторных недоборов за окно нет — отчёт не нужен.');

            return self::SUCCESS;
        }

        $rows = array_map(function (object $row) use ($stock) {
            $product = $row->product_id !== null
                ? Product::withoutGlobalScopes()->find($row->product_id)
                : null;

            return [
                'name' => $row->name,
                'shortages' => $row->shortages,
                'lost_amount' => $row->lost_amount,
                'stock' => $product !== null ? $stock->getAvailableStock($product) : 0,
                'incoming' => $product !== null ? $stock->getPreorderStock($product) : 0,
            ];
        }, $repeated);

        $this->table(
            ['Товар', 'Отмен', 'Потеряно, ₽', 'Остаток', 'Ожидается'],
            array_map(fn (array $row) => [
                mb_strimwidth($row['name'], 0, 60, '…'),
                $row['shortages'],
                number_format($row['lost_amount'], 0, ',', ' '),
                $row['stock'],
                $row['incoming'],
            ], $rows),
        );

        if ($this->option('dry-run')) {
            $this->info('dry-run: письмо не отправлено.');

            return self::SUCCESS;
        }

        $recipients = (array) config('notifications.mail.purchasing_recipients', []);

        if ($recipients === []) {
            $this->warn('MAIL_PURCHASING_RECIPIENTS пуст — отчёт некому отправить.');

            return self::SUCCESS;
        }

        foreach ($recipients as $recipient) {
            Notification::route('mail', $recipient)
                ->notify(new PurchasingShortageReportNotification($rows, $windowDays));
        }

        $this->info(sprintf('Отчёт отправлен: %s.', implode(', ', $recipients)));

        return self::SUCCESS;
    }
}
