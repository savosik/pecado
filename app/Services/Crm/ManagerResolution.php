<?php

namespace App\Services\Crm;

use App\Models\PersonalManager;
use Illuminate\Support\Carbon;

/**
 * Результат резолва «кто фактически ведёт клиентов менеджера на дату».
 *
 * В обычном режиме $manager — сам персональный менеджер, $absentManager — null.
 * При активном замещении $manager — замещающий, $absentManager — отсутствующий,
 * $until — последний день отсутствия (для текста «замещает до …»).
 */
final class ManagerResolution
{
    public function __construct(
        public readonly PersonalManager $manager,
        public readonly ?PersonalManager $absentManager = null,
        public readonly ?Carbon $until = null,
    ) {}

    public function isSubstitution(): bool
    {
        return $this->absentManager !== null;
    }
}
