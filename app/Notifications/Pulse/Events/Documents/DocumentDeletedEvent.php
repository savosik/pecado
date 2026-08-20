<?php

namespace App\Notifications\Pulse\Events\Documents;

use App\Enums\PrintedDocumentType;
use App\Notifications\Pulse\Events\AbstractNotificationEvent;
use App\Notifications\Pulse\Support\FieldSpec;

/**
 * 1С отозвала печатную форму.
 *
 * Отдельное событие, а не молчание: если бухгалтер получил акт сверки,
 * а его отозвали, знать об этом нужно тому же бухгалтеру.
 */
class DocumentDeletedEvent extends AbstractNotificationEvent
{
    public function key(): string
    {
        return 'documents.deleted';
    }

    public function label(): string
    {
        return 'Документ отозван';
    }

    public function description(): string
    {
        return '1С удалила ранее присланную печатную форму';
    }

    public function fields(): array
    {
        return [
            'document_type' => new FieldSpec('document_type', 'Тип документа', FieldSpec::TYPE_ENUM, PrintedDocumentType::options()),
            'document_number' => new FieldSpec('document_number', 'Номер документа', FieldSpec::TYPE_STRING),
        ];
    }

    protected function ownTags(array $data): array
    {
        return filled($data['document_type'] ?? null) ? ['документ:'.$data['document_type']] : [];
    }

    public function defaultSubject(): string
    {
        return 'Документ отозван — Pecado.ru';
    }
}
