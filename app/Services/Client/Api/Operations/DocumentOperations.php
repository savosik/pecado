<?php

namespace App\Services\Client\Api\Operations;

use App\Enums\PrintedDocumentType;
use App\Models\PrintedDocument;
use App\Models\User;
use App\Services\Client\Api\Envelope;
use App\Services\Client\Api\FeatureGate;
use App\Services\Client\Api\FileLinks;
use App\Services\Client\Api\Operation;
use App\Services\Client\Api\OperationProvider;
use App\Services\Documents\ClientDocumentQuery;
use App\Support\OperationApi\OperationInput;
use App\Support\OperationApi\Param;

/**
 * Печатные формы из 1С: счета, счета-фактуры, УПД, акты сверки.
 *
 * Сайт их не рисует — только показывает и отдаёт файл по временной ссылке.
 * Раздел закрыт флагом до сверки форм (гейт DOCUMENTS).
 */
class DocumentOperations implements OperationProvider
{
    public function __construct(
        private readonly ClientDocumentQuery $query,
        private readonly FileLinks $links,
    ) {}

    public static function section(): array
    {
        return ['documents', 'Документы'];
    }

    public static function operations(): array
    {
        return [
            new Operation(
                id: 'documents.list', section: 'documents', method: 'GET', uri: 'documents',
                summary: 'Печатные формы по своим контрагентам',
                description: 'Виды: '.implode(', ', PrintedDocumentType::values()).'. Фильтры по виду, контрагенту, '
                    .'нашей организации, дате, основанию (заказ/реализация). Только документы с готовым файлом.',
                params: [
                    Param::list('type', 'Виды документов'),
                    Param::integer('company_id', 'Контрагент клиента', rules: ['min:1']),
                    Param::integer('organization_id', 'Организация-продавец', rules: ['min:1']),
                    Param::string('date_from', 'С даты (YYYY-MM-DD)', rules: ['date']),
                    Param::string('date_to', 'По дату (YYYY-MM-DD)', rules: ['date']),
                    Param::string('number', 'Номер документа (можно без дефисов)', rules: ['max:100']),
                    Param::integer('order_id', 'Заказ-основание', rules: ['min:1']),
                    Param::integer('shipment_id', 'Реализация-основание', rules: ['min:1']),
                    Param::string('cursor', 'Курсор страницы из meta.next_cursor'),
                    Param::integer('per_page', 'Строк на странице, до 100', rules: ['min:1', 'max:100']),
                ],
                handler: [self::class, 'list'],
                gate: FeatureGate::DOCUMENTS,
            ),
            new Operation(
                id: 'documents.get', section: 'documents', method: 'GET', uri: 'documents/{document}',
                summary: 'Карточка документа со ссылкой на файл',
                description: 'Ссылка временная (по умолчанию 60 минут), открывается без токена — её можно передать человеку.',
                params: [Param::integer('document', 'id документа', true), Param::integer('ttl', 'Срок ссылки, минут (1–60)', rules: ['min:1', 'max:60'])],
                handler: [self::class, 'get'],
                gate: FeatureGate::DOCUMENTS,
            ),
            new Operation(
                id: 'documents.link', section: 'documents', method: 'GET', uri: 'documents/{document}/link',
                summary: 'Временная ссылка на файл документа',
                description: 'То же, что в карточке, но только ссылка: download_url и expires_at.',
                params: [Param::integer('document', 'id документа', true), Param::integer('ttl', 'Срок ссылки, минут (1–60)', rules: ['min:1', 'max:60'])],
                handler: [self::class, 'link'],
                gate: FeatureGate::DOCUMENTS,
            ),
        ];
    }

    /** @return array<string, mixed> */
    public function list(User $actor, OperationInput $input): array
    {
        $filters = $input->only(['type', 'company_id', 'organization_id', 'date_from', 'date_to', 'order_id', 'shipment_id']);
        $filters['search'] = $input->string('number');

        $query = $this->query->builder($actor, $filters);
        $this->query->applySort($query, 'date', 'desc');

        $paginator = $query->cursorPaginate(Envelope::perPage($input->get('per_page'), 50), ['*'], 'cursor', $input->string('cursor'));

        return Envelope::cursor($paginator, fn (PrintedDocument $d) => $this->row($d, $actor));
    }

    /** @return array<string, mixed> */
    public function get(User $actor, OperationInput $input): array
    {
        $document = $this->document($actor, (int) $input->int('document'));

        return Envelope::data($this->row($document, $actor) + $this->links->document($document, $actor, $input->int('ttl')));
    }

    /** @return array<string, mixed> */
    public function link(User $actor, OperationInput $input): array
    {
        $document = $this->document($actor, (int) $input->int('document'));

        return Envelope::data(['id' => $document->id, 'filename' => $document->download_name] + $this->links->document($document, $actor, $input->int('ttl')));
    }

    private function document(User $actor, int $id): PrintedDocument
    {
        return $this->query->builder($actor)->whereKey($id)->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function row(PrintedDocument $document, User $actor): array
    {
        $organizationsEnabled = (bool) config('erp.organizations.enabled');

        return [
            'id' => $document->id,
            'title' => $document->display_title,
            'type' => $document->type->value,
            'type_label' => $document->type_label,
            'number' => $document->number,
            'date' => $document->date?->toDateString(),
            'period' => $document->period_label,
            'format' => $document->format->value,
            'company' => $document->company ? ['id' => $document->company->id, 'name' => $document->company->name] : null,
            'organization' => $organizationsEnabled && $document->organization && ! $document->organization->is_stub
                ? $document->organization->name
                : null,
            'base' => $this->query->base($document),
            'size_bytes' => $document->size_bytes,
            'filename' => $document->download_name,
        ];
    }
}
