<?php

namespace App\Support\Crm;

/**
 * Что именно сделал импорт таблицы продаж.
 *
 * Отчёт копится по ходу дела и печатается целиком: у импорта, который трогает
 * планы двух сотен клиентов, «готово» в ответе бесполезно — важно, кого не нашли
 * в базе и на какую сумму из-за этого разошёлся план.
 */
final class SalesSheetImportReport
{
    public int $clientsMatched = 0;

    public int $plansSaved = 0;

    public int $profilesUpdated = 0;

    public int $statusesChanged = 0;

    public int $managerPlansSaved = 0;

    public int $departmentPlansSaved = 0;

    /** Сумма планов, не попавшая в CRM из-за несопоставленных клиентов. */
    public float $lostAmount = 0.0;

    /** @var list<array{name: string, line: int, amount: float}> */
    public array $unmatched = [];

    /** @var list<array{name: string, line: int, candidates: int}> */
    public array $ambiguous = [];

    /** @var list<string> */
    public array $warnings = [];

    public function addUnmatched(string $name, int $line, float $amount): void
    {
        $this->unmatched[] = ['name' => $name, 'line' => $line, 'amount' => $amount];
        $this->lostAmount += $amount;
    }

    public function addAmbiguous(string $name, int $line, int $candidates, float $amount): void
    {
        $this->ambiguous[] = ['name' => $name, 'line' => $line, 'candidates' => $candidates];
        $this->lostAmount += $amount;
    }
}
