<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Отписка от уведомлений — Pecado.ru</title>
    <style>
        :root { color-scheme: light dark; }
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f5f5f5;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
            color: #1a1a1a;
        }
        .card {
            max-width: 460px;
            margin: 24px;
            padding: 40px 32px;
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, .08);
            text-align: center;
        }
        h1 { font-size: 22px; margin: 0 0 12px; color: #9e1b32; }
        p { font-size: 15px; line-height: 1.6; color: #444; margin: 0 0 8px; }
        .muted { color: #888; font-size: 13px; margin-top: 20px; }
        a { color: #9e1b32; }
        @media (prefers-color-scheme: dark) {
            body { background: #16181d; color: #eaeaea; }
            .card { background: #1f2229; box-shadow: none; }
            p { color: #c7c7c7; }
            .muted { color: #8a8a8a; }
        }
    </style>
</head>
<body>
    <div class="card">
        @if ($found)
            <h1>Вы отписаны</h1>
            <p>Адрес <strong>{{ $destination }}</strong> больше не будет получать уведомления об изменениях
                @if ($sectionLabel)
                    в разделе «{{ $sectionLabel }}».
                @else
                    этого раздела.
                @endif
            </p>
            <p class="muted">Если это произошло по ошибке, вы можете снова подписать адрес в личном кабинете Pecado.ru.</p>
        @else
            <h1>Подписка не найдена</h1>
            <p>Ссылка отписки недействительна или уже была использована.</p>
        @endif
        <p class="muted"><a href="{{ url('/') }}">Вернуться на Pecado.ru</a></p>
    </div>
</body>
</html>
