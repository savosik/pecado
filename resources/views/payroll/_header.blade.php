<div class="head">
    <table class="cols">
        <tr>
            <td>
                <h1>{{ $title }}</h1>
                <div class="muted">{{ $manager->name }} · {{ $monthLabel }}</div>
            </td>
            <td class="right" style="width: 38%">
                <div class="small muted">Статус расчёта</div>
                <div>{{ $calc['status_label'] }}{{ $calc['version'] > 1 ? ' · версия '.$calc['version'] : '' }}</div>
                <div class="small muted">Сформирован {{ $generatedAt->format('d.m.Y в H:i') }}</div>
            </td>
        </tr>
    </table>
</div>
