<?php

namespace App\Support\Contacts;

/**
 * Итог переноса таблицы менеджеров: что записано и что осталось на руках.
 *
 * Ненайденные партнёры — не сбой, а часть результата: зарубежные клиенты
 * и «закрытые» ИП в базе сайта отсутствуют законно, и менеджер должен видеть
 * их список, а не догадываться по разнице в счётчиках.
 */
final class ManagerSheetReport
{
    public int $rowsTotal = 0;

    public int $rowsMatched = 0;

    public int $contactsCreated = 0;

    public int $contactsUpdated = 0;

    public int $linksCreated = 0;

    public int $profilesUpdated = 0;

    public int $commentsCreated = 0;

    public int $orphansCreated = 0;

    public int $orphansUpdated = 0;

    /** @var list<array{line: int, name: string}> */
    public array $unmatched = [];

    /** @var list<array{line: int, name: string, candidates: int}> */
    public array $ambiguous = [];

    /** @var list<string> */
    public array $warnings = [];
}
