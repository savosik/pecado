<?php

namespace Database\Factories;

use App\Enums\ContactSource;
use App\Enums\Crm\PreferredChannel;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Contact>
 */
class ContactFactory extends Factory
{
    protected $model = Contact::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'full_name' => fake()->name(),
            'position' => fake()->jobTitle(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => '+7'.fake()->numerify('##########'),
            'preferred_channel' => PreferredChannel::PHONE,
            'is_active' => true,
            'source' => ContactSource::MANUAL,
        ];
    }

    public function forClient(User $client): static
    {
        return $this->state(fn (): array => ['client_user_id' => $client->getKey()]);
    }

    /**
     * Контакт, заведённый самим партнёром в кабинете.
     */
    public function bySelf(): static
    {
        return $this->state(fn (): array => [
            'source' => ContactSource::SELF,
            'partner_touched_at' => now(),
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    public function unsubscribed(): static
    {
        return $this->state(fn (): array => ['unsubscribed_at' => now()]);
    }

    /**
     * День рождения без известного года: половина людей называет только число.
     */
    public function birthdayWithoutYear(string $date = '1900-05-17'): static
    {
        return $this->state(fn (): array => [
            'birthday' => $date,
            'birthday_has_year' => false,
        ]);
    }
}
