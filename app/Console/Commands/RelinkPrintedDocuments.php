<?php

namespace App\Console\Commands;

use App\Models\PrintedDocument;
use App\Services\Notifications\Pulse\DocumentSignalDispatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Доклейка связей печатных форм, приехавших раньше своих сторон (v16.1.0).
 *
 * Печатная форма принимается всегда, даже когда контрагента, заказа или реализации
 * на сайте ещё нет: сырые UUID сохраняются, а связь остаётся пустой. Эта команда
 * связывает их, когда сущность приезжает.
 *
 * Почему командой, а не observer'ом на Company::created: HandleContractorCreated
 * создаёт компанию внутри Company::withoutEvents(), и observer там просто не
 * выстрелит. Заводить исключение из этого правила ради одной связи дороже, чем
 * раз в десять минут пройтись по непривязанным строкам — их всегда единицы.
 */
class RelinkPrintedDocuments extends Command
{
    protected $signature = 'documents:relink {--chunk=1000 : Размер батча}';

    protected $description = 'Доклейка связей печатных форм: контрагент, партнёр, организация, заказ, реализация';

    /**
     * Колонка со связью → [таблица, колонка UUID в ней, колонка UUID у нас].
     *
     * @var array<string, array{0: string, 1: string, 2: string}>
     */
    private const LINKS = [
        'company_id' => ['companies', 'erp_id', 'contractor_uuid'],
        'user_id' => ['users', 'erp_id', 'partner_uuid'],
        'organization_id' => ['organizations', 'external_id', 'organization_uuid'],
        'order_id' => ['orders', 'uuid', 'order_uuid'],
        'shipment_id' => ['shipments', 'uuid', 'shipment_uuid'],
    ];

    public function handle(): int
    {
        $chunk = max(1, (int) $this->option('chunk'));
        $total = [];

        foreach (self::LINKS as $column => [$table, $foreignColumn, $uuidColumn]) {
            $linked = $this->relink($column, $table, $foreignColumn, $uuidColumn, $chunk);

            if ($linked > 0) {
                $total[$column] = $linked;
                $this->line("  {$column}: доклеено {$linked}");
            }
        }

        if ($total === []) {
            $this->info('Непривязанных печатных форм нет.');

            return self::SUCCESS;
        }

        Log::info('documents:relink: связи доклеены', $total);
        $this->info('Готово.');

        return self::SUCCESS;
    }

    /**
     * Курсор идёт по возрастанию id, а не по «первым N непривязанным».
     *
     * Иначе строки, чей UUID пока не резолвится, возвращались бы в каждом батче
     * и команда крутилась бы на месте: связь-то так и не появилась.
     */
    private function relink(string $column, string $table, string $foreignColumn, string $uuidColumn, int $chunk): int
    {
        $linked = 0;
        $lastId = 0;

        while (true) {
            $rows = PrintedDocument::withTrashed()
                ->whereNull($column)
                ->whereNotNull($uuidColumn)
                ->where('id', '>', $lastId)
                ->orderBy('id')
                ->limit($chunk)
                ->pluck($uuidColumn, 'id');

            if ($rows->isEmpty()) {
                break;
            }

            $lastId = (int) $rows->keys()->max();

            $map = DB::table($table)
                ->whereIn($foreignColumn, $rows->unique()->values())
                ->pluck('id', $foreignColumn);

            $resolved = [];

            foreach ($rows as $documentId => $uuid) {
                if (isset($map[$uuid])) {
                    $resolved[$map[$uuid]][] = $documentId;
                }
            }

            foreach ($resolved as $foreignId => $documentIds) {
                $linked += PrintedDocument::withTrashed()
                    ->whereIn('id', $documentIds)
                    ->update([$column => $foreignId]);

                // Контрагент появился только сейчас — значит и уведомить по правилам
                // контрагента можно только сейчас. Пока связи не было, сигнал
                // промахнулся бы мимо правил, ради которых домен и заводился.
                if ($column === 'company_id') {
                    $this->signalLinkedDocuments($documentIds);
                }
            }
        }

        return $linked;
    }

    /**
     * Сигнал пульту по документам, у которых контрагент доклеился только что.
     *
     * Возрастной ценз движка сам отсечёт старьё: массовая доклейка истории
     * не должна превращаться в рассылку.
     *
     * @param  array<int, int>  $documentIds
     */
    private function signalLinkedDocuments(array $documentIds): void
    {
        $dispatcher = app(DocumentSignalDispatcher::class);

        PrintedDocument::query()
            ->whereIn('id', $documentIds)
            ->where('file_status', PrintedDocument::FILE_STORED)
            ->each(function (PrintedDocument $document) use ($dispatcher): void {
                $dispatcher->published($document);
            });
    }
}
