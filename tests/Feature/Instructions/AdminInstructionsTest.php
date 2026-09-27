<?php

namespace Tests\Feature\Instructions;

use App\Enums\InstructionType;
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
 * Инструкции в админке: контент-менеджер ведёт, форма требует тело по формату.
 */
class AdminInstructionsTest extends TestCase
{
    use RefreshDatabase;

    /** Минимальный валидный PDF: пустой файл media-library отвергает как application/x-empty. */
    private const PDF = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[]/Count 0>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";

    private User $editor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake('public');
        config(['media-library.disk_name' => 'public']);

        $this->editor = User::factory()->create();
        $this->editor->assignRole('content-manager');
    }

    /**
     * @return array<string, mixed>
     */
    private function textPayload(array $overrides = []): array
    {
        return [
            'title' => 'Как оформить резерв',
            'short_description' => 'Пошагово: от корзины до подтверждения.',
            'type' => 'text',
            'audiences' => ['client', 'crm'],
            'is_published' => 1,
            'content' => json_encode(['blocks' => [['type' => 'paragraph', 'data' => ['text' => 'Шаг 1']]]], JSON_UNESCAPED_UNICODE),
            ...$overrides,
        ];
    }

    #[Test]
    #[TestDox('Без права instructions.view раздел закрыт')]
    public function section_requires_permission(): void
    {
        $staff = User::factory()->create();
        $staff->assignRole('sales-manager');

        $this->actingAs($staff)->get('/admin/instructions')->assertForbidden();
    }

    #[Test]
    #[TestDox('Контент-менеджер создаёт текстовую инструкцию для клиентов и CRM')]
    public function editor_creates_text_instruction(): void
    {
        $this->actingAs($this->editor)
            ->post('/admin/instructions', $this->textPayload())
            ->assertRedirect();

        $instruction = Instruction::firstOrFail();
        $this->assertSame(InstructionType::TEXT, $instruction->type);
        $this->assertTrue($instruction->for_clients);
        $this->assertTrue($instruction->for_crm);
        $this->assertFalse($instruction->for_wms);
        $this->assertTrue($instruction->is_published);
        $this->assertStringContainsString('Шаг 1', (string) $instruction->content);
    }

    #[Test]
    #[TestDox('Без аудитории и без текста форма не принимается')]
    public function form_requires_audience_and_body(): void
    {
        $this->actingAs($this->editor)
            ->from('/admin/instructions/create')
            ->post('/admin/instructions', $this->textPayload([
                'audiences' => [],
                'content' => json_encode(['blocks' => []]),
            ]))
            ->assertSessionHasErrors(['audiences', 'content']);

        $this->assertDatabaseCount('instructions', 0);
    }

    #[Test]
    #[TestDox('PDF-инструкции нужен файл, и он ложится в коллекцию file')]
    public function pdf_instruction_requires_and_stores_file(): void
    {
        $this->actingAs($this->editor)
            ->from('/admin/instructions/create')
            ->post('/admin/instructions', $this->textPayload(['type' => 'pdf', 'content' => null]))
            ->assertSessionHasErrors('pdf');

        $this->actingAs($this->editor)
            ->post('/admin/instructions', $this->textPayload([
                'type' => 'pdf',
                'content' => null,
                'pdf' => UploadedFile::fake()->createWithContent('guide.pdf', self::PDF),
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $instruction = Instruction::firstOrFail();
        $this->assertSame(InstructionType::PDF, $instruction->type);
        $this->assertNull($instruction->content);
        $this->assertNotNull($instruction->getFirstMedia(Instruction::COLLECTION_FILE));
        $this->assertSame('guide.pdf', $instruction->getFirstMedia(Instruction::COLLECTION_FILE)->file_name);
    }

    #[Test]
    #[TestDox('Видео: ссылка чужой площадки отклоняется, Rutube принимается')]
    public function video_instruction_validates_link(): void
    {
        $this->actingAs($this->editor)
            ->from('/admin/instructions/create')
            ->post('/admin/instructions', $this->textPayload([
                'type' => 'video',
                'content' => null,
                'video_url' => 'https://example.com/movie.mp4',
            ]))
            ->assertSessionHasErrors('video_url');

        $this->actingAs($this->editor)
            ->from('/admin/instructions/create')
            ->post('/admin/instructions', $this->textPayload(['type' => 'video', 'content' => null]))
            ->assertSessionHasErrors('video_url');

        $this->actingAs($this->editor)
            ->post('/admin/instructions', $this->textPayload([
                'type' => 'video',
                'content' => null,
                'video_url' => 'https://rutube.ru/video/0123456789abcdef0123456789abcdef/',
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('https://rutube.ru/video/0123456789abcdef0123456789abcdef/', Instruction::firstOrFail()->video_url);
    }

    #[Test]
    #[TestDox('Правка: смена формата стирает старое тело, снятие файла — удаляет его')]
    public function update_switches_format_and_removes_file(): void
    {
        $instruction = Instruction::factory()->pdf()->forWms()->create();
        $instruction->addMedia(UploadedFile::fake()->createWithContent('old.pdf', self::PDF))
            ->toMediaCollection(Instruction::COLLECTION_FILE);

        // Файл остаётся, пока его не сняли.
        $this->actingAs($this->editor)
            ->put("/admin/instructions/{$instruction->id}", $this->textPayload([
                'title' => 'Обновлённый заголовок',
                'type' => 'pdf',
                'content' => null,
                'audiences' => ['wms'],
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertNotNull($instruction->fresh()->getFirstMedia(Instruction::COLLECTION_FILE));
        $this->assertSame('Обновлённый заголовок', $instruction->fresh()->title);

        // Перевели в текст — PDF больше не нужен.
        $this->actingAs($this->editor)
            ->put("/admin/instructions/{$instruction->id}", $this->textPayload(['audiences' => ['wms']]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $fresh = $instruction->fresh();
        $this->assertSame(InstructionType::TEXT, $fresh->type);
        $this->assertNull($fresh->getFirstMedia(Instruction::COLLECTION_FILE));
        $this->assertFalse($fresh->for_clients);
        $this->assertTrue($fresh->for_wms);
    }

    #[Test]
    #[TestDox('Список показывает аудитории и фильтруется по ним')]
    public function index_lists_and_filters(): void
    {
        Instruction::factory()->forClients()->create(['title' => 'Для клиентов']);
        Instruction::factory()->forWms()->create(['title' => 'Для склада']);

        $this->actingAs($this->editor)
            ->get('/admin/instructions')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Pages/Instructions/Index', false)
                ->has('instructions.data', 2));

        $this->actingAs($this->editor)
            ->get('/admin/instructions?audience=wms')
            ->assertInertia(fn ($page) => $page
                ->has('instructions.data', 1)
                ->where('instructions.data.0.title', 'Для склада')
                ->where('instructions.data.0.audience_labels.0.value', 'wms'));
    }

    #[Test]
    #[TestDox('Удаление убирает инструкцию')]
    public function editor_deletes_instruction(): void
    {
        $instruction = Instruction::factory()->forCrm()->create();

        $this->actingAs($this->editor)
            ->delete("/admin/instructions/{$instruction->id}")
            ->assertRedirect('/admin/instructions');

        $this->assertDatabaseMissing('instructions', ['id' => $instruction->id]);
    }
}
