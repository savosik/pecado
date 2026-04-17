<?php

namespace App\Services\ProductExport\Presets;

use App\Models\ProductExport;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Контракт для всех пресетов стандартных выгрузок.
 */
interface PresetInterface
{
    /**
     * Уникальный ключ пресета (yml, shopify, woocommerce, etc.)
     */
    public function key(): string;

    /**
     * Человекочитаемое название для UI.
     */
    public function name(): string;

    /**
     * Краткое описание пресета для пользователя.
     */
    public function description(): string;

    /**
     * Расширение файла (xml, csv, xlsx).
     */
    public function fileExtension(): string;

    /**
     * MIME-тип для Response.
     */
    public function mimeType(): string;

    /**
     * Иконка/цвет для UI карточки.
     */
    public function color(): string;

    /**
     * Иконка для UI (имя иконки из Lucide).
     */
    public function icon(): string;

    /**
     * Генерировать содержимое файла и записать в поток.
     *
     * @param  resource  $stream  writable stream
     */
    public function writeToStream($stream, ProductExport $export): void;

    /**
     * Генерировать StreamedResponse для скачивания.
     */
    public function generate(ProductExport $export): StreamedResponse;
}
