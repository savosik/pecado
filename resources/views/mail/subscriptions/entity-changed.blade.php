@php
    // Блочная вёрстка изменений (email-safe, инлайн-стили). Каждое изменение —
    // отдельный блок со слегка серым фоном, чтобы строки не сливались.
    $palette = [
        'added' => '#2f9e6b',
        'removed' => '#d24d57',
        'modified' => '#e08a1e',
        'shortfall' => '#8e5bd0',
        'partial' => '#8e5bd0',
    ];
    $box = 'background:#f4f4f6;border-radius:8px;padding:11px 14px;margin:0 0 8px;';
    $rowsHtml = '';
    foreach (($rows ?? []) as $r) {
        $type = $r['type'] ?? '';
        if ($type === 'diff') {
            $rowsHtml .= '<div style="'.$box.'">'
                .'<div style="font-size:12px;color:#8a8a8a;font-weight:600;text-transform:uppercase;letter-spacing:.03em;margin:0 0 5px;">'.e($r['label']).'</div>'
                .'<div style="font-size:14px;color:#9a9a9a;text-decoration:line-through;margin:0 0 2px;">'.e($r['old']).'</div>'
                .'<div style="font-size:15px;color:#222222;font-weight:600;line-height:1.45;">'.e($r['new']).'</div>'
                .'</div>';
        } elseif ($type === 'action') {
            $color = $palette[$r['kind'] ?? ''] ?? '#666666';
            $rowsHtml .= '<div style="'.$box.'font-size:14px;color:#222222;line-height:1.5;">'
                .'<span style="display:inline-block;font-size:11px;font-weight:700;color:#ffffff;background:'.$color.';border-radius:4px;padding:2px 8px;margin-right:8px;">'.e($r['label']).'</span>'
                .e($r['text'])
                .'</div>';
        } elseif ($type === 'note') {
            $rowsHtml .= '<div style="font-size:14px;font-weight:600;color:#333333;margin:2px 0 8px;">'.e($r['text']).'</div>';
        }
    }

    // Фолбэк, если структурированных блоков нет — построчный вывод body.
    $fallbackLines = $rowsHtml === '' ? array_values(array_filter(
        array_map('trim', preg_split('/\r\n|\r|\n/', (string) $body)),
        fn ($l) => $l !== ''
    )) : [];
@endphp

@component('mail::message')
# {{ $sectionLabel }}: изменение

@if ($entityLabel)
**{{ $entityLabel }}**
@endif

@if ($rowsHtml !== '')

{!! $rowsHtml !!}

@else
@foreach ($fallbackLines as $line)
- {{ $line }}
@endforeach
@endif

@if ($url)
@component('mail::button', ['url' => $url, 'color' => 'primary'])
Открыть в личном кабинете
@endcomponent
@endif

С уважением,<br>
команда Pecado.ru

@component('mail::subcopy')
Вы получили это письмо, потому что этот адрес подписан на изменения в разделе «{{ $sectionLabel }}» личного кабинета Pecado.ru.
[Отписаться от уведомлений]({{ $unsubscribeUrl }})
@endcomponent
@endcomponent
