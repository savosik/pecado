<?php

namespace App\Services\Promotion;

use App\Enums\PromoKind;
use App\Models\PromotionRule;
use App\Models\Warehouse;
use Illuminate\Support\Facades\Schema as DbSchema;
use Illuminate\Validation\ValidationException;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

/**
 * Валидация конфигурации правила акции перед сохранением.
 *
 * Работает в два прохода, по образцу App\Services\Erp\ErpMessageValidator:
 *  1. структура — JSON Schema из app/Services/Promotion/Schemas/*.json
 *     (сообщения об ошибках заданы в самих схемах через `$error`);
 *  2. смысл — проверки, которые схемой не выражаются: непустой селектор,
 *     кратность с потолком и с шагом не больше порога, склад под вид
 *     промо-позиции.
 *
 * Все сообщения — на русском: их видит маркетолог в конструкторе акций.
 */
class PromotionRuleSchemaValidator
{
    /** Поле правила → файл схемы. */
    private const SCHEMA_MAP = [
        'conditions' => 'conditions.json',
        'rewards' => 'rewards.json',
        'audience' => 'audience.json',
        'limits' => 'limits.json',
    ];

    private Validator $validator;

    /** @var array<string, object> */
    private array $schemas = [];

    public function __construct(private readonly PromotionRuleProductResolver $resolver)
    {
        $this->validator = new Validator;
        $this->validator->setMaxErrors(10);
    }

    /**
     * Проверить конфигурацию правила.
     *
     * @param  array<string, mixed>  $rule  Поля правила: conditions, rewards, audience, limits, is_active
     * @return array{valid: bool, errors: array<string, string[]>}
     */
    public function validate(array $rule): array
    {
        $errors = [];

        foreach (self::SCHEMA_MAP as $field => $schemaFile) {
            $value = $rule[$field] ?? null;

            // audience и limits необязательны
            if ($value === null && in_array($field, ['audience', 'limits'], true)) {
                continue;
            }

            $fieldErrors = $this->validateAgainstSchema($schemaFile, $this->normalize($field, $value));

            if ($fieldErrors !== []) {
                $errors[$field] = $fieldErrors;
            }
        }

        // Смысловые проверки имеет смысл делать только по структурно корректным данным
        if (! isset($errors['conditions'])) {
            $conditionErrors = $this->validateConditionsMeaning((array) ($rule['conditions'] ?? []));

            if ($conditionErrors !== []) {
                $errors['conditions'] = array_merge($errors['conditions'] ?? [], $conditionErrors);
            }
        }

        if (! isset($errors['rewards'])) {
            $rewardErrors = $this->validateRewardsMeaning(
                (array) ($rule['rewards'] ?? []),
                (bool) ($rule['is_active'] ?? false),
                $this->conditionsProvideStep((array) ($rule['conditions'] ?? [])),
            );

            if ($rewardErrors !== []) {
                $errors['rewards'] = array_merge($errors['rewards'] ?? [], $rewardErrors);
            }
        }

        return ['valid' => $errors === [], 'errors' => $errors];
    }

    /**
     * Бросить ValidationException, если конфигурация некорректна.
     *
     * @param  array<string, mixed>  $rule
     *
     * @throws ValidationException
     */
    public function assertValid(array $rule): void
    {
        $result = $this->validate($rule);

        if (! $result['valid']) {
            throw ValidationException::withMessages($result['errors']);
        }
    }

    /**
     * Пустой ассоциативный массив в PHP неотличим от пустого списка, и json_encode
     * превращает его в `[]`. Для полей-объектов это ломает проверку типа, поэтому
     * подставляем пустой объект.
     */
    private function normalize(string $field, mixed $value): mixed
    {
        if (in_array($field, ['audience', 'limits'], true) && $value === []) {
            return new \stdClass;
        }

        if ($field !== 'conditions' || ! is_array($value)) {
            return $value;
        }

        foreach ($value['items'] ?? [] as $index => $item) {
            if (is_array($item) && ($item['selector'] ?? null) === []) {
                $value['items'][$index]['selector'] = new \stdClass;
            }
        }

        return $value;
    }

    /**
     * Структурная проверка одного поля по JSON Schema.
     *
     * @return string[]
     */
    private function validateAgainstSchema(string $schemaFile, mixed $value): array
    {
        $schema = $this->loadSchema($schemaFile);
        $data = json_decode(json_encode($value));

        $result = $this->validator->validate($data, $schema);

        if ($result->isValid()) {
            return [];
        }

        $formatted = (new ErrorFormatter)->format($result->error(), true);

        $messages = [];
        foreach ($formatted as $path => $pathMessages) {
            foreach ((array) $pathMessages as $message) {
                $messages[] = $this->decorateWithPath($message, (string) $path);
            }
        }

        return array_values(array_unique($messages));
    }

