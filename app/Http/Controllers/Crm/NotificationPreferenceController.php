<?php

namespace App\Http\Controllers\Crm;

use App\Models\User;
use App\Services\Crm\CrmEntitySearch;
use App\Services\Notifications\NotificationCatalog;
use App\Services\Notifications\NotificationMatrix;
use App\Services\Notifications\NotificationSettings;
use App\Support\Notifications\Destination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Настройки уведомлений партнёра — вкладка в его карточке.
 *
 * Отдельного права нет намеренно: «вижу партнёра, но не вижу, что ему пишут» —
 * состояние, которого быть не должно. Заводить четвёртый реестр прав ради
 * одной вкладки тоже лишнее.
 */
class NotificationPreferenceController extends CrmController
{
    public function __construct(
        private readonly NotificationMatrix $matrix,
        private readonly NotificationSettings $settings,
        private readonly NotificationCatalog $catalog,
    ) {}

    public function index(Request $request, int $client): JsonResponse
    {
        $partner = $this->partner($request, $client);

        return response()->json($this->matrix->forManager($partner));
    }

    public function update(Request $request, int $client): JsonResponse
    {
        $actor = $this->crmActor($request);
        $partner = $this->partner($request, $client);

        abort_unless($actor->can('crm-clients.edit'), 403);

        $validated = $request->validate([
            'occasion_key' => ['required', 'string', Rule::in(array_keys($this->catalog->all()))],
            'is_enabled' => ['required', 'boolean'],
            'destinations' => ['present', 'array', 'max:20'],
            'destinations.*.type' => ['required', 'string', Rule::in(Destination::types())],
            'destinations.*.email' => ['nullable', 'email', 'max:255'],
            'destinations.*.role' => ['nullable', 'string'],
            'destinations.*.contact_id' => ['nullable', 'integer'],
            'options' => ['nullable', 'array'],
            'options.subtypes' => ['nullable', 'array'],
            'options.subtypes.*' => ['string'],
        ], [
            'occasion_key.in' => 'Неизвестный тип уведомления.',
            'destinations.*.type.in' => 'Неизвестный тип адресата.',
            'destinations.*.email.email' => 'Проверьте адрес: он не похож на электронную почту.',
            'destinations.max' => 'Больше двадцати адресатов на одно уведомление — верный признак, что нужен список рассылки.',
        ]);

        $this->settings->save(
            $partner,
            $validated['occasion_key'],
            (bool) $validated['is_enabled'],
            $this->guardDestinations($actor, $partner, (array) $validated['destinations']),
            (array) ($validated['options'] ?? []),
            $actor,
        );

        return response()->json($this->matrix->forManager($partner->refresh()));
    }

    /**
     * Переключить рассылки. Отдельным действием, а не строкой матрицы:
     * они не повод, и хранятся не в настройках, а в стоп-листе.
     */
    public function marketing(Request $request, int $client): JsonResponse
    {
        $actor = $this->crmActor($request);
        $partner = $this->partner($request, $client);

        abort_unless($actor->can('crm-clients.edit'), 403);

        $this->matrix->setMarketing($partner, (bool) $request->boolean('enabled'));

        return response()->json($this->matrix->forManager($partner));
    }

    /**
     * Люди партнёра для выбора адресата.
     */
    public function contacts(Request $request, int $client, CrmEntitySearch $search): JsonResponse
    {
        $partner = $this->partner($request, $client);

        return response()->json([
            'options' => $this->matrix->contactOptions($partner, trim((string) $request->string('search'))),
        ]);
    }

    /**
     * Чужой контакт в адресаты не попадает: иначе через настройку одного
     * партнёра утекают адреса другого.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function guardDestinations(User $actor, User $partner, array $rows): array
    {
        $allowed = $this->matrix->contactIdsOf($partner);

        return array_values(array_filter($rows, function (array $row) use ($allowed): bool {
            if (($row['type'] ?? '') !== Destination::CONTACT) {
                return true;
            }

            return in_array((int) ($row['contact_id'] ?? 0), $allowed, true);
        }));
    }

    private function partner(Request $request, int $client): User
    {
        return User::query()
            ->visibleInCrm($this->crmActor($request))
            ->findOrFail($client);
    }
}
