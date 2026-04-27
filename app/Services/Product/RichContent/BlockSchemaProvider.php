<?php

namespace App\Services\Product\RichContent;

use RuntimeException;

class BlockSchemaProvider
{
    /** @var array<string, mixed>|null */
    private ?array $cachedRaw = null;

    public function __construct(
        private readonly string $schemaPath,
    ) {}

    /**
     * Подмножество blockTypes из docs/content-blocks-schema.json под whitelist.
     *
     * @param  list<string>  $allowedTypes
     * @return array<string, array{description: string, schema: array<string, mixed>, example: array<string, mixed>}>
     */
    public function blocksFor(array $allowedTypes): array
    {
        $all = $this->raw()['blockTypes'] ?? [];

        $filtered = [];
        foreach ($allowedTypes as $type) {
            if (! isset($all[$type])) {
                continue;
            }
            $filtered[$type] = [
                'description' => $all[$type]['description'] ?? '',
                'schema' => $all[$type]['schema'] ?? [],
                'example' => $all[$type]['example'] ?? [],
            ];
        }

        return $filtered;
    }

    /**
     * Человекочитаемый markdown-фрагмент со схемами всех разрешённых блоков
     * для скармливания в system prompt LLM-ке.
     *
     * @param  list<string>  $allowedTypes
     */
    public function promptContext(array $allowedTypes): string
    {
        $blocks = $this->blocksFor($allowedTypes);
        $lines = [];

        foreach ($blocks as $type => $info) {
            $schemaJson = json_encode(
                $info['schema'],
                JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
            );

            $exampleJson = json_encode(
                $info['example'],
                JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
            );

            $lines[] = "### {$type}\n";
            $lines[] = $info['description'];
            $lines[] = '';
            $lines[] = "**Структура:**\n```json\n{$schemaJson}\n```";
            $lines[] = '';
            $lines[] = "**Пример:**\n```json\n{$exampleJson}\n```";
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /** @return array<string, mixed> */
    private function raw(): array
    {
        if ($this->cachedRaw !== null) {
            return $this->cachedRaw;
        }

        if (! is_file($this->schemaPath)) {
            throw new RuntimeException("Schema file not found: {$this->schemaPath}");
        }

        $contents = file_get_contents($this->schemaPath);
        if ($contents === false) {
            throw new RuntimeException("Cannot read schema file: {$this->schemaPath}");
        }

        $decoded = json_decode($contents, true);
        if (! is_array($decoded)) {
            throw new RuntimeException("Schema file is not valid JSON: {$this->schemaPath}");
        }

        return $this->cachedRaw = $decoded;
    }
}
