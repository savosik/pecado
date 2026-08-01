<?php

namespace App\Services\Erp\Support;

use App\Models\Warehouse;
use Illuminate\Support\Facades\Log;

/**
 * Разбор организации и склада проведения из входящего payload 1С (v15.8.0).
 *
 * Общий код для заказов и реализаций: в обоих документах 1С присылает одинаковую пару
 * полей `organization` + `warehouse_uuid`.
 *
 * Главное правило: **отсутствие поля никогда не сбрасывает сохранённое значение.**
 * Тот же принцип уже действует для `delivery_method` (v15.3) и аудит-меток (v13.7) —
 * 1С может не уметь присылать поле, и это не повод терять то, что уже известно.
 */
trait ResolvesDocumentOrganization
{
    /**
     * Поля документа, которые нужно записать. Ключи, отсутствующие в payload,
     * в результат не попадают — вызывающий код мержит их в свой набор.
     *
     * @param  array<string, mixed>  $payload
     * @param  string  $context  Имя handler-а для логов
     * @return array<string, int|null>
     */
    protected function resolveOrganizationFields(array $payload, string $context): array
    {
        $fields = [];

        $organizationId = $this->resolveOrganizationId($payload);
        if ($organizationId !== false) {
            $fields['organization_id'] = $organizationId;
        }

        $warehouseId = $this->resolvePostedWarehouseId($payload, $context);
        if ($warehouseId !== false) {
            $fields['warehouse_id'] = $warehouseId;
        }

        return $fields;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return int|null|false `false` — поля в payload нет, значение не трогаем
     */
    private function resolveOrganizationId(array $payload): int|null|false
    {
        $organization = $payload['organization'] ?? null;

        // Явный null трактуем как «1С не знает», а не «очистить»: организация
        // у проведённого документа есть всегда, а потерять связь необратимо.
        if (! is_array($organization)) {
            return false;
        }

        $uuid = $organization['uuid'] ?? null;

        if (! is_string($uuid) || trim($uuid) === '') {
            return false;
        }

        return app(OrganizationResolver::class)
            ->resolveByUuid($uuid, $organization)
            ?->id;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return int|null|false `false` — поля в payload нет, значение не трогаем
     */
    private function resolvePostedWarehouseId(array $payload, string $context): int|null|false
    {
        $uuid = $payload['warehouse_uuid'] ?? null;

        if (! is_string($uuid) || trim($uuid) === '') {
            return false;
        }

        $warehouse = Warehouse::where('external_id', trim($uuid))->first();

        if (! $warehouse) {
            // Склад не заводим автоматически: справочник складов ведётся отдельно
            // и влияет на витрину через регионы. Пишем null, а не оставляем прежний:
            // показать старый склад было бы прямой дезинформацией.
            Log::warning($context.': склад проведения не найден на сайте', [
                'warehouse_uuid' => $uuid,
            ]);

            return null;
        }

        return $warehouse->id;
    }
}
