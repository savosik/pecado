{{--
    Разбор зарплаты: те же числа, что на экране, но по шагам и словами.

    Форма отвечает на «почему столько» без звонка руководителю, поэтому каждый
    шаг показывает не только результат, но и что было бы иначе: без вычета, без
    множителя, при закрытом плане. Считать здесь ничего нельзя — только показывать
    посчитанное снимком, иначе бумага и экран разойдутся.
--}}
@include('payroll._style')
@include('payroll._header')

@php
    $kpi = $calc['kpi'];
    $inputs = $calc['inputs'];
    $components = $calc['breakdown']['components'] ?? [];
    $salary = collect($components)->firstWhere('key', 'salary')['amount'] ?? 0;
    $bonus = $kpi['amount'] ?? 0;
    $extra = collect($components)->whereNotIn('key', ['salary', 'kpi_bonus'])->sum('amount');
    $ratio = ($inputs['plan'] ?? 0) > 0 ? $inputs['revenue'] / $inputs['plan'] : null;
    $advice = collect($calc['forecast']['advice'] ?? [])->where('feasible', '!==', false)->take(6);
    $rub = fn ($v, $d = 0) => number_format((float) $v, $d, ',', ' ').' ₽';
    $pct = fn ($v, $d = 0) => $v === null ? '—' : number_format((float) $v * 100, $d, ',', ' ').' %';
@endphp

<table class="cols">
    <tr>
        <td style="width: 46%">
            <div class="small muted">Итого за месяц</div>
            <div class="big">{{ $rub($calc['total'], 2) }}</div>
        </td>
        <td>
            <table>
                <tr><td>Оклад — фиксированная часть</td><td class="right num">{{ $rub($salary) }}</td></tr>
                <tr><td>Премия — зависит от работы</td><td class="right num">{{ $rub($bonus) }}</td></tr>
                @if (abs($extra) >= 1)
                    <tr><td>Прочие начисления</td><td class="right num">{{ $rub($extra) }}</td></tr>
                @endif
            </table>
        </td>
    </tr>
</table>

<h2>Из чего складывается зарплата</h2>
<p>
    Зарплата — это <b>оклад</b> плюс <b>премия</b>. Оклад не меняется никогда: сколько бы вы ни продали,
    он останется {{ $rub($salary) }}. Меняется только премия, и вся эта бумага — про неё.
</p>
<p>
    Премия считается от <b>базовой суммы {{ $rub($kpi['base'] ?? 0) }}</b>. Если выполнить план ровно на 100 %,
    продать всем плановым клиентам и не иметь просрочек — получится ровно базовая сумма. Дальше премия
    растёт, но не выше потолка {{ $rub($kpi['max_amount'] ?? 0) }}.
</p>

<h2>Как получилась ваша премия — шаг за шагом</h2>

<div class="step">
    <h3>Шаг 1. Сколько отгружено за месяц</h3>
    <p>
        Реализации по вашим клиентам: <b>{{ $rub($inputs['revenue'], 2) }}</b>.
        Считаются документы отгрузки, а не деньги в кассе: продали — засчиталось, даже если клиент ещё не заплатил.
    </p>
</div>

<div class="step">
    <h3>Шаг 2. Вычитаем задержки оплат</h3>
    @if (($kpi['penalty'] ?? 0) > 0)
        <p>
            Клиенты закрыли часть накладных позже срока, поэтому из отгрузок вычли <b class="neg">{{ $rub($kpi['penalty'], 2) }}</b>.
            Вычет — это не удержание из зарплаты: он уменьшает выручку, которая идёт в зачёт плана.
            Размер зависит от опоздания: задержка до 2 рабочих дней — бесплатно, 3–7 дней — полтора размера накладной,
            8 дней и больше — тройной.
        </p>
        <p>Осталось в зачёт плана: <b>{{ $rub($kpi['adjusted'], 2) }}</b>.</p>
        @if (($kpi['without_penalty'] ?? null) !== null)
            <p class="muted">Без единой задержки премия была бы {{ $rub($kpi['without_penalty'], 2) }} — разница {{ $rub($kpi['without_penalty'] - $bonus, 2) }}.</p>
        @endif
    @else
        <p>Все оплаты пришли в срок — вычитать нечего. В зачёт плана идут все {{ $rub($inputs['revenue'], 2) }}.</p>
    @endif
</div>

