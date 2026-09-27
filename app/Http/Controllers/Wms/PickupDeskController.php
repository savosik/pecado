<?php

namespace App\Http\Controllers\Wms;

use App\Models\Pickup\PickupDeskDay;
use App\Models\Pickup\PickupDeskPause;
use App\Services\Pickup\PickupDeskSchedule;
use App\Services\Warehouse\WarehouseSchedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Стойка выдачи самовывоза (pick-18): выдают ли сейчас, «Отойти»/«Вернулся» и график по дням недели.
 *
 * Статус и отлучки — JSON для экрана выдачи (телефон у стойки). График — одна таблица «день недели →
 * часы и перерывы», которую начальник склада правит прямо в ней; она же уходит курьеру и клиенту.
 */
class PickupDeskController extends WmsController
{
    public const REASONS = ['обед', 'почта', 'отгрузка', 'приёмка', 'другое'];

    private const DAY_NAMES = [1 => 'Понедельник', 2 => 'Вторник', 3 => 'Среда', 4 => 'Четверг', 5 => 'Пятница', 6 => 'Суббота', 7 => 'Воскресенье'];

    public function __construct(
        private readonly PickupDeskSchedule $desk,
        private readonly WarehouseSchedule $schedule,
    ) {}

    public function status(): JsonResponse
    {
        $this->ensureEnabled();

        return response()->json(['desk' => $this->desk->summary(now())]);
    }

    /** «Отойти»: на сколько и зачем. Стойка закрыта до срока — это видят курьер и клиент. */
    public function pause(Request $request): JsonResponse
    {
        $this->ensureEnabled();
        $data = $request->validate([
            'minutes' => ['required_without:until_close', 'nullable', 'integer', 'min:5', 'max:720'],
            'until_close' => ['nullable', 'boolean'],
            'reason' => ['required', 'string', 'max:60'],
        ], [], ['minutes' => 'минуты', 'reason' => 'причина']);

        $now = now();
        $until = ! empty($data['until_close'])
            ? ($this->schedule->closesAt($now) ?? $now->copy()->endOfDay())
            : $now->copy()->addMinutes((int) $data['minutes']);

        if ($until->lte($now)) {
            return response()->json(['message' => 'Склад уже закрыт — отмечать отлучку не нужно'], 422);
        }

        // Повторное «Отойти» переписывает срок, а не плодит записи.
        PickupDeskPause::query()->active($now)->update(['ended_at' => $now]);

        $pause = PickupDeskPause::create([
            'user_id' => $this->wmsActor($request)->id,
            'reason' => mb_strtolower(trim($data['reason'])),
            'started_at' => $now,
            'until_at' => $until,
        ]);

        return response()->json([
            'ok' => true,
            'message' => 'Выдача закрыта до '.$pause->until_at->format('H:i').' — курьеры это видят',
            'desk' => $this->desk->summary($now),
        ]);
    }

    /** «Вернулся»: гасит действующую отлучку. */
    public function resume(): JsonResponse
    {
        $this->ensureEnabled();
        $ended = PickupDeskPause::query()->active()->update(['ended_at' => now()]);

        return response()->json([
            'ok' => true,
            'message' => $ended > 0 ? 'Выдача открыта' : 'Открытых отлучек нет',
            'desk' => $this->desk->summary(now()),
        ]);
    }

    // ---- график: одна таблица по дням недели (начальник склада)

    public function schedule(): Response
    {
        $this->ensureEnabled();

        return Inertia::render('Wms/Pages/Pickups/Schedule', $this->schedulePayload());
    }

