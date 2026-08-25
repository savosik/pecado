<?php

namespace Tests\Feature\Contacts;

use App\Models\Company;
use App\Models\Contact;
use App\Models\User;
use App\Services\Contacts\ContactSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Наполнение справочника: только люди.
 *
 * Критерий заказчика: «допустимо Петров И.И. + емейл, недопустимо
 * ООО Ручеек + емейл». Раньше мастер выводил имя из адреса, и почта
 * `zakaz@meloskop.ru` порождала человека по имени «Zakaz».
 */
class ContactSeederPeopleTest extends TestCase
{
    use RefreshDatabase;

    private ContactSeeder $contacts;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->contacts = app(ContactSeeder::class);
        $this->actor = User::factory()->create();
    }

    #[Test]
    public function имя_берётся_из_карточки_а_не_из_адреса(): void
    {
        $partner = User::factory()->create([
            'erp_name' => 'Ковалев Александр Юрьевич ИП, г.Москва',
            'email' => 'adultoys@yandex.ru',
        ]);

        $candidate = $this->contacts->candidates()
            ->firstWhere('email', 'adultoys@yandex.ru');

        $this->assertNotNull($candidate);
        $this->assertSame('Ковалев Александр Юрьевич', $candidate['full_name']);
        $this->assertSame($partner->id, $candidate['client_id']);
    }

    #[Test]
    public function юрлицо_человеком_не_становится(): void
    {
        $partner = User::factory()->create([
            'erp_name' => 'РЕДИНГТОН ООО',
            'email' => 'zakaz@meloskop.ru',
        ]);
        Company::factory()->create([
            'user_id' => $partner->id,
            'name' => 'ТАНДЕР АО',
            'email' => 'sverka_raschetov@magnit.ru',
        ]);

        $candidate = $this->contacts->candidates()
            ->firstWhere('email', 'sverka_raschetov@magnit.ru');

        $this->assertNotNull($candidate, 'кандидат остаётся в списке — менеджер может назвать его сам');
        $this->assertNull($candidate['full_name']);

        // Но карточку по нему автоматически не заводим.
        $created = $this->contacts->accept(['sverka_raschetov@magnit.ru'], $this->actor);

        $this->assertSame(0, $created);
        $this->assertSame(0, Contact::query()->count());

        // И сам партнёр-юрлицо тоже: «zakaz@meloskop.ru» раньше давал
        // человека по имени «Zakaz».
        $this->assertNull($this->contacts->candidates()->firstWhere('email', 'zakaz@meloskop.ru')['full_name']);
    }

    #[Test]
    public function человек_из_карточки_заводится(): void
    {
        User::factory()->create([
            'erp_name' => 'Савушкин Сергей Юрьевич ИП',
            'email' => 'hozdv@yandex.ru',
        ]);

        $created = $this->contacts->accept(['hozdv@yandex.ru'], $this->actor);

        $this->assertSame(1, $created);
        $this->assertSame('Савушкин Сергей Юрьевич', Contact::query()->value('full_name'));
    }

    #[Test]
    public function сводка_показывает_безымянных_отдельно(): void
    {
        $partner = User::factory()->create([
            'erp_name' => 'Пирогов Сергей Сергеевич ИП',
            'email' => 'pirogov@example.ru',
        ]);
        Company::factory()->create([
            'user_id' => $partner->id,
            'name' => 'ФЕНИКС ООО',
            'email' => 'ooo.fenix@example.ru',
        ]);

        $candidates = $this->contacts->candidates()
            ->whereIn('email', ['pirogov@example.ru', 'ooo.fenix@example.ru'])
            ->keyBy('email');

        $this->assertSame('Пирогов Сергей Сергеевич', $candidates['pirogov@example.ru']['full_name']);
        $this->assertNull($candidates['ooo.fenix@example.ru']['full_name']);

        // Безымянные считаются отдельной строкой сводки — это не брак,
        // а те, кого менеджер должен назвать сам.
        $this->assertGreaterThanOrEqual(1, $this->contacts->summary()['— из них без имени человека'] ?? 0);
    }
}
