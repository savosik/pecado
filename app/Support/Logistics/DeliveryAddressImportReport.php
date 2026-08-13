<?php

namespace App\Support\Logistics;

/**
 * Что именно сделал импорт адресов из таблицы логиста.
 *
 * Импорт пишет в кабинеты живых клиентов, поэтому «готово» в ответе бесполезно:
 * важно, кого не удалось опознать и какие адреса ушли в кабинет — это единственный
 * способ заметить, что адрес приписан не тому контрагенту.
 */
final class DeliveryAddressImportReport
{
    public int $rowsRead = 0;

    public int $rowsWithoutAddress = 0;

    public int $rowsSelfPickup = 0;

    public int $matchedByTaxId = 0;

    public int $matchedByName = 0;

    public int $addressesCreated = 0;

    public int $addressesAlreadyPresent = 0;

    public int $addressesTrimmed = 0;

    public int $daDataFailures = 0;

    /** @var list<array{client: string, line: int, sheet: string, address: string}> */
    public array $unmatched = [];

    /** @var list<array{client: string, line: int, sheet: string, candidates: int}> */
    public array $ambiguous = [];

    /** @var list<array{user_id: int, user: string, name: string, address: string, carrier: ?string, shipments: int, last_year: int, matched_by: string, parsed: bool}> */
    public array $created = [];

    /** @var list<string> */
    public array $warnings = [];

    public function addUnmatched(string $client, string $sheet, int $line, string $address): void
    {
        $this->unmatched[] = ['client' => $client, 'sheet' => $sheet, 'line' => $line, 'address' => $address];
    }

    public function addAmbiguous(string $client, string $sheet, int $line, int $candidates): void
    {
        $this->ambiguous[] = ['client' => $client, 'sheet' => $sheet, 'line' => $line, 'candidates' => $candidates];
    }

    /**
     * Клиенты, которых импорт не опознал, — по одному разу, с числом строк.
     *
     * @return array<string, int>
     */
    public function unmatchedClients(): array
    {
        $counts = [];

        foreach ($this->unmatched as $row) {
            $key = $row['client'] === '' ? '(без контрагента)' : $row['client'];
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        arsort($counts);

        return $counts;
    }

    /**
     * Сколько клиентов получило адреса.
     */
    public function clientsTouched(): int
    {
        return count(array_unique(array_column($this->created, 'user_id')));
    }
}
