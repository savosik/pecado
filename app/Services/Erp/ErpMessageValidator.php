<?php

namespace App\Services\Erp;

use App\Models\ErpValidationError;
use Illuminate\Support\Facades\Log;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

/**
 * Валидатор входящих и исходящих ERP-сообщений по JSON Schema.
 *
 * Схемы расположены в app/Services/Erp/Schemas/{event_name}.json
 * и соответствуют AsyncAPI спецификации в docs/asyncapi/pecado-erp-integration.yaml
 *
 * Входящие (1С → Сайт): SCHEMA_MAP
 * Исходящие (Сайт → 1С): OUTBOUND_SCHEMA_MAP
 */
class ErpMessageValidator
{
    private Validator $validator;

    /**
     * Маппинг событий на файлы схем.
     */
    private const SCHEMA_MAP = [
        // US-02: Партнёры
        'partner.created' => 'partner.created.json',
        'partner.updated' => 'partner.updated.json',
        'partner.deleted' => 'partner.deleted.json',
        // US-03: Базовые цены
        'price.updated' => 'price.updated.json',
        // US-05: Курсы валют
        'exchange_rate.updated' => 'exchange_rate.updated.json',
        // US-06: Остатки
        'stock.updated' => 'stock.updated.json',
        // US-07: Контрагенты
        'contractor.created' => 'contractor.created.json',
        // US-08: Заказы
        'order.created' => 'order.created.json',
        'order.updated' => 'order.updated.json',
        'order.deleted' => 'order.deleted.json',
        // US-09: Возвраты
        'return.updated' => 'return.updated.json',
        'return.deleted' => 'return.deleted.json',
        // US-10: Реализации
        'shipment.created' => 'shipment.created.json',
        'shipment.updated' => 'shipment.updated.json',
        'shipment.deleted' => 'shipment.deleted.json',
        // US-11: Баланс
        'balance.updated' => 'balance.updated.json',
        // US-14: Индивидуальные цены
        'individual_prices.ready' => 'individual_prices.ready.json',
        // US-15: Каталог
        'category.created' => 'category.created.json',
        'category.updated' => 'category.created.json', // Та же структура
        'product.created' => 'product.created.json',
        'product.updated' => 'product.updated.json',
        // US-16: Промо-флаги товаров (новинка/бестселлер/ликвидация)
        'promotion.created' => 'promotion.created.json',
        'promotion.updated' => 'promotion.updated.json',
        'promotion.deleted' => 'promotion.deleted.json',
    ];

    /**
     * Маппинг исходящих событий (Сайт → 1С) на файлы схем.
     */
    private const OUTBOUND_SCHEMA_MAP = [
        'partner.created' => 'partner.created.to_erp.json',
        'order.created' => 'order.created.to_erp.json',
        'return.created' => 'return.created.to_erp.json',
    ];

    /**
     * Кеш загруженных схем.
     *
     * @var array<string, object>
     */
    private array $schemas = [];

    public function __construct()
    {
        $this->validator = new Validator;
        $this->validator->setMaxErrors(5);
    }

    /**
     * Проверить, есть ли схема для данного события.
     */
    public function hasSchema(string $event): bool
    {
        return isset(self::SCHEMA_MAP[$event]);
    }

    /**
     * Проверить, есть ли исходящая схема для данного события.
     */
    public function hasOutboundSchema(string $event): bool
    {
        return isset(self::OUTBOUND_SCHEMA_MAP[$event]);
    }

    /**
     * Валидировать исходящий payload (Сайт → 1С) по JSON Schema.
     *
     * @param  string  $event  Тип события (e.g. "order.created")
     * @param  array  $payload  Payload перед отправкой
     * @return array{valid: bool, errors: string[]}
     */
    public function validateOutbound(string $event, array $payload): array
    {
        if (! $this->hasOutboundSchema($event)) {
            return ['valid' => true, 'errors' => []];
        }

        try {
            $schema = $this->loadSchema($event, self::OUTBOUND_SCHEMA_MAP);
        } catch (\Throwable $e) {
            Log::warning('ERP Validator: не удалось загрузить исходящую схему', [
                'event' => $event,
                'error' => $e->getMessage(),
            ]);

            return ['valid' => true, 'errors' => []];
        }

        $data = json_decode(json_encode($payload));
        $result = $this->validator->validate($data, $schema);

        if ($result->isValid()) {
            return ['valid' => true, 'errors' => []];
        }

        $formatter = new ErrorFormatter;
        $errors = $formatter->format($result->error(), true);

        return ['valid' => false, 'errors' => $this->flattenErrors($errors)];
    }

