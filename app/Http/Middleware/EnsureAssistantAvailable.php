<?php

namespace App\Http\Middleware;

use App\Services\Assistant\AssistantAvailability;
use App\Services\Assistant\AssistantQuotas;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Маршруты помощника существуют, только пока он доступен: выключен,
 * кончился баланс или исчерпан месячный предел — 404, как будто раздела нет.
 */
class EnsureAssistantAvailable
{
    public function __construct(
        private readonly AssistantAvailability $availability,
        private readonly AssistantQuotas $quotas,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->availability->isAvailable() || $this->quotas->orgMonthlyExceeded()) {
            abort(404);
        }

        return $next($request);
    }
}