<div class="step">
    <h3>Шаг 3. Сравниваем с планом</h3>
    <p>
        План месяца — {{ $rub($inputs['plan'] ?? 0, 2) }}. Отгружено {{ $pct($ratio, 1) }} плана,
        но после вычета за задержки в зачёт идёт <b>{{ $pct(($inputs['plan'] ?? 0) > 0 ? ($kpi['adjusted'] ?? 0) / $inputs['plan'] : null, 1) }}</b>
        — {{ $rub($kpi['adjusted'] ?? 0, 2) }}. Именно это число сравнивается с планом.
    </p>
    <div class="bar"><div style="width: {{ min(100, (int) round((($inputs['plan'] ?? 0) > 0 ? ($kpi['adjusted'] ?? 0) / $inputs['plan'] : 0) * 100)) }}%"></div></div>
    @if ($ratio !== null && $ratio < 1)
        <p class="small muted">До 100 % не хватает {{ $rub($inputs['remaining'], 2) }} отгрузок.</p>
    @endif
</div>

<div class="step">
    <h3>Шаг 4. Умножаем на охват клиентов</h3>
    <p>
        Из {{ $inputs['planned_count'] }} клиентов с планом купили <b>{{ $inputs['active_count'] }}</b> —
        это {{ $pct($inputs['active_share']) }}. За такой охват положен множитель
        <b>× {{ rtrim(rtrim(number_format($kpi['multiplier'] ?? 1, 2, ',', ' '), '0'), ',') }}</b>, и он умножает премию целиком.
    </p>
    <p class="muted">
        Поэтому один новый клиент иногда стоит дороже крупной отгрузки: он может поднять ступень множителя,
        а ступень действует на всю премию сразу.
        @if (($kpi['without_multiplier'] ?? null) !== null && $kpi['without_multiplier'] > $bonus)
            При множителе ×1 премия была бы {{ $rub($kpi['without_multiplier'], 2) }}.
        @endif
    </p>
</div>

<div class="step">
    <h3>Шаг 5. Считаем премию</h3>
    <p>
        Коэффициент: {{ $pct(($inputs['plan'] ?? 0) > 0 ? ($kpi['adjusted'] ?? 0) / $inputs['plan'] : null, 1) }}
        × {{ rtrim(rtrim(number_format($kpi['multiplier'] ?? 1, 2, ',', ' '), '0'), ',') }}
        = <b>{{ number_format($kpi['performance'] ?? 0, 4, ',', ' ') }}</b>.
    </p>
    <p>
        Премия: {{ $rub($kpi['base'] ?? 0) }} × {{ number_format($kpi['performance'] ?? 0, 4, ',', ' ') }}
        = <b>{{ $rub($bonus, 2) }}</b>.
        @if ($kpi['capped'])
            Больше потолка {{ $rub($kpi['max_amount']) }} премия не начисляется — вы в него упёрлись.
        @endif
    </p>
</div>

<h2>Что уменьшило премию и сколько это стоило</h2>
<table>
    <thead><tr><th>Причина</th><th style="width: 26%">Как есть сейчас</th><th class="right" style="width: 18%">Цена, ₽</th></tr></thead>
    <tbody>
        @if ($ratio !== null && $ratio < 1)
            @php $planLoss = collect($calc['forecast']['advice'] ?? [])->firstWhere('key', 'plan_gap')['gain'] ?? null; @endphp
            <tr>
                <td>План не закрыт</td>
                <td>отгружено {{ $pct($ratio) }} плана</td>
                <td class="right num neg">{{ $planLoss ? '−'.$rub($planLoss) : '—' }}</td>
            </tr>
        @endif
        @if (($kpi['without_penalty'] ?? 0) > $bonus)
            <tr>
                <td>Клиенты платили с задержкой</td>
                <td>вычтено {{ $rub($kpi['penalty']) }} из выручки</td>
                <td class="right num neg">−{{ $rub($kpi['without_penalty'] - $bonus) }}</td>
            </tr>
        @endif
        @if (($kpi['without_multiplier'] ?? 0) > $bonus)
            <tr>
                <td>Часть плановых клиентов не купила</td>
                <td>{{ $inputs['active_count'] }} из {{ $inputs['planned_count'] }}</td>
                <td class="right num neg">−{{ $rub($kpi['without_multiplier'] - $bonus) }}</td>
            </tr>
        @endif
    </tbody>
</table>
<p class="small muted">
    Цены не складываются в одну сумму: показатели в формуле перемножаются, поэтому каждая строка отвечает
    на свой вопрос — «сколько прибавится, если поправить только это».
</p>

