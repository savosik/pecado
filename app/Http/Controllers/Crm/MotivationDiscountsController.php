<?php

namespace App\Http\Controllers\Crm;

use App\Services\Motivation\DiscountJournalService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Журнал скидок — постконтроль без влияния на расчёт (mot-38; форма B11).
 */
class MotivationDiscountsController extends CrmController
{
    public function __construct(private readonly DiscountJournalService $journal) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Crm/Pages/Motivation/Discounts', $this->payload($request));
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
        $today = CarbonImmutable::today();
        $from = $this->date((string) $request->query('from', ''), $today->subDays(30));
        $to = $this->date((string) $request->query('to', ''), $today);
        if ($to->lessThan($from)) {
            [$from, $to] = [$to, $from];
        }
        $managerId = $request->integer('manager') ?: null;
        $minPercent = max(0.0, (float) $request->query('min_percent', 0));
        $page = max(1, $request->integer('page') ?: 1);

        return $this->journal->build($from, $to, $managerId, $minPercent, $page) + [
            'query' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'manager' => $managerId, 'min_percent' => $minPercent, 'page' => $page],
        ];
    }

    private function date(string $raw, CarbonImmutable $default): CarbonImmutable
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1 ? CarbonImmutable::parse($raw) : $default;
    }
}
