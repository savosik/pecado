<?php

namespace App\Support\Crm;

use App\Enums\Crm\BusinessType;
use App\Enums\Crm\ClientSpecialization;
use App\Enums\Crm\CreditRating;
use App\Enums\Crm\DeliveryMethod;
use App\Enums\Crm\NoveltyAttitude;
use App\Enums\Crm\PaymentType;
use App\Enums\Crm\PointLocation;
use App\Enums\Crm\PriceSegment;
use App\Enums\Crm\Psychotype;
use App\Enums\Crm\SalesChannel;
use App\Enums\Crm\StaffLevel;
use App\Models\CrmClientProfile;
use App\Services\Crm\Api\Param;
use Illuminate\Validation\Rule;

/**
 * Паспорт клиента — единственное описание полей, которые отдел собирает интервью.
 *
 * Одно поле живёт минимум в четырёх местах: колонка в базе, правило проверки формы,
 * аргумент операции агентского API и подпись в карточке. Расписанные по разным
 * файлам, они расходятся на первой же правке — и агент начинает слать поле, которое
 * форма не покажет, либо форма показывает поле, которое сервер молча отбросит.
 * Поэтому перечень один, а всё остальное из него выводится.
 *
 * Здесь только атрибуты паспорта. Поля, которые были в профиле до него (ЛПР,
 * платёжное поведение, цикл закупок, заметки), остаются на своих местах: они уже
 * описаны и в реестре операций, и в форме.
 */
final class ClientPassport
{
    /** Секции карточки — порядок задаёт и порядок вопросов интервью. */
    public const SECTIONS = [
        'business' => 'Бизнес клиента',
        'logistics' => 'География и логистика',
        'commercial' => 'Коммерческие условия',
        'limits' => 'Ограничения и конкуренты',
        'contacts' => 'Контакты по ролям',
        'communication' => 'Как общаться',
        'marketing' => 'Маркетинг',
    ];

