<?php

namespace App\Http\Controllers\User;

use App\Enums\InstructionAudience;
use App\Http\Controllers\Concerns\ReadsInstructions;
use App\Http\Controllers\Controller;

/**
 * Инструкции для клиентов в личном кабинете: как оформить заказ, резерв, самовывоз и т.п.
 */
class CabinetInstructionController extends Controller
{
    use ReadsInstructions;

    protected function audience(): InstructionAudience
    {
        return InstructionAudience::CLIENT;
    }

    protected function pages(): string
    {
        return 'User/Cabinet/Instructions';
    }

    protected function routePrefix(): string
    {
        return 'cabinet.instructions';
    }
}
