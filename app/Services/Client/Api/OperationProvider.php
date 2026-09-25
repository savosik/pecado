<?php

namespace App\Services\Client\Api;

/**
 * Секция реестра: класс, который объявляет свои операции и сам же их выполняет.
 *
 * Описание и обработчик живут в одном файле намеренно — так секции независимы
 * друг от друга, и добавить операцию в «заказы» можно, не касаясь «корзин».
 */
interface OperationProvider
{
    /**
     * Ключ секции и её русское название для каталога и тегов OpenAPI.
     *
     * @return array{string, string}
     */
    public static function section(): array;

    /**
     * @return list<Operation>
     */
    public static function operations(): array;
}
