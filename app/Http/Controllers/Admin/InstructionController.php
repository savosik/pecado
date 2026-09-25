<?php

namespace App\Http\Controllers\Admin;

use App\Enums\InstructionAudience;
use App\Enums\InstructionType;
use App\Http\Controllers\Admin\Traits\RedirectsAfterSave;
use App\Http\Requests\Admin\InstructionRequest;
use App\Models\Instruction;
use App\Services\Content\InstructionPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Инструкции в админке: что показываем клиентам, менеджерам и складу.
 *
 * Читальные разделы (кабинет, CRM, WMS) только показывают — правят здесь.
 */
class InstructionController extends Controller
{
    use RedirectsAfterSave;

    public function __construct(private readonly InstructionPresenter $presenter) {}

    public function index(Request $request): Response
    {
        $query = Instruction::query();

        if ($search = trim((string) $request->input('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('short_description', 'like', "%{$search}%");
            });
        }

        if ($audience = InstructionAudience::tryFrom((string) $request->input('audience'))) {
            $query->forAudience($audience);
        }

        if ($type = InstructionType::tryFrom((string) $request->input('type'))) {
            $query->where('type', $type->value);
        }

        $sortBy = in_array($request->input('sort_by'), ['id', 'title', 'type', 'updated_at', 'created_at'], true)
            ? $request->input('sort_by')
            : 'updated_at';
        $sortOrder = $request->input('sort_order') === 'asc' ? 'asc' : 'desc';

        $instructions = $query->orderBy($sortBy, $sortOrder)
            ->paginate((int) $request->input('per_page', 15))
            ->withQueryString()
            ->through(fn (Instruction $i) => $this->presenter->admin($i));

        return Inertia::render('Admin/Pages/Instructions/Index', [
            'instructions' => $instructions,
            'filters' => $request->only(['search', 'audience', 'type', 'sort_by', 'sort_order', 'per_page']),
            'options' => $this->options(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/Pages/Instructions/Create', [
            'options' => $this->options(),
        ]);
    }

    public function store(InstructionRequest $request): RedirectResponse
    {
        $instruction = Instruction::create($this->attributes($request));
        $this->syncMedia($request, $instruction);

        return $this->redirectAfterSave($request, 'admin.instructions.index', 'admin.instructions.edit', $instruction, 'Инструкция создана');
    }

    public function edit(Instruction $instruction): Response
    {
        return Inertia::render('Admin/Pages/Instructions/Edit', [
            'instruction' => $this->presenter->admin($instruction),
            'options' => $this->options(),
        ]);
    }

    public function update(InstructionRequest $request, Instruction $instruction): RedirectResponse
    {
        $instruction->update($this->attributes($request));
        $this->syncMedia($request, $instruction);

        // Смена файла или обложки — тоже обновление, читатель должен видеть дату.
        $instruction->touch();

        return $this->redirectAfterSave($request, 'admin.instructions.index', 'admin.instructions.edit', $instruction, 'Инструкция обновлена');
    }

    public function destroy(Instruction $instruction): RedirectResponse
    {
        $instruction->delete();

        return redirect()->route('admin.instructions.index')->with('success', 'Инструкция удалена');
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(InstructionRequest $request): array
    {
        $type = InstructionType::from($request->input('type'));
        $audiences = array_map('strval', (array) $request->input('audiences', []));

        $attributes = [
            'title' => $request->input('title'),
            'short_description' => $request->input('short_description') ?: null,
            'type' => $type,
            'is_published' => $request->boolean('is_published', true),
            // Тело другого формата не храним: переключили текст на PDF —
            // старые блоки не должны всплыть при обратном переключении как «актуальные».
            'content' => $type === InstructionType::TEXT ? $request->input('content') : null,
            'video_url' => $type === InstructionType::VIDEO ? ($request->input('video_url') ?: null) : null,
        ];

        foreach (InstructionAudience::cases() as $audience) {
            $attributes[$audience->column()] = in_array($audience->value, $audiences, true);
        }

        return $attributes;
    }

    private function syncMedia(InstructionRequest $request, Instruction $instruction): void
    {
        $type = $instruction->type;

        if ($request->boolean('remove_cover')) {
            $instruction->clearMediaCollection(Instruction::COLLECTION_COVER);
        }
        if ($request->hasFile('cover')) {
            $instruction->clearMediaCollection(Instruction::COLLECTION_COVER);
            $instruction->addMediaFromRequest('cover')->toMediaCollection(Instruction::COLLECTION_COVER);
        }

        // Файл чужого формата не нужен: PDF у видеоинструкции только путает.
        if ($type !== InstructionType::PDF || $request->boolean('remove_file')) {
            $instruction->clearMediaCollection(Instruction::COLLECTION_FILE);
        }
        if ($type === InstructionType::PDF && $request->hasFile('pdf')) {
            $instruction->clearMediaCollection(Instruction::COLLECTION_FILE);
            $instruction->addMediaFromRequest('pdf')->toMediaCollection(Instruction::COLLECTION_FILE);
        }

        if ($type !== InstructionType::VIDEO || $request->boolean('remove_video')) {
            $instruction->clearMediaCollection(Instruction::COLLECTION_VIDEO);
        }
        if ($type === InstructionType::VIDEO && $request->hasFile('video')) {
            $instruction->clearMediaCollection(Instruction::COLLECTION_VIDEO);
            $instruction->addMediaFromRequest('video')->toMediaCollection(Instruction::COLLECTION_VIDEO);
        }
    }

    /**
     * Справочники формы и фильтров.
     *
     * @return array<string, list<array<string, string>>>
     */
    private function options(): array
    {
        return [
            'types' => array_map(fn (InstructionType $t) => [
                'value' => $t->value,
                'label' => $t->label(),
                'color' => $t->color(),
            ], InstructionType::cases()),
            'audiences' => array_map(fn (InstructionAudience $a) => [
                'value' => $a->value,
                'label' => $a->label(),
                'color' => $a->color(),
            ], InstructionAudience::cases()),
        ];
    }
}
