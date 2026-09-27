<?php

namespace App\Http\Controllers\Crm;

use App\Enums\InstructionAudience;
use App\Http\Controllers\Concerns\ReadsInstructions;

/**
 * Инструкции для менеджеров отдела продаж. Без отдельного права: читает каждый, у кого есть доступ в CRM.
 */
class InstructionController extends CrmController
{
    use ReadsInstructions;

    protected function audience(): InstructionAudience
    {
        return InstructionAudience::CRM;
    }

    protected function pages(): string
    {
        return 'Crm/Pages/Instructions';
    }

    protected function routePrefix(): string
    {
        return 'crm.instructions';
    }
}
