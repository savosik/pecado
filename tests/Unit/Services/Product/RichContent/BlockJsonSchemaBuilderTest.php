<?php

namespace Tests\Unit\Services\Product\RichContent;

use App\Services\Product\RichContent\BlockJsonSchemaBuilder;
use PHPUnit\Framework\TestCase;

class BlockJsonSchemaBuilderTest extends TestCase
{
    private BlockJsonSchemaBuilder $builder;

    /** @var list<string> */
    private array $allowed = [
        'header', 'paragraph', 'list', 'quote', 'pullQuote', 'delimiter',
        'table', 'warning', 'alertBanner', 'faq', 'iconFeature', 'stats',
        'steps', 'timeline', 'comparison', 'tabs', 'columns',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = new BlockJsonSchemaBuilder;
    }

    public function test_validates_minimal_valid_payload(): void
    {
        $payload = [
            'blocks' => [
                ['type' => 'paragraph', 'data' => ['text' => 'Какой-то текст про товар.']],
            ],
        ];

        $result = $this->builder->validate($payload, $this->allowed);

        $this->assertTrue($result['valid'], implode('; ', $result['errors']));
    }

    public function test_rejects_payload_without_blocks(): void
    {
        $result = $this->builder->validate(['something' => 'else'], $this->allowed);

        $this->assertFalse($result['valid']);
    }

    public function test_rejects_unknown_block_type(): void
    {
        $payload = [
            'blocks' => [
                ['type' => 'image', 'data' => ['file' => ['url' => 'http://example.test/x.jpg']]],
            ],
        ];

        $result = $this->builder->validate($payload, $this->allowed);

        $this->assertFalse($result['valid']);
    }

    public function test_rejects_header_with_invalid_level(): void
    {
        $payload = [
            'blocks' => [
                ['type' => 'header', 'data' => ['text' => 'Заголовок', 'level' => 1]],
            ],
        ];

        $result = $this->builder->validate($payload, $this->allowed);

        $this->assertFalse($result['valid']);
    }

    public function test_rejects_faq_without_items(): void
    {
        $payload = [
            'blocks' => [
                ['type' => 'faq', 'data' => []],
            ],
        ];

        $result = $this->builder->validate($payload, $this->allowed);

        $this->assertFalse($result['valid']);
    }

    public function test_rejects_icon_feature_with_missing_required_field(): void
    {
        $payload = [
            'blocks' => [[
                'type' => 'iconFeature',
                'data' => [
                    'columns' => '3',
                    'items' => [
                        ['icon' => '🚀', 'title' => 'Заголовок'],
                    ],
                ],
            ]],
        ];

        $result = $this->builder->validate($payload, $this->allowed);

        $this->assertFalse($result['valid']);
    }

    public function test_validates_complex_realistic_payload(): void
    {
        $payload = [
            'blocks' => [
                ['type' => 'header', 'data' => ['text' => 'Описание товара', 'level' => 2]],
                ['type' => 'paragraph', 'data' => ['text' => 'Это <b>отличный</b> товар.']],
                ['type' => 'list', 'data' => [
                    'style' => 'unordered',
                    'items' => [
                        ['content' => 'Преимущество 1', 'items' => []],
                        ['content' => 'Преимущество 2', 'items' => []],
                    ],
                ]],
                ['type' => 'delimiter', 'data' => []],
                ['type' => 'faq', 'data' => [
                    'items' => [
                        ['question' => 'Вопрос?', 'answer' => 'Ответ.'],
                    ],
                ]],
                ['type' => 'stats', 'data' => [
                    'items' => [
                        ['value' => '5 лет', 'label' => 'Гарантия'],
                    ],
                ]],
                ['type' => 'comparison', 'data' => [
                    'col1Title' => 'Обычный',
                    'col2Title' => 'Этот',
                    'rows' => [
                        ['feature' => 'Качество', 'col1Text' => 'Среднее', 'col1Icon' => 'cross', 'col2Text' => 'Высокое', 'col2Icon' => 'check'],
                    ],
                ]],
            ],
        ];

        $result = $this->builder->validate($payload, $this->allowed);

        $this->assertTrue($result['valid'], implode('; ', $result['errors']));
    }

    public function test_rejects_alert_banner_with_invalid_style(): void
    {
        $payload = [
            'blocks' => [
                ['type' => 'alertBanner', 'data' => ['text' => 'x', 'style' => 'danger']],
            ],
        ];

        $result = $this->builder->validate($payload, $this->allowed);

        $this->assertFalse($result['valid']);
    }
}
