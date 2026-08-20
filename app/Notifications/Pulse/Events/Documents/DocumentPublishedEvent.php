<?php

namespace App\Notifications\Pulse\Events\Documents;

use App\Enums\PrintedDocumentType;
use App\Notifications\Pulse\Events\AbstractNotificationEvent;
use App\Notifications\Pulse\Support\FieldSpec;

/**
 * 1С выложила печатную форму: акт сверки, УПД, счёт, накладную.
 *
 * Кейс заказчика: «акты сверки по контрагенту приходят на одни емейлы,
 * а реализации (сканы) — на другие». Закрывается двумя правилами
 * с условием по типу документа.
 */
class DocumentPublishedEvent extends AbstractNotificationEvent
{
    public function key(): string
    {
        return 'documents.published';
    }

    public function label(): string
    {
        return 'Опубликован документ';
    }

    public function description(): string
    {
        return 'Из 1С пришла печатная форма и файл перенесён в хранилище';
    }

    public function fields(): array
    {
        return [
            'document_type' => new FieldSpec(
                'document_type',
                'Тип документа',
                FieldSpec::TYPE_ENUM,
                PrintedDocumentType::options(),
                hint: 'Акт сверки, УПД, счёт и прочие формы',
            ),
            'document_number' => new FieldSpec('document_number', 'Номер документа', FieldSpec::TYPE_STRING),
            'document_date' => new FieldSpec('document_date', 'Дата документа', FieldSpec::TYPE_DATE),
            'organization_id' => new FieldSpec('organization_id', 'Наше юрлицо', FieldSpec::TYPE_NUMBER),
            'is_revision' => new FieldSpec('is_revision', 'Перевыставленный документ', FieldSpec::TYPE_BOOL,
                hint: 'Исправленная версия ранее присланной формы'),
            'base_document_kind' => new FieldSpec('base_document_kind', 'Основание', FieldSpec::TYPE_STRING,
                hint: 'Заказ, реализация или иной документ-основание'),
        ];
    }

    protected function ownTags(array $data): array
    {
        $tags = [];

        if (filled($data['document_type'] ?? null)) {
            $tags[] = 'документ:'.$data['document_type'];
        }

        if ($data['is_revision'] ?? false) {
            $tags[] = 'документ:перевыставлен';
        }

        return $tags;
    }

    public function defaultTemplate(): string
    {
        return 'mail.pulse.documents.published';
    }

    public function defaultSubject(): string
    {
        return '{{document_title}} — Pecado.ru';
    }
}
