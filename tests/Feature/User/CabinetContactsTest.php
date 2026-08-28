<?php

namespace Tests\Feature\User;

use App\Enums\ContactSource;
use App\Models\Company;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Контакты в кабинете партнёра.
 *
 * Главная граница: свою карточку партнёр удаляет, нашу — только гасит.
 * За нашей могут стоять отправленные письма, и стирать её он не вправе.
 */
class CabinetContactsTest extends TestCase
{
    use RefreshDatabase;

    private User $partner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->partner = User::factory()->create();
    }

    #[Test]
    public function partner_sees_own_and_managers_contacts(): void
    {
        Contact::factory()->forClient($this->partner)->bySelf()->create(['full_name' => 'Мой контакт']);
        Contact::factory()->forClient($this->partner)->create(['full_name' => 'Контакт менеджера']);

        $response = $this->actingAs($this->partner)->getJson(route('cabinet.contacts.list'));

        $response->assertOk();
        $names = array_column($response->json('data'), 'full_name');

        $this->assertContains('Мой контакт', $names);
        $this->assertContains('Контакт менеджера', $names);
    }

    #[Test]
    public function foreign_contact_answers_404(): void
    {
        $stranger = User::factory()->create();
        $foreign = Contact::factory()->forClient($stranger)->create();

        $this->actingAs($this->partner)
            ->patchJson(route('cabinet.contacts.update', $foreign), ['full_name' => 'Взлом'])
            ->assertNotFound();
    }

    #[Test]
    public function created_contact_is_marked_as_partners_own(): void
    {
        $response = $this->actingAs($this->partner)->postJson(route('cabinet.contacts.store'), [
            'full_name' => 'Афонина Мария',
            'phone' => '+79123456789',
        ]);

        $response->assertCreated()->assertJsonPath('is_mine', true);

        $contact = Contact::query()->firstOrFail();

        $this->assertSame(ContactSource::SELF, $contact->source);
        $this->assertSame($this->partner->id, $contact->client_user_id);
        $this->assertNotNull($contact->partner_touched_at);
    }

    #[Test]
    public function editing_our_contact_leaves_a_visible_mark(): void
    {
        // Менеджер должен видеть, что данные свежие и не от него.
        $contact = Contact::factory()->forClient($this->partner)->create(['phone' => '+70000000000']);

        $this->actingAs($this->partner)
            ->patchJson(route('cabinet.contacts.update', $contact), [
                'full_name' => $contact->full_name,
                'phone' => '+79123456789',
            ])
            ->assertOk();

        $contact->refresh();

        $this->assertSame('+79123456789', $contact->phone);
        $this->assertNotNull($contact->partner_touched_at);
        $this->assertSame($this->partner->id, $contact->updated_by_user_id);
        // Источник не подменяется: карточку по-прежнему завёл менеджер.
        $this->assertSame(ContactSource::MANUAL, $contact->source);
    }

    #[Test]
    public function our_contact_cannot_be_deleted_but_can_be_retired(): void
    {
        $contact = Contact::factory()->forClient($this->partner)->create();

        $this->actingAs($this->partner)
            ->deleteJson(route('cabinet.contacts.destroy', $contact))
            ->assertStatus(422);

        $this->assertNotNull($contact->fresh());

        $this->actingAs($this->partner)
            ->postJson(route('cabinet.contacts.deactivate', $contact))
            ->assertOk()
            ->assertJsonPath('is_active', false);
    }

    #[Test]
    public function partners_own_contact_is_deletable(): void
    {
        $contact = Contact::factory()->forClient($this->partner)->bySelf()->create();

        $this->actingAs($this->partner)
            ->deleteJson(route('cabinet.contacts.destroy', $contact))
            ->assertOk();

        // Мягкое удаление: за карточкой могут стоять письма и звонки.
        $this->assertSoftDeleted($contact);
    }

    #[Test]
    public function managers_note_never_leaves_for_the_cabinet(): void
    {
        // Там пишут «требует особого подхода» и подобное.
        Contact::factory()->forClient($this->partner)->create([
            'notes' => 'Скандальный, звонить только утром',
        ]);

        $response = $this->actingAs($this->partner)->getJson(route('cabinet.contacts.list'));

        $response->assertOk();
        $this->assertStringNotContainsString('Скандальный', $response->getContent());
    }

    #[Test]
    public function marketing_consent_is_not_offered_in_the_cabinet(): void
    {
        // Партнёр не может дать согласие на обработку данных за третье лицо.
        $contact = Contact::factory()->forClient($this->partner)->bySelf()->create();

        $this->actingAs($this->partner)
            ->patchJson(route('cabinet.contacts.update', $contact), [
                'full_name' => $contact->full_name,
                'phone' => '+79123456789',
                'marketing_consent' => true,
            ])
            ->assertOk();

        $this->assertFalse($contact->fresh()->marketing_consent);
    }

    #[Test]
    public function person_without_any_way_to_reach_them_is_refused(): void
    {
        $response = $this->actingAs($this->partner)->postJson(route('cabinet.contacts.store'), [
            'full_name' => 'Безымянный',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('телефон или почту', $response->json('errors.phone.0'));
    }

    #[Test]
    public function link_to_a_foreign_company_is_ignored(): void
    {
        $stranger = User::factory()->create();
        $foreignCompany = Company::factory()->create(['user_id' => $stranger->id]);

        $this->actingAs($this->partner)->postJson(route('cabinet.contacts.store'), [
            'full_name' => 'Афонина Мария',
            'phone' => '+79123456789',
            'company_id' => $foreignCompany->id,
            'role' => 'accountant',
        ])->assertCreated();

        $this->assertSame(0, \App\Models\ContactLink::query()->count());
    }

    #[Test]
    public function limit_is_explained_in_russian(): void
    {
        Contact::factory()->count(50)->forClient($this->partner)->bySelf()->create();

        $response = $this->actingAs($this->partner)->postJson(route('cabinet.contacts.store'), [
            'full_name' => 'Пятьдесят первый',
            'phone' => '+79123456789',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('не поместится', $response->json('message'));
    }

    #[Test]
    public function guest_cannot_reach_contacts(): void
    {
        $this->getJson(route('cabinet.contacts.list'))->assertUnauthorized();
    }

    #[Test]
    public function one_person_can_hold_roles_in_several_companies(): void
    {
        $first = Company::factory()->create(['user_id' => $this->partner->id, 'name' => 'Ромашка ООО']);
        $second = Company::factory()->create(['user_id' => $this->partner->id, 'name' => 'Василёк ИП']);
        $stranger = Company::factory()->create(['user_id' => User::factory()->create()->id]);

        $id = $this->actingAs($this->partner)->postJson(route('cabinet.contacts.store'), [
            'full_name' => 'Афонина Мария',
            'phone' => '+79123456789',
            'links' => [
                ['company_id' => $first->id, 'role' => 'accountant'],
                ['company_id' => $second->id, 'role' => 'accountant'],
                ['company_id' => $stranger->id, 'role' => 'director'],
            ],
        ])->assertCreated()->json('id');

        $links = \App\Models\ContactLink::query()->where('contact_id', $id)->get();
        $this->assertEqualsCanonicalizing([$first->id, $second->id], $links->pluck('subject_id')->map(fn ($v) => (int) $v)->all());

        $row = collect($this->actingAs($this->partner)->getJson(route('cabinet.contacts.list'))->json('data'))->firstWhere('id', $id);
        $this->assertEqualsCanonicalizing(['Ромашка ООО', 'Василёк ИП'], collect($row['links'])->pluck('company_name')->all());

        // Снятая галочка — снятая привязка.
        $this->actingAs($this->partner)->patchJson(route('cabinet.contacts.update', $id), [
            'full_name' => 'Афонина Мария',
            'phone' => '+79123456789',
            'links' => [['company_id' => $second->id, 'role' => 'director']],
        ])->assertOk();

        $this->assertSame([$second->id], \App\Models\ContactLink::query()->where('contact_id', $id)->pluck('subject_id')->map(fn ($v) => (int) $v)->all());
    }
}
