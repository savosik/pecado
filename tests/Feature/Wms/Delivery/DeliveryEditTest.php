<?php

namespace Tests\Feature\Wms\Delivery;

use App\Models\Delivery\DeliveryShipment;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Правка черновика отправки.
 *
 * Пока заявка не ушла перевозчику, отправка — обычный черновик: состав, места
 * и получателя должно быть можно переделать, не создавая новую. Форма при этом
 * та же самая, что и при создании.
 */
class DeliveryEditTest extends DeliveryTestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(DeliveryShipment $delivery, array $overrides = []): array
    {
        return array_merge([
            'shipment_ids' => $delivery->shipments()->pluck('shipments.id')->all(),
            'delivery_type' => 1,
            'pickup_type' => 1,
            'places' => [
                ['weight' => 4200, 'length' => 60, 'width' => 40, 'height' => 30],
            ],
            'recipient' => [
                'contactName' => 'Затулина Ирина Андреевна',
                'phone' => '+79057548176',
                'city' => 'Чехов',
                'region' => 'Московская обл',
                'street' => 'ул Дружбы',
                'house' => '25',
                'index' => '142306',
            ],
        ], $overrides);
    }

    private function draftWithShipment(): DeliveryShipment
    {
        $shipment = $this->makeShipment();
        $delivery = DeliveryShipment::factory()->create(['user_id' => $shipment->user_id]);
        $delivery->shipments()->attach($shipment->id);
        $this->addPlace($delivery);

        return $delivery;
    }

    #[Test]
    #[TestDox('Форма правки открывается и приходит заполненной')]
    public function edit_form_is_prefilled(): void
    {
        $delivery = $this->draftWithShipment();

        $this->actingAs($this->userWithRole('storekeeper'));
        $props = $this->inertiaProps("/wms/deliveries/{$delivery->id}/edit");

        $this->assertSame($delivery->number, data_get($props, 'delivery.number'));
        $this->assertSame('3200', data_get($props, 'delivery.places.0.weight'));
        $this->assertCount(1, data_get($props, 'preselected'));
    }

    #[Test]
    #[TestDox('Переданную заявку править нельзя — уводит в карточку с пояснением')]
    public function submitted_delivery_cannot_be_edited(): void
    {
        $delivery = DeliveryShipment::factory()->submitted()->create();

        $this->actingAs($this->userWithRole('storekeeper'))
            ->get("/wms/deliveries/{$delivery->id}/edit")
            ->assertRedirect("/wms/deliveries/{$delivery->id}")
            ->assertSessionHas('error');
    }

    #[Test]
    #[TestDox('Правка меняет места и получателя')]
    public function update_changes_places_and_recipient(): void
    {
        $delivery = $this->draftWithShipment();

        $this->actingAs($this->userWithRole('storekeeper'))
            ->put("/wms/deliveries/{$delivery->id}", $this->payload($delivery))
            ->assertSessionHas('success');

        $delivery->refresh();

        $this->assertSame(1, $delivery->places()->count());
        $this->assertSame(4200, $delivery->places()->first()->weight);
        $this->assertSame(4200, $delivery->declared_weight);
        $this->assertSame('Чехов', $delivery->recipient['city']);
    }

    #[Test]
    #[TestDox('Правка переданной заявки отклоняется')]
    public function update_is_rejected_after_submit(): void
    {
        $shipment = $this->makeShipment();
        $delivery = DeliveryShipment::factory()->submitted()->create(['user_id' => $shipment->user_id]);
        $delivery->shipments()->attach($shipment->id);
        $this->addPlace($delivery);

        $this->actingAs($this->userWithRole('storekeeper'))
            ->put("/wms/deliveries/{$delivery->id}", $this->payload($delivery))
            ->assertSessionHas('error');

        $this->assertSame(3200, $delivery->refresh()->places()->first()->weight);
    }

    #[Test]
    #[TestDox('Без права на правку форма недоступна')]
    public function edit_requires_permission(): void
    {
        $delivery = $this->draftWithShipment();

        // Пользователя без складских прав гейт кабинета уводит на витрину,
        // не показывая, что такой адрес вообще существует.
        $this->actingAs(\App\Models\User::factory()->create())
            ->get("/wms/deliveries/{$delivery->id}/edit")
            ->assertRedirect('/');
    }
}
