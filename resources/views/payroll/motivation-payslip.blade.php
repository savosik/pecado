{{--
    Расчётный лист по Положению о мотивации 2.2 (карточка mot-31).

    Тот же снимок, что на экране, тот же сервис: бумага и страница разойтись
    не могут. Полный перечень отгрузок и накладных печатается целиком —
    на экране они свёрнуты по партнёрам, в PDF раскрыты (п. 11.2).
--}}
@include('payroll._style')
@include('payroll._header')

@php
    $calc = $slip['calculation'];
    $money = fn ($v, $d = 2) => number_format((float) $v, $d, ',', ' ');
    $date = fn ($iso) => $iso ? \Carbon\CarbonImmutable::parse($iso)->format('d.m.Y') : '—';
@endphp

<p class="small muted">
    Версия {{ $calc['version'] }} · {{ $calc['status_label'] }}
    @if ($calc['approved_at']) · утверждён {{ $date($calc['approved_at']) }} @endif
    @if (! $calc['frozen']) · предварительный расчёт: данные могут измениться до утверждения @endif
</p>

<table>
    <thead>
        <tr>
            <th style="width: 34%">Начисление</th>
            <th style="width: 8%">Пункт</th>
            <th>Как получено</th>
            <th class="right" style="width: 16%">Сумма, ₽</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($slip['lines'] as $line)
            <tr>
                <td>{{ $line['label'] }}</td>
                <td class="muted small">{{ $line['clause'] ?? '' }}</td>
                <td class="muted small">{{ $line['explanation'] ?? '' }}</td>
                <td class="right num {{ $line['amount'] < 0 ? 'neg' : '' }}">{{ $money($line['amount']) }}</td>
            </tr>
        @endforeach
        <tr class="total">
            <td colspan="3">Итого к начислению</td>
            <td class="right">{{ $money($calc['total']) }}</td>
        </tr>
    </tbody>
</table>

@if (! empty($slip['quarterly_share']))
    <h2>Квартальная премия отдела · {{ $slip['quarterly_share']['quarter_label'] }} (п. 7.6)</h2>
    <p>
        Премия отдела {{ $money($slip['quarterly_share']['bonus_amount']) }} ({{ $slip['quarterly_share']['status_label'] }}, ступень {{ $slip['quarterly_share']['step'] }}).
        @if ($slip['quarterly_share']['distributed'])
            Доля работника: <strong>{{ $money($slip['quarterly_share']['amount']) }}</strong>@if ($slip['quarterly_share']['reason']) — {{ $slip['quarterly_share']['reason'] }}@endif.
        @else
            Распределение между работниками ещё не сделано.
        @endif
        <span class="muted small">В переменную часть и в итог месяца не входит.</span>
    </p>
@endif

@if (($slip['plan']['amount'] ?? null) !== null)
    <h2>План и порог оплаты</h2>
    <table>
        <tr><td>Личный план месяца{{ $slip['plan']['reduced_by_absence'] ? ' (уменьшен по табелю, п. 10.1)' : '' }}</td><td class="right num">{{ $money($slip['plan']['amount']) }}</td></tr>
        <tr><td>Порог оплаты</td><td class="right num">{{ $money($slip['plan']['threshold']) }}</td></tr>
        <tr><td>Отгрузки закреплённой базы за вычетом возвратов</td><td class="right num">{{ $money($slip['plan']['shipped']) }}</td></tr>
    </table>
@endif

@foreach (['base' => 'Отгрузки закреплённой базы (П1)', 'new' => 'Отгрузки новым партнёрам (П2)'] as $group => $heading)
    @if ($slip['shipments'][$group] !== [])
        <h2>{{ $heading }}</h2>
        <table>
            <thead>
                <tr>
                    <th>Партнёр / документ</th>
                    <th style="width: 14%">Дата</th>
                    <th class="right" style="width: 18%">Сумма, ₽</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($slip['shipments'][$group] as $partner)
                    <tr>
                        <td><strong>{{ $partner['partner_name'] }}</strong></td>
                        <td></td>
                        <td class="right num"><strong>{{ $money($partner['amount']) }}</strong></td>
                    </tr>
                    @foreach ($partner['documents'] as $doc)
                        <tr>
                            <td class="small muted" style="padding-left: 14px">{{ $doc['number'] }}</td>
                            <td class="small muted">{{ $date($doc['date']) }}</td>
                            <td class="right num small">{{ $money($doc['amount']) }}</td>
                        </tr>
                    @endforeach
                @endforeach
            </tbody>
        </table>
    @endif
