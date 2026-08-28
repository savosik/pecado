<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Contract;
use App\Support\Crm\CrmAttachments;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Договоры партнёра в личном кабинете.
 *
 * Только чтение: договор ведёт менеджер. Партнёр видит номер, обе стороны,
 * статус, срок и сканы, отмеченные менеджером как видимые. Заметки менеджера,
 * категория-вкладка (внутренняя папка реестра) и черновики сюда не отдаются.
 *
 * Ось видимости — юрлица партнёра, как у печатных форм. Чужой договор или
 * файл отвечает 404: 403 подтвердил бы его существование.
 */
class ContractController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        $contracts = Contract::query()
            ->visibleTo($user)
            ->with(['organization:id,name,legal_name,tax_id', 'company:id,name,legal_name,tax_id', 'responsibleManager:id,name,phone,email', 'media'])
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Contract $contract): array => [
                'id' => (int) $contract->getKey(),
                'number' => $contract->number,
                'date' => $contract->date?->format('d.m.Y'),
                'signed_at' => $contract->signed_at?->format('d.m.Y'),
                'valid_from' => $contract->valid_from?->format('d.m.Y'),
                'valid_until' => $contract->valid_until?->format('d.m.Y'),
                'is_expired' => $contract->is_expired && $contract->status->isActive(),
                'status_label' => $contract->status->label(),
                'status_color' => $contract->status->color(),
                'payment_terms_label' => $contract->payment_terms?->label(),
                'form_label' => $contract->form?->label(),
                'organization' => $contract->organization === null ? null : [
                    'name' => (string) ($contract->organization->name ?: $contract->organization->legal_name),
                    'tax_id' => $contract->organization->tax_id,
                ],
                'company' => $contract->company instanceof Company ? [
                    'name' => (string) ($contract->company->name ?: $contract->company->legal_name),
                    'tax_id' => $contract->company->tax_id,
                ] : ['name' => $contract->counterparty_name, 'tax_id' => null],
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
                        'url' => route('cabinet.contracts.download', [$contract->getKey(), $media->getKey()]),
                    ])
                    ->values()
                    ->all(),
            ])
            ->all();

        return Inertia::render('User/Cabinet/Contracts/Index', [
            'contracts' => $contracts,
        ]);
    }

    /**
     * Скан договора. Не через Storage::url(): публичная ссылка обошла бы
     * проверку принадлежности, а на dev AWS_URL указывает на прод.
     */
    public function download(Request $request, int $contract, int $media): HttpResponse
    {
        $found = Contract::query()
            ->visibleTo($request->user())
            ->whereKey($contract)
            ->first();

        abort_if($found === null, 404);

        $file = $found->getMedia(CrmAttachments::COLLECTION)->firstWhere('id', $media);

        abort_if($file === null, 404);

        return $file->toResponse($request);
    }
}
