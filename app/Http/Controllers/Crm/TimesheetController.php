<?php

namespace App\Http\Controllers\Crm;

use App\Services\Crm\TimesheetService;
use App\Services\SimpleCsvExporter;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Табель отдела продаж: месячная сетка явок и отсутствий (эпик abs-00).
 *
 * Данные производны от записей раздела «Отсутствия» — отдельного ввода
 * дневных отметок нет. Доступ только у руководителя (crm-timesheet.view).
 */
class TimesheetController extends CrmController
{
    public function index(Request $request, TimesheetService $timesheet): Response
    {
        return Inertia::render('Crm/Pages/Absences/Timesheet', [
            'timesheet' => $timesheet->forMonth($this->month($request)),
            'canEditAbsences' => $this->crmActor($request)->can('crm-absences.edit'),
        ]);
    }

    public function export(Request $request, TimesheetService $timesheet, SimpleCsvExporter $csv): StreamedResponse
    {
        $data = $timesheet->forMonth($this->month($request));

        $headers = [
            'Менеджер',
            ...array_map(fn (array $day): string => (string) $day['day'], $data['days']),
            'Явки', 'Отпуск', 'Отгул', 'Больничный', 'Прогулы',
        ];

        $rows = array_map(fn (array $row): array => [
            $row['manager']['name'],
            ...array_map(fn (array $cell): string => $cell['code'], $row['cells']),
            $row['totals']['work'],
            $row['totals']['vacation'],
            $row['totals']['day_off'],
            $row['totals']['sick_leave'],
            $row['totals']['truancy'],
        ], $data['rows']);

        return $csv->stream("tabel_{$data['month']}", $headers, $rows);
    }

    private function month(Request $request): CarbonImmutable
    {
        $raw = (string) $request->query('month', '');

        if (preg_match('/^\d{4}-\d{2}$/', $raw)) {
            return CarbonImmutable::createFromFormat('Y-m-d', $raw.'-01')->startOfDay();
        }

        return CarbonImmutable::today()->startOfMonth();
    }
}
