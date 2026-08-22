<?php

namespace Database\Factories;

use App\Enums\ContactRole;
use App\Enums\ContactSource;
use App\Models\Contact;
use App\Models\ContactLink;
use App\Support\Crm\CrmEntityMap;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<ContactLink>
 */
class ContactLinkFactory extends Factory
{
    protected $model = ContactLink::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'contact_id' => Contact::factory(),
            'role' => ContactRole::ACCOUNTANT,
            'is_primary' => false,
            'source' => ContactSource::MANUAL,
        ];
    }

    /**
     * Привязка к конкретной сущности; партнёр денормализуется тем же способом,
     * что у комментариев и задач.
     */
    public function to(Model $subject, ContactRole $role = ContactRole::ACCOUNTANT): static
    {
        return $this->state(fn (): array => [
            'subject_type' => $subject::class,
            'subject_id' => $subject->getKey(),
            'role' => $role,
            'client_user_id' => CrmEntityMap::clientIdFor($subject),
        ]);
    }

    public function primary(): static
    {
        return $this->state(fn (): array => ['is_primary' => true]);
    }
}
