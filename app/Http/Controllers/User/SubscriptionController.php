<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\EntitySubscription;
use App\Models\NotificationSuppression;
use App\Services\Subscriptions\SubscriptionRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Универсальный CRUD подписок на изменения сущностей раздела кабинета.
 *
 * Один контроллер обслуживает любой раздел из App\Services\Subscriptions\
 * SubscriptionRegistry — фронт (SubscriptionPanel) передаёт ключ раздела в
 * URL. Сейчас поддержан канал email; telegram появится отдельной фазой.
 */
class SubscriptionController extends Controller
{
    /**
     * Максимум активных email-адресов на один раздел для пользователя.
     */
    private const MAX_PER_SECTION = 5;

    /**
     * Поля подписки, отдаваемые фронту.
     *
     * @var array<int, string>
     */
    private const PAYLOAD_FIELDS = ['id', 'section', 'channel', 'destination', 'events', 'is_active', 'created_at'];

    public function __construct(
        private readonly SubscriptionRegistry $registry,
    ) {}

    /**
     * Список активных подписок текущего пользователя по разделу.
     * Отписанные (is_active=false) не показываем — для владельца кабинета
     * их как будто нет; при повторном добавлении адрес реактивируется.
     */
    public function index(Request $request, string $section): JsonResponse
    {
        $this->ensureSection($section);

        $subscriptions = EntitySubscription::query()
            ->where('user_id', $request->user()->id)
            ->where('section', $section)
            ->where('is_active', true)
            ->orderByDesc('id')
            ->get(self::PAYLOAD_FIELDS);

        return response()->json([
            'data' => $subscriptions,
            'max' => self::MAX_PER_SECTION,
            'events' => $this->eventCatalog($section),
        ]);
    }

    /**
     * Добавить email-подписку на изменения раздела.
     *
     * Необязательный `events` — список типов событий раздела, на которые
     * подписывается адрес. Не передан (или выбраны все) — подписка на все
     * типы, включая те, что появятся в разделе позже.
     */
    public function store(Request $request, string $section): JsonResponse
    {
        $this->ensureSection($section);

        $data = Validator::make($request->all(), [
            'email' => ['required', 'string', 'email', 'max:255'],
            'events' => ['sometimes', 'nullable', 'array'],
            'events.*' => ['string', Rule::in($this->registry->eventKeys($section))],
        ], [
            'email.required' => 'Укажите email-адрес.',
            'email.email' => 'Неверный формат email-адреса.',
            'events.array' => 'Типы событий переданы в неверном формате.',
            'events.*.in' => 'Указан неизвестный тип события.',
        ])->validate();

        $email = mb_strtolower(trim($data['email']));
        $events = $this->registry->normalizeEvents($section, $data['events'] ?? null);

        $subscription = EntitySubscription::query()
            ->where('user_id', $request->user()->id)
            ->where('section', $section)
            ->where('channel', 'email')
            ->where('destination', $email)
            ->first();

        // Уже подписан и активен — новую строку не заводим, но набор типов
        // событий обновляем: повторное добавление того же адреса читается
        // как «переподписать его вот на это».
        if ($subscription && $subscription->is_active) {
            if (array_key_exists('events', $data)) {
                $subscription->update(['events' => $events]);
            }

            return response()->json(['data' => $subscription->only(self::PAYLOAD_FIELDS)], 200);
        }

        // Добавление нового адреса или реактивация отписанного увеличит число
        // активных — проверяем лимит.
        $activeCount = EntitySubscription::query()
            ->where('user_id', $request->user()->id)
            ->where('section', $section)
            ->where('channel', 'email')
            ->where('is_active', true)
            ->count();

        if ($activeCount >= self::MAX_PER_SECTION) {
            throw ValidationException::withMessages([
                'email' => 'Достигнут лимит: не более '.self::MAX_PER_SECTION.' адресов на раздел. Удалите один из существующих, чтобы добавить новый.',
            ]);
        }

        if ($subscription) {
            $subscription->update(['is_active' => true, 'events' => $events]);
        } else {
            $subscription = EntitySubscription::create([
                'user_id' => $request->user()->id,
                'section' => $section,
                'channel' => 'email',
                'destination' => $email,
                'events' => $events,
                'is_active' => true,
            ]);
        }

        return response()->json(['data' => $subscription->only(self::PAYLOAD_FIELDS)], 201);
    }

