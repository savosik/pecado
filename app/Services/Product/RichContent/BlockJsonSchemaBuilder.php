<?php

namespace App\Services\Product\RichContent;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

class BlockJsonSchemaBuilder
{
    private const ROOT_SCHEMA_ID = 'https://pecado.local/schemas/rich-content.json';

    /**
     * @param  list<string>  $allowedTypes
     * @return array{valid: bool, errors: list<string>}
     */
    public function validate(array $payload, array $allowedTypes): array
    {
        $validator = new Validator;
        $validator->setMaxErrors(10);

        $schema = $this->buildSchemaObject($allowedTypes);
        $data = json_decode(json_encode($payload));

        $result = $validator->validate($data, $schema);

        if ($result->isValid()) {
            return ['valid' => true, 'errors' => []];
        }

        $errors = (new ErrorFormatter)->format($result->error(), true);
        $flat = [];
        foreach ($errors as $path => $messages) {
            $messages = is_array($messages) ? $messages : [$messages];
            foreach ($messages as $message) {
                $flat[] = "{$path}: {$message}";
            }
        }

        return ['valid' => false, 'errors' => $flat];
    }

    /** @param list<string> $allowedTypes */
    private function buildSchemaObject(array $allowedTypes): object
    {
        $blockSchemas = [];
        foreach ($allowedTypes as $type) {
            $schema = $this->blockSchema($type);
            if ($schema !== null) {
                $blockSchemas[] = $schema;
            }
        }

        $rootArray = [
            '$id' => self::ROOT_SCHEMA_ID,
            '$schema' => 'http://json-schema.org/draft-07/schema#',
            'type' => 'object',
            'required' => ['blocks'],
            'additionalProperties' => true,
            'properties' => [
                'time' => ['type' => 'integer'],
                'version' => ['type' => 'string'],
                'blocks' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'items' => [
                        'oneOf' => $blockSchemas,
                    ],
                ],
            ],
        ];

