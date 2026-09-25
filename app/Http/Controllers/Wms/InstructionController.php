<?php

namespace App\Http\Controllers\Wms;

use App\Enums\InstructionAudience;
use App\Http\Controllers\Concerns\ReadsInstructions;
use App\Http\Controllers\Controller;

/**
 * Инструкции для склада. Без отдельного права: читает каждый, у кого есть доступ в WMS.
 */
class InstructionController extends Controller
{
    use ReadsInstructions;

    protected function audience(): InstructionAudience
    {
        return InstructionAudience::WMS;
    }

    protected function pages(): string
    {
        return 'Wms/Pages/Instructions';
    }

    protected function routePrefix(): string
    {
        return 'wms.instructions';
    }
}
