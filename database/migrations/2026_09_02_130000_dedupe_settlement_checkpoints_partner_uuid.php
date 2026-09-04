<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Чистка задвоенных контрольных точек (круг 13, пара Кириллова).
 *
 * До круга 11 1С слала `settlement.checkpoint` без partner_uuid — точки лежат
 * с пустым партнёром. После добавления партнёра в уникальный ключ пересылка
 * той же точки уже с партнёром не находила легаси-строку и вставляла вторую:
 * одна ось «контрагент × организация × валюта × дата» получала и пустую,
 * и заполненную строки. Обработчик починен (фолбэк на легаси-строку),
 * а накопленные дубли убирает эта миграция: пустая строка при наличии
 * заполненной по той же оси — всегда устаревший дубль, её и удаляем.
 */
return new class extends Migration
{
    public function up(): void
    {
        $duplicateIds = DB::table('settlement_checkpoints as legacy')
            ->join('settlement_checkpoints as actual', function ($join) {
                $join->on('actual.contractor_uuid', '=', 'legacy.contractor_uuid')
                    ->on('actual.organization_uuid', '=', 'legacy.organization_uuid')
                    ->on('actual.currency_code', '=', 'legacy.currency_code')
                    ->on('actual.as_of_date', '=', 'legacy.as_of_date');
            })
            ->where('legacy.partner_uuid', '')
            ->where('actual.partner_uuid', '<>', '')
            ->pluck('legacy.id');

        if ($duplicateIds->isNotEmpty()) {
            DB::table('settlement_checkpoints')->whereIn('id', $duplicateIds)->delete();
        }
    }

    public function down(): void
    {
        // Удалённые дубли не восстановить, да и незачем: актуальные точки остались.
    }
};
