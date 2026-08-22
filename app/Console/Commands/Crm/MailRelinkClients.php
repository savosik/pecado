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
        {--contacts : Заодно проставить человека из справочника}
        {--dry-run : Только показать, сколько нашлось}';

    protected $description = 'Связать письма с карточками партнёров и людей по адресу получателя';

    public function handle(PartnerAddressBook $book): int
    {
        $since = now()->subDays((int) $this->option('days'));
        $dryRun = (bool) $this->option('dry-run');

        $withContacts = (bool) $this->option('contacts');
        $letters = 0;
        $letterContacts = 0;

        CrmEmail::query()
            ->whereNull('client_user_id')
            ->where('created_at', '>=', $since)
            ->chunkById(200, function ($chunk) use ($book, $dryRun, &$letters): void {
                foreach ($chunk as $letter) {
                    $contact = $book->resolveAnyContact((array) $letter->to);
                    $clientId = $contact?->client_user_id ?? $book->resolveAny((array) $letter->to);

                    if ($clientId === null) {
                        continue;
                    }

                    $letters++;

                    if (! $dryRun) {
                        $letter->forceFill(array_filter([
                            'client_user_id' => $clientId,
                            'contact_id' => $contact?->getKey(),
                        ]))->save();
                    }
                }
            });

        // Письмо могло быть подшито к партнёру ещё до появления справочника,
        // а человека у него нет. Отдельным проходом — по прямой команде.
        if ($withContacts) {
            CrmEmail::query()
                ->whereNull('contact_id')
                ->where('created_at', '>=', $since)
                ->chunkById(200, function ($chunk) use ($book, $dryRun, &$letterContacts): void {
                    foreach ($chunk as $letter) {
                        $contact = $book->resolveAnyContact((array) $letter->to);

                        if ($contact === null) {
                            continue;
                        }

                        $letterContacts++;

                        if (! $dryRun) {
                            $letter->forceFill(['contact_id' => $contact->getKey()])->save();
                        }
                    }
                });
        }

        $journal = 0;

        SentEmail::query()
            ->whereNull('client_user_id')
            ->where('sent_at', '>=', $since)
            ->chunkById(500, function ($chunk) use ($book, $dryRun, &$journal): void {
                foreach ($chunk as $row) {
                    $contact = $book->resolveContact((string) $row->recipient);
                    $clientId = $contact?->client_user_id ?? $book->resolve((string) $row->recipient);

                    if ($clientId === null) {
                        continue;
                    }

                    $journal++;

                    if (! $dryRun) {
                        $row->forceFill(array_filter([
                            'client_user_id' => $clientId,
                            'contact_id' => $contact?->getKey(),
                        ]))->save();
                    }
                }
            });

        $this->info($dryRun ? 'Пробный разбор:' : 'Разбор завершён:');
        $this->line("  писем CRM подшито: {$letters}");
        $this->line("  записей журнала подшито: {$journal}");

        if ($withContacts) {
            $this->line("  писем связано с человеком: {$letterContacts}");
        }

        return self::SUCCESS;
    }
}