    /**
     * Добавить к сообщению номер позиции, если ошибка внутри списка.
     */
    private function decorateWithPath(string $message, string $path): string
    {
        if (preg_match('~/items/(\d+)~', $path, $matches) === 1) {
            return 'Условие '.((int) $matches[1] + 1).': '.$message;
        }

        if (preg_match('~^/(\d+)~', $path, $matches) === 1) {
            return 'Награда '.((int) $matches[1] + 1).': '.$message;
        }

        return $message;
    }

    /**
     * Смысловые проверки условий.
     *
     * @param  array<string, mixed>  $conditions
     * @return string[]
     */
    private function validateConditionsMeaning(array $conditions): array
    {
        $errors = [];

        foreach (array_values((array) ($conditions['items'] ?? [])) as $index => $item) {
            $number = $index + 1;
            $item = (array) $item;
            $selector = (array) ($item['selector'] ?? []);

            $hasSelection = $this->resolver->selectorQuery($selector) !== null;
            $wholeCart = (bool) ($selector['whole_cart'] ?? false);

            if (! $hasSelection && ! $wholeCart) {
                $errors[] = "Условие {$number}: не выбрано ни одного товара. "
                    .'Укажите товары, категории, бренды, теги или ERP-промо, '
                    .'либо явно включите «вся корзина».';
            }

            $errors = array_merge($errors, $this->validateConditionStep($item, $number));
        }

        return $errors;
    }

    /**
     * Шаг кратности в позиции условия.
     *
     * Две ловушки, из-за которых правило срабатывало бы, а награда не выдавалась:
     * шаг больше порога (порог взят, а кратность — ноль) и шаг при сравнении
     * «не больше»/«меньше», где делить набранное на шаг попросту бессмысленно.
     *
     * @param  array<string, mixed>  $item
     * @return string[]
     */
    private function validateConditionStep(array $item, int $number): array
    {
        $perValue = $item['per_value'] ?? null;

        if ($perValue === null || ! is_numeric($perValue)) {
            return [];
        }

        $step = (float) $perValue;
        $target = (float) ($item['value'] ?? 0);
        $operator = (string) ($item['operator'] ?? '>=');

        if (! in_array($operator, ['>=', '>'], true)) {
            return ["Условие {$number}: кратность работает только со сравнением «не меньше» или «больше»."];
        }

        if ($step > $target) {
            return ["Условие {$number}: шаг кратности ({$this->plain($step)}) больше порога ({$this->plain($target)}). "
                .'Порог был бы взят, а награда не выдалась бы ни разу — задайте шаг не больше порога.'];
        }

        return [];
    }

