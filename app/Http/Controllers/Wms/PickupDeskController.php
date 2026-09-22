<?php

namespace App\Http\Controllers\Wms;

use App\Models\Pickup\PickupDeskBreak;
use App\Models\Pickup\PickupDeskPause;
use App\Models\Pickup\PickupDeskStaff;
use App\Models\User;
use App\Services\Pickup\PickupDeskSchedule;
use App\Services\Warehouse\WarehouseSchedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Стойка выдачи самовывоза (pick-18): выдают ли сейчас, «Отойти»/«Вернулся» и график перерывов.
 *
 * Статус и отлучки — JSON для экрана выдачи (телефон у стойки). График (смена и плановые перерывы)
 * ведёт начальник склада на отдельной странице; расчёт «когда выдача закрыта» — {@see PickupDeskSchedule}.
 */
class PickupDeskController extends WmsController
{
    public const REASONS = ['обед', 'почта', 'отгрузка', 'приёмка', 'другое'];

    public function __construct(
        private readonly PickupDeskSchedule $desk,
        private readonly WarehouseSchedule $schedule,
    ) {}

    public function status(): JsonResponse
    {
        $this->ensureEnabled();

        return response()->json(['desk' => $this->desk->summary(now())]);
    }

    /** «Отойти»: кто, на сколько и зачем. Второй сотрудник смены при этом выдачу продолжает. */
    public function pause(Request $request): JsonResponse
    {
        $this->ensureEnabled();
        $data = $request->validate([
            'staff_id' => ['nullable', 'integer', Rule::exists('pickup_desk_staff', 'id')->where('active', true)],
            'minutes' => ['required_without:until_close', 'nullable', 'integer', 'min:5', 'max:720'],
            'until_close' => ['nullable', 'boolean'],
            'reason' => ['required', 'string', 'max:60'],
        ], [], ['staff_id' => 'сотрудник', 'minutes' => 'минуты', 'reason' => 'причина']);

        $now = now();
        $until = ! empty($data['until_close'])
            ? ($this->schedule->closesAt($now) ?? $now->copy()->endOfDay())
            : $now->copy()->addMinutes((int) $data['minutes']);

        if ($until->lte($now)) {
            return response()->json(['message' => 'Склад уже закрыт — отмечать отлучку не нужно'], 422);
        }

        // Повторное «Отойти» того же человека переписывает срок, а не плодит записи.
        PickupDeskPause::query()->active($now)
            ->when(isset($data['staff_id']), fn ($q) => $q->where('staff_id', $data['staff_id']), fn ($q) => $q->whereNull('staff_id'))
            ->update(['ended_at' => $now]);

        $pause = PickupDeskPause::create([
            'staff_id' => $data['staff_id'] ?? null,
            'user_id' => $this->wmsActor($request)->id,
            'reason' => mb_strtolower(trim($data['reason'])),
            'started_at' => $now,
            'until_at' => $until,
        ]);

        $summary = $this->desk->summary($now);
        $message = $summary['state'] === PickupDeskSchedule::STATE_BREAK
            ? 'Выдача закрыта до '.$pause->until_at->format('H:i').' — курьеры это видят'
            : 'Отмечено. На стойке остаётся коллега, выдача продолжается';

        return response()->json(['ok' => true, 'message' => $message, 'desk' => $summary]);
    }

    /** «Вернулся»: гасит отлучку (свою или указанную). */
    public function resume(Request $request): JsonResponse
    {
        $this->ensureEnabled();
        $data = $request->validate(['pause_id' => ['nullable', 'integer', 'exists:pickup_desk_pauses,id']]);

        $query = PickupDeskPause::query()->active();
        if (isset($data['pause_id'])) {
            $query->whereKey($data['pause_id']);
        }
        $ended = $query->update(['ended_at' => now()]);

        return response()->json([
            'ok' => true,
            'message' => $ended > 0 ? 'Выдача открыта' : 'Открытых отлучек нет',
            'desk' => $this->desk->summary(now()),
        ]);
    }

    // ---- график: смена и плановые перерывы (начальник склада)

    public function schedule(): Response
    {
        $this->ensureEnabled();

        return Inertia::render('Wms/Pages/Pickups/Schedule', $this->schedulePayload());
    }

    public function storeStaff(Request $request): JsonResponse
    {
        $this->ensureEnabled();
        $data = $this->validateStaff($request);
        $data['sort_order'] = (int) PickupDeskStaff::query()->max('sort_order') + 1;
        PickupDeskStaff::create($data);

        return response()->json(['ok' => true, 'message' => 'Сотрудник добавлен в смену', ...$this->schedulePayload()]);
    }

    public function updateStaff(Request $request, PickupDeskStaff $staff): JsonResponse
    {
        $this->ensureEnabled();
        $staff->update($this->validateStaff($request));

        return response()->json(['ok' => true, 'message' => 'Сохранено', ...$this->schedulePayload()]);
    }

