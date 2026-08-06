<?php

namespace Tests\Feature\Admin;

use App\Models\Certificate;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Сертификаты в форме товара.
 *
 * Селектор сертификатов хранит выбранные значения объектами
 * {id, name, type, status}, а валидация ждала массив id — сохранение
 * товара с сертификатами падало с «Значение поля certificates.0 не существует».
 */
class AdminProductCertificatesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('super-admin');
    }

    #[Test]
    public function товар_сохраняется_когда_сертификаты_переданы_объектами(): void
    {
        $product = Product::factory()->create();
        $certificate = Certificate::create([
            'name' => 'Декларация соответствия',
            'type' => 'declaration',
        ]);

        $this->actingAs($this->admin)
            ->put(route('admin.products.update', $product->id), [
                'name' => $product->name,
                'base_price' => 100,
                'certificates' => [
                    ['id' => $certificate->id, 'name' => $certificate->name, 'type' => $certificate->type, 'status' => 'active'],
                ],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame([$certificate->id], $product->certificates()->pluck('certificates.id')->all());
    }

    #[Test]
    public function товар_сохраняется_когда_сертификаты_переданы_идентификаторами(): void
    {
        $product = Product::factory()->create();
        $certificate = Certificate::create([
            'name' => 'Сертификат соответствия',
            'type' => 'certificate',
        ]);

        $this->actingAs($this->admin)
            ->put(route('admin.products.update', $product->id), [
                'name' => $product->name,
                'base_price' => 100,
                'certificates' => [$certificate->id],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame([$certificate->id], $product->certificates()->pluck('certificates.id')->all());
    }

    #[Test]
    public function несуществующий_сертификат_по_прежнему_отклоняется(): void
    {
        $product = Product::factory()->create();

        $this->actingAs($this->admin)
            ->put(route('admin.products.update', $product->id), [
                'name' => $product->name,
                'base_price' => 100,
                'certificates' => [['id' => 999999, 'name' => 'Нет такого', 'type' => 'certificate']],
            ])
            ->assertSessionHasErrors('certificates.0');
    }

    #[Test]
    public function новый_товар_создаётся_с_сертификатами_объектами(): void
    {
        $certificate = Certificate::create([
            'name' => 'Отказное письмо',
            'type' => 'refusal_letter',
        ]);

        $this->actingAs($this->admin)
            ->post(route('admin.products.store'), [
                'name' => 'Тестовый товар с сертификатом',
                'base_price' => 500,
                'certificates' => [
                    ['id' => $certificate->id, 'name' => $certificate->name, 'type' => $certificate->type, 'status' => 'active'],
                ],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $product = Product::where('name', 'Тестовый товар с сертификатом')->firstOrFail();

        $this->assertSame([$certificate->id], $product->certificates()->pluck('certificates.id')->all());
    }
}
