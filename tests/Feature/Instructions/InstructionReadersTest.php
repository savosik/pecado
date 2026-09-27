<?php

namespace Tests\Feature\Instructions;

use App\Models\Instruction;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * Читальные разделы «Инструкции» в кабинете, CRM и WMS.
 *
 * Одна таблица, три аудитории: клиент видит только помеченное «клиентам»,
 * менеджер — «CRM», кладовщик — «WMS». Скрытая инструкция не видна никому,
 * чужая по угаданному id — 404.
 */
class InstructionReadersTest extends TestCase
{
    use RefreshDatabase;

    /** Минимальный валидный PDF: пустой файл media-library отвергает как application/x-empty. */
    private const PDF = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[]/Count 0>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";

    private User $client;

    private User $manager;

    private User $storekeeper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake('public');
        config(['media-library.disk_name' => 'public']);

        $this->client = User::factory()->create();

        $this->manager = User::factory()->create();
        $this->manager->assignRole('sales-manager-crm');

        $this->storekeeper = User::factory()->create();
        $this->storekeeper->assignRole('storekeeper');
    }

    #[Test]
    #[TestDox('Каждая панель видит только свою аудиторию и только опубликованное')]
    public function each_panel_sees_its_audience(): void
    {
        Instruction::factory()->forClients()->create(['title' => 'Клиентам']);
        Instruction::factory()->forCrm()->forWms()->create(['title' => 'CRM и складу']);
        Instruction::factory()->forWms()->create(['title' => 'Только складу']);
        Instruction::factory()->forClients()->forCrm()->forWms()->unpublished()->create(['title' => 'Черновик']);

        $this->actingAs($this->client)
            ->get('/cabinet/instructions')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('User/Cabinet/Instructions/Index', false)
                ->has('instructions.data', 1)
                ->where('instructions.data.0.title', 'Клиентам'));

        $this->actingAs($this->manager)
            ->get('/crm/instructions')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Crm/Pages/Instructions/Index', false)
                ->has('instructions.data', 1)
                ->where('instructions.data.0.title', 'CRM и складу'));

        $this->actingAs($this->storekeeper)
            ->get('/wms/instructions')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Wms/Pages/Instructions/Index', false)
                ->has('instructions.data', 2));
    }

    #[Test]
    #[TestDox('Чужая или скрытая инструкция по id — 404')]
    public function foreign_or_hidden_instruction_is_not_found(): void
    {
        $wmsOnly = Instruction::factory()->forWms()->create();
        $hidden = Instruction::factory()->forClients()->unpublished()->create();

        $this->actingAs($this->client)->get("/cabinet/instructions/{$wmsOnly->id}")->assertNotFound();
        $this->actingAs($this->client)->get("/cabinet/instructions/{$hidden->id}")->assertNotFound();
        $this->actingAs($this->manager)->get("/crm/instructions/{$wmsOnly->id}")->assertNotFound();
        $this->actingAs($this->storekeeper)->get("/wms/instructions/{$wmsOnly->id}")->assertOk();
    }

    #[Test]
    #[TestDox('Текстовая инструкция отдаёт блоки, даты и признак обновления')]
    public function text_instruction_detail(): void
    {
        $instruction = Instruction::factory()->forClients()->create([
            'created_at' => now()->subDays(10),
            'updated_at' => now()->subDay(),
        ]);

        $this->actingAs($this->client)
            ->get("/cabinet/instructions/{$instruction->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('User/Cabinet/Instructions/Show', false)
                ->where('instruction.type', 'text')
                ->where('instruction.content', $instruction->content)
                ->where('instruction.was_updated', true)
                ->where('instruction.file', null)
                ->where('instruction.video', null)
                ->where('backUrl', route('cabinet.instructions.index')));
    }

    #[Test]
    #[TestDox('PDF-инструкция отдаёт адрес файла, видео — адрес встраивания')]
    public function pdf_and_video_details(): void
    {
        $pdf = Instruction::factory()->pdf()->forWms()->create();
        $pdf->addMedia(UploadedFile::fake()->createWithContent('sborka.pdf', self::PDF))
            ->toMediaCollection(Instruction::COLLECTION_FILE);

        $this->actingAs($this->storekeeper)
            ->get("/wms/instructions/{$pdf->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('instruction.type', 'pdf')
                ->where('instruction.file.name', 'sborka.pdf')
                ->where('instruction.content', null));

        $video = Instruction::factory()->video('https://youtu.be/dQw4w9WgXcQ')->forCrm()->create();

        $this->actingAs($this->manager)
            ->get("/crm/instructions/{$video->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('instruction.type', 'video')
                ->where('instruction.video.embed_url', 'https://www.youtube.com/embed/dQw4w9WgXcQ')
                ->where('instruction.video.file_url', null));
    }

    #[Test]
    #[TestDox('Гость в кабинет не попадает, клиент — не в CRM и не в WMS')]
    public function access_follows_panels(): void
    {
        $this->get('/cabinet/instructions')->assertRedirect();
        $this->actingAs($this->client)->get('/crm/instructions')->assertRedirect();
        $this->actingAs($this->client)->get('/wms/instructions')->assertRedirect();
    }
}
