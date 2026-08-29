<?php

namespace App\Support\Contacts;

/**
 * Итог переноса таблицы контрагентов партнёра: кого завели и кого не смогли.
 *
 * Пропуски — часть результата, а не сбой: за названием ООО человека не видно,
 * а общий ящик сети («открытие франшизы») личной почтой не является. Менеджер
 * должен видеть поимённо, что осталось на руках, а не вычитать одно число
 * из другого.
 */
final class ContractorSheetReport
{
    public int $rowsTotal = 0;

    public int $rowsMatched = 0;

    public int $contactsCreated = 0;

    public int $contactsUpdated = 0;

    public int $linksCreated = 0;

    /** @var list<array{line: int, contractor: string}> Контрагента нет среди юрлиц партнёра. */
    public array $unmatched = [];

    /** @var list<array{line: int, contractor: string, candidates: int}> Несколько юрлиц с таким названием. */
    public array $ambiguous = [];

    /** @var list<array{line: int, contractor: string}> Человек за названием не читается — только ООО. */
    public array $withoutPerson = [];

    /** @var list<string> Адреса, признанные общими: в справочник людей они не идут. */
    public array $sharedEmails = [];

    /** @var list<string> */
    public array $warnings = [];
}
