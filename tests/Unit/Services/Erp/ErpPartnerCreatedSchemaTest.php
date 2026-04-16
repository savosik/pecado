<?php

namespace Tests\Unit\Services\Erp;

use App\Services\Erp\ErpMessageValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Регрессионные тесты JSON Schema для partner.created (1С → Сайт).
 *
 * Покрывает баг: 1С генерирует пароли длиной 9–10 символов (CRC32 в десятичном
 * представлении), которые ранее отвергались из-за maxLength: 8.
 */
class ErpPartnerCreatedSchemaTest extends TestCase
{
    private ErpMessageValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new ErpMessageValidator();
    }

    private function basePayload(array $override = []): array
    {
        return array_merge([
            'event'      => 'partner.created',
            'message_id' => 'msg-test-001',
            'uuid'       => '550e8400-e29b-41d4-a716-446655440000',
            'login'      => 'test@example.com',
            'name'       => 'Иванов Иван',
            'email'      => 'test@example.com',
            'password'   => '12345678',
            'country'    => 'RU',
            'is_active'  => true,
        ], $override);
    }

    // ──────────────────────────────────────────────
    // Регрессия: длина пароля 9–10 символов (CRC32)
    // ──────────────────────────────────────────────

    #[Test]
    #[DataProvider('validPasswordLengths')]
    public function password_of_valid_length_passes_schema(string $password): void
    {
        $result = $this->validator->validate('partner.created', $this->basePayload([
            'password' => $password,
        ]));

        $this->assertTrue($result['valid'], "Пароль длиной " . strlen($password) . " символов должен проходить валидацию: " . implode(', ', $result['errors']));
    }

    public static function validPasswordLengths(): array
    {
        return [
            '1 символ'   => ['1'],
            '8 символов' => ['12345678'],
            '9 символов' => ['123456789'],      // регрессия: ранее отвергался
            '10 символов' => ['2926704424'],    // регрессия: реальное значение CRC32
            '32 символа' => [str_repeat('a', 32)],
        ];
    }

    #[Test]
    public function password_longer_than_32_fails_schema(): void
    {
        $result = $this->validator->validate('partner.created', $this->basePayload([
            'password' => str_repeat('x', 33),
        ]));

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
    }

    #[Test]
    public function empty_password_fails_schema(): void
    {
        $result = $this->validator->validate('partner.created', $this->basePayload([
            'password' => '',
        ]));

        $this->assertFalse($result['valid']);
    }
}