    public function destroyStaff(PickupDeskStaff $staff): JsonResponse
    {
        $this->ensureEnabled();
        $staff->delete();

        return response()->json(['ok' => true, 'message' => 'Сотрудник убран из смены', ...$this->schedulePayload()]);
    }

    public function storeBreak(Request $request, PickupDeskStaff $staff): JsonResponse
    {
        $this->ensureEnabled();
        $staff->breaks()->create($this->validateBreak($request));

        return response()->json(['ok' => true, 'message' => 'Перерыв добавлен', ...$this->schedulePayload()]);
    }

    public function updateBreak(Request $request, PickupDeskBreak $break): JsonResponse
    {
        $this->ensureEnabled();
        $break->update($this->validateBreak($request));

        return response()->json(['ok' => true, 'message' => 'Сохранено', ...$this->schedulePayload()]);
    }

    public function destroyBreak(PickupDeskBreak $break): JsonResponse
    {
        $this->ensureEnabled();
        $break->delete();

        return response()->json(['ok' => true, 'message' => 'Перерыв удалён', ...$this->schedulePayload()]);
    }

    /** @return array<string, mixed> */
    private function validateStaff(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'weekdays' => ['required', 'array', 'min:1'],
            'weekdays.*' => ['integer', 'between:1,7'],
            'active' => ['nullable', 'boolean'],
        ], [], ['name' => 'имя', 'user_id' => 'учётка', 'weekdays' => 'дни недели']);
    }

    /** @return array<string, mixed> */
    private function validateBreak(Request $request): array
    {
        $data = $request->validate([
            'weekdays' => ['required', 'array', 'min:1'],
            'weekdays.*' => ['integer', 'between:1,7'],
            'starts_at' => ['required', 'date_format:H:i'],
            'ends_at' => ['required', 'date_format:H:i', 'after:starts_at'],
            'label' => ['required', 'string', 'max:60'],
            'active' => ['nullable', 'boolean'],
        ], ['ends_at.after' => 'Конец перерыва должен быть позже начала'], [
            'weekdays' => 'дни недели', 'starts_at' => 'начало', 'ends_at' => 'конец', 'label' => 'подпись',
        ]);
        $data['label'] = mb_strtolower(trim($data['label']));

        return $data;
    }

    /** @return array<string, mixed> */
    private function schedulePayload(): array
    {
        $names = [1 => 'Понедельник', 2 => 'Вторник', 3 => 'Среда', 4 => 'Четверг', 5 => 'Пятница', 6 => 'Суббота', 7 => 'Воскресенье'];
        $monday = now($this->schedule->timezone())->startOfWeek();

        // Недельный предпросмотр: когда по плану на стойке никого — то, что увидят курьер и клиент.
        $preview = [];
        for ($iso = 1; $iso <= 7; $iso++) {
            $day = $monday->copy()->addDays($iso - 1);
            $hours = $this->schedule->hoursFor($day);
            $preview[] = [
                'iso' => $iso,
                'name' => $names[$iso],
                'works' => $hours !== null,
                'hours' => $hours ? $hours[0]->format('H:i').'–'.$hours[1]->format('H:i') : null,
                'roster' => $this->desk->roster($day)->pluck('name')->values()->all(),
                'closed' => array_map(fn (array $w) => [
                    'from' => $w['from']->format('H:i'), 'to' => $w['to']->format('H:i'), 'label' => $w['label'],
                ], $hours ? $this->desk->closedWindows($day, false) : []),
            ];
        }

        return [
            'staff' => PickupDeskStaff::query()->with(['breaks', 'user:id,name'])->orderBy('sort_order')->orderBy('id')->get()
                ->map(fn (PickupDeskStaff $s) => [
                    'id' => $s->id,
                    'name' => $s->name,
                    'user_id' => $s->user_id,
                    'user_name' => $s->user?->name,
                    'weekdays' => array_map('intval', (array) $s->weekdays),
                    'active' => $s->active,
                    'breaks' => $s->breaks->map(fn (PickupDeskBreak $b) => [
                        'id' => $b->id,
                        'weekdays' => array_map('intval', (array) $b->weekdays),
                        'starts_at' => PickupDeskBreak::hm($b->starts_at),
                        'ends_at' => PickupDeskBreak::hm($b->ends_at),
                        'label' => $b->label,
                        'active' => $b->active,
                    ])->values()->all(),
                ])->values()->all(),
            'preview' => $preview,
            'weekText' => $this->desk->weekText(),
            // Учётки склада — чтобы «Отойти» на экране выдачи знало, кто нажал.
            'users' => User::query()->role(['storekeeper', 'warehouse-head', 'pickup-operator'])
                ->orderBy('name')->get(['id', 'name'])->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])->all(),
            'reasons' => self::REASONS,
        ];
    }

    private function ensureEnabled(): void
    {
        abort_unless((bool) config('pickup.wms_enabled'), 404);
    }
}
