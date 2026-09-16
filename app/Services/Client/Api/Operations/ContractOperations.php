<?php

namespace App\Services\Client\Api\Operations;

use App\Models\Contract;
use App\Models\User;
use App\Services\Client\Api\Envelope;
use App\Services\Client\Api\FeatureGate;
use App\Services\Client\Api\FileLinks;
use App\Services\Client\Api\Operation;
use App\Services\Client\Api\OperationProvider;
use App\Services\Contracts\ClientContractPresenter;
use App\Support\Crm\CrmAttachments;
use App\Support\OperationApi\OperationInput;
use App\Support\OperationApi\Param;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Договоры партнёра: только чтение, договор ведёт менеджер. Гейт CONTRACTS.
 */
class ContractOperations implements OperationProvider
{
    public function __construct(
        private readonly ClientContractPresenter $presenter,
        private readonly FileLinks $links,
    ) {}

    public static function section(): array
    {
        return ['contracts', 'Договоры'];
    }

    public static function operations(): array
    {
        return [
            new Operation(
                id: 'contracts.list', section: 'contracts', method: 'GET', uri: 'contracts',
                summary: 'Договоры по своим юрлицам',
                description: 'Номер, стороны, статус, срок, сканы (видимые партнёру). Заметки менеджера не отдаются.',
                params: [
                    Param::string('status', 'Статус договора', rules: ['max:30']),
                    Param::integer('company_id', 'Юрлицо клиента', rules: ['min:1']),
                    Param::string('cursor', 'Курсор страницы из meta.next_cursor'),
                    Param::integer('per_page', 'Строк на странице, до 100', rules: ['min:1', 'max:100']),
                ],
                handler: [self::class, 'list'],
                gate: FeatureGate::CONTRACTS,
            ),
            new Operation(
                id: 'contracts.get', section: 'contracts', method: 'GET', uri: 'contracts/{contract}',
                summary: 'Карточка договора с временными ссылками на сканы',
                description: 'Ссылки живут 60 минут и открываются без токена.',
                params: [Param::integer('contract', 'id договора', true)],
                handler: [self::class, 'get'],
                gate: FeatureGate::CONTRACTS,
            ),
            new Operation(
                id: 'contracts.link', section: 'contracts', method: 'GET', uri: 'contracts/{contract}/files/{media}/link',
                summary: 'Временная ссылка на скан договора',
                description: 'download_url и expires_at для одного файла.',
                params: [
                    Param::integer('contract', 'id договора', true),
                    Param::integer('media', 'id файла из карточки', true),
                    Param::integer('ttl', 'Срок ссылки, минут (1–60)', rules: ['min:1', 'max:60']),
                ],
                handler: [self::class, 'link'],
                gate: FeatureGate::CONTRACTS,
            ),
        ];
    }

    /** @return array<string, mixed> */
    public function list(User $actor, OperationInput $input): array
    {
        $query = Contract::query()->visibleTo($actor)->with(ClientContractPresenter::eagerLoads());

        if ($input->has('status')) {
            $query->where('status', $input->string('status'));
        }

        if ($input->has('company_id')) {
            $query->where('company_id', $input->int('company_id'));
        }

        $paginator = $query->orderByDesc('date')->orderByDesc('id')
            ->cursorPaginate(Envelope::perPage($input->get('per_page'), 50), ['*'], 'cursor', $input->string('cursor'));

        return Envelope::cursor($paginator, fn (Contract $c) => $this->presenter->row($c, $this->fileUrl($actor), iso: true));
    }

    /** @return array<string, mixed> */
    public function get(User $actor, OperationInput $input): array
    {
        $contract = $this->contract($actor, (int) $input->int('contract'));

        return Envelope::data($this->presenter->row($contract, $this->fileUrl($actor), iso: true));
    }

    /** @return array<string, mixed> */
    public function link(User $actor, OperationInput $input): array
    {
        $contract = $this->contract($actor, (int) $input->int('contract'));
        $media = $contract->getMedia(CrmAttachments::COLLECTION)->firstWhere('id', (int) $input->int('media'));

        if (! $media instanceof Media) {
            throw (new ModelNotFoundException)->setModel(Media::class);
        }

        return Envelope::data(['contract_id' => $contract->id, 'media_id' => $media->id, 'filename' => $media->file_name]
            + $this->links->contractFile($contract, $media, $actor, $input->int('ttl')));
    }

    private function contract(User $actor, int $id): Contract
    {
        return Contract::query()->visibleTo($actor)->with(ClientContractPresenter::eagerLoads())->whereKey($id)->firstOrFail();
    }

    private function fileUrl(User $actor): callable
    {
        return fn (Contract $contract, Media $media): string => $this->links->contractFile($contract, $media, $actor)['download_url'];
    }
}
