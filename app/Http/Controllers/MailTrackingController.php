<?php

namespace App\Http\Controllers;

use App\Models\CrmEmailDelivery;
use App\Services\Crm\Mail\MailTracker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Пиксель и редирект отслеживания.
 *
 * Оба эндпоинта публичные: их дёргает почтовый клиент получателя, у которого
 * никакой сессии нет. Оба обязаны отвечать **всегда** — картинкой и переходом
 * соответственно. Ошибка учёта не имеет права испортить человеку письмо:
 * он не виноват, что мы что-то считаем.
 */
class MailTrackingController extends Controller
{
    public function __construct(private readonly MailTracker $tracker) {}

    /**
     * Прозрачный пиксель. Отдаётся при любом исходе, даже если токен не нашёлся:
     * иначе в письме появится битая картинка и человек это увидит.
     */
    public function open(Request $request, string $token): Response
    {
        try {
            $delivery = CrmEmailDelivery::query()->where('track_token', $token)->first();

            if ($delivery !== null) {
                $this->tracker->recordOpen($delivery, $request);
            }
        } catch (\Throwable) {
            // Молча: считать открытия — не повод ломать показ письма.
        }

        return response($this->tracker->pixelBody(), 200, [
            'Content-Type' => 'image/gif',
            // Кэш запрещаем: иначе повторные открытия не увидим вовсе.
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
            'Pragma' => 'no-cache',
        ]);
    }

    /**
     * Переход по ссылке из письма.
     *
     * Подпись проверяется middleware `signed`: без неё эндпоинт был бы открытым
     * редиректом, и нашим доменом уводили бы людей куда угодно.
     */
    public function click(Request $request, string $token): RedirectResponse
    {
        $url = $this->target($request);

        try {
            $delivery = CrmEmailDelivery::query()->where('track_token', $token)->first();

            if ($delivery !== null) {
                $this->tracker->recordClick($delivery, $url, $request);
            }
        } catch (\Throwable) {
            // Молча: человек шёл по ссылке, а не считаться.
        }

        return redirect()->away($url);
    }

    /**
     * Куда вести. Подпись уже проверена, но схему всё равно сверяем: параметр
     * может быть подписан нами и при этом содержать `javascript:` — подпись
     * говорит «это наша ссылка», а не «эта ссылка безопасна».
     */
    private function target(Request $request): string
    {
        $url = base64_decode((string) $request->query('u'), true);

        if ($url === false || ! preg_match('~^https?://~i', $url)) {
            return config('app.url');
        }

        return $url;
    }
}
