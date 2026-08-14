<?php

namespace App\Services\Erp\Handlers;

use App\Services\Erp\Exceptions\ErpUnprocessableMessageException;
use App\Services\Erp\Support\ResolvesSettlementParties;
use Illuminate\Support\Facades\Log;

/**
 * Начальное сальдо — принимается, но НЕ применяется (v16.3.0, 14.08.2026).
 *
 * ## Почему обработчик ничего не делает
 *
 * 1С отдаёт ленту движений **целиком, с самого начала истории**: первые движения
 * датированы 2025 годом. `settlement.opening_balance` описывает ту же историю,
 * свёрнутую в одну строку, — то есть дублирует ленту, а не дополняет её.
 *
 * Пока сальдо прибавлялось, баланс был задвоен на 13,7 млн ₽. Сверка с контрольной
 * точкой 01.08.2026 (эталон 1С, 150 пар, −6 639 322,54 ₽):
 *
 *   лента со строками сальдо → расхождение 13 899 656,86 ₽
 *   лента без них            → расхождение    177 925,32 ₽, 140 пар из 150 сходятся точно
 *
 * ## Почему не выбрасываем в DLQ
 *
 * Событие остаётся валидным по контракту, ломающих изменений для 1С нет. Отправка
 * в очередь недоставленных завалила бы её мусором на каждой досылке и заставила бы
 * дежурного разбирать сообщения, с которыми всё в порядке.
 *
 * Поэтому принимаем, пишем в журнал и молча роняем. Журнал важен: пока 1С не
 * прекратила публикацию, по нему видно, что событие ещё живо.
 *
 * ## Почему не удалили обработчик совсем
 *
 * Без него `ErpIncomingJob` не нашёл бы обработчик и отправил сообщение в DLQ —
 * то есть получилось бы ровно то, чего мы избегаем. Явный no-op с объяснением
 * честнее молчаливого отсутствия.
 */
class HandleSettlementOpeningBalance
{
    use ResolvesSettlementParties;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(array $payload): void
    {
        $uuid = $this->stringOrNull($payload['uuid'] ?? null);
        $asOfDate = $this->stringOrNull($payload['as_of_date'] ?? null);

        // Валидацию оставляем: если 1С однажды пришлёт битое сообщение, знать
        // об этом полезнее, чем проглотить. Схема то же самое проверяет раньше,
        // но обработчик не должен зависеть от валидатора.
        if ($uuid === null || $asOfDate === null || ! array_key_exists('amount', $payload)) {
            throw new ErpUnprocessableMessageException(
                'settlement.opening_balance: отсутствует uuid, as_of_date или amount',
            );
        }

        Log::info('settlement.opening_balance: принято, но не применено (устарело с v16.3.0)', [
            'uuid' => $uuid,
            'as_of_date' => $asOfDate,
            'amount' => $payload['amount'],
            'contractor_uuid' => $this->stringOrNull($payload['contractor_uuid'] ?? null),
        ]);
    }
}
