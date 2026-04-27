<?php

namespace Tests\Unit\Services\Erp\Handlers;

use App\Models\Category;
use App\Services\Erp\Handlers\HandleCategoryCreated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HandleCategoryCreatedTest extends TestCase
{
    use RefreshDatabase;

    private function handler(): HandleCategoryCreated
    {
        return app(HandleCategoryCreated::class);
    }

    private function dispatch(array $payload): void
    {
        $this->handler()->handle($payload);
    }

    private function assertTreeHealthy(): void
    {
        $errors = Category::countErrors();
        $this->assertSame(0, $errors['oddness'], 'NestedSet: oddness > 0');
        $this->assertSame(0, $errors['duplicates'], 'NestedSet: дубликаты _lft/_rgt');
        $this->assertSame(0, $errors['wrong_parent'], 'NestedSet: wrong_parent — узлы лежат вне поддерева своего parent_id');
        $this->assertSame(0, $errors['missing_parent'], 'NestedSet: missing_parent');
    }

    #[Test]
    public function it_creates_root_category_without_parent(): void
    {
        $this->dispatch([
            'uuid' => 'root-uuid',
            'name' => 'Секс-игрушки',
        ]);

        $cat = Category::where('uuid', 'root-uuid')->firstOrFail();
        $this->assertNull($cat->parent_id);
        $this->assertTrue($cat->isRoot());
        $this->assertTreeHealthy();
    }

    #[Test]
    public function it_creates_child_under_existing_parent(): void
    {
        $this->dispatch(['uuid' => 'p', 'name' => 'Секс-игрушки']);
        $this->dispatch(['uuid' => 'c', 'name' => 'Анальные игрушки', 'parent_uuid' => 'p']);

        $parent = Category::where('uuid', 'p')->firstOrFail();
        $child = Category::where('uuid', 'c')->firstOrFail();

        $this->assertSame($parent->id, $child->parent_id);
        $this->assertSame([$parent->id], $child->ancestors()->pluck('id')->all());
        $this->assertTreeHealthy();
    }

    #[Test]
    public function it_moves_category_when_parent_changes(): void
    {
        $this->dispatch(['uuid' => 'r1', 'name' => 'Секс-игрушки']);
        $this->dispatch(['uuid' => 'r2', 'name' => 'БДСМ']);
        $this->dispatch(['uuid' => 'c', 'name' => 'Наборы', 'parent_uuid' => 'r1']);

        $this->dispatch(['uuid' => 'c', 'name' => 'Наборы', 'parent_uuid' => 'r2']);

        $r2 = Category::where('uuid', 'r2')->firstOrFail();
        $c = Category::where('uuid', 'c')->firstOrFail();

        $this->assertSame($r2->id, $c->parent_id);
        $this->assertSame([$r2->id], $c->ancestors()->pluck('id')->all(),
            'Ancestors по NestedSet должны совпадать с parent_id после перемещения');
        $this->assertTreeHealthy();
    }

    #[Test]
    public function it_keeps_tree_healthy_under_repeated_upserts(): void
    {
        // Воспроизводим типичный поток из 1С: 3 корня + 30 детей,
        // потом часть детей перемещается между корнями.
        foreach (['r1', 'r2', 'r3'] as $u) {
            $this->dispatch(['uuid' => $u, 'name' => "Root {$u}"]);
        }

        $roots = ['r1', 'r2', 'r3'];
        for ($i = 0; $i < 30; $i++) {
            $this->dispatch([
                'uuid' => "c{$i}",
                'name' => "Child {$i}",
                'parent_uuid' => $roots[$i % 3],
            ]);
        }

        for ($i = 0; $i < 30; $i += 5) {
            $this->dispatch([
                'uuid' => "c{$i}",
                'name' => "Child {$i}",
                'parent_uuid' => $roots[($i + 1) % 3],
            ]);
        }

        $this->assertTreeHealthy();

        Category::all()->each(function (Category $c) {
            $ancestorIds = $c->ancestors()->orderBy('_lft')->pluck('id')->all();
            $expectedParent = end($ancestorIds) ?: null;
            $this->assertSame(
                $c->parent_id,
                $expectedParent,
                "Категория id={$c->id}: parent_id ({$c->parent_id}) не совпадает с последним предком по NestedSet (".
                ($expectedParent ?? 'null').')'
            );
        });
    }

    #[Test]
    public function it_is_idempotent_on_repeat(): void
    {
        $payload = ['uuid' => 'x', 'name' => 'Категория'];
        $this->dispatch($payload);
        $this->dispatch($payload);
        $this->dispatch($payload);

        $this->assertSame(1, Category::where('uuid', 'x')->count());
        $this->assertTreeHealthy();
    }

    #[Test]
    public function it_decodes_html_entities_in_name(): void
    {
        $this->dispatch([
            'uuid' => 'n',
            'name' => 'Игрушки &quot;Pro&quot; &amp; набор',
        ]);

        $cat = Category::where('uuid', 'n')->firstOrFail();
        $this->assertSame('Игрушки "Pro" & набор', $cat->name);
    }

    #[Test]
    public function it_skips_payload_without_uuid_or_name(): void
    {
        $this->dispatch(['name' => 'без uuid']);
        $this->dispatch(['uuid' => 'no-name']);

        $this->assertSame(0, Category::count());
    }
}