        return json_decode(json_encode($rootArray));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function blockSchema(string $type): ?array
    {
        return match ($type) {
            'paragraph' => $this->wrap($type, [
                'type' => 'object',
                'required' => ['text'],
                'properties' => [
                    'text' => ['type' => 'string', 'minLength' => 1],
                ],
            ]),

            'header' => $this->wrap($type, [
                'type' => 'object',
                'required' => ['text', 'level'],
                'properties' => [
                    'text' => ['type' => 'string', 'minLength' => 1],
                    'level' => ['type' => 'integer', 'enum' => [2, 3, 4]],
                ],
            ]),

            'list' => $this->wrap($type, [
                'type' => 'object',
                'required' => ['style', 'items'],
                'properties' => [
                    'style' => ['type' => 'string', 'enum' => ['unordered', 'ordered']],
                    'items' => [
                        'type' => 'array',
                        'minItems' => 1,
                        'items' => [
                            'type' => 'object',
                            'required' => ['content'],
                            'properties' => [
                                'content' => ['type' => 'string'],
                                'items' => ['type' => 'array'],
                            ],
                        ],
                    ],
                ],
            ]),

            'quote' => $this->wrap($type, [
                'type' => 'object',
                'required' => ['text'],
                'properties' => [
                    'text' => ['type' => 'string', 'minLength' => 1],
                    'caption' => ['type' => 'string'],
                    'alignment' => ['type' => 'string', 'enum' => ['left', 'center']],
                ],
            ]),

            'pullQuote' => $this->wrap($type, [
                'type' => 'object',
                'required' => ['text'],
                'properties' => [
                    'text' => ['type' => 'string', 'minLength' => 1],
                    'caption' => ['type' => 'string'],
                ],
            ]),

            'delimiter' => [
                'type' => 'object',
                'required' => ['type'],
                'properties' => [
                    'type' => ['type' => 'string', 'const' => 'delimiter'],
                    'data' => ['type' => ['object', 'array']],
                ],
            ],

            'table' => $this->wrap($type, [
                'type' => 'object',
                'required' => ['content'],
                'properties' => [
                    'withHeadings' => ['type' => 'boolean'],
                    'content' => [
                        'type' => 'array',
                        'minItems' => 1,
                        'items' => [
                            'type' => 'array',
                            'minItems' => 1,
                            'items' => ['type' => 'string'],
                        ],
                    ],
                ],
            ]),

            'warning' => $this->wrap($type, [
                'type' => 'object',
                'required' => ['title', 'message'],
                'properties' => [
                    'title' => ['type' => 'string', 'minLength' => 1],
                    'message' => ['type' => 'string', 'minLength' => 1],
                ],
            ]),

            'alertBanner' => $this->wrap($type, [
                'type' => 'object',
                'required' => ['text', 'style'],
                'properties' => [
                    'text' => ['type' => 'string', 'minLength' => 1],
                    'style' => ['type' => 'string', 'enum' => ['promo', 'info', 'warning']],
                    'btnText' => ['type' => 'string'],
                    'btnUrl' => ['type' => 'string'],
                ],
            ]),

            'faq' => $this->wrap($type, [
                'type' => 'object',
                'required' => ['items'],
                'properties' => [
                    'items' => [
                        'type' => 'array',
                        'minItems' => 1,
                        'items' => [
                            'type' => 'object',
                            'required' => ['question', 'answer'],
                            'properties' => [
                                'question' => ['type' => 'string', 'minLength' => 1],
                                'answer' => ['type' => 'string', 'minLength' => 1],
                            ],
                        ],
                    ],
                ],
            ]),

            'iconFeature' => $this->wrap($type, [
                'type' => 'object',
                'required' => ['columns', 'items'],
                'properties' => [
                    'columns' => ['type' => 'string', 'enum' => ['2', '3', '4']],
                    'items' => [
                        'type' => 'array',
                        'minItems' => 1,
                        'items' => [
                            'type' => 'object',
                            'required' => ['icon', 'title', 'text'],
                            'properties' => [
                                'icon' => ['type' => 'string', 'minLength' => 1],
                                'title' => ['type' => 'string', 'minLength' => 1],
                                'text' => ['type' => 'string', 'minLength' => 1],
                            ],
                        ],
                    ],
                ],
            ]),

            'stats' => $this->wrap($type, [
                'type' => 'object',
                'required' => ['items'],
                'properties' => [
                    'items' => [
                        'type' => 'array',
                        'minItems' => 1,
                        'items' => [
                            'type' => 'object',
                            'required' => ['value', 'label'],
                            'properties' => [
                                'value' => ['type' => 'string', 'minLength' => 1],
                                'label' => ['type' => 'string', 'minLength' => 1],
                            ],
                        ],
                    ],
                ],
            ]),

            'steps' => $this->wrap($type, [
                'type' => 'object',
                'required' => ['steps'],
                'properties' => [
                    'steps' => [
                        'type' => 'array',
                        'minItems' => 1,
                        'items' => [
                            'type' => 'object',
                            'required' => ['title', 'text'],
                            'properties' => [
                                'title' => ['type' => 'string', 'minLength' => 1],
                                'text' => ['type' => 'string', 'minLength' => 1],
                            ],
                        ],
                    ],
                ],
            ]),

            'timeline' => $this->wrap($type, [
                'type' => 'object',
                'required' => ['events'],
                'properties' => [
                    'events' => [
                        'type' => 'array',
                        'minItems' => 1,
                        'items' => [
                            'type' => 'object',
                            'required' => ['date', 'title'],
                            'properties' => [
                                'date' => ['type' => 'string', 'minLength' => 1],
                                'title' => ['type' => 'string', 'minLength' => 1],
                                'text' => ['type' => 'string'],
                            ],
                        ],
                    ],
                ],
            ]),

            'comparison' => $this->wrap($type, [
                'type' => 'object',
                'required' => ['col1Title', 'col2Title', 'rows'],
                'properties' => [
                    'col1Title' => ['type' => 'string', 'minLength' => 1],
                    'col2Title' => ['type' => 'string', 'minLength' => 1],
                    'rows' => [
                        'type' => 'array',
                        'minItems' => 1,
                        'items' => [
                            'type' => 'object',
                            'required' => ['feature', 'col1Text', 'col1Icon', 'col2Text', 'col2Icon'],
                            'properties' => [
                                'feature' => ['type' => 'string', 'minLength' => 1],
                                'col1Text' => ['type' => 'string'],
                                'col1Icon' => ['type' => 'string', 'enum' => ['none', 'check', 'cross', 'text']],
                                'col2Text' => ['type' => 'string'],
                                'col2Icon' => ['type' => 'string', 'enum' => ['none', 'check', 'cross', 'text']],
                            ],
                        ],
                    ],
                ],
            ]),

            'tabs' => $this->wrap($type, [
                'type' => 'object',
                'required' => ['tabs'],
                'properties' => [
                    'tabs' => [
                        'type' => 'array',
                        'minItems' => 1,
                        'items' => [
                            'type' => 'object',
                            'required' => ['title', 'content'],
                            'properties' => [
                                'title' => ['type' => 'string', 'minLength' => 1],
                                'content' => ['type' => 'string', 'minLength' => 1],
                            ],
                        ],
                    ],
                ],
            ]),

            'columns' => $this->wrap($type, [
                'type' => 'object',
                'required' => ['layout', 'columns'],
                'properties' => [
                    'layout' => ['type' => 'string', 'enum' => ['2', '3']],
                    'columns' => [
                        'type' => 'array',
                        'minItems' => 2,
                        'maxItems' => 3,
                        'items' => ['type' => 'string', 'minLength' => 1],
                    ],
                ],
            ]),

            default => null,
        };
    }

    /**
     * Обернуть data-схему в общий каркас Editor.js блока.
     *
     * @param  array<string, mixed>  $dataSchema
     * @return array<string, mixed>
     */
    private function wrap(string $type, array $dataSchema): array
    {
        return [
            'type' => 'object',
            'required' => ['type', 'data'],
            'properties' => [
                'type' => ['type' => 'string', 'const' => $type],
                'data' => $dataSchema,
            ],
        ];
    }
}
