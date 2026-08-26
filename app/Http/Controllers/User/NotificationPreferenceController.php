<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Services\Notifications\NotificationCatalog;
use App\Services\Notifications\NotificationMatrix;
use App\Services\Notifications\NotificationSettings;
use App\Support\Notifications\Destination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Настройки уведомлений в кабинете партнёра.
 *
 * Главный критерий всего эпика: клиент должен настроить себе почту сам,
 * без объяснений. Именно на этом умерли два предыдущих подхода — они были
 * маршрутизаторами, которые нельзя показать никому, кроме разработчика.
 */
class NotificationPreferenceController extends Controller
{
    public function __construct(
        private readonly NotificationMatrix $matrix,
        private readonly NotificationSettings $settings,
        private readonly NotificationCatalog $catalog,
    ) {}

    public function index(Request $request): Response
    {
        return Inertia::render('User/Cabinet/Notifications/Index', [
            'matrix' => $this->matrix->forClient($request->user()),
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        return response()->json($this->matrix->forClient($request->user()));
    }

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'occasion_key' => ['required', 'string', Rule::in($this->catalog->clientVisibleKeys())],
            'is_enabled' => ['required', 'boolean'],
            'destinations' => ['present', 'array', 'max:10'],
            'destinations.*.type' => ['required', 'string', Rule::in([Destination::LOGIN, Destination::EMAIL])],
            'destinations.*.email' => ['nullable', 'email', 'max:255'],
            'options' => ['nullable', 'array'],
            'options.statuses' => ['nullable', 'array'],
            'options.statuses.*' => ['string'],
        ], [
            'occasion_key.in' => 'Такое уведомление настроить нельзя.',
            'destinations.*.type.in' => 'Выберите почту аккаунта или укажите другой адрес.',
            'destinations.*.email.email' => 'Проверьте адрес: он не похож на электронную почту.',
            'destinations.max' => 'Больше десяти адресов на одно уведомление — это уже рассылка.',
        ]);

        $this->settings->save(
            $user,
            $validated['occasion_key'],
            (bool) $validated['is_enabled'],
            (array) $validated['destinations'],
            (array) ($validated['options'] ?? []),
            $user,
            byClient: true,
        );

        return response()->json($this->matrix->forClient($user->refresh()));
    }
}
