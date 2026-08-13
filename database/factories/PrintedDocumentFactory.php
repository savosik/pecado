<?php

namespace Database\Factories;

use App\Enums\PrintedDocumentType;
use App\Models\Company;
use App\Models\Organization;
use App\Models\PrintedDocument;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PrintedDocument>
 */
class PrintedDocumentFactory extends Factory
{
    protected $model = PrintedDocument::class;

    public function definition(): array
    {
        $uuid = $this->faker->uuid();
        $date = now()->subDays($this->faker->numberBetween(1, 300));

        return [
            'uuid' => $uuid,
            'type' => PrintedDocumentType::TAX_INVOICE,
            'erp_type_code' => 'tax_invoice',
            'erp_type_name' => 'Счёт-фактура',
            'number' => '29УТ-'.$this->faker->unique()->numberBetween(100000, 999999),
            'date' => $date->toDateString(),
            'title' => null,
            'user_id' => User::factory(),
            'company_id' => Company::factory(),
            'organization_id' => Organization::factory(),
            'order_id' => null,
            'shipment_id' => null,
            'partner_uuid' => $this->faker->uuid(),
            'contractor_uuid' => $this->faker->uuid(),
            'organization_uuid' => $this->faker->uuid(),
            'order_uuid' => null,
            'shipment_uuid' => null,
            'tax_id' => (string) $this->faker->numberBetween(1000000000, 9999999999),
            'base_document_kind' => null,
            'disk' => config('documents.disk'),
            'path' => $date->format('Y/m').'/'.$uuid.'.pdf',
            'source_url' => 's3://documents-exchange/'.$date->format('Y/m').'/'.$uuid.'.pdf',
            'original_filename' => 'Счет-фактура.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => $this->faker->numberBetween(50_000, 500_000),
            'checksum' => hash('sha256', $uuid),
            'file_status' => PrintedDocument::FILE_STORED,
            'stored_at' => now(),
            'version' => 1,
            'revision' => 1,
            'applied_revision' => 1,
        ];
    }

    /**
     * Документ, приехавший раньше своих сторон: связи пусты, сырые UUID на месте.
     * Штатная ситуация — порядок доставки очередей не гарантирован.
     */
    public function unmatched(): static
    {
        return $this->state(fn () => [
            'user_id' => null,
            'company_id' => null,
            'organization_id' => null,
        ]);
    }

    /**
     * Запись создана, файл ещё не перенесён. Клиенту такой документ не показывается.
     */
    public function pending(): static
    {
        return $this->state(fn () => [
            'file_status' => PrintedDocument::FILE_PENDING,
            'disk' => null,
            'path' => null,
            'checksum' => null,
            'stored_at' => null,
            'version' => 0,
        ]);
    }

    /**
     * 1С не выложила файл в обменный бакет.
     */
    public function missing(): static
    {
        return $this->state(fn () => [
            'file_status' => PrintedDocument::FILE_MISSING,
            'disk' => null,
            'path' => null,
            'checksum' => null,
            'stored_at' => null,
            'version' => 0,
        ]);
    }

    public function ofType(PrintedDocumentType $type): static
    {
        return $this->state(fn () => [
            'type' => $type,
            'erp_type_code' => $type->value,
            'erp_type_name' => $type->label(),
        ]);
    }
}
