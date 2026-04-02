<?php

namespace App\Http\Controllers\Admin;

use App\Models\UserQuestionnaire;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use App\Http\Controllers\Admin\Traits\RedirectsAfterSave;

class UserQuestionnaireController extends Controller
{
    use RedirectsAfterSave;

    public function index(Request $request)
    {
        $query = UserQuestionnaire::query()
            ->with(['user']);

        // Поиск по имени пользователя
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('business_name', 'like', "%{$search}%")
                    ->orWhere('business_type', 'like', "%{$search}%")
                    ->orWhereHas('user', function ($q) use ($search) {
                        $q->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }

        // Фильтр по статусу заполнения
        if ($request->filled('status')) {
            if ($request->input('status') === 'completed') {
                $query->whereNotNull('completed_at');
            } else {
                $query->whereNull('completed_at');
            }
        }

        // Фильтр по типу бизнеса
        if ($request->filled('business_type')) {
            $query->where('business_type', $request->input('business_type'));
        }

        // Сортировка
        $sortBy = $request->input('sort_by', 'id');
        $sortOrder = $request->input('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        // Пагинация
        $perPage = $request->input('per_page', 15);
        $questionnaires = $query->paginate($perPage)->withQueryString();

        return Inertia::render('Admin/Pages/UserQuestionnaires/Index', [
            'questionnaires' => $questionnaires,
            'filters' => $request->only(['search', 'status', 'business_type', 'sort_by', 'sort_order', 'per_page']),
        ]);
    }

    public function edit(UserQuestionnaire $userQuestionnaire)
    {
        $userQuestionnaire->load(['user']);

        return Inertia::render('Admin/Pages/UserQuestionnaires/Edit', [
            'questionnaire' => $userQuestionnaire,
        ]);
    }

    public function update(Request $request, UserQuestionnaire $userQuestionnaire)
    {
        $validated = $request->validate([
            'business_type' => 'nullable|array',
            'business_name' => 'nullable|string|max:255',
            'website_url' => 'nullable|string|max:500',
            'years_in_business' => 'nullable|string|max:255',
            'monthly_order_volume' => 'nullable|string|max:255',
            'has_physical_store' => 'boolean',
            'store_count' => 'nullable|string|max:255',
            'product_categories' => 'nullable|array',
            'how_found_us' => 'nullable|string|max:255',
            'additional_info' => 'nullable|string|max:2000',
        ], [
            'business_name.max' => 'Название компании не должно превышать 255 символов',
            'website_url.max' => 'URL не должен превышать 500 символов',
            'additional_info.max' => 'Дополнительная информация не должна превышать 2000 символов',
        ]);

        $userQuestionnaire->update($validated);

        return $this->redirectAfterSave($request, 'admin.user-questionnaires.index', 'admin.user-questionnaires.edit', $userQuestionnaire, 'Анкета успешно обновлена');
    }

    public function destroy(UserQuestionnaire $userQuestionnaire)
    {
        $userQuestionnaire->delete();

        return redirect()->route('admin.user-questionnaires.index')->with('success', 'Анкета успешно удалена');
    }
}
