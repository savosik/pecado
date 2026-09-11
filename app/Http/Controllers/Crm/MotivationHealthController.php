<?php

namespace App\Http\Controllers\Crm;

use App\Services\Motivation\BaseHealthService;
use App\Services\Payroll\Support\MonthLabel;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «Здоровье базы» — сводка руководителя по клиентской базе отдела (mot-38; форма B5).
 */
class MotivationHealthController extends CrmController
{
    public function __construct(private readonly BaseHealthService $health) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Crm/Pages/Motivation/Health', $this->payload($request));
    }

    public function data(Request $request): JsonResponse
    {
        return response()->json($this->payload($request));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Request $request): array
    {
        $raw = (string) $request->query('month', '');
        $month = preg_match('/^\d{4}-\d{2}(-\d{2})?$/', $raw) === 1
            ? CarbonImmutable::parse(substr($raw, 0, 7).'-01')->startOfMonth()
            : CarbonImmutable::now()->startOfMonth();

        return $this->health->build($month) + ['month_label' => MonthLabel::ru($month)];
    }
}
