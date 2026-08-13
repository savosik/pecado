<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Закрывает раздел «Документы» в личном кабинете, пока печатные формы не сверены.
 *
 * Гейтится только показ клиенту: приём из 1С работает всегда, иначе документы,
 * присланные раньше открытия раздела, потерялись бы безвозвратно — печатные формы
 * там не хранятся, и перезалить их неоткуда.
 *
 * 404, а не 403 — по той же причине, что у EnsureCabinetFinanceEnabled: закрытого
 * раздела для клиента просто не существует, а «доступ запрещён» породил бы вопрос
 * менеджеру, которого можно не создавать.
 */
class EnsureDocumentsEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless((bool) config('documents.enabled'), 404);

        return $next($request);
    }
}