    /**
     * Задаёт ли хоть одна позиция условия свой шаг кратности.
     *
     * @param  array<string, mixed>  $conditions
     */
    private function conditionsProvideStep(array $conditions): bool
    {
        foreach ((array) ($conditions['items'] ?? []) as $item) {
            $perValue = ((array) $item)['per_value'] ?? null;

            if (is_numeric($perValue) && (float) $perValue > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Число без хвоста из нулей — для подстановки в текст ошибки.
     */
    private function plain(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    /**
     * Смысловые проверки наград.
     *
     * Товар награды **разрешено** держать и в условии: «каждый шестой такой же
     * товар в подарок» — обычная механика. Промо-строки движок в агрегаты не
     * берёт (PromoContext::countableLines), поэтому подарок собственное условие
     * не подкручивает.
     *
     * @param  array<int, mixed>  $rewards
     * @param  bool  $isActive  Правило включают — проверки, блокирующие только активацию
     * @param  bool  $conditionsProvideStep  Шаг кратности задан в позициях условия
     * @return string[]
     */
    private function validateRewardsMeaning(array $rewards, bool $isActive, bool $conditionsProvideStep = false): array
    {
        $errors = [];

        foreach (array_values($rewards) as $index => $reward) {
            $number = $index + 1;
            $reward = (array) $reward;

            $errors = array_merge(
                $errors,
                $this->validateRewardProducts($reward, $number),
                $this->validateRewardPrice($reward, $number),
                $this->validateRewardMultiply($reward, $number, $conditionsProvideStep),
                $this->validateRewardWarehouse($reward, $number, $isActive),
            );
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $reward
     * @return string[]
     */
    private function validateRewardProducts(array $reward, int $number): array
    {
        $errors = [];
        $type = $reward['type'] ?? null;

        if ($type === PromotionRule::REWARD_TYPE_FIXED) {
            if (empty($reward['product_id'])) {
                $errors[] = "Награда {$number}: для типа «конкретный товар» нужно выбрать товар.";
            }
        }

        if ($type === PromotionRule::REWARD_TYPE_CHOICE) {
            $choices = array_values(array_unique(array_filter(
                array_map('intval', (array) ($reward['choices'] ?? [])),
                fn (int $id) => $id > 0,
            )));

            if (count($choices) < 2) {
                $errors[] = "Награда {$number}: для типа «на выбор» нужно указать минимум два товара.";
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $reward
     * @return string[]
     */
    private function validateRewardPrice(array $reward, int $number): array
    {
        if (! array_key_exists('price', $reward) || ! is_numeric($reward['price'])) {
            return [];
        }

        $price = (float) $reward['price'];

        if ($price < 0) {
            return ["Награда {$number}: промо-цена не может быть отрицательной."];
        }

        if (abs(round($price, 2) - $price) > PHP_FLOAT_EPSILON) {
            return ["Награда {$number}: промо-цена задаётся в рублях, не более двух знаков после запятой."];
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $reward
     * @return string[]
     */
    private function validateRewardMultiply(array $reward, int $number, bool $conditionsProvideStep = false): array
    {
        if (($reward['multiply'] ?? null) !== PromotionRule::MULTIPLY_PER_THRESHOLD) {
            return [];
        }

        $errors = [];
        $perValue = $reward['per_value'] ?? null;
        $maxMultiplier = $reward['max_multiplier'] ?? null;

        // Шаг задан в позициях условия — у каждой свой, и общий шаг в награде не нужен
        if (! $conditionsProvideStep && (! is_numeric($perValue) || (float) $perValue <= 0)) {
            $errors[] = "Награда {$number}: для кратности «на каждые N» нужен шаг больше нуля — "
                .'задайте его здесь либо в позициях условия, если у артикулов кратность разная.';
        }

        if (! is_numeric($maxMultiplier) || (int) $maxMultiplier < 1) {
            $errors[] = "Награда {$number}: для кратности «на каждые N» обязателен потолок — "
                .'сколько раз максимум выдаётся промо-позиция. Без потолка одна крупная закупка '
                .'выметет весь остаток склада.';
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $reward
     * @return string[]
     */
    private function validateRewardWarehouse(array $reward, int $number, bool $isActive): array
    {
        $promoKind = $reward['promo_kind'] ?? null;
        $warehouseId = ! empty($reward['warehouse_id']) ? (int) $reward['warehouse_id'] : null;

        $warehouse = $warehouseId !== null
            ? Warehouse::query()->find($warehouseId)
            : null;

        if ($warehouseId !== null && $warehouse === null) {
            return ["Награда {$number}: указанный склад-источник не найден."];
        }

        if ($promoKind === PromoKind::SAMPLE->value) {
            return $this->validateSampleWarehouse($warehouse, $number, $isActive);
        }

        if ($warehouse !== null && $warehouse->is_defect) {
            return ["Награда {$number}: склад некондиции не может быть источником промо-позиции."];
        }

        return [];
    }

    /**
     * Пробники живут на складе «Москва реклама» — он появляется в волне 3.
     * До тех пор награду-пробник можно сохранить, но правило с ней не включается.
     *
     * @return string[]
     */
    private function validateSampleWarehouse(?Warehouse $warehouse, int $number, bool $isActive): array
    {
        $flagExists = DbSchema::hasColumn('warehouses', 'is_promo_sample');

        if (! $flagExists) {
            return $isActive
                ? ["Награда {$number}: пробники появятся вместе со складом «Москва реклама» — "
                    .'правило с наградой-пробником пока нельзя включить.']
                : [];
        }

        if ($warehouse === null) {
            return ["Награда {$number}: для пробника нужно указать склад «Москва реклама»."];
        }

        if (! $warehouse->getAttribute('is_promo_sample')) {
            return ["Награда {$number}: пробник можно выдавать только со склада пробников."];
        }

        return [];
    }

    /**
     * Загрузить JSON Schema. opis/json-schema требует абсолютный URI в $id.
     */
    private function loadSchema(string $schemaFile): object
    {
        if (isset($this->schemas[$schemaFile])) {
            return $this->schemas[$schemaFile];
        }

        $path = __DIR__.'/Schemas/'.$schemaFile;

        if (! file_exists($path)) {
            throw new \RuntimeException("Не найден файл схемы правила акции: {$path}");
        }

        $schema = json_decode((string) file_get_contents($path));

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException("Некорректный JSON в схеме правила акции: {$schemaFile}");
        }

        $schema->{'$id'} = 'https://pecado.local/schemas/promotion/'.$schemaFile;

        $this->schemas[$schemaFile] = $schema;

        return $schema;
    }
}