@if ($advice->isNotEmpty())
    <h2>Что можно сделать и сколько это даст</h2>
    <table>
        <thead><tr><th>Действие</th><th class="right" style="width: 18%">Прибавка, ₽</th></tr></thead>
        <tbody>
            @foreach ($advice as $item)
                <tr>
                    <td>
                        {{ $item['title'] }}
                        <div class="muted small">{{ $item['detail'] }}</div>
                        @if (count($item['affects'] ?? []) > 1)
                            <span class="tag">двойной эффект: и выручка, и охват</span>
                        @endif
                    </td>
                    <td class="right num {{ ($item['protective'] ?? false) ? 'neg' : 'pos' }}">
                        {{ ($item['protective'] ?? false) ? 'сохранит '.$rub($item['gain']) : '+'.$rub($item['gain']) }}
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

@php
    $penalized = collect($calc['breakdown']['components'] ?? [])
        ->firstWhere('key', 'kpi_bonus')['children'] ?? [];
    $penaltyEvidence = collect($penalized)->firstWhere('key', 'discipline_penalty')['evidence'] ?? [];
    $byPartner = collect($penaltyEvidence)
        ->groupBy('partner_name')
        ->map(fn ($rows, $name) => [
            'name' => $name,
            'count' => $rows->count(),
            'amount' => $rows->sum('amount'),
            'penalty' => $rows->sum('penalty'),
        ])
        ->sortByDesc('penalty')
        ->take(8);
@endphp

@if ($byPartner->isNotEmpty())
    <h2>Кто платил с задержкой</h2>
    <table>
        <thead>
            <tr>
                <th>Клиент</th>
                <th class="right" style="width: 12%">Накладных</th>
                <th class="right" style="width: 20%">На сумму, ₽</th>
                <th class="right" style="width: 22%">Вычет из выручки, ₽</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($byPartner as $row)
                <tr>
                    <td>{{ $row['name'] }}</td>
                    <td class="right num">{{ $row['count'] }}</td>
                    <td class="right num">{{ $rub($row['amount']) }}</td>
                    <td class="right num neg">−{{ $rub($row['penalty']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

@php
    $clients = collect($inputs['planned_clients'] ?? []);
    $groups = [
        ['Перевыполнили план', $clients->filter(fn ($c) => ($c['plan'] ?? 0) > 0 && ($c['fact'] ?? 0) >= ($c['plan'] ?? 0) * 1.05)],
        ['Выполнили план', $clients->filter(fn ($c) => ($c['plan'] ?? 0) > 0 && ($c['fact'] ?? 0) > 0 && ($c['fact'] ?? 0) >= ($c['plan'] ?? 0) * 0.95 && ($c['fact'] ?? 0) < ($c['plan'] ?? 0) * 1.05)],
        ['Недовыполнили план', $clients->filter(fn ($c) => ($c['fact'] ?? 0) > 0 && ($c['fact'] ?? 0) < ($c['plan'] ?? 0) * 0.95)],
        ['Не заказали ни разу', $clients->filter(fn ($c) => ! (($c['fact'] ?? 0) > 0))],
    ];
@endphp

<h2>Плановые клиенты месяца</h2>
<table>
    <thead>
        <tr>
            <th>Группа</th>
            <th class="right" style="width: 12%">Клиентов</th>
            <th class="right" style="width: 22%">Отгружено, ₽</th>
            <th class="right" style="width: 22%">Надо было, ₽</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($groups as [$label, $list])
            <tr>
                <td>{{ $label }}</td>
                <td class="right num">{{ $list->count() }}</td>
                <td class="right num">{{ $rub($list->sum('fact')) }}</td>
                <td class="right num muted">{{ $rub($list->sum('plan')) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>
<p class="small muted">
    В множитель премии идёт число клиентов с отгрузкой, а не сумма: клиент из последней строки стоит дороже,
    чем добавка к тому, кто и так купил.
</p>

<div class="foot">
    @if ($calc['status'] === 'draft')
        Это черновик: месяц не закрыт, каждая новая отгрузка и оплата меняют суммы.
    @else
        Расчёт {{ mb_strtolower($calc['status_label']) }} и заморожен: изменения в него больше не попадают.
    @endif
    Данные на {{ $calc['computed_at'] ? \Carbon\Carbon::parse($calc['computed_at'])->format('d.m.Y H:i') : '—' }}.
    Все суммы посчитаны тем же расчётом, что показан в разделе «Моя зарплата», — расхождений быть не может.
</div>
