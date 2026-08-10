<?php

namespace App\Http\Requests\Wms;

use App\Models\Delivery\DeliveryShipment;
use Illuminate\Validation\Rule;

/**
 * Правка черновика: состав и места те же, что при создании, плюс выбранный тариф.
 *
 * Наследуемся от создания, чтобы правила адреса и мест не разъехались между
 * двумя формами: у отправки это буквально одна и та же карточка.
 */
class UpdateDeliveryShipmentRequest extends StoreDeliveryShipmentRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('wms-deliveries.edit') ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'provider_key' => ['nullable', 'string', 'max:50'],
            'tariff_id' => ['nullable', 'integer', 'min:1'],
            'tariff_name' => ['nullable', 'string', 'max:255'],
            'delivery_cost' => ['nullable', 'numeric', 'min:0'],
            'delivery_cost_original' => ['nullable', 'numeric', 'min:0'],
            // Пункт выдачи обязателен ровно тогда, когда груз едет до ПВЗ.
            'point_id' => [
                Rule::requiredIf(fn (): bool => (int) $this->input('delivery_type') === DeliveryShipment::DELIVERY_TYPE_POINT),
                'nullable', 'string', 'max:50',
            ],
            'point_address' => ['nullable', 'string', 'max:500'],
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'point_id.required' => 'Выберите пункт выдачи — при доставке до ПВЗ он обязателен.',
            'tariff_id.min' => 'Некорректный тариф.',
            'delivery_cost.min' => 'Стоимость доставки не может быть отрицательной.',
        ]);
    }
}