    /**
     * Валидировать payload сообщения по JSON Schema.
     *
     * @param  string  $event  Тип события (e.g. "partner.created")
     * @param  array  $payload  Декодированный JSON payload
     * @return array{valid: bool, errors: string[]}
     */
    public function validate(string $event, array $payload): array
    {
        if (! $this->hasSchema($event)) {
            // Нет схемы — пропускаем валидацию
            return ['valid' => true, 'errors' => []];
        }

        try {
            $schema = $this->loadSchema($event, self::SCHEMA_MAP);
        } catch (\Throwable $e) {
            Log::warning('ERP Validator: не удалось загрузить схему', [
                'event' => $event,
                'error' => $e->getMessage(),
            ]);

            // При ошибке загрузки схемы — пропускаем валидацию, чтобы не блокировать обработку
            return ['valid' => true, 'errors' => []];
        }

        // Конвертируем в объект для opis/json-schema
        $data = json_decode(json_encode($payload));

        $result = $this->validator->validate($data, $schema);

        if ($result->isValid()) {
            return ['valid' => true, 'errors' => []];
        }

        $formatter = new ErrorFormatter;
        $errors = $formatter->format($result->error(), true);

        // Преобразуем вложенную структуру ошибок в плоский массив строк
        $flatErrors = $this->flattenErrors($errors);

        return ['valid' => false, 'errors' => $flatErrors];
    }

    /**
     * Загрузить JSON Schema из файла.
     *
     * opis/json-schema требует, чтобы $id был абсолютным URI.
     * Мы заменяем $id на абсолютный URI при загрузке.
     */
    private function loadSchema(string $event, array $schemaMap): object
    {
        $schemaFile = $schemaMap[$event];

        if (isset($this->schemas[$schemaFile])) {
            return $this->schemas[$schemaFile];
        }

        $path = __DIR__.'/Schemas/'.$schemaFile;

        if (! file_exists($path)) {
            throw new \RuntimeException("Schema file not found: {$path}");
        }

        $content = file_get_contents($path);
        $schema = json_decode($content);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException("Invalid JSON in schema: {$schemaFile}");
        }

        // opis/json-schema требует абсолютный URI в $id
        $schema->{'$id'} = 'https://pecado.local/schemas/'.$schemaFile;

        $this->schemas[$schemaFile] = $schema;

        return $schema;
    }

    /**
     * Преобразовать вложенную структуру ошибок в плоский массив строк.
     *
     * @return string[]
     */
    private function flattenErrors(array $errors): array
    {
        $flat = [];

        foreach ($errors as $path => $messages) {
            if (is_array($messages)) {
                foreach ($messages as $message) {
                    $flat[] = "{$path}: {$message}";
                }
            } else {
                $flat[] = "{$path}: {$messages}";
            }
        }

        return $flat;
    }

    /**
     * Записать ошибку валидации в БД для отображения в админке.
     *
     * @param  string  $event  Тип события (e.g. "partner.created")
     * @param  string  $direction  "incoming" или "outgoing"
     * @param  array  $errors  Массив строк с описанием ошибок
     * @param  array  $payload  Исходный payload
     */
    public function logValidationError(string $event, string $direction, array $errors, array $payload): void
    {
        try {
            ErpValidationError::create([
                'event' => $event,
                'direction' => $direction,
                'message_id' => $payload['message_id'] ?? null,
                'errors' => $errors,
                'payload' => $payload,
            ]);
        } catch (\Throwable $e) {
            Log::error('Не удалось записать ошибку валидации ERP в БД: '.$e->getMessage());
        }
    }
}
