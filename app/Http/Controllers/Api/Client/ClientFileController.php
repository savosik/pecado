<?php

namespace App\Http\Controllers\Api\Client;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\PrintedDocument;
use App\Models\User;
use App\Services\Client\Api\FeatureGate;
use App\Support\Cabinet\PaymentOrdersGate;
use App\Support\Crm\CrmAttachments;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Файлы по временным подписанным ссылкам из клиентского API v1.
 *
 * Без Bearer: ссылку агент передаёт человеку. Подпись проверяет middleware
 * `signed`, здесь — принадлежность файла клиенту из подписи и гейт раздела.
 * Чужое или выключенное — 404, а не 403: существование не подтверждаем.
 */
class ClientFileController extends Controller
{
    public function document(Request $request, int $document): Response
    {
        $user = $this->user($request);
        abort_unless(FeatureGate::DOCUMENTS->allows($user), 404);

        $found = PrintedDocument::query()->visibleTo($user)->stored()->whereKey($document)->first();
        abort_if($found === null, 404);

        $disk = Storage::disk($found->disk);
        abort_unless($found->path && $disk->exists($found->path), 404, 'Файл не найден');

        return $disk->download($found->path, $found->download_name);
    }

    public function contractFile(Request $request, int $contract, int $media): Response
    {
        $user = $this->user($request);
        abort_unless(FeatureGate::CONTRACTS->allows($user), 404);

        $found = Contract::query()->visibleTo($user)->whereKey($contract)->first();
        abort_if($found === null, 404);

        $file = $found->getMedia(CrmAttachments::COLLECTION)->firstWhere('id', $media);
        abort_if($file === null, 404);

        return $file->toResponse($request);
    }

    public function paymentOrder(Request $request): Response
    {
        $user = $this->user($request);
        abort_unless(PaymentOrdersGate::availableFor($user), 404);

        return app(\App\Services\Client\Api\Operations\PaymentOrderOperations::class)->file($user, $request->query());
    }

    private function user(Request $request): User
    {
        return User::query()->findOrFail((int) $request->query('user'));
    }
}