    /**
     * Изменить набор типов событий уже существующей подписки (только своей).
     *
     * Раздел берётся из самой подписки — фронту достаточно её id. Пустой
     * список запрещён: подписка без типов не имела бы смысла, для отказа от
     * уведомлений адрес удаляют.
     */
    public function update(Request $request, EntitySubscription $subscription): JsonResponse
    {
        if ($subscription->user_id !== $request->user()->id) {
            abort(404);
        }

        $eventKeys = $this->registry->eventKeys($subscription->section);

        $eventRules = $eventKeys === []
            ? ['nullable', 'array']
            : ['required', 'array', 'min:1'];

        $data = Validator::make($request->all(), [
            'events' => $eventRules,
            'events.*' => ['string', Rule::in($eventKeys)],
        ], [
            'events.required' => 'Выберите хотя бы один тип уведомлений.',
            'events.min' => 'Выберите хотя бы один тип уведомлений.',
            'events.array' => 'Типы событий переданы в неверном формате.',
            'events.*.in' => 'Указан неизвестный тип события.',
        ])->validate();

        $subscription->update([
            'events' => $this->registry->normalizeEvents($subscription->section, $data['events'] ?? null),
        ]);

        return response()->json(['data' => $subscription->only(self::PAYLOAD_FIELDS)]);
    }

    /**
     * Удалить подписку (только свою).
     */
    public function destroy(Request $request, EntitySubscription $subscription): JsonResponse
    {
        if ($subscription->user_id !== $request->user()->id) {
            abort(404);
        }

        $subscription->delete();

        return response()->json(['deleted' => true]);
    }

    /**
     * Публичная отписка по токену из письма (без авторизации).
     *
     * Токен принадлежит подписке кабинета. Адрес заодно попадает в стоп-лист:
     * человек нажал «отписаться», и было бы издевательством, если бы то же
     * письмо продолжало приходить по правилу-фильтру.
     */
    public function unsubscribe(string $token): \Illuminate\Http\Response
    {
        $subscription = EntitySubscription::query()
            ->where('unsubscribe_token', $token)
            ->first();

        if ($subscription && $subscription->is_active) {
            $subscription->update(['is_active' => false]);
        }

        if ($subscription && filled($subscription->destination)) {
            NotificationSuppression::updateOrCreate(
                [
                    'email' => mb_strtolower(trim($subscription->destination)),
                    'scope' => NotificationSuppression::SCOPE_ALL,
                ],
                [
                    'reason' => NotificationSuppression::REASON_UNSUBSCRIBED,
                    'note' => 'Отписка по ссылке из письма',
                ],
            );
        }

        $sectionLabel = $subscription
            ? $this->registry->label($subscription->section)
            : null;

        return response()->view('subscriptions.unsubscribed', [
            'found' => (bool) $subscription,
            'sectionLabel' => $sectionLabel,
            'destination' => $subscription?->destination,
        ]);
    }

    /**
     * Каталог типов событий раздела для фронта (порядок — как в конфиге).
     *
     * @return array<int, array{value: string, label: string, description: string}>
     */
    private function eventCatalog(string $section): array
    {
        $catalog = [];

        foreach ($this->registry->events($section) as $key => $meta) {
            $catalog[] = [
                'value' => (string) $key,
                'label' => (string) ($meta['label'] ?? $key),
                'description' => (string) ($meta['description'] ?? ''),
            ];
        }

        return $catalog;
    }

    private function ensureSection(string $section): void
    {
        if (! $this->registry->exists($section)) {
            abort(404);
        }
    }
}