    /** Правка строки дня прямо в таблице: работает ли, часы, перерывы. */
    public function updateDay(Request $request, int $iso): JsonResponse
    {
        $this->ensureEnabled();
        abort_unless($iso >= 1 && $iso <= 7, 404);

        $data = $request->validate([
            'works' => ['required', 'boolean'],
            'opens_at' => ['required_if:works,true', 'nullable', 'date_format:H:i'],
            'closes_at' => ['required_if:works,true', 'nullable', 'date_format:H:i', 'after:opens_at'],
            'breaks' => ['present', 'array', 'max:10'],
            'breaks.*.from' => ['required', 'date_format:H:i'],
            'breaks.*.to' => ['required', 'date_format:H:i'],
            'breaks.*.label' => ['nullable', 'string', 'max:60'],
        ], [
            'closes_at.after' => 'Закрытие должно быть позже открытия',
        ], [
            'opens_at' => 'открытие', 'closes_at' => 'закрытие', 'breaks' => 'перерывы',
            'breaks.*.from' => 'начало перерыва', 'breaks.*.to' => 'конец перерыва', 'breaks.*.label' => 'подпись',
        ]);

        $breaks = [];
        foreach ($data['breaks'] as $i => $b) {
            if ($b['to'] <= $b['from']) {
                return response()->json(['message' => 'Конец перерыва должен быть позже начала', 'errors' => ["breaks.$i.to" => ['Конец перерыва должен быть позже начала']]], 422);
            }
            if ($data['works'] && ($b['from'] < $data['opens_at'] || $b['to'] > $data['closes_at'])) {
                return response()->json(['message' => 'Перерыв выходит за часы выдачи', 'errors' => ["breaks.$i.from" => ['Перерыв выходит за часы выдачи']]], 422);
            }
            $breaks[] = ['from' => $b['from'], 'to' => $b['to'], 'label' => mb_strtolower(trim((string) ($b['label'] ?? '')))];
        }
        usort($breaks, fn (array $a, array $b) => strcmp($a['from'], $b['from']));

        PickupDeskDay::query()->updateOrCreate(['iso_weekday' => $iso], [
            'works' => $data['works'],
            'opens_at' => $data['works'] ? $data['opens_at'] : null,
            'closes_at' => $data['works'] ? $data['closes_at'] : null,
            'breaks' => $breaks,
        ]);
        WarehouseSchedule::forgetWeek();

        return response()->json(['ok' => true, 'message' => self::DAY_NAMES[$iso].': сохранено', ...$this->schedulePayload()]);
    }

    /** @return array<string, mixed> */
    private function schedulePayload(): array
    {
        // Свежий сервис: часы кешируются на запрос, а мы их только что поменяли.
        $schedule = new WarehouseSchedule;
        $desk = new PickupDeskSchedule($schedule);
        $monday = now($schedule->timezone())->startOfWeek();
        $rows = PickupDeskDay::query()->get()->keyBy('iso_weekday');

        $days = [];
        for ($iso = 1; $iso <= 7; $iso++) {
            $day = $monday->copy()->addDays($iso - 1);
            $hours = $schedule->hoursFor($day);
            $row = $rows->get($iso);
            $days[] = [
                'iso' => $iso,
                'name' => self::DAY_NAMES[$iso],
                'works' => $hours !== null,
                'opens_at' => $hours ? $hours[0]->format('H:i') : (PickupDeskDay::hm($row?->opens_at) ?? config("warehouse.week.$iso.0") ?? '09:00'),
                'closes_at' => $hours ? $hours[1]->format('H:i') : (PickupDeskDay::hm($row?->closes_at) ?? config("warehouse.week.$iso.1") ?? '21:00'),
                'breaks' => $desk->plannedBreaks($iso),
                // Итог, как его увидят курьер и клиент: перерывы обрезаны часами выдачи.
                'closed' => array_map(fn (array $w) => [
                    'from' => $w['from']->format('H:i'), 'to' => $w['to']->format('H:i'), 'label' => $w['label'],
                ], $hours ? $desk->closedWindows($day, false) : []),
            ];
        }

        return [
            'days' => $days,
            'weekText' => $desk->weekText(),
            'hoursText' => $schedule->weekText(),
            'reasons' => self::REASONS,
        ];
    }

    private function ensureEnabled(): void
    {
        abort_unless((bool) config('pickup.wms_enabled'), 404);
    }
}
