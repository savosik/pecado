<?php

namespace App\Services\Client\Api;

use App\Models\User;
use App\Services\Client\Api\Operations\CartOperations;
use App\Services\Client\Api\Operations\CatalogOperations;
use App\Services\Client\Api\Operations\CheckoutOperations;
use App\Services\Client\Api\Operations\CompanyOperations;
use App\Services\Client\Api\Operations\ContentOperations;
use App\Services\Client\Api\Operations\ContractOperations;
use App\Services\Client\Api\Operations\DeliveryAddressOperations;
use App\Services\Client\Api\Operations\DocumentOperations;
use App\Services\Client\Api\Operations\FinanceOperations;
use App\Services\Client\Api\Operations\NotificationOperations;
use App\Services\Client\Api\Operations\OrderOperations;
use App\Services\Client\Api\Operations\PartnerContactOperations;
use App\Services\Client\Api\Operations\PaymentOrderOperations;
use App\Services\Client\Api\Operations\ProfileOperations;
use App\Services\Client\Api\Operations\QuestionOperations;
use App\Services\Client\Api\Operations\ReserveOperations;
use App\Services\Client\Api\Operations\ReturnOperations;
use App\Services\Client\Api\Operations\ShipmentOperations;

/**
 * Каталог операций клиентского API v1.
 *
 * Единственный список эндпоинтов клиента: из него строятся маршруты REST,
 * ответ `/me`, OpenAPI-документ и каталог инструментов MCP `/mcp/client`.
 * Второго перечня нет намеренно — у `/api/content/me` он был захардкожен
 * и уже разошёлся с маршрутами.
 *
 * Операции собираются из секций-провайдеров: каждая секция объявляет свои
 * операции рядом с обработчиками, реестр только складывает их вместе.
 */
class OperationRegistry
{
    /**
     * Секции в порядке показа в каталоге и документе.
     *
     * @var list<class-string<OperationProvider>>
     */
    private const PROVIDERS = [
        CatalogOperations::class,
        CartOperations::class,
        CheckoutOperations::class,
        ReturnOperations::class,
        ProfileOperations::class,
        OrderOperations::class,
        ShipmentOperations::class,
        ReserveOperations::class,
        DocumentOperations::class,
        ContractOperations::class,
        FinanceOperations::class,
        PaymentOrderOperations::class,
        CompanyOperations::class,
        DeliveryAddressOperations::class,
        PartnerContactOperations::class,
        QuestionOperations::class,
        NotificationOperations::class,
        ContentOperations::class,
    ];

    /** @var list<Operation>|null */
    private ?array $operations = null;

    /**
     * @return list<Operation>
     */
    public function all(): array
    {
        if ($this->operations === null) {
            $this->operations = [];

            foreach (self::PROVIDERS as $provider) {
                foreach ($provider::operations() as $operation) {
                    $this->operations[] = $operation;
                }
            }
        }

        return $this->operations;
    }

    public function find(string $id): ?Operation
    {
        foreach ($this->all() as $operation) {
            if ($operation->id === $id) {
                return $operation;
            }
        }

        return null;
    }

    /**
     * Операции, которым выдаётся маршрут REST и инструмент MCP.
     *
     * @return list<Operation>
     */
    public function callable(): array
    {
        return array_values(array_filter($this->all(), fn (Operation $o) => $o->agentAllowed));
    }

    /**
     * @return array<string, string> ключ секции → русское название
     */
    public function sections(): array
    {
        $sections = [];

        foreach (self::PROVIDERS as $provider) {
            [$key, $label] = $provider::section();
            $sections[$key] = $label;
        }

        return $sections;
    }

    /**
     * Каталог для агента: все операции (или одна секция) с флагом доступности.
     *
     * @return list<array<string, mixed>>
     */
    public function catalog(User $actor, ?string $section = null): array
    {
        $entries = [];

        foreach ($this->all() as $operation) {
            if ($section !== null && $operation->section !== $section) {
                continue;
            }

            $entries[] = $operation->catalogEntry($actor);
        }

        return $entries;
    }
}
