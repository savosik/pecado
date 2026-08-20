<?php

namespace App\Notifications\Pulse\Contracts;

use App\Notifications\Pulse\Support\FieldSpec;

/**
 * Описание события пульта уведомлений.
 *
 * Единственное место, где событие описывается. Реализовал интерфейс,
 * зарегистрировал класс в config/notification_pulse.php — событие само появилось
 * в выпадающем списке конструктора, его поля стали условиями, метки заработали,
 * журнал и трасса подхватили.
 *
 * Ни движок, ни интерфейс, ни журнал при добавлении события не трогаются.
 * Если понадобилось — контракт неполон, и его надо расширить, а не обойти.
 */
interface NotificationEventContract
{
    /** Технический ключ: 'orders.status_changed'. Домен — часть до точки. */
    public function key(): string;

    /** Домен: 'orders'. Гейтится через config('notification_pulse.domains'). */
    public function domain(): string;

    /** Название для менеджера: «Смена статуса заказа». Русское, без жаргона. */
    public function label(): string;

    /** Группа в выпадающем списке конструктора: «Заказы», «Оплаты», «Документы». */
    public function group(): string;

    /** Подсказка под названием: когда именно это срабатывает. */
    public function description(): string;

    /**
     * Поля, доступные условиям правила.
     *
     * @return array<string, FieldSpec>
     */
    public function fields(): array;

    /**
     * Метки, вычисляемые из данных сигнала.
     *
     * Дают условия «содержит / не содержит» и работают для событий, которых
     * на момент создания правила ещё не существовало: правило «всё по этому
     * контрагенту» подхватит новое событие само.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, string>
     */
    public function tags(array $data): array;

    /** Blade-шаблон письма; 'mail.pulse.default' если своего нет. */
    public function defaultTemplate(): string;

    /** Тема письма по умолчанию, поддерживает плейсхолдеры {{...}}. */
    public function defaultSubject(): string;
}
