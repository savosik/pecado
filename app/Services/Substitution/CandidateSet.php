<?php

namespace App\Services\Substitution;

/**
 * Результат автоподбора по одной отменённой строке.
 *
 * «Подождать прихода» — не замена, а отдельный исход, поэтому он отделён
 * от кандидатов: страница клиента рисует его отдельной радио-опцией.
 *
 * @phpstan-type Candidate array{
 *     product_id: int|null,
 *     product_defect_id: int|null,
 *     kind: \App\Enums\Substitution\CandidateKind,
 *     reason: string,
 *     price: float,
 *     available: int,
 *     suggested_quantity: int
 * }
 */
class CandidateSet
{
    /**
     * @param  array<int, array<string, mixed>>  $candidates
     */
    public function __construct(
        public readonly bool $waitAvailable,
        public readonly ?string $waitReason,
        public readonly array $candidates,
    ) {}
}
