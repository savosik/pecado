<?php

namespace Tests\Feature\Api\Client;

use App\Enums\ContactSource;
use App\Models\Company;
use App\Models\CompanyBankAccount;
use App\Models\Contact;
use App\Models\DeliveryAddress;
use App\Models\User;
use App\Services\Client\Api\OperationRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

class ClientApiProfileTest extends ClientApiTestCase
{
    private function companyPayload(string $inn = '7727563778'): array
    {
        return ['country' => 'RU', 'name' => 'ООО Вектор', 'legal_name' => 'ООО «Вектор»', 'tax_id' => $inn, 'tax_code' => '770101001'];
    }

    #[Test]
    #[TestDox('Юрлица: создание, чужой ИНН — 422 текстом кабинета, осиротевшая забирается, смена основной, счета')]
    public function companies_and_bank_accounts(): void
    {
        $created = $this->api('POST', '/companies', $this->companyPayload(), ['Idempotency-Key' => 'c-1'])->assertStatus(201);
        $id = $created->json('data.id');
        $this->assertSame('7727563778', $created->json('data.inn'));
        $this->assertFalse($created->json('data.is_default'), 'основная уже есть');

        // Тот же ИНН у другого аккаунта
        Company::factory()->create(['user_id' => User::factory()->create()->id, 'tax_id' => '5008048645']);
        $this->api('POST', '/companies', $this->companyPayload('5008048645'), ['Idempotency-Key' => 'c-2'])
            ->assertStatus(422)
            ->assertJsonPath('errors.0.field', 'tax_id')
            ->assertJsonPath('errors.0.message', 'Этот ИНН уже привязан к другому аккаунту. Если это ваша компания — обратитесь к менеджеру.');

        // Осиротевшая компания из 1С забирается
        $orphan = Company::factory()->create(['user_id' => null, 'tax_id' => '7743013901']);
        $this->api('POST', '/companies', $this->companyPayload('7743013901'), ['Idempotency-Key' => 'c-3'])->assertStatus(201)->assertJsonPath('data.id', $orphan->id);
        $this->assertSame($this->client->id, $orphan->fresh()->user_id);

        $this->api('POST', "/companies/{$id}/default")->assertOk()->assertJsonPath('data.is_default', true);
        $this->assertFalse($this->company->fresh()->is_default);

        $this->api('PATCH', "/companies/{$id}", ['phone' => '+79990000000'])->assertOk()->assertJsonPath('data.phone', '+79990000000');
        $this->api('PATCH', "/companies/{$id}", ['phone' => 'abc'])->assertStatus(422)->assertJsonPath('errors.0.field', 'phone');

        $account = $this->api('POST', "/companies/{$id}/bank-accounts", ['bank_name' => 'Сбер', 'account_number' => '40702810900000001111', 'is_primary' => true])
            ->assertStatus(201)->json('data.id');
        $this->api('PATCH', "/bank-accounts/{$account}", ['bank_name' => 'Сбербанк', 'account_number' => '40702810900000001111'])->assertOk()->assertJsonPath('data.bank_name', 'Сбербанк');

        $foreignAccount = CompanyBankAccount::factory()->create(['company_id' => Company::factory()->create()->id]);
        $this->api('PATCH', "/bank-accounts/{$foreignAccount->id}", ['bank_name' => 'x', 'account_number' => '1'])->assertStatus(404);

        $this->api('GET', '/companies')->assertOk()->assertJsonCount(3, 'data');
        $this->api('GET', '/companies/'.Company::factory()->create()->id)->assertStatus(404);
    }

    #[Test]
    #[TestDox('В реестре нет удалений, кроме корзины')]
    public function no_delete_operations(): void
    {
        $deletes = array_values(array_map(fn ($o) => $o->id, array_filter(app(OperationRegistry::class)->all(), fn ($o) => $o->method === 'DELETE')));
        $this->assertSame(['carts.delete'], $deletes);
    }

