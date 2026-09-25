<?php

namespace App\Services\Contracts;

use App\Models\Company;
use App\Models\Contract;
use App\Support\Crm\CrmAttachments;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Договор глазами партнёра — одно представление для кабинета и API v1.
 *
 * Заметки менеджера, категория-вкладка и черновики сюда не попадают.
 */
class ClientContractPresenter
{
    /**
     * @param  callable(Contract, Media): string  $fileUrl  ссылка на файл в нужном транспорте
     * @return array<string, mixed>
     */
    public function row(Contract $contract, callable $fileUrl, bool $iso = false): array
    {
        $date = fn ($value) => $value === null ? null : ($iso ? $value->toDateString() : $value->format('d.m.Y'));

        return [
            'id' => (int) $contract->getKey(),
            'number' => $contract->number,
            'date' => $date($contract->date),
            'signed_at' => $date($contract->signed_at),
            'valid_from' => $date($contract->valid_from),
            'valid_until' => $date($contract->valid_until),
            'is_expired' => $contract->is_expired && $contract->status->isActive(),
            'status' => $contract->status->value,
            'status_label' => $contract->status->label(),
            'status_color' => $contract->status->color(),
            'payment_terms_label' => $contract->payment_terms?->label(),
            'form_label' => $contract->form?->label(),
            'organization' => $contract->organization === null ? null : [
                'name' => (string) ($contract->organization->name ?: $contract->organization->legal_name),
                'tax_id' => $contract->organization->tax_id,
            ],
            'company' => $contract->company instanceof Company ? [
                'id' => (int) $contract->company->getKey(),
                'name' => (string) ($contract->company->name ?: $contract->company->legal_name),
                'tax_id' => $contract->company->tax_id,
            ] : ['id' => null, 'name' => $contract->counterparty_name, 'tax_id' => null],
            'manager' => $contract->responsibleManager === null ? null : [
                'name' => $contract->responsibleManager->name,
                'phone' => $contract->responsibleManager->phone,
                'email' => $contract->responsibleManager->email,
            ],
            'files' => $contract->getMedia(CrmAttachments::COLLECTION)
                ->map(fn (Media $media): array => [
                    'id' => (int) $media->getKey(),
                    'name' => $media->file_name,
                    'size_label' => $media->human_readable_size,
                    'url' => $fileUrl($contract, $media),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * Связи, нужные представлению.
     *
     * @return list<string>
     */
    public static function eagerLoads(): array
    {
        return ['organization:id,name,legal_name,tax_id', 'company:id,name,legal_name,tax_id', 'responsibleManager:id,name,phone,email', 'media'];
    }
}
