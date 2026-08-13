<?php

namespace App\Services\Erp\Handlers;

use App\Jobs\StorePrintedDocumentFile;
use App\Models\Order;
use App\Models\PrintedDocument;
use App\Models\Shipment;
use App\Services\Erp\Support\OrganizationResolver;
use App\Services\Erp\Support\PrintedDocumentTypeMapper;
use App\Services\Erp\Support\ResolvesContractorParty;
use Illuminate\Support\Facades\Log;

/**
 * Публикация печатной формы документа из 1С (v16.1.0).
 *
 * Handler сам S3 не трогает: он валидирует конверт, сохраняет запись и ставит
 * задачу переноса файла в отдельную очередь. Так же устроен приём индивидуальных
 * цен — потребитель шины не должен блокироваться на скачивании мегабайтного PDF,
 * пока за ним стоит очередь других сообщений.
 *
 * Ключ идемпотентности — `uuid` печатной формы, стабильный между перевыставлениями.
 * Повторная публикация обновляет запись и перезаписывает файл по тому же ключу:
 * клиент видит одну актуальную версию, истории редакций сайт не ведёт.
 *
 * Связи резолвятся мягко. Печатная форма может приехать раньше контрагента, заказа
 * или реализации, и терять её из-за порядка доставки нельзя: 1С формы не хранит,
 * перезалить PDF неоткуда. Сырые UUID сохраняются всегда — по ним связь доклеит
 * команда `documents:relink`.
 */
class HandlePrintedDocumentPublished
{
    use ResolvesContractorParty;

    protected string $event = 'printed_document.published';

    public function __construct(
        private readonly PrintedDocumentTypeMapper $typeMapper,
        private readonly OrganizationResolver $organizationResolver,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(array $payload): void
    {
        $uuid = $this->stringOrNull($payload['uuid'] ?? null);
        $fileUrl = $this->stringOrNull($payload['file_url'] ?? null);

        if ($uuid === null || $fileUrl === null) {
            Log::warning($this->event.': отсутствует uuid или file_url', ['payload' => $payload]);

            return;
        }

        $contractorUuid = $this->stringOrNull($payload['contractor_uuid'] ?? null);
        $partnerUuid = $this->stringOrNull($payload['partner_uuid'] ?? null);
        $taxId = $this->stringOrNull($payload['tax_id'] ?? null);

        [$companyId, $userId] = $this->resolveCompanyAndUser($contractorUuid, $taxId, $partnerUuid);

        if ($contractorUuid === null) {
            // Не ошибка обмена, а нарушение бизнес-правила: документ примем,
            // но клиенту он не покажется — видимость строится по контрагенту.
            // Менеджер увидит его в CRM в отборе «без контрагента».
            Log::warning($this->event.': не передан contractor_uuid, документ не увидит клиент', [
                'uuid' => $uuid,
            ]);
        } elseif ($companyId === null) {
            Log::info($this->event.': контрагент ещё не на сайте, связь доклеится позже', [
                'uuid' => $uuid,
                'contractor_uuid' => $contractorUuid,
            ]);
        }

        $organizationUuid = $this->stringOrNull($payload['organization_uuid'] ?? null);
        $orderUuid = $this->stringOrNull($payload['order_uuid'] ?? null);
        $shipmentUuid = $this->stringOrNull($payload['shipment_uuid'] ?? null);

        $type = $this->typeMapper->map(
            $this->stringOrNull($payload['type_code'] ?? null),
            $this->stringOrNull($payload['type_name'] ?? null),
        );

        $document = PrintedDocument::withTrashed()->firstOrNew(['uuid' => $uuid]);

        $document->fill([
            'type' => $type,
            'erp_type_code' => $this->stringOrNull($payload['type_code'] ?? null),
            'erp_type_name' => $this->stringOrNull($payload['type_name'] ?? null),
            'number' => $this->stringOrNull($payload['number'] ?? null),
            'date' => $this->stringOrNull($payload['date'] ?? null),
            'title' => $this->stringOrNull($payload['title'] ?? null),
            'user_id' => $userId,
            'company_id' => $companyId,
            'organization_id' => $this->organizationResolver->resolveByUuid($organizationUuid)?->id,
            'order_id' => $orderUuid ? Order::where('uuid', $orderUuid)->value('id') : null,
            'shipment_id' => $shipmentUuid ? Shipment::where('uuid', $shipmentUuid)->value('id') : null,
            'partner_uuid' => $partnerUuid,
            'contractor_uuid' => $contractorUuid,
            'organization_uuid' => $organizationUuid,
            'order_uuid' => $orderUuid,
            'shipment_uuid' => $shipmentUuid,
            'tax_id' => $taxId,
            'base_document_kind' => $this->stringOrNull($payload['base_document_kind'] ?? null),
            'source_url' => $fileUrl,
            'original_filename' => $this->stringOrNull($payload['file_name'] ?? null),
            'mime_type' => $this->stringOrNull($payload['mime_type'] ?? null) ?? 'application/pdf',
            'revision' => $this->intOrNull($payload['revision'] ?? null),
            'erp_created_at' => $payload['erp_created_at'] ?? null,
            'erp_updated_at' => $payload['erp_updated_at'] ?? null,
        ]);

        // Повторная публикация возвращает отозванную форму: снятие пометки
        // удаления в 1С — обычная операция, а не исключение.
        if ($document->trashed()) {
            $document->deleted_at = null;
        }

        // Размер и хеш — снимок с уже перенесённого файла. Обнуляем их вместе
        // со статусом: иначе перевыставление с новым PDF считалось бы дубликатом
        // по старой контрольной сумме, и файл не обновился бы.
        $document->file_status = PrintedDocument::FILE_PENDING;
        $document->size_bytes = $this->intOrNull($payload['file_size'] ?? null);

        $document->save();

        StorePrintedDocumentFile::dispatch(
            $document->id,
            $fileUrl,
            $this->stringOrNull($payload['file_checksum'] ?? null),
        );

        Log::info($this->event.': документ сохранён, файл поставлен в очередь переноса', [
            'printed_document_id' => $document->id,
            'uuid' => $document->uuid,
            'type' => $type->value,
            'company_id' => $companyId,
        ]);
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
