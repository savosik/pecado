<?php

namespace Database\Factories;

use App\Models\CrmEmailTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CrmEmailTemplate>
 */
class CrmEmailTemplateFactory extends Factory
{
    protected $model = CrmEmailTemplate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Коммерческое предложение',
            'subject' => 'Предложение для {{client_name}}',
            'body_html' => '<p>Здравствуйте, {{client_name}}!</p><p>С уважением, {{manager_name}}</p>',
            'is_active' => true,
        ];
    }
}
