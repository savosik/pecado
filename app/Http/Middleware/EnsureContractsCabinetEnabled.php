<?php

namespace App\Http\Middleware;

use App\Services\Client\Api\FeatureGate;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Закрывает раздел «Договоры» в кабинете партнёра, пока реестр не выверен.
 *
 * 404, а не 403 — как у EnsureDocumentsEnabled: закрытого раздела для клиента
 * просто не существует.
 */
class EnsureContractsCabinetEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(FeatureGate::CONTRACTS->allows($request->user()), 404);

        return $next($request);
    }
}