    /**
     * Описание полей: ключ → тип, секция, подпись, подсказка, ограничения.
     *
     * `type`: enum | string | text | integer | date.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function fields(): array
    {
        return [
            // --- Бизнес ---
            'business_type' => [
                'type' => 'enum', 'enum' => BusinessType::class, 'section' => 'business',
                'label' => 'Вид бизнеса', 'hint' => 'Офлайн-розница, интернет-магазин, сеть или опт',
            ],
            'points_count' => [
                'type' => 'integer', 'section' => 'business', 'min' => 0, 'max' => 5000,
                'label' => 'Количество точек', 'hint' => 'Сколько торговых точек у клиента',
            ],
            'specialization' => [
                'type' => 'enum', 'enum' => ClientSpecialization::class, 'section' => 'business',
                'label' => 'Специализация', 'hint' => 'Какую матрицу держит клиент',
            ],
            'primary_channel' => [
                'type' => 'enum', 'enum' => SalesChannel::class, 'section' => 'business',
                'label' => 'Основной канал', 'hint' => 'Где клиент делает основной оборот',
            ],
            'secondary_channel' => [
                'type' => 'enum', 'enum' => SalesChannel::class, 'section' => 'business',
                'label' => 'Вторичный канал', 'hint' => 'Низкоприоритетный канал — допродажи по нему не форсируем',
            ],
            'point_location' => [
                'type' => 'enum', 'enum' => PointLocation::class, 'section' => 'business',
                'label' => 'Локация точек', 'hint' => 'Вокзалы, спальные районы, ТЦ, стрит-ритейл, промзона',
            ],
            'price_segment' => [
                'type' => 'enum', 'enum' => PriceSegment::class, 'section' => 'business',
                'label' => 'Ценовой сегмент', 'hint' => 'Отсекает неподходящий товар до подбора',
            ],
            'staff_level' => [
                'type' => 'enum', 'enum' => StaffLevel::class, 'section' => 'business',
                'label' => 'Уровень продавцов', 'hint' => 'Новичкам — простое и ходовое, экспертам — сложное',
            ],

            // --- География и логистика ---
            'regions' => [
                'type' => 'string', 'section' => 'logistics', 'max' => 255,
                'label' => 'Регионы присутствия', 'hint' => 'Если отличаются от юридического адреса',
            ],
            'delivery_method' => [
                'type' => 'enum', 'enum' => DeliveryMethod::class, 'section' => 'logistics',
                'label' => 'Способ доставки', 'hint' => 'Самовывоз, везём мы или транспортная компания',
            ],
            'carrier' => [
                'type' => 'string', 'section' => 'logistics', 'max' => 255,
                'label' => 'Транспортная компания', 'hint' => 'Какая ТК и до какого терминала или адреса',
            ],
            'receiving_hours' => [
                'type' => 'string', 'section' => 'logistics', 'max' => 255,
                'label' => 'График приёмки', 'hint' => 'Дни и часы, когда клиент принимает товар',
            ],
            'packaging_notes' => [
                'type' => 'text', 'section' => 'logistics', 'max' => 2000,
                'label' => 'Упаковка и маркировка', 'hint' => 'Особые требования к отгрузке',
            ],

            // --- Коммерческие условия ---
            'payment_type' => [
                'type' => 'enum', 'enum' => PaymentType::class, 'section' => 'commercial',
                'label' => 'Тип оплаты', 'hint' => 'Договорное условие, а не наблюдение за платежами',
            ],
            'deferral_days' => [
                'type' => 'integer', 'section' => 'commercial', 'min' => 0, 'max' => 365,
                'label' => 'Отсрочка, дней', 'hint' => 'Сколько дней отсрочки согласовано',
            ],
            'credit_rating' => [
                'type' => 'enum', 'enum' => CreditRating::class, 'section' => 'commercial',
                'label' => 'Опыт оплаты', 'hint' => 'Как клиент платит по факту',
            ],
            'commercial_terms' => [
                'type' => 'text', 'section' => 'commercial', 'max' => 2000,
                'label' => 'Дополнительные условия', 'hint' => 'Бесплатная доставка, компенсация логистики, спецскидка',
            ],
            'unique_terms' => [
                'type' => 'text', 'section' => 'commercial', 'max' => 2000,
                'label' => 'Уникальные договорённости', 'hint' => 'Индивидуальные условия — с датой, с которой действуют',
            ],

            // --- Ограничения и конкуренты ---
            'taboo_categories' => [
                'type' => 'text', 'section' => 'limits', 'max' => 2000,
                'label' => 'Табу-категории', 'hint' => 'Что клиент принципиально не берёт — исключается из допродаж',
            ],
            'taboo_brands' => [
                'type' => 'text', 'section' => 'limits', 'max' => 2000,
                'label' => 'Табу-бренды', 'hint' => 'С кем не работает или берёт у эксклюзивных поставщиков',
            ],
            'competitors' => [
                'type' => 'text', 'section' => 'limits', 'max' => 2000,
                'label' => 'Альтернативные поставщики', 'hint' => 'С кем ещё работает, чьи бренды стоят рядом',
            ],

            // --- Контакты по ролям ---
            'decision_maker_birthday' => [
                'type' => 'date', 'section' => 'contacts',
                'label' => 'День рождения ЛПР', 'hint' => 'Формат ГГГГ-ММ-ДД',
            ],
            'accountant_name' => [
                'type' => 'string', 'section' => 'contacts', 'max' => 255,
                'label' => 'Бухгалтер', 'hint' => 'ФИО — контакт по дебиторке',
            ],
            'accountant_contact' => [
                'type' => 'string', 'section' => 'contacts', 'max' => 255,
                'label' => 'Контакт бухгалтера', 'hint' => 'Телефон и почта',
            ],
            'owner_name' => [
                'type' => 'string', 'section' => 'contacts', 'max' => 255,
                'label' => 'Собственник', 'hint' => 'ФИО — для экстренной дебиторки и крупных сделок',
            ],
            'owner_contact' => [
                'type' => 'string', 'section' => 'contacts', 'max' => 255,
                'label' => 'Контакт собственника', 'hint' => 'Телефон и почта',
            ],

            // --- Как общаться ---
            'novelty_attitude' => [
                'type' => 'enum', 'enum' => NoveltyAttitude::class, 'section' => 'communication',
                'label' => 'Отношение к новинкам', 'hint' => 'Консерватору новинку предлагаем последней',
            ],
            'psychotype' => [
                'type' => 'enum', 'enum' => Psychotype::class, 'section' => 'communication',
                'label' => 'Стиль общения', 'hint' => 'Задаёт форму разговора и письма',
            ],

            // --- Маркетинг ---
            'marketing_needs' => [
                'type' => 'text', 'section' => 'marketing', 'max' => 2000,
                'label' => 'Потребность в поддержке', 'hint' => 'Тестеры, POS-материалы, обучение персонала',
            ],
            'traffic_work' => [
                'type' => 'text', 'section' => 'marketing', 'max' => 2000,
                'label' => 'Работа с трафиком', 'hint' => 'Блогеры, инфлюенсеры, реклама',
            ],
        ];
    }

    /**
     * Ключи полей паспорта — для `only()` и списков доступных полей.
     *
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::fields());
    }

    /**
     * Поля, которые нельзя отправлять пустой строкой: селекты и числа.
     *
     * Очищенный менеджером селект приходит как `''` и упал бы на проверке енума,
     * поэтому перед валидацией такие значения превращаются в null.
     *
     * @return list<string>
     */
    public static function nullableOnEmpty(): array
    {
        return array_keys(array_filter(
            self::fields(),
            fn (array $field): bool => in_array($field['type'], ['enum', 'integer', 'date'], true),
        ));
    }

