<?php

namespace App\Services\Erp\Support;

use App\Models\Organization;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * Единая точка получения нашей организации по UUID из входящего сообщения 1С.
 *
 * Справочник организаций 1С не присылает — она указывает только UUID, а сами юрлица
 * заводятся в админке вручную (как склады). Значит рассинхрон неизбежен: 1С завела
 * юрлицо и начала проводить на него документы раньше, чем админ внёс его на сайт.
 *
 * В этом случае создаётся заглушка (`is_stub = true`) с UUID вместо названия. Заглушка
 * сохраняет связь документа с организацией: когда админ заполнит карточку, все ранее
 * принятые заказы и реализации отобразятся правильно сами, без бэкфилла. Вариант
 * «organization_id = NULL + лог» потребовал бы разбирать историю руками.
 *
 * Карточка канбана: `2026-08-01_org-01-directory.md` в `docs/tasks/`.
 */
class OrganizationResolver
{
    /**
     * Поля справочника, которые 1С может прислать подсказкой в payload документа.
     *
     * Только эти — реквизиты банка 1С не передаёт, их заполняет админ.
     *
     * @var array<string, string>
     */
    private const HINT_FIELDS = [
        'name' => 'name',
        'legal_name' => 'legal_name',
        'tax_id' => 'tax_id',
        'tax_code' => 'tax_code',
    ];

    /**
     * @param  string|null  $uuid  UUID организации из сообщения 1С
     * @param  array<string, mixed>|null  $hint  Необязательные поля из того же payload
     */
    public function resolveByUuid(?string $uuid, ?array $hint = null): ?Organization
    {
        $uuid = is_string($uuid) ? trim($uuid) : '';

        // Документ без организации легитимен: 1С может ещё не уметь её присылать.
        // NULL здесь значит «не указана», а не «неизвестная» — историю не бэкфиллим.
        if ($uuid === '') {
            return null;
        }

        $organization = $this->findIncludingTrashed($uuid);

        if ($organization) {
            $this->fillFromHint($organization, $hint);

            return $organization;
        }

        return $this->createStub($uuid, $hint);
    }

    /**
     * Поиск с учётом мягко удалённых.
     *
     * Организацию с историей документов удаляют только soft delete. Если 1С продолжает
     * проводить на неё документы — восстанавливаем: иначе `belongsTo` вернёт null,
     * клиент увидит пустого продавца, а создать заглушку с тем же UUID не даст
     * unique-индекс.
     */
    private function findIncludingTrashed(string $uuid): ?Organization
    {
        /** @var Organization|null $organization */
        $organization = Organization::withTrashed()->where('external_id', $uuid)->first();

        if ($organization && $organization->trashed()) {
            $organization->restore();

            Log::warning('ERP: организация восстановлена — 1С проводит документы на удалённое юрлицо', [
                'organization_id' => $organization->id,
                'external_id' => $uuid,
            ]);
        }

        return $organization;
    }

    /**
     * Дозаполнение пустых полей подсказкой из payload.
     *
     * Введённое админом не перезаписываем — 1С прислала подсказку, а не источник истины
     * по реквизитам. Исключение: у заглушки в названии лежит сам UUID, это заполнитель,
     * его настоящим названием заменяем.
     *
     * `is_stub` здесь **не снимается**: флаг означает «админ не подтверждал карточку»,
     * и снимает его только сохранение в админке. Иначе автоматически названная
     * организация ушла бы из виду без банковских реквизитов, которые нужны клиенту
     * в разрезе взаиморасчётов (org-06).
     *
     * @param  array<string, mixed>|null  $hint
     */
    private function fillFromHint(Organization $organization, ?array $hint): void
    {
        if (! $hint) {
            return;
        }

        foreach (self::HINT_FIELDS as $payloadKey => $column) {
            $value = $hint[$payloadKey] ?? null;

            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            $isNamePlaceholder = $column === 'name'
                && $organization->is_stub
                && $organization->name === $organization->external_id;

            if ($organization->{$column} === null || $organization->{$column} === '' || $isNamePlaceholder) {
                $organization->{$column} = trim($value);
            }
        }

        if ($organization->isDirty()) {
            $organization->save();
        }
    }

    /**
     * @param  array<string, mixed>|null  $hint
     */
    private function createStub(string $uuid, ?array $hint): Organization
    {
        $name = $hint['name'] ?? null;
        $attributes = [
            'external_id' => $uuid,
            'name' => is_string($name) && trim($name) !== '' ? trim($name) : $uuid,
            'is_active' => true,
            'is_stub' => true,
        ];

        foreach (self::HINT_FIELDS as $payloadKey => $column) {
            if ($column === 'name') {
                continue;
            }

            $value = $hint[$payloadKey] ?? null;

            if (is_string($value) && trim($value) !== '') {
                $attributes[$column] = trim($value);
            }
        }

        try {
            $organization = Organization::create($attributes);
        } catch (QueryException $e) {
            // Гонка воркеров: параллельный job уже создал заглушку с этим UUID.
            // Unique-индекс по external_id — единственный надёжный арбитр.
            $existing = $this->findIncludingTrashed($uuid);

            if (! $existing) {
                throw $e;
            }

            return $existing;
        }

        Log::info('ERP: создана заглушка организации — UUID не заведён в справочнике', [
            'organization_id' => $organization->id,
            'external_id' => $uuid,
        ]);

        return $organization;
    }
}
