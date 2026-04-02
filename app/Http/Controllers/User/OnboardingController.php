<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\UserQuestionnaire;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class OnboardingController extends Controller
{
    /**
     * Show the onboarding questionnaire.
     */
    public function show()
    {
        $user = Auth::user();
        $questionnaire = $user->questionnaire;

        // Если анкета уже заполнена — редирект на главную
        if ($questionnaire && $questionnaire->isCompleted()) {
            return redirect('/');
        }

        // Корневые категории товаров (только активные)
        $rootCategories = \App\Models\Category::whereNull('parent_id')
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->toArray();

        return Inertia::render('Auth/Onboarding', [
            'questionnaire' => $questionnaire,
            'rootCategories' => $rootCategories,
            'user' => [
                'name' => $user->name,
                'phone' => $user->phone,
                'country' => $user->country,
                'city' => $user->city,
            ],
        ]);
    }

    /**
     * Store the questionnaire data.
     */
    public function store(Request $request)
    {
        $user = Auth::user();

        $validated = $request->validate([
            // Личные данные пользователя (обязательные)
            'name' => 'required|string|max:255',
            'phone' => ['required', 'string', 'max:20', 'regex:/^\+[1-9]\d{6,14}$/'],
            'country' => 'required|string|in:RU,BY,KZ',
            'city' => 'required|string|max:255',
            // Анкета
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
        ]);

        // Сохраняем личные данные в User
        $user->update([
            'name' => $validated['name'] ?? $user->name,
            'phone' => $validated['phone'] ?? $user->phone,
            'country' => $validated['country'] ?? $user->country,
            'city' => $validated['city'] ?? $user->city,
        ]);

        // Сохраняем анкету
        $questionnaireData = collect($validated)
            ->except(['name', 'phone', 'country', 'city'])
            ->toArray();
        $questionnaireData['completed_at'] = now();

        $user->questionnaire()->updateOrCreate(
            ['user_id' => $user->id],
            $questionnaireData
        );

        return redirect('/')->with('success', 'Спасибо за заполнение анкеты! Добро пожаловать!');
    }

    /**
     * Skip the questionnaire.
     */
    public function skip()
    {
        $user = Auth::user();

        $user->questionnaire()->updateOrCreate(
            ['user_id' => $user->id],
            ['completed_at' => now()]
        );

        return redirect('/')->with('success', 'Добро пожаловать в Pecado!');
    }

    /**
     * Save personal info (intermediate save after step 1).
     */
    public function savePersonal(Request $request)
    {
        $user = Auth::user();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => ['required', 'string', 'max:20', 'regex:/^\+[1-9]\d{6,14}$/'],
            'country' => 'required|string|in:RU,BY,KZ',
            'city' => 'required|string|max:255',
        ]);

        $user->update($validated);

        return response()->json(['ok' => true]);
    }
}
