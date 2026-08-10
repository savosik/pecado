<?php

namespace App\Http\Requests\Wms;

use App\Models\Delivery\DeliveryShipment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Создание черновика отправки: состав, места и адрес получателя.
 *
 * Тариф на этом шаге ещё не выбран — его считают уже по сохранённому черновику,
 * потому что расчёт зависит от того же веса и адреса, что тут задаются.
 */
class StoreDeliveryShipmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('wms-deliveries.create') ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'shipment_ids' => ['required', 'array', 'min:1', 'max:50'],
            'shipment_ids.*' => ['integer', 'exists:shipments,id'],

            'delivery_type' => ['required', Rule::in([
                DeliveryShipment::DELIVERY_TYPE_DOOR,
                DeliveryShipment::DELIVERY_TYPE_POINT,
            ])],
            'pickup_type' => ['required', Rule::in([
                DeliveryShipment::PICKUP_TYPE_COURIER,
                DeliveryShipment::PICKUP_TYPE_SELF,
            ])],

            'pickup_date' => ['nullable', 'date', 'after_or_equal:today'],
            'comment' => ['nullable', 'string', 'max:2000'],

            'places' => ['required', 'array', 'min:1', 'max:100'],
            'places.*.weight' => ['required', 'integer', 'min:1', 'max:1000000'],
            'places.*.length' => ['nullable', 'integer', 'min:1', 'max:500'],
            'places.*.width' => ['nullable', 'integer', 'min:1', 'max:500'],
            'places.*.height' => ['nullable', 'integer', 'min:1', 'max:500'],

            // Адрес приходит уже разобранным: фронт отдаёт подсказку DaData,
            // а бэкенд доразбирает свободный текст через DeliveryAddressResolver.
            'recipient' => ['required', 'array'],
            'recipient.contactName' => ['required', 'string', 'max:255'],
            'recipient.phone' => ['required', 'string', 'max:32'],
            'recipient.email' => ['nullable', 'email', 'max:255'],
            'recipient.companyName' => ['nullable', 'string', 'max:255'],
            'recipient.countryCode' => ['nullable', 'string', 'size:2'],
            'recipient.region' => ['nullable', 'string', 'max:150'],
            'recipient.city' => ['required', 'string', 'max:150'],
            'recipient.street' => ['nullable', 'string', 'max:255'],
            'recipient.house' => ['nullable', 'string', 'max:50'],
            'recipient.block' => ['nullable', 'string', 'max:50'],
            'recipient.office' => ['nullable', 'string', 'max:50'],
            'recipient.index' => ['nullable', 'string', 'max:10'],
            'recipient.addressString' => ['nullable', 'string', 'max:500'],
            'recipient.lat' => ['nullable', 'numeric'],
            'recipient.lng' => ['nullable', 'numeric'],
        ];
    }

    /**
     * До двери без дома везти некуда, а до ПВЗ дом наоборот не нужен.
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $isDoor = (int) $this->input('delivery_type') === DeliveryShipment::DELIVERY_TYPE_DOOR;
                $house = trim((string) $this->input('recipient.house'));

                if ($isDoor && $house === '') {
                    $validator->errors()->add(
                        'recipient.house',
                        'Для доставки до двери укажите номер дома.'
                    );
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'shipment_ids.required' => 'Выберите хотя бы одну реализацию.',
            'shipment_ids.min' => 'Выберите хотя бы одну реализацию.',
            'shipment_ids.max' => 'В одну отправку можно включить не больше 50 реализаций.',
            'shipment_ids.*.exists' => 'Одна из выбранных реализаций не найдена.',
            'delivery_type.required' => 'Выберите тип доставки.',
            'delivery_type.in' => 'Неизвестный тип доставки.',
            'pickup_type.required' => 'Выберите способ передачи груза перевозчику.',
            'pickup_type.in' => 'Неизвестный способ передачи груза.',
            'pickup_date.after_or_equal' => 'Дата передачи груза не может быть в прошлом.',
            'comment.max' => 'Комментарий слишком длинный (максимум 2000 символов).',
            'places.required' => 'Добавьте хотя бы одно грузовое место.',
            'places.min' => 'Добавьте хотя бы одно грузовое место.',
            'places.max' => 'Слишком много мест (максимум 100).',
            'places.*.weight.required' => 'Укажите вес места в граммах.',
            'places.*.weight.min' => 'Вес места должен быть больше нуля.',
            'places.*.weight.max' => 'Вес места слишком большой (максимум 1 000 000 г).',
            'places.*.length.max' => 'Длина места не может превышать 500 см.',
            'places.*.width.max' => 'Ширина места не может превышать 500 см.',
            'places.*.height.max' => 'Высота места не может превышать 500 см.',
            'recipient.required' => 'Заполните данные получателя.',
            'recipient.contactName.required' => 'Укажите контактное лицо получателя.',
            'recipient.phone.required' => 'Укажите телефон получателя.',
            'recipient.email.email' => 'Некорректный email получателя.',
            'recipient.city.required' => 'Укажите город получателя — без него доставку не рассчитать.',
        ];
    }
}
