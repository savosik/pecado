<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\UserSearchPreset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CRUD для сохранённых поисков (PR 5.1).
 * Все методы за флагом `search-cabinet.presets` — при выключенном флаге
 * возвращают 404, чтобы фронт «не знал» о существовании эндпойнта.
 */
class SearchPresetController extends Controller
{
    /**
     * Допустимые секции — синхронизированы с разделами кабинета. Запросы
     * с произвольной строкой отклоняются, чтобы не плодить мусорные section'ы.
     */
    private const SECTIONS = [
        'orders',
        'returns',
        'shipments',
        'favorites',
        'cart-products',
        'media',
        'carts-list',
        'product-exports',
    ];

    public function index(Request $request, string $section): JsonResponse
    {
        $this->ensureEnabled();
        $this->ensureSection($section);

        $presets = UserSearchPreset::query()
            ->where('user_id', $request->user()->id)
            ->where('section', $section)
            ->orderByDesc('id')
            ->get(['id', 'section', 'name', 'filters', 'created_at']);

        return response()->json(['data' => $presets]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->ensureEnabled();

        $data = $request->validate([
            'section' => ['required', 'string', 'in:'.implode(',', self::SECTIONS)],
            'name' => ['required', 'string', 'max:120'],
            'filters' => ['required', 'array'],
        ]);

        $preset = UserSearchPreset::create([
            'user_id' => $request->user()->id,
            'section' => $data['section'],
            'name' => $data['name'],
            'filters' => $data['filters'],
        ]);

        return response()->json(['data' => $preset->only([
            'id', 'section', 'name', 'filters', 'created_at',
        ])], 201);
    }

    public function destroy(Request $request, UserSearchPreset $preset): JsonResponse
    {
        $this->ensureEnabled();

        if ($preset->user_id !== $request->user()->id) {
            abort(404);
        }

        $preset->delete();

        return response()->json(['deleted' => true]);
    }

    private function ensureEnabled(): void
    {
        if (! (bool) config('search-cabinet.presets')) {
            abort(404);
        }
    }

    private function ensureSection(string $section): void
    {
        if (! in_array($section, self::SECTIONS, true)) {
            abort(404);
        }
    }
}