    /**
     * Правила проверки для формы карточки.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        $rules = [];

        foreach (self::fields() as $key => $field) {
            $rules[$key] = match ($field['type']) {
                'enum' => ['nullable', Rule::enum($field['enum'])],
                'integer' => ['nullable', 'integer', 'min:'.($field['min'] ?? 0), 'max:'.($field['max'] ?? 65535)],
                'date' => ['nullable', 'date'],
                default => ['nullable', 'string', 'max:'.($field['max'] ?? 255)],
            };
        }

        return $rules;
    }

    /**
     * Сообщения об ошибках — по-русски и по делу.
     *
     * @return array<string, string>
     */
    public static function messages(): array
    {
        $messages = [];

        foreach (self::fields() as $key => $field) {
            $label = mb_strtolower($field['label']);

            $messages += match ($field['type']) {
                'enum' => [$key.'.enum' => "Выберите значение поля «{$field['label']}» из списка."],
                'integer' => [
                    $key.'.integer' => "Поле «{$field['label']}» указывается целым числом.",
                    $key.'.min' => "Значение поля «{$field['label']}» слишком мало.",
                    $key.'.max' => "Значение поля «{$field['label']}» слишком велико.",
                ],
                'date' => [$key.'.date' => "Укажите {$label} датой в формате ГГГГ-ММ-ДД."],
                default => [$key.'.max' => "Поле «{$field['label']}» длиннее допустимого."],
            };
        }

        return $messages;
    }

    /**
     * Аргументы операции `client.profile.update` агентского API.
     *
     * @return list<Param>
     */
    public static function apiParams(): array
    {
        $params = [];

        foreach (self::fields() as $key => $field) {
            $description = $field['label'].' — '.$field['hint'];

            $params[] = match ($field['type']) {
                'enum' => Param::string($key, $description, enum: array_column($field['enum']::cases(), 'value'), nullable: true),
                'integer' => Param::integer($key, $description, rules: ['min:'.($field['min'] ?? 0), 'max:'.($field['max'] ?? 65535)], nullable: true),
                'date' => Param::string($key, $description.' (ГГГГ-ММ-ДД)', rules: ['date'], nullable: true),
                default => Param::string($key, $description, rules: ['max:'.($field['max'] ?? 255)], nullable: true),
            };
        }

        return $params;
    }

    /**
     * Списки значений для селектов карточки.
     *
     * @return array<string, list<array{value: string, label: string}>>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::fields() as $key => $field) {
            if ($field['type'] === 'enum') {
                $options[$key] = $field['enum']::options();
            }
        }

        return $options;
    }

    /**
     * Секции с полями — из них рисуется форма и строится план интервью.
     *
     * @return array<string, array{label: string, fields: list<array<string, mixed>>}>
     */
    public static function sections(): array
    {
        $sections = [];

        foreach (self::SECTIONS as $key => $label) {
            $sections[$key] = ['label' => $label, 'fields' => []];
        }

        foreach (self::fields() as $key => $field) {
            $sections[$field['section']]['fields'][] = [
                'key' => $key,
                'type' => $field['type'],
                'label' => $field['label'],
                'hint' => $field['hint'],
            ];
        }

        return $sections;
    }

    /**
     * Значения паспорта из профиля — сырые, пригодные и для формы, и для API.
     *
     * @return array<string, mixed>
     */
    public static function values(CrmClientProfile $profile): array
    {
        $values = [];

        foreach (self::fields() as $key => $field) {
            $value = $profile->getAttribute($key);

            $values[$key] = match (true) {
                $value === null => null,
                $field['type'] === 'enum' => $value->value,
                $field['type'] === 'date' => $value->format('Y-m-d'),
                default => $value,
            };
        }

        return $values;
    }

    /**
     * Подписи выбранных значений — чтобы карточка в режиме чтения не переводила
     * енумы сама и не разошлась с формой.
     *
     * @return array<string, string|null>
     */
    public static function labels(CrmClientProfile $profile): array
    {
        $labels = [];

        foreach (self::fields() as $key => $field) {
            if ($field['type'] !== 'enum') {
                continue;
            }

            $labels[$key] = $profile->getAttribute($key)?->label();
        }

        return $labels;
    }

    /**
     * Насколько паспорт заполнен: сколько полей из скольких.
     *
     * Нужна и агенту (кого расспрашивать первым), и карточке — показать менеджеру,
     * что клиент описан наполовину.
     *
     * @return array{filled: int, total: int, percent: int}
     */
    public static function completeness(CrmClientProfile $profile): array
    {
        $total = count(self::fields());
        $filled = 0;

        foreach (self::keys() as $key) {
            $value = $profile->getAttribute($key);

            if ($value !== null && $value !== '') {
                $filled++;
            }
        }

        return [
            'filled' => $filled,
            'total' => $total,
            'percent' => $total > 0 ? (int) round($filled / $total * 100) : 0,
        ];
    }
}
