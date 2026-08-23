<?php

namespace App\Services\Crm\Mail;

use App\Models\CrmEmailDelivery;
use App\Models\CrmEmailEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * Отслеживание прочтения писем.
 *
 * Что здесь честно, а что нет — важнее самой механики.
 *
 * **Открытие — сигнал, а не факт.** Outlook и корпоративные шлюзы режут
 * картинки: письмо прочли, а пиксель не загрузился. Apple Mail Privacy
 * Protection и Gmail, наоборот, подгружают картинки сами, без участия человека.
 * Поэтому отсутствие открытия не значит «не прочитали», а наличие не значит
 * «прочитали». Юридической силы у этого сигнала нет, и в интерфейсе так
 * и написано.
 *
 * **Переход по ссылке — сигнал куда честнее**: прокси кликают редко, человек
 * кликает осознанно. Поэтому считается отдельно и показывается отдельно.
 */
class MailTracker
{
    /**
     * Прозрачный GIF 1×1. Лежит константой, а не файлом: отдавать его надо
     * быстро и всегда, даже если диск недоступен.
     */
    private const PIXEL = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

    public function enabled(): bool
    {
        return (bool) config('mail_stream.tracking', true);
    }

    /**
     * Готовое тело письма для конкретного адресата.
     *
     * Ссылки заменяются на редирект, в конец добавляется пиксель. Ничего
     * не переписываем, если отслеживание выключено — ни глобально, ни на письме.
     */
    public function decorate(string $html, CrmEmailDelivery $delivery): string
    {
        if (! $this->enabled() || blank($delivery->track_token)) {
            return $html;
        }

        return $this->rewriteLinks($html, $delivery);
    }

    /**
     * Ссылка на пиксель. Пустая строка — отслеживание выключено, и шаблон
     * не станет вставлять картинку вовсе.
     */
    public function pixelUrl(CrmEmailDelivery $delivery): string
    {
        if (! $this->enabled() || blank($delivery->track_token)) {
            return '';
        }

        return route('mail.track.open', ['token' => $delivery->track_token]);
    }

    /**
     * Зафиксировать открытие.
     *
     * Повторные загрузки картинки считаются, но первое открытие не переписывается:
     * вопрос «когда впервые увидели» важнее, чем «когда в последний раз».
     */
    public function recordOpen(CrmEmailDelivery $delivery, Request $request): void
    {
        $now = now();

        $delivery->forceFill([
            'opened_at' => $delivery->opened_at ?? $now,
            'last_opened_at' => $now,
            'opens_count' => $delivery->opens_count + 1,
        ])->save();

        $this->log($delivery, CrmEmailEvent::TYPE_OPEN, null, $request);
    }

    public function recordClick(CrmEmailDelivery $delivery, string $url, Request $request): void
    {
        $now = now();

        $delivery->forceFill([
            'clicked_at' => $delivery->clicked_at ?? $now,
            'last_clicked_at' => $now,
            'clicks_count' => $delivery->clicks_count + 1,
            // Переход означает, что письмо открывали, даже если картинка
            // не загрузилась. Иначе клиент, у которого Outlook режет картинки,
            // выглядел бы не читающим при том, что он ходил по нашим ссылкам.
            'opened_at' => $delivery->opened_at ?? $now,
        ])->save();

        $this->log($delivery, CrmEmailEvent::TYPE_CLICK, $url, $request);
    }

    public function pixelBody(): string
    {
        return base64_decode(self::PIXEL);
    }

    /**
     * Переписать ссылки на подписанный редирект.
     *
     * Подпись обязательна: без неё эндпоинт стал бы открытым редиректом,
     * и нашим доменом уводили бы людей на фишинг.
     */
    private function rewriteLinks(string $html, CrmEmailDelivery $delivery): string
    {
        return preg_replace_callback(
            '/href="(https?:\/\/[^"]+)"/i',
            function (array $match) use ($delivery): string {
                $url = html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');

                // Свою же ссылку отслеживания переписывать не надо.
                if (str_contains($url, '/e/o/') || str_contains($url, '/e/c/')) {
                    return $match[0];
                }

                $signed = URL::signedRoute('mail.track.click', [
                    'token' => $delivery->track_token,
                    'u' => base64_encode($url),
                ]);

                return 'href="'.e($signed).'"';
            },
            $html,
        ) ?? $html;
    }

    private function log(CrmEmailDelivery $delivery, string $type, ?string $url, Request $request): void
    {
        CrmEmailEvent::query()->create([
            'delivery_id' => $delivery->getKey(),
            'type' => $type,
            'url' => $url === null ? null : mb_substr($url, 0, 1024),
            'ip' => $request->ip(),
            // По User-Agent отличается предзагрузка Apple и Gmail от живого
            // человека — без него открытия невозможно интерпретировать.
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 512),
        ]);
    }
}
