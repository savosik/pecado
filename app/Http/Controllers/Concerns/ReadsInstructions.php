<?php

namespace App\Http\Controllers\Concerns;

use App\Enums\InstructionAudience;
use App\Models\Instruction;
use App\Services\Content\InstructionPresenter;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Читальный раздел «Инструкции» — один код для кабинета, CRM и WMS.
 *
 * Контроллер панели задаёт три вещи: аудиторию, каталог страниц Inertia
 * и префикс маршрутов. Всё остальное — отбор опубликованного по флагу
 * аудитории и 404 на чужое — общее: инструкция для склада не должна
 * открываться клиенту по угаданному id.
 */
trait ReadsInstructions
{
    abstract protected function audience(): InstructionAudience;

    /** Каталог страниц Inertia, например `User/Cabinet/Instructions`. */
    abstract protected function pages(): string;

    /** Префикс имён маршрутов, например `cabinet.instructions`. */
    abstract protected function routePrefix(): string;

    public function index(Request $request): Response
    {
        $presenter = app(InstructionPresenter::class);

        $instructions = Instruction::query()
            ->published()
            ->forAudience($this->audience())
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate(12)
            ->withQueryString()
            ->through(fn (Instruction $i) => $presenter->card($i, route($this->routePrefix().'.show', $i)));

        return Inertia::render($this->pages().'/Index', [
            'instructions' => $instructions,
        ]);
    }

    public function show(Instruction $instruction): Response
    {
        abort_unless($instruction->is_published && $instruction->isFor($this->audience()), 404);

        return Inertia::render($this->pages().'/Show', [
            'instruction' => app(InstructionPresenter::class)->detail(
                $instruction,
                route($this->routePrefix().'.show', $instruction),
            ),
            'backUrl' => route($this->routePrefix().'.index'),
        ]);
    }
}