@endforeach

@if ($slip['returns'] !== [])
    <h2>Возвраты периода</h2>
    <table>
        <thead><tr><th>Документ</th><th>Партнёр</th><th style="width: 14%">Дата</th><th class="right" style="width: 18%">Сумма, ₽</th></tr></thead>
        <tbody>
            @foreach ($slip['returns'] as $row)
                <tr><td>{{ $row['number'] ?? '—' }}</td><td>{{ $row['partner_name'] }}</td><td>{{ $date($row['date']) }}</td><td class="right num neg">−{{ $money($row['amount']) }}</td></tr>
            @endforeach
        </tbody>
    </table>
@endif

@if ($slip['overdue'] !== [])
    <h2>Просроченная задолженность (К1)</h2>
    <table>
        <thead>
            <tr>
                <th>Накладная</th>
                <th>Партнёр</th>
                <th style="width: 12%">Срок</th>
                <th class="right" style="width: 8%">Дней</th>
                <th class="right" style="width: 16%">База, ₽·дн.</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($slip['overdue'] as $row)
                <tr>
                    <td>{{ $row['number'] }}{{ ($row['needs_review'] ?? false) ? ' *' : '' }}</td>
                    <td>{{ $row['partner_name'] }}</td>
                    <td>{{ $date($row['due_on'] ?? null) }}</td>
                    <td class="right num">{{ $row['days'] }}</td>
                    <td class="right num">{{ $money($row['integral'], 0) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    @if (collect($slip['overdue'])->contains(fn ($r) => $r['needs_review'] ?? false))
        <p class="small muted">* Дата погашения не восстановлена: начисление по состоянию «не погашено».</p>
    @endif
@endif

@if ($slip['excluded'] !== [])
    <h2>Выведено из базы начисления</h2>
    <table>
        <thead><tr><th>Накладная</th><th>Партнёр</th><th>Основание</th><th class="right" style="width: 12%">Дней</th></tr></thead>
        <tbody>
            @foreach ($slip['excluded'] as $row)
                <tr><td>{{ $row['number'] }}</td><td>{{ $row['partner_name'] }}</td><td class="small muted">{{ $row['exclusion_reason'] ?? '' }}</td><td class="right num">{{ $row['excluded_days'] ?? 0 }}</td></tr>
            @endforeach
        </tbody>
    </table>
@endif

@if ($slip['corrections'] !== [])
    <h2>Корректировки руководителя (п. 6.7)</h2>
    <table>
        <thead><tr><th>Основание</th><th class="right" style="width: 18%">Сумма, ₽</th></tr></thead>
        <tbody>
            @foreach ($slip['corrections'] as $row)
                <tr><td>{{ $row['comment'] ?? $row['title'] ?? '' }}</td><td class="right num {{ ($row['amount'] ?? 0) < 0 ? 'neg' : '' }}">{{ $money($row['amount'] ?? 0) }}</td></tr>
            @endforeach
        </tbody>
    </table>
@endif

@if ($slip['objection']['items'] !== [])
    <h2>Возражения</h2>
    @foreach ($slip['objection']['items'] as $item)
        <p class="small">{{ $date($item['created_at']) }} — {{ $item['status_label'] }}: {{ $item['reason'] }}@if ($item['response']) <br>Ответ: {{ $item['response'] }}@endif</p>
    @endforeach
@endif

<p class="small muted">Сформировано {{ $generatedAt->format('d.m.Y H:i') }}. Нормативная часть — Положение о мотивации и об оплате труда, редакция 2.2.</p>
