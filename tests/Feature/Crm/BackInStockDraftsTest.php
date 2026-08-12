<?php

namespace Tests\Feature\Crm;

use App\Models\CrmBackInStockOffer;
use App\Models\CrmEmail;
use App\Models\PersonalManager;
use App\Models\Product;
use App\Models\ProductAvailabilityEvent;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\User;
use App\Services\Crm\BackInStockDraftService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Черновики «товар снова в наличии» (crm-31).
 */
class BackInStockDraftsTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private PersonalManager $card;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        config([
            'crm.back_in_stock.min_quantity' => 10,
            'crm.back_in_stock.min_purchases' => 2,
            'crm.back_in_stock.dedup_days' => 60,
            'crm.back_in_stock.max_drafts' => 50,
        ]);

        $this->manager = User::factory()->create(['name' => 'Менеджер Иванов']);
        $this->manager->assignRole('sales-manager');
        $this->card = PersonalManager::factory()->create(['user_id' => $this->manager->id]);

        $this->product = Product::factory()->create(['name' => 'Смазка «Ромашка»', 'sku' => 'SM-01']);
    }

    private function backInStock(int $quantity = 50, ?Product $product = null): void
    {
        ProductAvailabilityEvent::create([
            'product_id' => ($product ?? $this->product)->id,
            'event' => ProductAvailabilityEvent::IN_STOCK,
            'quantity' => $quantity,
            'happened_at' => Carbon::now()->subDay(),
            'missing_days' => 12,
        ]);
    }

    private function client(array $attributes = []): User
    {
        return User::factory()->create([
            'personal_manager_id' => $this->card->id,
            'email' => fake()->unique()->safeEmail(),
            ...$attributes,
        ]);
    }

    private function bought(User $client, int $times, ?Product $product = null): void
    {
        for ($i = 0; $i < $times; $i++) {
            $shipment = Shipment::factory()->create([
                'user_id' => $client->id,
                'erp_created_at' => Carbon::now()->subDays(30 + $i),
            ]);

            ShipmentItem::factory()->create([
                'shipment_id' => $shipment->id,
                'product_id' => ($product ?? $this->product)->id,
            ]);
        }
    }

    #[Test]
    public function draft_lands_in_the_personal_managers_mailbox(): void
    {
        $client = $this->client();
        $this->bought($client, 3);
        $this->backInStock();

        app(BackInStockDraftService::class)->run();

        $email = CrmEmail::query()->firstOrFail();

        $this->assertSame($this->manager->id, $email->user_id);
        $this->assertSame($client->id, $email->client_user_id);
        $this->assertSame([$client->email], $email->to);
    }

    /**
     * Ничего не уходит само — даже при включённом флаге исходящей почты.
     */
    #[Test]
    public function nothing_is_sent_only_drafts_are_created(): void
    {
        config(['notifications.mail_features.crm_outbound' => true]);

        $client = $this->client();
        $this->bought($client, 3);
        $this->backInStock();

        app(BackInStockDraftService::class)->run();

        $this->assertSame('draft', CrmEmail::query()->firstOrFail()->status->value);
        $this->assertNull(CrmEmail::query()->firstOrFail()->sent_at);
    }

    /**
     * Вернулось пять позиций — это одно письмо со списком, а не пять писем.
     * Иначе в день большой приёмки менеджер получит сотню черновиков
     * и не отправит ни одного.
     */
    #[Test]
    public function one_letter_per_client_with_a_list_not_one_per_product(): void
    {
        $second = Product::factory()->create(['name' => 'Гель «Василёк»']);

        $client = $this->client();
        $this->bought($client, 3);
        $this->bought($client, 3, $second);

        $this->backInStock();
        $this->backInStock(product: $second);

        app(BackInStockDraftService::class)->run();

        $this->assertSame(1, CrmEmail::query()->count());
        $this->assertStringContainsString('Смазка «Ромашка»', CrmEmail::query()->firstOrFail()->body_html);
        $this->assertStringContainsString('Гель «Василёк»', CrmEmail::query()->firstOrFail()->body_html);
    }

    /**
     * Остаток дребезжит около порога — без журнала предложений клиент получил бы
     * серию одинаковых писем, и хороший повод превратился бы в спам.
     */
    #[Test]
    public function the_same_product_is_not_offered_twice_inside_the_window(): void
    {
        $client = $this->client();
        $this->bought($client, 3);
        $this->backInStock();

        app(BackInStockDraftService::class)->run();
        $this->assertSame(1, CrmEmail::query()->count());

        $this->backInStock();
        app(BackInStockDraftService::class)->run();

        $this->assertSame(1, CrmEmail::query()->count());
    }

    /**
     * Факт предложения должен пережить удаление черновика — иначе следующий
     * прогон предложит то же самое ещё раз.
     */
    #[Test]
    public function the_offer_log_survives_deleting_the_draft(): void
    {
        $client = $this->client();
        $this->bought($client, 3);
        $this->backInStock();

        app(BackInStockDraftService::class)->run();
        CrmEmail::query()->firstOrFail()->delete();

        $this->assertSame(1, CrmBackInStockOffer::query()->count());
    }

    #[Test]
    public function a_one_off_buyer_is_not_written_to(): void
    {
        $client = $this->client();
        $this->bought($client, 1);
        $this->backInStock();

        app(BackInStockDraftService::class)->run();

        $this->assertSame(0, CrmEmail::query()->count());
    }

    #[Test]
    public function a_small_return_does_not_trigger_letters(): void
    {
        $client = $this->client();
        $this->bought($client, 3);
        $this->backInStock(quantity: 2);

        app(BackInStockDraftService::class)->run();

        $this->assertSame(0, CrmEmail::query()->count());
    }

    /**
     * Карточка менеджера без учётки сотрудника — реальный случай на проде:
     * такие карточки в базе есть. Письмо ложится в чью-то почту, и если почты
     * нет, прогон обязан это сосчитать, а не молча «ничего не найти».
     */
    #[Test]
    public function a_client_whose_manager_card_has_no_account_is_counted_as_skipped(): void
    {
        $orphanCard = PersonalManager::factory()->create(['user_id' => null]);
        $client = User::factory()->create([
            'personal_manager_id' => $orphanCard->id,
            'email' => fake()->unique()->safeEmail(),
        ]);

        $this->bought($client, 3);
        $this->backInStock();

        $result = app(BackInStockDraftService::class)->run();

        $this->assertSame(0, $result['drafts']);
        $this->assertSame(1, $result['skipped']);
    }

    #[Test]
    public function the_command_reports_what_it_did(): void
    {
        $client = $this->client();
        $this->bought($client, 3);
        $this->backInStock();

        $this->artisan('crm:back-in-stock-drafts')
            ->expectsOutputToContain('Создано черновиков: 1')
            ->assertSuccessful();
    }
}