    #[Test]
    #[TestDox('Адреса доставки: создание без дублей, основной, правка, чужой — 404')]
    public function delivery_addresses(): void
    {
        $a = $this->api('POST', '/delivery-addresses', ['name' => 'Склад', 'address' => 'Москва, Ленина 1', 'is_default' => true], ['Idempotency-Key' => 'a-1'])
            ->assertStatus(201)->assertJsonPath('data.is_default', true)->json('data.id');
        $this->api('POST', '/delivery-addresses', ['name' => 'Дубль', 'address' => 'Москва, Ленина 1'])->assertOk()->assertJsonPath('data.id', $a)->assertJsonPath('meta.created', false);

        $b = $this->api('POST', '/delivery-addresses', ['name' => 'Офис', 'address' => 'Москва, Тверская 2'])->assertStatus(201)->json('data.id');
        $this->api('POST', "/delivery-addresses/{$b}/default")->assertOk()->assertJsonPath('data.is_default', true);
        $this->assertFalse(DeliveryAddress::find($a)->is_default);

        $this->api('PATCH', "/delivery-addresses/{$a}", ['name' => 'Склад №1'])->assertOk()->assertJsonPath('data.name', 'Склад №1');
        $this->api('GET', '/delivery-addresses')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.id', $b);

        $foreign = DeliveryAddress::factory()->create(['user_id' => User::factory()->create()->id]);
        $this->api('PATCH', "/delivery-addresses/{$foreign->id}", ['name' => 'x'])->assertStatus(404);
    }

    #[Test]
    #[TestDox('Контакты: создание с привязкой, без телефона и почты — 422, деактивация, чужой — 404, лимит')]
    public function contacts(): void
    {
        $this->api('POST', '/contacts', ['full_name' => 'Иванова Анна'])->assertStatus(422)->assertJsonPath('errors.0.field', 'phone');

        $created = $this->api('POST', '/contacts', [
            'full_name' => 'Иванова Анна', 'phone' => '+79990001122', 'position' => 'Бухгалтер',
            'links' => [['company_id' => $this->company->id, 'role' => 'accountant'], ['company_id' => Company::factory()->create()->id, 'role' => 'director']],
        ], ['Idempotency-Key' => 'k-1'])->assertStatus(201);
        $id = $created->json('data.id');
        $this->assertTrue($created->json('data.is_mine'));
        $this->assertCount(1, $created->json('data.links'), 'чужое юрлицо отброшено');
        $this->assertSame('accountant', $created->json('data.links.0.role'));

        $this->api('PATCH', "/contacts/{$id}", ['full_name' => 'Иванова Анна Петровна', 'phone' => '+79990001122'])->assertOk()
            ->assertJsonPath('data.full_name', 'Иванова Анна Петровна')
            ->assertJsonCount(1, 'data.links');

        $byManager = Contact::factory()->create(['client_user_id' => $this->client->id, 'source' => ContactSource::MANUAL]);
        $this->api('POST', "/contacts/{$byManager->id}/deactivate")->assertOk()->assertJsonPath('data.is_active', false);

        $foreign = Contact::factory()->create(['client_user_id' => User::factory()->create()->id]);
        $this->api('PATCH', "/contacts/{$foreign->id}", ['full_name' => 'x', 'phone' => '1'])->assertStatus(404);

        $this->api('GET', '/contacts')->assertOk()->assertJsonCount(2, 'data');

        // Кабинет видит то же самое
        $this->actingAs($this->client)->getJson('/cabinet/contacts/list')->assertOk()->assertJsonCount(2, 'data');
    }

    #[Test]
    #[TestDox('Профиль: менеджер, флаги, обновление контактов; предзаказы через API не меняются')]
    public function profile(): void
    {
        $this->api('GET', '/profile')->assertOk()->assertJsonPath('data.email', $this->client->email)->assertJsonStructure(['data' => ['manager', 'preorders_enabled', 'preorder_lead_days']]);
        $this->api('PATCH', '/profile', ['name' => 'Новое имя'])->assertOk()->assertJsonPath('data.name', 'Новое имя');
        $this->api('PATCH', '/profile', ['email' => User::factory()->create()->email])->assertStatus(422)->assertJsonPath('errors.0.field', 'email');
        $this->assertNull(app(OperationRegistry::class)->find('profile.preorders'));
    }
}
