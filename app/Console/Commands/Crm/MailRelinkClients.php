<?php

namespace App\Console\Commands\Crm;

use App\Models\CrmEmail;
use App\Models\SentEmail;
use App\Services\Crm\Mail\PartnerAddressBook;
use Illuminate\Console\Command;

/**
 * Подшить к карточкам партнёров письма, ушедшие на адреса их контактов.
 *
 * Новые письма связываются сами, в момент создания. Команда нужна для того,
 * что накопилось раньше: письмо бухгалтеру уходило на личный ящик, пользователя
 * сайта по такому адресу не найти, и в ленте партнёра его не было — при том
 * что это и есть его переписка.
 */
class MailRelinkClients extends Command
{
    protected $signature = 'mail:relink-clients
        {--days=180 : За какой период разбирать}
        {--dry-run : Только показать, сколько нашлось}';

    protected $description = 'Связать письма без партнёра с карточками по адресу получателя';

    public function handle(PartnerAddressBook $book): int
    {
        $since = now()->subDays((int) $this->option('days'));
        $dryRun = (bool) $this->option('dry-run');

        $letters = 0;

        CrmEmail::query()
            ->whereNull('client_user_id')
            ->where('created_at', '>=', $since)
            ->chunkById(200, function ($chunk) use ($book, $dryRun, &$letters): void {
                foreach ($chunk as $letter) {
                    $clientId = $book->resolveAny((array) $letter->to);

                    if ($clientId === null) {
                        continue;
                    }

                    $letters++;

                    if (! $dryRun) {
                        $letter->forceFill(['client_user_id' => $clientId])->save();
                    }
                }
            });

        $journal = 0;

        SentEmail::query()
            ->whereNull('client_user_id')
            ->where('sent_at', '>=', $since)
            ->chunkById(500, function ($chunk) use ($book, $dryRun, &$journal): void {
                foreach ($chunk as $row) {
                    $clientId = $book->resolve((string) $row->recipient);

                    if ($clientId === null) {
                        continue;
                    }

                    $journal++;

                    if (! $dryRun) {
                        $row->forceFill(['client_user_id' => $clientId])->save();
                    }
                }
            });

        $this->info($dryRun ? 'Пробный разбор:' : 'Разбор завершён:');
        $this->line("  писем CRM подшито: {$letters}");
        $this->line("  записей журнала подшито: {$journal}");

        return self::SUCCESS;
    }
}
