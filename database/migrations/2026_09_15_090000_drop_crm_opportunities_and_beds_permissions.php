<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;

/**
 * Разделы «Возможности» (crm-07) и «Грядки» (crm-08) сняты 15.09.2026.
 *
 * Оба стояли на планах на партнёра — инструменте методики «сверху вниз»,
 * от которой отдел ушёл с приказами о планах на квартал. Сигналы по партнёрам
 * («выпал из ритма», «не берёт бренд») живут в «Мотивации → Мои клиенты».
 * Права удаляются вместе со связями ролей; сидер их больше не заводит.
 */
return new class extends Migration
{
    private const PERMISSIONS = ['crm-opportunities.view', 'crm-beds.view'];

    public function up(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        Permission::query()->whereIn('name', self::PERMISSIONS)->where('guard_name', 'web')->get()->each->delete();
    }

    public function down(): void
    {
        // Права не восстанавливаются: разделов, которые они открывали, больше нет.
    }
};
