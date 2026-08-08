<?php

namespace App\Services\Erp;

use App\Models\Order;
use App\Models\Payment;
use App\Models\ProductReturn;
use App\Models\Shipment;
use Illuminate\Database\Eloquent\Model;

/**
 * Отбрасывание устаревших сообщений 1С по номеру ревизии документа (v15.16.0).
 *
 * ## Зачем
 *
 * Порядок доставки не гарантирован ни между очередями (у каждой свой connection
 * и свой набор воркеров), ни внутри одной очереди при `numprocs > 1`.
 *
 * Дедупликация по `message_id` от этого не спасает: она ловит повторную доставку
 * ТОГО ЖЕ сообщения брокером, а здесь речь о двух РАЗНЫХ сообщениях по одному
 * документу — у них разные `message_id`, и оба будут применены в порядке доставки.
 *
 * Отличить свежее от устаревшего по `erp_updated_at` тоже нельзя. Живой случай,
 * заказ 29УТ-012045:
 *
 *   [4609584] событие 15:41:28 → 1 строка: 5 шт, не отменено      (устаревшие)
 *   [4609585] событие 15:41:28 → 2 строки: 2 активно + 3 отменено (актуальные)
 *
 * Метка совпадает до секунды.
 *
 * ## Правила
 *
 * - Сообщение без `revision` проверку не проходит вовсе и применяется как раньше.
 *   Это позволяет 1С включать поле по одному каналу за раз, а не одномоментно.
 * - `applied_revision` сдвигается только ПОСЛЕ успешной обработки: если обработчик
 *   упал и сообщение вернулось в очередь, повторная попытка снова пройдёт проверку.
 * - Справочники (товары, цены, остатки, контрагенты) в карте отсутствуют: там нет
 *   документа с жизненным циклом, последнее пришедшее значение и есть правильное.
 * - Расходные ордера исключены намеренно — у документа в 1С отключена история
 *   данных, номера ревизии не существует.
 */
class ErpRevisionGuard
{
    /**
     * Событие → модель документа. Ключ поиска везде `uuid` из payload.
     *
     * @var array<string, class-string<Model>>
     */
    private const DOCUMENTS = [
        'order.created' => Order::class,
        'order.updated' => Order::class,
        'order.deleted' => Order::class,
        'shipment.created' => Shipment::class,
        'shipment.updated' => Shipment::class,
        'shipment.deleted' => Shipment::class,
        'payment.created' => Payment::class,
        'payment.updated' => Payment::class,
        'payment.deleted' => Payment::class,
        'return.updated' => ProductReturn::class,
        'return.deleted' => ProductReturn::class,
    ];

    /**
     * Причина, по которой сообщение считается устаревшим, либо null.
     *
     * Возвращается человекочитаемый текст: он уходит в журнал шины и читается
     * менеджером, а не только разработчиком.
     *
     * @param  array<string, mixed>  $payload
     */
    public function staleReason(string $event, array $payload): ?string
    {
        $revision = $this->revisionFrom($payload);

        if ($revision === null) {
            return null;
        }

        $applied = $this->appliedRevision($event, $payload);

        if ($applied === null || $revision > $applied) {
            return null;
        }

        return sprintf(
            'Сообщение устарело и не применено: пришла ревизия %d, на документе уже ревизия %d. '
            .'Скорее всего, сообщения по документу доехали в обратном порядке.',
            $revision,
            $applied,
        );
    }

    /**
     * Зафиксировать применённую ревизию после успешной обработки.
     *
     * Пишется запросом, а не через модель: сохранение Order поднимает listener
     * публикации заказа обратно в 1С, и служебная отметка вызвала бы эхо-сообщение.
     *
     * @param  array<string, mixed>  $payload
     */
    public function remember(string $event, array $payload): void
    {
        $revision = $this->revisionFrom($payload);

        if ($revision === null) {
            return;
        }

        $query = $this->documentQuery($event, $payload);

        if ($query === null) {
            return;
        }

        // Условие по applied_revision защищает от гонки: два воркера могли взять
        // сообщения по одному документу одновременно, и более старое не должно
        // откатить отметку назад.
        $query->where(function ($inner) use ($revision) {
            $inner->whereNull('applied_revision')->orWhere('applied_revision', '<', $revision);
        })->update(['applied_revision' => $revision]);
    }

    /**
     * Ревизия из payload. Нецелые и отрицательные значения игнорируются —
     * схема их не пропустит, но обработчик не должен зависеть от валидатора.
     *
     * @param  array<string, mixed>  $payload
     */
    private function revisionFrom(array $payload): ?int
    {
        $revision = $payload['revision'] ?? null;

        if ($revision === null || ! is_numeric($revision)) {
            return null;
        }

        $revision = (int) $revision;

        return $revision >= 0 ? $revision : null;
    }

    /**
     * Ревизия, уже применённая к документу. null — документа нет либо ревизия
     * по нему ещё не приходила.
     *
     * @param  array<string, mixed>  $payload
     */
    private function appliedRevision(string $event, array $payload): ?int
    {
        $query = $this->documentQuery($event, $payload);

        if ($query === null) {
            return null;
        }

        $applied = $query->value('applied_revision');

        return $applied === null ? null : (int) $applied;
    }

    /**
     * Запрос к документу по uuid из payload.
     *
     * withTrashed обязателен: `*.deleted` мягко удаляет документ, и следующее
     * сообщение по нему обязано сравниваться с той же отметкой, а не начинать
     * отсчёт заново.
     *
     * @param  array<string, mixed>  $payload
     * @return \Illuminate\Database\Eloquent\Builder<Model>|null
     */
    private function documentQuery(string $event, array $payload): ?\Illuminate\Database\Eloquent\Builder
    {
        $model = self::DOCUMENTS[$event] ?? null;
        $uuid = $payload['uuid'] ?? null;

        if ($model === null || ! is_string($uuid) || $uuid === '') {
            return null;
        }

        /** @var \Illuminate\Database\Eloquent\Builder<Model> $query */
        $query = $model::query()->withoutGlobalScopes()->withTrashed()->where('uuid', $uuid);

        return $query;
    }
}
