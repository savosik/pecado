{{--
    Тело письма менеджера отдаётся получателю как есть: его составил доверенный
    сотрудник, и почтовый клиент должен получить именно то, что тот написал.
    Санитайзинг стоит на нашей стороне — при показе письма в интерфейсе CRM.
--}}
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $email->subject }}</title>
</head>
<body style="margin:0; padding:24px; background:#f5f5f5; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif; color:#1a202c;">
    <div style="max-width:640px; margin:0 auto; background:#ffffff; border-radius:8px; padding:32px;">
        {!! $bodyHtml !!}
    </div>
    @if (!empty($trackingPixel))
        {{-- Пиксель отслеживания. Загрузился — письмо открывали; не загрузился —
             это ничего не значит: почтовые клиенты часто режут картинки. --}}
        <img src="{{ $trackingPixel }}" width="1" height="1" alt="" style="display:block;width:1px;height:1px;border:0;">
    @endif
</body>
</html>
