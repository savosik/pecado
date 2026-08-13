<?php

namespace Database\Factories;

use App\Models\CrmLead;
use App\Models\CrmLeadStage;
use App\Models\PersonalManager;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CrmLead>
 */
class CrmLeadFactory extends Factory
{
    protected $model = CrmLead::class;

    /**
     * Лид по умолчанию — ничей и без стадии: именно так он и попадает в базу
     * из формы, а тесты скоупа опираются на «ничьего» лида чаще всего.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'phone' => fake()->numerify('+7 9## ###-##-##'),
            'email' => null,
            'messenger' => null,
            'company_name' => null,
            'source' => null,
            'manager_id' => null,
            'stage_id' => null,
            'qualified_amount' => null,
            'currency_code' => null,
            'expected_close_at' => null,
            'decision_maker' => null,
            'interests' => null,
            'notes' => null,
            'lost_reason' => null,
            'converted_user_id' => null,
            'stage_changed_at' => null,
        ];
    }

    public function managedBy(PersonalManager $manager): static
    {
        return $this->state(fn () => ['manager_id' => $manager->getKey()]);
    }

    /**
     * Лид на стадии. `stage_changed_at` ставится вместе со стадией: без него
     * «дней на этапе» не считается, и тесты залежавшихся лидов молча проходят.
     */
    public function onStage(CrmLeadStage $stage): static
    {
        return $this->state(fn () => [
            'stage_id' => $stage->getKey(),
            'stage_changed_at' => now(),
        ]);
    }

    /**
     * Лид, который стоит на месте указанное число дней.
     */
    public function stagnantFor(int $days): static
    {
        return $this->state(fn () => ['stage_changed_at' => now()->subDays($days)]);
    }

    public function converted(User $client): static
    {
        return $this->state(fn () => ['converted_user_id' => $client->getKey()]);
    }
}
