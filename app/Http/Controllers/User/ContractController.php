<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Services\Contracts\ClientContractPresenter;
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

        $presenter = app(ClientContractPresenter::class);
        $fileUrl = fn (Contract $contract, Media $media): string => route('cabinet.contracts.download', [$contract->getKey(), $media->getKey()]);

        $contracts = Contract::query()
            ->visibleTo($user)
            ->with(ClientContractPresenter::eagerLoads())
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Contract $contract): array => $presenter->row($contract, $fileUrl))
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
