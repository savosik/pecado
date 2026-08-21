@php
    // Тело письма, собранного системой. Не самостоятельное письмо, а фрагмент:
    // рамку, шапку и подпись добавляет mail.crm.manager-message — та же, что
    // у писем менеджера. Это и есть смысл общего потока: получатель не должен
    // видеть разницы между «написал человек» и «собрала система».
    //
    // Вёрстка email-safe: инлайн-стили, без внешних таблиц стилей.
    $palette = [
        'added' => '#2f9e6b',
        'removed' => '#d24d57',
        'modified' => '#e08a1e',
        'shortfall' => '#8e5bd0',
        'partial' => '#8e5bd0',
    ];
    $box = 'background:#f4f4f6;border-radius:8px;padding:11px 14px;margin:0 0 8px;';
@endphp
@if (!empty($title))
<p style="font-size:17px;font-weight:700;color:#222222;margin:0 0 10px;">{{ $title }}</p>
@endif
@if (!empty($entityLabel))
<p style="font-size:14px;color:#666666;margin:0 0 12px;">{{ $entityLabel }}</p>
@endif
@if (!empty($body))
<p style="font-size:15px;color:#333333;line-height:1.5;margin:0 0 14px;">{{ $body }}</p>
@endif
@foreach (($rows ?? []) as $row)
    @php $type = $row['type'] ?? ''; @endphp
    @if ($type === 'diff')
        <div style="{{ $box }}">
            <div style="font-size:12px;color:#8a8a8a;font-weight:600;text-transform:uppercase;letter-spacing:.03em;margin:0 0 5px;">{{ $row['label'] ?? '' }}</div>
            <div style="font-size:14px;color:#9a9a9a;text-decoration:line-through;margin:0 0 2px;">{{ $row['old'] ?? '' }}</div>
            <div style="font-size:15px;color:#222222;font-weight:600;line-height:1.45;">{{ $row['new'] ?? '' }}</div>
        </div>
    @elseif ($type === 'action')
        <div style="{{ $box }}font-size:14px;color:#222222;line-height:1.5;">
            <span style="display:inline-block;font-size:11px;font-weight:700;color:#ffffff;background:{{ $palette[$row['kind'] ?? ''] ?? '#666666' }};border-radius:4px;padding:2px 8px;margin-right:8px;">{{ $row['label'] ?? '' }}</span>{{ $row['text'] ?? '' }}
        </div>
    @elseif ($type === 'note')
        <div style="font-size:14px;font-weight:600;color:#333333;margin:2px 0 8px;">{{ $row['text'] ?? '' }}</div>
    @endif
@endforeach
@if (!empty($url))
<p style="margin:16px 0 0;"><a href="{{ $url }}" style="display:inline-block;background:#9e1b32;color:#ffffff;text-decoration:none;border-radius:6px;padding:10px 18px;font-size:14px;font-weight:600;">Открыть в личном кабинете</a></p>
@endif
