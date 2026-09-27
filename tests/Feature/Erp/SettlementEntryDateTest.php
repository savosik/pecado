<?php

namespace Tests\Feature\Erp;

use App\Models\SettlementEntry;
use App\Services\Erp\ErpMessageValidator;
use App\Services\Erp\Exceptions\ErpUnprocessableMessageException;
use App\Services\Erp\Handlers\HandleSettlementPosted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Период движения регистра (`entries[].entry_date`, контракт 16.12.0, топик №10 Agent Hub).
 *
 * Вторая дата движения: `date` — дата хозяйственной операции, `entry_date` — период
 * движения регистра, которым 1С отбирает движения в контрольную точку. За июль–август
 * 2026 оси разошлись у 9,4 % движений, разрыв до 74 дней.
 *
 * Поле опционально и приезжает со смещением часового пояса сеанса 1С: сервер учёта
 * работает не в нашей зоне, и хранить строку как есть нельзя — сравнение с отсечкой
 * точки должно идти моментами времени.
 */
class SettlementEntryDateTest extends TestCase
{
    use RefreshDatabase;

    private const CONTRACTOR_UUID = '00000000-0000-4000-a000-000000006000';

    /**
     * @param  array<string, mixed>  $entry
     */
    private function postMovement(array $entry, string $suffix = '1'): void
    {
        app(HandleSettlementPosted::class)->handle([
            'event' => 'settlement.posted',
            'message_id' => 'msg-entry-date-'.$suffix,
            'spec_version' => '16.4',
            'document_uuid' => '00000000-0000-4000-a000-00000000610'.$suffix,
            'document_kind' => 'payment',
            'document_date' => '2026-07-30',
            'entries' => [$entry + [
                'uuid' => '00000000-0000-4000-a000-00000000620'.$suffix,
                'type' => 'payment_in',
                'amount' => 548.00,
                'contractor_uuid' => self::CONTRACTOR_UUID,
            ]],
        ]);
    }

    /**
     * Боевой случай из круга 13: дата документа 30.07, период движения 03.08 23:59:59.
     * 1С отдаёт его в зоне своего сеанса (+05:00) — это тот же момент, что 23:59:59
     * по Москве, и хранить его надо приведённым к нашей зоне.
     */
    #[Test]
    public function период_со_смещением_приводится_к_зоне_сайта(): void
    {
        $this->postMovement([
            'date' => '2026-07-30',
            'entry_date' => '2026-08-04T01:59:59+05:00',
        ]);

        $entry = SettlementEntry::query()->sole();

        $this->assertSame('2026-08-03 23:59:59', $entry->entry_date?->toDateTimeString());
        // Дату операции период не подменяет: в акте сверки строка остаётся на 30.07.
        $this->assertSame('2026-07-30', $entry->date?->toDateString());
    }

    /**
     * Смещение обязательно контрактом, но если 1С однажды пришлёт «голое» значение —
     * это стенные часы учёта, а не наши. Молча повесить на них зону сайта значит
     * сдвинуть момент на разницу поясов.
     */
    #[Test]
    public function период_без_смещения_читается_как_время_учётной_зоны(): void
    {
        config(['erp.settlements.accounting_timezone' => 'Asia/Yekaterinburg']);

        $this->postMovement([
            'date' => '2026-07-30',
            'entry_date' => '2026-08-04T01:59:59',
        ], '2');

        // 01:59:59 в Екатеринбурге — это 23:59:59 предыдущего дня по Москве.
        $this->assertSame(
            '2026-08-03 23:59:59',
            SettlementEntry::query()->sole()->entry_date?->toDateTimeString(),
        );
    }

    #[Test]
    public function строка_без_поля_остаётся_на_прежней_оси(): void
    {
        $this->postMovement(['date' => '2026-07-30'], '3');

        $this->assertNull(SettlementEntry::query()->sole()->entry_date);
    }

    /**
     * `null` равнозначен отсутствию ключа: 1С фиксирует у себя отсутствие ключа,
     * но принять оба варианта дешевле, чем ронять сообщение на совместимости.
     */
    #[Test]
    public function null_равнозначен_отсутствию_ключа(): void
    {
        $this->postMovement(['date' => '2026-07-30', 'entry_date' => null], '4');

        $this->assertNull(SettlementEntry::query()->sole()->entry_date);
    }

    /**
     * Пустая строка — признак сломанного форматировщика на стороне 1С (их
     * `СформироватьISO8601СTimezone` для пустой даты возвращает ""). Превратить её
     * в «периода нет» значило бы спрятать дефект и молча оставить строку на фолбэке.
     */
    #[Test]
    public function пустая_строка_отправляет_сообщение_в_разбор(): void
    {
        $this->expectException(ErpUnprocessableMessageException::class);

        $this->postMovement(['date' => '2026-07-30', 'entry_date' => ''], '5');
    }

    #[Test]
    public function нечитаемое_значение_отправляет_сообщение_в_разбор(): void
    {
        $this->expectException(ErpUnprocessableMessageException::class);

        $this->postMovement(['date' => '2026-07-30', 'entry_date' => 'третьего августа'], '6');
    }

    /**
     * Схема обязана принять и сообщение с полем, и сообщение без него, причём
     * со `spec_version = 16.4`: сборщик 1С шлёт общий для нескольких сообщений
     * номер, и версия на разбор не влияет.
     */
    #[Test]
    public function схема_принимает_поле_и_старый_spec_version(): void
    {
        $validator = app(ErpMessageValidator::class);

        $payload = [
            'event' => 'settlement.posted',
            'message_id' => 'msg-entry-date-schema',
            'spec_version' => '16.4',
            'document_uuid' => '00000000-0000-4000-a000-000000006300',
            'document_kind' => 'payment',
            'document_date' => '2026-07-30',
            'entries' => [[
                'uuid' => '00000000-0000-4000-a000-000000006301',
                'type' => 'payment_in',
                'amount' => 548.00,
                'date' => '2026-07-30',
                'entry_date' => '2026-08-04T01:59:59+05:00',
                'contractor_uuid' => self::CONTRACTOR_UUID,
            ]],
        ];

        $this->assertTrue($validator->validate('settlement.posted', $payload)['valid']);

        unset($payload['entries'][0]['entry_date']);

        $this->assertTrue($validator->validate('settlement.posted', $payload)['valid']);
    }
}
