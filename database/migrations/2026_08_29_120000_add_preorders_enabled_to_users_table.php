<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Галочка «предлагать предзаказ» на клиенте.
 *
 * Клиенты «на автомате» набивают количества по прайсу, а сайт молча переливает
 * то, чего нет на складе, в предзаказ-близнец. Через день выясняется, что ждать
 * поставку они не собирались, и менеджер удаляет документ в 1С (33 из 63
 * предзаказов за лето — так). Выключенный флаг убирает предзаказ из витрины
 * целиком: остаток предзаказных складов для такого клиента равен нулю, корзина
 * не переливает, клиентское API не заводит предзаказ. Выключает сам клиент в
 * кабинете или менеджер в CRM; изменение журналируется в crm_client_status_changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('preorders_enabled')->default(true)
                ->comment('Предлагать предзаказ: 1 — товар без остатка можно заказать у поставщика (предзаказный склад региона виден), 0 — клиент видит только наличие; меняет клиент в кабинете или менеджер в CRM');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('preorders_enabled');
        });
    }
};
